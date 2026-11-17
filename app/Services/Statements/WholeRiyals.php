<?php

namespace App\Services\Statements;

/**
 * The whole riyals a statement shows (Q27): each line rounded, each total the sum of what its parts show, and each total's
 * rounding difference (its own rounded amount − the sum of its rounded parts) put on one line under it, so every total
 * foots and shows its own rounded amount, and the balance sheet balances. The line: the user's choice for that total,
 * else retained earnings when under it, else the largest line directly under it, else the largest line anywhere under it;
 * never a line tied to an outside source (NEVER: capital, cash and banks, related parties, zakat). A difference above the limit is not put anywhere: the
 * total shows the sum of its parts and the check is an error.
 */
final class WholeRiyals
{
    /**
     * Lines that never take a difference: each ties to an outside source (the registered capital, the banks' statements,
     * the related parties' confirmations, the zakat assessment) and shows its own amount.
     */
    public const NEVER = ['sofp.share_capital', 'sofp.cash', 'sofp.bank_overdraft', 'sofp.murabaha_deposits', 'sofp.due_from_related_parties',
        'sofp.due_to_related_parties', 'sofp.due_to_related_parties_non_current', 'sofp.zakat_provision'];

    /** The line that takes a difference before the largest, when under the total. */
    public const PREFERRED = 'sofp.retained_earnings';

    /**
     * @param list<array> $rows StatementBuilder rows, totals after their parts
     * @param array<string, string> $targets total key → the line chosen to take its difference
     * @return array{shown: array<string, ?int>, plugs: list<array{total: string, line: string, diff: int, before: int, after: int}>, over: list<array{total: string, diff: int, before: int, after: int}>, lines: array<string, list<string>>}
     *         shown: key → whole riyals (null: missing); lines: total → the lines under it
     */
    public static function settle(array $rows, string $scope, array $targets, float $limit): array
    {
        $exact = $shown = $leaves = $direct = [];
        $plugs = $over = [];
        foreach ($rows as $r) {
            if (! in_array($r['type'], ['line', 'total'], true) || ! isset($r[$scope])) {
                continue;
            }
            $key = $r['key'];
            $exact[$key] = $r[$scope]->isMissing() ? null : (float) $r[$scope]->amount;
            if ($r['type'] === 'line') {
                $shown[$key] = $exact[$key] === null ? null : (int) round($exact[$key]);
                $leaves[$key] = [$key];
                continue;
            }

            $parts = array_values(array_filter($r['sum'], fn ($k) => array_key_exists($k, $shown)));
            $leaves[$key] = array_merge(...array_map(fn ($k) => $leaves[$k], $parts) ?: [[]]);
            $direct[$key] = array_values(array_filter($parts, fn ($k) => $leaves[$k] === [$k]));
            if ($exact[$key] === null || array_filter($parts, fn ($k) => $shown[$k] === null)) {
                $shown[$key] = null;
                continue;
            }
            $before = (int) array_sum(array_map(fn ($k) => $shown[$k], $parts));
            $after  = (int) round($exact[$key]);
            $diff   = $after - $before;
            $shown[$key] = $before;
            if ($diff === 0) {
                continue;
            }
            if (abs($diff) > $limit) {
                $over[] = ['total' => $key, 'diff' => $diff, 'before' => $before, 'after' => $after];
                continue;
            }
            $line = self::target($key, $targets, $leaves[$key], $direct[$key], $exact);
            if ($line === null) {
                continue;
            }
            $shown[$line] += $diff;
            foreach ($leaves as $total => $under) {
                // The totals between the line and this one show it too (the line's own entry is itself).
                if ($total !== $line && $total !== $key && $shown[$total] !== null && in_array($line, $under, true)) {
                    $shown[$total] += $diff;
                }
            }
            $shown[$key] = $after;
            $plugs[] = ['total' => $key, 'line' => $line, 'diff' => $diff, 'before' => $before, 'after' => $after];
        }

        return ['shown' => $shown, 'plugs' => $plugs, 'over' => $over,
            'lines' => array_diff_key($leaves, array_filter($leaves, fn ($under, $k) => $under === [$k], ARRAY_FILTER_USE_BOTH))];
    }

    /** The line under a total that takes its difference (see the class). */
    private static function target(string $total, array $targets, array $leaves, array $direct, array $exact): ?string
    {
        $chosen = $targets[$total] ?? null;
        foreach ([[$chosen], [self::PREFERRED]] as [$line]) {
            if ($line !== null && ! in_array($line, self::NEVER, true) && in_array($line, $leaves, true) && $exact[$line] !== null) {
                return $line;
            }
        }
        foreach ([$direct, $leaves] as $candidates) {
            $known = array_values(array_filter($candidates, fn ($k) => $exact[$k] !== null && ! in_array($k, self::NEVER, true)));
            if ($known) {
                usort($known, fn ($a, $b) => abs($exact[$b]) <=> abs($exact[$a]));

                return $known[0];
            }
        }

        return null;
    }
}
