<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\Statements\EquityStatementBuilder;
use App\Services\Statements\LineCatalogue;
use App\Support\ClassificationKey;
use App\Support\ImportNormalizer;

/**
 * Last year's changes in equity from its PDF (brief §8, Phase 4.1): the block of the file's own year (a statement of
 * changes in equity prints two years, each from its opening balance to its closing one; the later is last year's, the
 * report's comparative year), its rows placed on the rows typed for the comparative year (EquityStatementBuilder::
 * ENTERED) and its columns on the equity columns, by their words. The total column and the total comprehensive income
 * are computed, never taken. A row or a column that can't be placed is listed, with its page, for the accountant.
 */
final class EquityBlock
{
    /** A movement row's words → the row typed for it (normalised: ClassificationKey). */
    private const ROWS = [
        'total_comprehensive_income'   => '/(اجمالي|مجموع).*(الشامل)/u',
        'oci'                          => '/الشامل[هة]? ال?اخر/u',
        'net_profit'                   => '/(صافي|ربح|خساره|الدخل).*(السنه|العام|الفتره)|^صافي (ربح|خساره|الدخل)|(ربح|خساره|الدخل) (بعد|قبل) الزكاه/u',
        'capital_increase'             => '/زياده (في )?راس المال/u',
        'transfer_to_partners_current' => '/(المحول|محول|تحويل).*(الجاري|جاري)/u',
        'transfer_to_reserve'          => '/(المحول|محول|تحويل).*احتياطي/u',
        'dividends'                    => '/توزيعات/u',
        'partners_current_movements'   => '/(الحسابات الجاريه|حساب جاري|جاري الشركاء)/u',
    ];

    /** A column's words for the total: never taken. */
    public const TOTAL = '/^(ال)?(مجموع|اجمالي|الاجمالي)/u';

    /**
     * @param array $doc PriorPdfReader::process()
     * @return ?array{rows: array<string, array<string, float>>, closing: array<string, float>, year: ?int, pages: list<int>,
     *         unplaced_rows: list<array{label: string, page: int}>, unplaced_columns: list<string>} null without the statement
     */
    public static function of(array $doc, LineCatalogue $catalogue): ?array
    {
        $st = $doc['statements']['changes_in_equity'] ?? null;
        if ($st === null || $st['rows'] === []) {
            return null;
        }
        $cfg = $catalogue->changesInEquity();
        $first = $doc['tables'][$st['tables'][0]];
        [$columns, $unplacedColumns] = self::columns($first['columns'], $cfg['columns'], Tables::totalColumn($st['rows'])['column'] ?? null);

        // The blocks: from a balance to the next one. A year's closing is the next year's opening when movements follow
        // it (a statement printing «31 ديسمبر 2023» once); a balance right after a closing opens a block of its own.
        $blocks = [];
        $current = null;
        $after = [];   // movements after a closed block
        foreach ($st['rows'] as $r) {
            if ($r['kind'] === 'heading') {
                continue;
            }
            $balance = Tables::isBalance($r['label']);
            if ($balance && $current !== null && $current['closing'] !== null) {
                $blocks[] = $current;
                $current = $after !== [] ? ['opening' => $current['closing'], 'moves' => $after, 'closing' => $r] : ['opening' => $r, 'moves' => [], 'closing' => null];
                $after = [];
            } elseif ($balance && $current === null) {
                $current = ['opening' => $r, 'moves' => [], 'closing' => null];
            } elseif ($balance) {
                $current['closing'] = $r;
            } elseif ($current !== null && $current['closing'] !== null) {
                $after[] = $r;
            } elseif ($current !== null) {
                $current['moves'][] = $r;
            }
        }
        $current !== null && $blocks[] = $current;
        $blocks = array_values(array_filter($blocks, fn ($b) => $b['closing'] !== null));
        if ($blocks === []) {
            return null;
        }
        // Last year's block: the one whose closing names the file's year, else the last.
        $year = $doc['years']['current'] ?? null;
        $block = collect($blocks)->first(fn ($b) => $year !== null && in_array($year, Tables::years($b['closing']['label']), true)) ?? end($blocks);

        $rows = [];
        $unplacedRows = [];
        $value = fn (array $r) => array_map(fn ($i) => $r['cells'][$i]['amount'] ?? 0.0, $columns);
        $rows['opening'] = $value($block['opening']);
        foreach ($block['moves'] as $r) {
            if (array_filter($r['cells'], fn ($c) => abs($c['amount'] ?? 0.0) > Tables::TOLERANCE) === []) {
                continue;   // a line of nothing
            }
            $key = self::row($r['label']);
            if ($key === 'total_comprehensive_income') {
                continue;   // computed
            }
            if ($key === null) {
                $unplacedRows[] = ['label' => $r['label'], 'page' => $r['page']];
                continue;
            }
            foreach ($value($r) as $col => $amount) {
                $rows[$key][$col] = round(($rows[$key][$col] ?? 0.0) + $amount, 2);
            }
        }

        return ['rows' => array_map(fn ($cells) => array_filter($cells, fn ($v) => abs($v) > 0.005), $rows), 'closing' => $value($block['closing']), 'year' => $year,
            'pages' => array_values(array_unique([$block['opening']['page'], $block['closing']['page']])), 'unplaced_rows' => $unplacedRows, 'unplaced_columns' => $unplacedColumns];
    }

    /** The row typed for a movement's words, or null. */
    public static function row(string $label): ?string
    {
        $text = ClassificationKey::name(trim((string) preg_replace('/[()\[\]]+/u', ' ', $label)));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        foreach (self::ROWS as $key => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $key === 'total_comprehensive_income' || in_array($key, EquityStatementBuilder::ENTERED, true) ? $key : null;
            }
        }

        return null;
    }

    /**
     * Each column printed → an equity column (more than half the column's words found in the header), the total left
     * out (the column adding up the others, or called the total); and the headers placed on none.
     *
     * @return array{0: array<string, int>, 1: list<string>} column key → its index; headers unplaced
     */
    private static function columns(array $printed, array $known, ?int $total = null): array
    {
        $out = [];
        $unplaced = [];
        foreach ($printed as $i => $c) {
            $header = trim(explode(' / ', $c['header'])[0]);
            $words = array_filter(explode(' ', ImportNormalizer::text($header)));
            if ($i === $total || preg_match(self::TOTAL, implode(' ', $words)) === 1 || $words === []) {
                continue;
            }
            $best = null;
            $score = 0.0;
            foreach ($known as $key => $col) {
                $want = array_filter(explode(' ', ImportNormalizer::text($col['label_ar'])));
                $s = $want === [] ? 0.0 : count(array_intersect($want, $words)) / count($want);
                if ($s > $score) {
                    [$best, $score] = [$key, $s];
                }
            }
            if ($best !== null && $score > 0.5 && ! isset($out[$best])) {
                $out[$best] = $i;
            } else {
                $unplaced[] = $header;
            }
        }

        return [$out, $unplaced];
    }
}
