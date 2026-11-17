<?php

namespace App\Services\Statements;

/**
 * Lays one statement out in template order from its line figures, and computes its totals (plan §4.2 step 5). A total
 * is derived from its parts: missing when any part is, pending when any part is. Nothing is typed or forced to balance.
 */
final class StatementBuilder
{
    /**
     * @param list<array>                $structure   LineCatalogue::statement()
     * @param array<string, Figure>      $lines       line key → current figure as displayed
     * @param array<string, array>       $rows        line key → contributing rows
     * @param ?array<string, Figure>     $comparative line key → comparative figure as displayed; null: no comparative column (first year)
     * @return array{rows: list<array>, figures: array<string, Figure>, comparative: ?array<string, Figure>}
     */
    public function build(array $structure, array $lines, array $rows = [], ?array $comparative = null): array
    {
        $figures = $lines;
        $prior   = $comparative;
        $out     = [];
        foreach ($structure as $item) {
            if ($item['type'] === 'total') {
                $refs = array_map(fn ($k) => ['type' => 'line', 'key' => $k], $item['sum']);
                $figures[$item['key']] = Figure::sum(array_map(fn ($k) => $figures[$k], $item['sum']), $refs);
                if ($prior !== null) {
                    $prior[$item['key']] = Figure::sum(array_map(fn ($k) => $prior[$k], $item['sum']), $refs);
                }
            }
            $cmp = $prior === null || $item['type'] === 'heading' ? [] : ['comparative' => $prior[$item['key']]];
            $out[] = match ($item['type']) {
                'heading' => $item,
                'line'    => ['type' => 'line', 'key' => $item['key'], 'label_ar' => $item['label_ar'], 'input' => $item['input'], 'current' => $figures[$item['key']]]
                    + $cmp + ['rows' => $rows[$item['key']] ?? [], 'check_codes' => []],
                'total'   => ['type' => 'total', 'key' => $item['key'], 'label_ar' => $item['label_ar'], 'current' => $figures[$item['key']]] + $cmp + ['sum' => $item['sum']],
            };
        }

        return ['rows' => $out, 'figures' => $figures, 'comparative' => $prior];
    }
}
