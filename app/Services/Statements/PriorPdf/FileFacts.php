<?php

namespace App\Services\Statements\PriorPdf;

/**
 * What last year's file says that the accountant should know before it is used (P3), each a flag with its page:
 * - `framework_full_ifrs`: the statement of compliance names the full IFRS; the output template is the SMEs' one;
 * - `year_typo_rows`: a statement's row labels name other years than its columns' (R16 on row labels: «كما في 31 ديسمبر
 *   2022» closing 2023's block): read as the statements' years;
 * - `transition_note`: a note on adopting a framework for the first time belongs to that year only: not carried into
 *   the new year (proposed, for the accountant to confirm);
 * - `partners_changed`: the capital note lists partners of an earlier year who no longer hold shares.
 */
final class FileFacts
{
    /** A note on a first adoption or a transition to a framework. */
    private const TRANSITION = '/تبني|التطبيق\s+لأول|لأول\s+مر|التحول\s+(إلى|الى)/u';

    public static function flags(array $file): array
    {
        $doc = $file['document'] ?? null;
        if ($doc === null) {
            return [];
        }
        $flags = [];
        $framework = CompanyFacts::of($doc, $doc['corrections'] ?? [], $file['wording_choices'] ?? [])['framework'] ?? null;
        if (($framework['value'] ?? null) === 'ifrs') {
            $flags[] = ['code' => 'framework_full_ifrs', 'page' => $framework['page']];
        }
        $current = $doc['years']['current'] ?? null;
        foreach ($doc['statements'] ?? [] as $st => $statement) {
            $years = [];
            $page = null;
            foreach ($statement['rows'] ?? [] as $r) {
                if (($found = Tables::years($r['label'])) !== []) {
                    $years = [...$years, ...$found];
                    $page ??= $r['page'] ?? null;
                }
            }
            if ($current !== null && $years !== [] && max($years) !== $current) {
                $printed = array_values(array_unique($years));
                rsort($printed);
                $shift = $current - max($years);
                $flags[] = ['code' => 'year_typo_rows', 'page' => $page ?? ($doc['tables'][$statement['tables'][0] ?? 0]['page'] ?? 0), 'statement' => $st,
                    'printed' => $printed, 'used' => array_map(fn ($y) => $y + $shift, $printed)];
            }
        }
        foreach ($doc['sections'] ?? [] as $s) {
            if ($s['parent'] === null && preg_match(self::TRANSITION, $s['title'])) {
                $pages = array_values(array_unique(array_column(array_filter($doc['sections'], fn ($x) => $x['id'] === $s['id'] || $x['parent'] === $s['id']), 'page')));
                $flags[] = ['code' => 'transition_note', 'page' => $s['page'], 'pages' => $pages, 'note' => $s['printed'], 'title' => $s['title']];
            }
        }
        $partners = Partners::read($file);
        if (($partners['former'] ?? []) !== []) {
            $flags[] = ['code' => 'partners_changed', 'page' => $partners['page'], 'note' => $partners['note'], 'title' => implode('، ', $partners['former'])];
        }

        return $flags;
    }
}
