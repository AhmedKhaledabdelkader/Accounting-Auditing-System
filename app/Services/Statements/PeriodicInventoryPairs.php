<?php

namespace App\Services\Statements;

/**
 * Check 26a, `periodic_inventory_pair` (plan Q32b): in a periodic system the opening and closing stock accounts belong in
 * cost of sales, but a client who labels them inventory gets them on the balance sheet, which misstates profit by the
 * change in stock. Found from balances and movements only, never from account numbers or names:
 *
 * - opening stock: an account on the inventory line whose debit balance equals the opening balance of another inventory
 *   account (or of all of them together), or whose period debit does;
 * - closing stock: an account on the inventory line with a credit balance equal to another inventory account's closing
 *   balance (or all of them together), or whose period credit does.
 *
 * Each match is a proposal to move the account to cost of revenue. It is never applied: the user confirms it.
 */
final class PeriodicInventoryPairs
{
    private const INVENTORY = 'sofp.inventory';
    private const COST      = 'is.cost_of_revenue';

    /**
     * @param array<int, array> $mapped LineMapper rows (row => mapping)
     * @return list<array{code: string, kind: string, row: int, matches: list<int>, amount: float, current_line: string, proposed_line: string, basis: string, message: string, status: string}>
     */
    public function find(array $rows, array $mapped, LayoutProfile $profile): array
    {
        $tolerance = (float) config('classification.materiality_threshold', 1.0);
        $byRow     = array_column($rows, null, 'row');
        $stock     = array_filter($mapped, fn ($m) => isset($m['lines']['closing'][self::INVENTORY]) || isset($m['lines']['opening'][self::INVENTORY]));
        $proposals = [];

        foreach ($stock as $n => $mapping) {
            if ($mapping['source'] === 'override' || ! isset($mapping['lines']['closing'][self::INVENTORY])) {
                continue;
            }
            $balance = $profile->closing($byRow[$n]);
            if ($balance === null || abs($balance) <= $tolerance) {
                continue;
            }
            $others   = array_diff_key($stock, [$n => true]);
            $movement = $profile->movement($byRow[$n]);
            $kind     = $balance > 0 ? 'opening_stock' : 'closing_stock';
            // What the account mirrors: the other accounts' opening (opening stock) or closing (closing stock) balances.
            $mirror = fn (int $o) => $kind === 'opening_stock' ? $profile->opening($byRow[$o]) : $profile->closing($byRow[$o]);
            if ($kind === 'opening_stock' && ! $profile->has('opening')) {
                continue;   // nothing to compare an opening-stock balance with
            }

            $targets = array_filter([abs($balance), $movement ? ($kind === 'opening_stock' ? $movement['debit'] : $movement['credit']) : null], fn ($v) => $v !== null && $v > $tolerance);
            $matches = [];
            $basis   = null;
            foreach (array_keys($others) as $o) {
                $value = $mirror($o);
                foreach ($targets as $i => $target) {
                    if ($value !== null && $value > $tolerance && abs($value - $target) <= $tolerance) {
                        $matches[] = $o;
                        $basis ??= $i === 0 ? 'balance' : 'movement';
                    }
                }
            }
            if ($matches === [] && count($others) > 1) {
                $total = array_sum(array_map(fn ($o) => max(0.0, (float) $mirror($o)), array_keys($others)));
                foreach ($targets as $i => $target) {
                    if ($total > $tolerance && abs($total - $target) <= $tolerance) {
                        $matches = array_values(array_filter(array_keys($others), fn ($o) => (float) $mirror($o) > $tolerance));
                        $basis   = ($i === 0 ? 'balance' : 'movement').'_total';
                        break;
                    }
                }
            }
            if ($matches === []) {
                continue;
            }

            $matches = array_values(array_unique($matches));
            $proposals[] = [
                'code' => 'periodic_inventory_pair', 'kind' => $kind, 'row' => $n, 'matches' => $matches, 'amount' => abs($balance),
                'current_line' => self::INVENTORY, 'proposed_line' => self::COST, 'basis' => $basis, 'status' => 'pending',
                'message' => self::message($kind, $matches, $byRow)->in('ar'),
            ];
        }

        return $proposals;
    }

    /** Why the row should move (check 26a); `message` of the proposal is its Arabic. */
    public static function message(string $kind, array $matches, array $byRow): \App\Support\Phrase
    {
        return ReportChecks::msg($kind === 'opening_stock' ? 'periodic_opening_stock' : 'periodic_closing_stock', ['accounts' => AccountNames::phrase($matches, $byRow)]);
    }
}
