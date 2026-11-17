<?php

namespace App\Services\Statements;

/**
 * What the comparatives count as 0 because the user typed the prior year by hand and left it empty (PR 13c): a figure of
 * origin `prior_year_manual` with no input behind it. Keyed as `PUT …/prior-year` takes them, so the review can name each
 * one and send the user back to type it. A section the user typed nothing in stays missing, so it is never listed here.
 */
final class PriorYearDefaults
{
    /**
     * @param array $built CurrentYearStatements::build()
     * @return array{figures: list<string>, changes_in_equity: list<array{row: string, column: string}>, cash_flows: list<string>,
     *               notes: array<string, list<string>>, schedules: list<string>}
     */
    public static function of(array $built): array
    {
        $statements = $built['statements'];
        $lines = fn (array $rows) => array_values(array_map(fn ($r) => $r['key'],
            array_filter($rows, fn ($r) => $r['type'] === 'line' && self::defaulted($r['comparative'] ?? null))));

        $equity = [];
        foreach ($statements['changes_in_equity']['comparative'] ?? [] as $r) {
            if (! in_array($r['key'], EquityStatementBuilder::ENTERED, true)) {
                continue;   // totals and the closing row are sums
            }
            foreach ($r['cells'] as $column => $f) {
                if ($column !== 'total' && self::defaulted($f)) {
                    $equity[] = ['row' => $r['key'], 'column' => $column];
                }
            }
        }

        $notes = [];
        foreach ($built['breakdowns'] ?? [] as $note => $b) {
            foreach ($b['rows'] as $r) {
                if (self::defaulted($r['comparative'] ?? null)) {
                    $notes[$note][] = (string) $r['key'];
                }
            }
            foreach (NoteBreakdowns::BUILD_UP as $part) {
                if (self::defaulted($b['build_up'][$part]['comparative'] ?? null)) {
                    $notes["{$note}_build_up"][] = $part;
                }
            }
        }

        // Movement tables: a provision's prior year is typed per row (`<schedule>.<row>`), an asset's per cell.
        $kinds = array_column($built['schedules'] ?? [], 'kind', 'key');
        $schedules = [];
        foreach ($built['schedules_comparative'] ?? [] as $key => $f) {
            if (self::defaulted($f)) {
                $parts = explode('.', $key);
                $schedules[] = ($kinds[$parts[0]] ?? null) === 'provision' ? "{$parts[0]}.{$parts[1]}" : $key;
            }
        }

        return [
            'figures'           => [...$lines($statements['financial_position']), ...$lines($statements['comprehensive_income'])],
            'changes_in_equity' => $equity,
            'cash_flows'        => $lines($statements['cash_flows']),
            'notes'             => $notes,
            'schedules'         => array_values(array_unique($schedules)),
        ];
    }

    private static function defaulted(mixed $f): bool
    {
        return $f instanceof Figure && $f->origin === 'prior_year_manual' && $f->refs === [];
    }
}
