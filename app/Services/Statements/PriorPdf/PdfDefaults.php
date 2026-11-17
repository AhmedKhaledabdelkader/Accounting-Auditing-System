<?php

namespace App\Services\Statements\PriorPdf;

use App\Models\FinancialReport;

/**
 * The notes' questions answered from last year's statements confirmed from a PDF (P2: the accountant types only what
 * the file can't give), as a prior report's inputs would answer them: the approving body (the approval note's), the
 * policy choices (the policies note's), the partners (the capital note's). The accountant can change any.
 */
final class PdfDefaults
{
    /** @return array<string, mixed> input key → default ([] without a confirmed PDF) */
    public static function inputs(FinancialReport $report): array
    {
        $file = ($report->prior_figures['source'] ?? null) === 'statements' ? ($report->prior_figures['file'] ?? null) : null;
        $doc = $file['document'] ?? null;
        if ($doc === null) {
            return [];
        }
        $facts = CompanyFacts::of($doc, $doc['corrections'] ?? [], $file['wording_choices'] ?? []);

        // The partners as last year's capital note lists them (R14); their names left empty when they don't pair up
        // with the share rows (never put on a row by guessing).
        $partners = Partners::read($file, $report->prior_figures['rows'] ?? []);
        $table = $partners === null ? null : array_map(fn ($r) => ['name' => $r['name'], 'shares' => $r['shares'], 'share_value' => $r['share_value']], $partners['rows']);


        return array_filter(['approval.body' => $facts['approval.body']['value'] ?? null, 'partners' => $table,
            'other_provisions.description' => self::otherProvisions($doc)] + self::policies($doc), fn ($v) => $v !== null);
    }

    /**
     * What last year's note on the other provisions says they are (its text, as printed): the note whose title names
     * «مخصصات أخرى» or «مخصصات متنوعة», with the text of its parts. Null when there is no such note or it has no text.
     */
    public static function otherProvisions(array $doc): ?string
    {
        $sections = $doc['sections'] ?? [];
        foreach ($sections as $s) {
            if ($s['parent'] !== null || ! preg_match('/مخصصات\s+(أخرى|اخرى|متنوعة)|المخصصات\s+(الأخرى|الاخرى|المتنوعة)/u', $s['title'])) {
                continue;
            }
            $text = $s['paragraphs'];
            foreach ($sections as $child) {
                if ($child['parent'] === $s['id']) {
                    $text = [...$text, ...$child['paragraphs']];
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $text)));

            return mb_strlen($text) >= 3 ? mb_substr($text, 0, 2000) : null;
        }

        return null;
    }

    /**
     * The policy choices last year's policies note made (Phase 5.3): employees' end-of-service benefits by the labour law
     * or actuarially; inventory of a manufacturer (its policy, or work in progress and raw materials among the inventory
     * note's lines) or general; a policy for foreign currency, a lessor's leases, subsidiaries; the revenue streams its
     * revenue policy names. A choice the note doesn't make is left to the accountant (null).
     *
     * @return array<string, mixed>
     */
    public static function policies(array $doc): array
    {
        $sections = $doc['sections'] ?? [];
        $top = function (array $s) use ($sections) {
            while ($s['parent'] !== null) {
                $s = $sections[$s['parent']];
            }

            return $s;
        };
        $policies = [];   // title → text, every part of the policies note
        $inventoryLines = '';
        foreach ($sections as $s) {
            $t = $top($s);
            if (preg_match('/السياسات\s+المحاسبية/u', $t['title'])) {
                $policies[$s['title']] = ($policies[$s['title']] ?? '').' '.implode(' ', $s['paragraphs']);
            }
            if (preg_match('/^(ال)?مخزون$/u', trim($t['title']))) {
                foreach (Sections::tablesOf($t['id'], $sections, $doc['tables'] ?? []) as $table) {
                    $inventoryLines .= ' '.implode(' ', array_column($table['rows'], 'label'));
                }
            }
        }
        if ($policies === []) {
            return [];
        }
        $all = implode(' ', array_keys($policies)).' '.implode(' ', $policies);
        $about = fn (string $title) => implode(' ', array_filter($policies, fn ($text, $t) => preg_match($title, $t) === 1, ARRAY_FILTER_USE_BOTH));
        $inventory = $about('/مخزون/u');
        $revenue = $about('/إيراد|ايراد|الإيرادات|الايرادات/u');
        $streams = $revenue === '' ? [] : array_keys(array_filter([
            'goods'       => preg_match('/بيع\s+(البضا|المنتجات|السلع)|المبيعات/u', $revenue),
            'services'    => preg_match('/تقديم\s+الخدمات|الخدمات/u', $revenue),
            'contracting' => preg_match('/عقود\s+(الإنشاء|الانشاء|المقاولات)|المقاولات/u', $revenue),
            'leasing'     => preg_match('/إيرادات\s+الإيجار|ايرادات\s+الايجار|الإيجارات/u', $revenue),
        ]));

        return [
            'policies.employee_benefits' => match (true) {
                (bool) preg_match('/اكتواري|إكتواري|الوحدة\s+(الإضافية|الاضافية)\s+المقدرة/u', $all) => 'actuarial',
                (bool) preg_match('/(نظام|أنظمة|انظمة)\s+العمل/u', $all) => 'labour_law',
                default => null,
            },
            'policies.inventory' => match (true) {
                (bool) preg_match('/تحت\s+التشغيل|التصنيع|المواد\s+الخام|الإنتاج\s+التام|انتاج\s+تام/u', $inventory.' '.$inventoryLines) => 'manufacturing',
                $inventory !== '' => 'general',
                default => null,
            },
            'policies.foreign_currency' => preg_match('/العملات\s+(الأجنبية|الاجنبية)/u', implode(' ', array_keys($policies))) === 1 ? true : null,
            'policies.lessor' => preg_match('/المؤجر|كمؤجر|بصفتها\s+مؤجر/u', $about('/إيجار|ايجار/u')) === 1 ? true : null,
            'policies.subsidiaries' => preg_match('/الشركات\s+التابعة|توحيد/u', implode(' ', array_keys($policies))) === 1 ? true : null,
            'policies.revenue' => $streams === [] ? null : $streams,
        ];
    }
}
