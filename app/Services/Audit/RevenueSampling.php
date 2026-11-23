<?php

namespace App\Services\Audit;

use App\Models\AuditPlan;
use App\Models\Company;

/**
 * The revenue samples of a client's year (ISA 530), as the firm's working paper (7002, its «العينات» tab) has them: a sample per
 * account (sales, other revenue), each over that account's entries in the client's sales list (SalesListReader), and a table
 * of them: the account, its population, the sample's size, the misstatement found and the misstatement projected on the
 * population.
 *
 * Every entry at or above the performance materiality is tested in full (key items). The rest, by one of two methods:
 * - `mus` (statistical): a systematic monetary-unit sample from a random start kept with it (the same sample comes again);
 *   n = the rest's total × the assurance factor ÷ (the performance materiality − the expected misstatement × the expansion).
 *   The key items are outside the office's cap; the cap (audit.sampling.limits: 12% to 25% of the rest's invoices, never more
 *   than 50 nor fewer than 5) holds n only: above it, the accountant decides (test n in full, a lower assurance, or the cap
 *   with a justification). The confidence achieved (executed n × PM ÷ the rest) is kept and shown. An entry larger than
 *   the interval is hit once. Projected: each sampled entry's misstatement as a share of it (its tainting) × the interval.
 * - `strata_amount` / `strata_date` (non-statistical, stratified): the rest split into strata by amount (bands of equal value)
 *   or by month; the same size, shared out by the strata's value (one at least in each), picked at random in each. Projected:
 *   each stratum's misstatement as a share of the entries tested × the stratum's value.
 * The key items' misstatements count as found, never projected. The accountant gives each entry's audited amount; the
 * misstatement is the book amount less it (an overstatement of revenue positive). Config: audit.sampling.
 */
final class RevenueSampling
{
    public const METHODS = ['mus', 'strata_amount', 'strata_date'];

    /**
     * @param array $materiality Materiality::build()
     * @return array{sales: ?array, population: array, accounts: list<array>, reconciliation: ?array, samples: list<array>, sample: ?array, performance: ?float}
     */
    public function build(Company $company, int $year, array $materiality): array
    {
        $plan = AuditPlan::of($company, $year);
        $sales = $plan->sales;
        $performance = $materiality['performance'];
        if ($sales === null) {
            return ['sales' => null, 'population' => [], 'accounts' => [], 'reconciliation' => null, 'samples' => [], 'sample' => null, 'performance' => $performance];
        }
        $rows = $sales['rows'] ?? [];
        $roles = array_column($sales['accounts'] ?? [], 'role', 'number');
        // The revenue line's entries: the sales accounts and their returns (other revenue apart); a single list is all of it.
        $isRevenue = fn ($r) => $roles === [] || in_array($roles[$r['account'] ?? ''] ?? null, ['sales', 'returns'], true);
        $sold = array_sum(array_map(fn ($r) => $r['kind'] === 'sale' && $isRevenue($r) ? $r['amount'] : 0.0, $rows));
        $returned = array_sum(array_map(fn ($r) => $r['kind'] === 'return' && $isRevenue($r) ? $r['amount'] : 0.0, $rows));
        $revenue = $materiality['figures']['revenue'] ?? null;
        $population = ['count' => count(array_filter($rows, fn ($r) => $r['kind'] === 'sale' && $isRevenue($r))), 'sales' => round($sold, 2),
            'returns' => round($returned, 2), 'net' => round($sold - $returned, 2)];
        $reconciliation = $revenue === null ? null : ['revenue' => $revenue, 'list' => $population['net'], 'list_gross' => $population['sales'],
            'difference' => round($population['net'] - $revenue, 2), 'difference_gross' => round($population['sales'] - $revenue, 2),
            'agrees' => $materiality['trivial'] !== null && min(abs($population['net'] - $revenue), abs($population['sales'] - $revenue)) <= $materiality['trivial']];

        // The file's dates against the year's period: a list of another year (a 2024 statement for a 2023 sample) is said plainly.
        $dates = array_values(array_filter(array_map(fn ($r) => $r['kind'] === 'sale' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['date']) ? $r['date'] : null, $rows)));
        $period = $company->periodEndingIn($year);
        [$start, $end] = [$period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')];
        $population['period'] = $dates === [] ? null : ['from' => min($dates), 'to' => max($dates), 'start' => $start, 'end' => $end,
            'outside' => count(array_filter($dates, fn ($d) => $d < $start || $d > $end)), 'dated' => count($dates)];

        // The populations a sample can be drawn on: each account taken (sales, other revenue), else the whole list.
        $accounts = [];
        foreach (self::populations($sales) as $key => $p) {
            $accounts[] = ['key' => $key, 'name' => $p['name'], 'role' => $p['role'], 'count' => count($p['rows']), 'total' => round(array_sum(array_column($p['rows'], 'amount')), 2)];
        }

        $samples = array_map(fn ($s) => self::evaluate($s, $sales, $performance, $materiality['trivial']), self::samples($plan->sampling));

        return ['sales' => $sales, 'population' => $population, 'accounts' => $accounts, 'reconciliation' => $reconciliation, 'samples' => $samples,
            'sample' => $samples[0] ?? null, 'performance' => $performance];
    }

    /** The samples kept on a plan (one kept before samples were several: the first). */
    public static function samples(?array $sampling): array
    {
        if ($sampling === null) {
            return [];
        }
        if (isset($sampling['selected'])) {
            return [$sampling + ['id' => 's1', 'account' => null, 'method' => 'mus', 'results' => []]];
        }

        return array_values($sampling['samples'] ?? []);
    }

    /**
     * The populations of a sales list: by account (`account` number → name, role, its sale entries with their index in the
     * list), or the whole list (`all`) when it has no accounts.
     *
     * @return array<string, array{name: ?string, role: ?string, rows: array<int, array>}>
     */
    public static function populations(array $sales): array
    {
        $rows = $sales['rows'] ?? [];
        $accounts = array_values(array_filter($sales['accounts'] ?? [], fn ($a) => in_array($a['role'] ?? null, ['sales', 'other_revenue'], true)));
        if ($accounts === []) {
            return ['all' => ['name' => null, 'role' => 'sales', 'rows' => array_filter($rows, fn ($r) => $r['kind'] === 'sale')]];
        }
        $out = [];
        foreach ($accounts as $a) {
            $out[(string) $a['number']] = ['name' => $a['name'], 'role' => $a['role'],
                'rows' => array_filter($rows, fn ($r) => $r['kind'] === 'sale' && (string) ($r['account'] ?? '') === (string) $a['number'])];
        }

        return $out;
    }

    /**
     * The accounts of the year's trial balance a sample can be drawn on (any of them, like the firm's working papers): each with
     * its number, name, classes, its natural side (the class's, else its balance's), the year's movement on that side and its
     * closing balance. By main class, in the trial balance's order.
     *
     * @return list<array{row: int, number: ?string, name: string, main_class: ?string, sub_class: ?string, side: string, movement: ?float, balance: float}>
     */
    public static function trialBalanceAccounts(?\App\Models\ClassificationRun $run): array
    {
        if ($run === null) {
            return [];
        }
        $taxonomy = app(\App\Repositories\Contracts\TaxonomyRepositoryInterface::class);
        $out = [];
        foreach ((array) ($run->result['data'] ?? []) as $r) {
            if (($r['row_type'] ?? 'account') !== 'account' || trim((string) ($r['account_name'] ?? '')) === '') {
                continue;
            }
            $closing = (float) ($r['closing_debit'] ?? 0) - (float) ($r['closing_credit'] ?? 0);
            $side = ($r['sub_class'] ?? null) ? $taxonomy->normalSide((string) $r['sub_class']) : null;
            $side = in_array($side, ['debit', 'credit'], true) ? $side : ($closing < 0 ? 'credit' : 'debit');
            $movement = isset($r["period_{$side}"]) ? round((float) $r["period_{$side}"], 2) : null;
            if (abs($closing) < 0.005 && ! $movement) {
                continue;   // nothing on it this year
            }
            $out[] = ['row' => (int) $r['row'], 'number' => isset($r['account_number']) ? (string) $r['account_number'] : null, 'name' => trim((string) $r['account_name']),
                'main_class' => $r['main_class'] ?? null, 'sub_class' => $r['sub_class'] ?? null, 'side' => $side, 'movement' => $movement,
                'balance' => round($side === 'debit' ? $closing : -$closing, 2)];
        }

        return $out;
    }

    /**
     * An account's population in the accounts' statements file: its entries on its natural side (the debits of an expense or a
     * receivable, the credits of revenue or a payable), found by the account's number, else its name. Each entry with its index
     * in the file. Empty when the file doesn't hold the account.
     *
     * @return array<int, array{date: ?string, number: ?string, party: ?string, amount: float, kind: string}>
     */
    public static function ledgerPopulation(array $sales, ?string $number, string $name, string $side): array
    {
        $rows = $sales['rows'] ?? [];
        $accounts = array_column($sales['accounts'] ?? [], 'name', 'number');
        $key = $number !== null && isset($accounts[$number]) ? $number : null;
        if ($key === null) {
            $wanted = \App\Support\ImportNormalizer::text($name);
            foreach ($accounts as $n => $nm) {
                if (\App\Support\ImportNormalizer::text((string) $nm) === $wanted) {
                    $key = (string) $n;
                    break;
                }
            }
        }
        if ($key === null) {
            return [];
        }

        return self::onSide(array_filter($rows, fn ($r) => (string) ($r['account'] ?? '') === $key), $side);
    }

    /** Entries on a side: an account statement's debits or credits; a list without sides (invoices) as it is. */
    public static function onSide(array $rows, string $side): array
    {
        $out = [];
        foreach ($rows as $i => $r) {
            $amount = array_key_exists($side, $r) && $r[$side] !== null ? (float) $r[$side] : ((($r['kind'] ?? 'sale') !== 'return') ? (float) $r['amount'] : 0.0);
            if ($amount > 0.005) {
                $out[$i] = ['date' => $r['date'] ?? null, 'number' => $r['number'] ?? null, 'party' => $r['party'] ?? null, 'amount' => round($amount, 2), 'kind' => 'sale'];
            }
        }

        return $out;
    }

    /** The sample's selected entries with their rows kept on it (a sample is read back without its population). */
    public static function keepRows(array $sample, array $population): array
    {
        foreach ($sample['selected'] as $k => $x) {
            $r = $population[$x['index']] ?? [];
            $sample['selected'][$k]['row'] = ['date' => $r['date'] ?? null, 'number' => $r['number'] ?? null, 'party' => $r['party'] ?? null, 'amount' => (float) ($r['amount'] ?? 0)];
        }

        return $sample;
    }

    /**
     * Draws a sample over a population: the key items, then the rest by the method.
     *
     * @param array<int, array> $rows the population's entries, by their index in the list
     * @return array the sample (without its id, account and results)
     */
    public static function draw(array $rows, float $performance, string $assurance, ?int $seed = null, string $method = 'mus', int $strata = 3, float $expected = 0.0, ?string $capDecision = null): array
    {
        $cfg = (array) config('audit.sampling');
        $factor = (float) ($cfg['factors'][$assurance] ?? $cfg['factors'][$cfg['default_assurance']]);
        $expansion = (float) ($cfg['expansion'][$assurance] ?? 1.6);
        $seed ??= random_int(1, PHP_INT_MAX >> 1);
        $selected = [];
        $rest = [];
        foreach ($rows as $i => $r) {
            if (($r['kind'] ?? 'sale') !== 'sale') {
                continue;
            }
            // A key item (at or above the performance materiality): tested in full, outside the cap.
            if ($r['amount'] >= $performance) {
                $selected[$i] = ['reason' => 'key'];
            } else {
                $rest[$i] = $r;
            }
        }
        $keysCount = count($selected);
        $keysTotal = array_sum(array_map(fn ($i) => (float) $rows[$i]['amount'], array_keys($selected)));
        $restTotal = array_sum(array_column($rest, 'amount'));
        // n = ceil(remainder × CF ÷ (PM − expected × expansion)): the representative part, over the rest only.
        $tolerable = $performance - max(0.0, $expected) * $expansion;
        $asked = $rest === [] || $restTotal <= 0 ? 0 : ($tolerable <= 0 ? count($rest) : (int) ceil($restTotal * $factor / $tolerable));
        // The office's cap and floor (audit.sampling.limits) on n only: a share of the rest's invoices, never more than 50 nor fewer than 5.
        $limits = self::limits(count($rest));
        $decision = null;
        if ($asked > $limits['max'] && $limits['max'] < count($rest)) {
            if ($capDecision === null) {
                return ['needs_decision' => true, 'calculated_size' => $asked, 'cap' => $limits['max'], 'remainder' => round($restTotal, 2),
                    'achieved_cf_at_cap' => $restTotal > 0 ? round($limits['max'] * $performance / $restTotal, 2) : null];
            }
            $decision = $capDecision;
            $size = $decision === 'full' ? $asked : $limits['max'];
        } else {
            $size = max($limits['min'], $asked);
        }
        $size = (int) min(count($rest), $size);
        $interval = null;
        $bands = [];
        if ($size > 0 && $size >= count($rest)) {
            foreach (array_keys($rest) as $i) {
                $selected[$i] = ['reason' => 'all'];
            }
        } elseif ($size > 0 && $method === 'mus') {
            $interval = $restTotal / $size;
            mt_srand($seed);
            $point = mt_rand() / mt_getrandmax() * $interval;
            $cumulative = 0.0;
            foreach ($rest as $i => $r) {
                $from = $cumulative;
                $cumulative += $r['amount'];
                if ($point >= $from && $point < $cumulative) {
                    $selected[$i] = ['reason' => 'mus'];
                    while ($point < $cumulative) {
                        $point += $interval;   // an entry larger than the interval is hit once
                    }
                }
            }
            // An entry hit more than once leaves the sample short of the office's minimum: made up at random from the rest.
            $missing = min($size, $limits['min']) - (count($selected) - $keysCount);
            if ($missing > 0) {
                $left = array_values(array_diff(array_keys($rest), array_keys($selected)));
                for ($k = count($left) - 1; $k > 0; $k--) {
                    $j = mt_rand(0, $k);
                    [$left[$k], $left[$j]] = [$left[$j], $left[$k]];
                }
                foreach (array_slice($left, 0, $missing) as $i) {
                    $selected[$i] = ['reason' => 'top_up'];
                }
            }
            mt_srand();
        } elseif ($size > 0) {
            $groups = $method === 'strata_date' ? self::byMonth($rest) : self::byAmount($rest, max(2, min(10, $strata)));
            $sizes = self::share($groups, $size);
            mt_srand($seed);
            foreach ($groups as $g => $members) {
                $keys = array_keys($members);
                // A seeded shuffle (Fisher–Yates): the same seed gives the same entries.
                for ($k = count($keys) - 1; $k > 0; $k--) {
                    $j = mt_rand(0, $k);
                    [$keys[$k], $keys[$j]] = [$keys[$j], $keys[$k]];
                }
                foreach (array_slice($keys, 0, $sizes[$g]) as $i) {
                    $selected[$i] = ['reason' => 'stratum', 'stratum' => $g];
                }
                $bands[$g] = ['label' => $g, 'count' => count($members), 'total' => round(array_sum(array_column($members, 'amount')), 2), 'size' => $sizes[$g],
                    'from' => min(array_column($members, 'amount')), 'to' => max(array_column($members, 'amount'))];
            }
            mt_srand();
        }
        ksort($selected);
        $executed = count($selected) - $keysCount;
        // The confidence the sample really gives: executed n × PM ÷ remainder (3 or more: high assurance).
        $achieved = $restTotal > 0 ? round($executed * $performance / $restTotal, 2) : null;

        return ['method' => $method, 'assurance' => $assurance, 'factor' => $factor, 'expansion' => $expansion, 'expected' => round(max(0.0, $expected), 2),
            'seed' => $seed, 'performance' => $performance,
            'interval' => $interval === null ? null : round($interval, 2), 'size' => $size, 'rest_total' => round($restTotal, 2), 'remainder' => round($restTotal, 2),
            'keys' => ['count' => $keysCount, 'total' => round($keysTotal, 2)],
            'calculated_size' => $asked, 'executed_size' => $executed, 'cap' => $limits['max'], 'cap_decision' => $decision,
            'achieved_cf' => $achieved, 'achieved_level' => self::level($achieved),
            // What the method asked for (key items + n) and the office's limits n was brought into.
            'asked' => $keysCount + $asked, 'limits' => $limits,
            'strata' => array_values($bands), 'drawn_at' => now()->toIso8601String(),
            'population' => ['count' => count(array_filter($rows, fn ($r) => ($r['kind'] ?? 'sale') === 'sale')), 'total' => round(array_sum(array_column($rows, 'amount')), 2)],
            'selected' => array_map(fn ($i, $s) => ['index' => $i] + $s, array_keys($selected), $selected)];
    }

    /** The assurance a confidence factor gives: high at 3 or more, moderate at 2.3, low at 1.6, else below. */
    public static function level(?float $cf): ?string
    {
        return $cf === null ? null : ($cf >= 3.0 ? 'high' : ($cf >= 2.3 ? 'moderate' : ($cf >= 1.6 ? 'low' : 'below')));
    }

    /**
     * The office's limits on a sample of a population of `count` invoices (audit.sampling.limits): at least 12% and at most 25%
     * of them, then never more than 50 nor fewer than 5, never more than there are.
     *
     * @return array{min: int, max: int, count: int}
     */
    public static function limits(int $count): array
    {
        $l = (array) config('audit.sampling.limits');
        $max = min($count, (int) $l['max_items'], max((int) $l['min_items'], (int) floor($count * $l['max_share'] / 100)));
        $min = min($max, max((int) $l['min_items'], (int) ceil($count * $l['min_share'] / 100)));

        return ['min' => $min, 'max' => $max, 'count' => $count];
    }

    /** The rest by amount: `n` bands of about equal value, the smallest entries first. */
    private static function byAmount(array $rest, int $n): array
    {
        uasort($rest, fn ($a, $b) => $a['amount'] <=> $b['amount']);
        $total = array_sum(array_column($rest, 'amount'));
        $groups = [];
        $cumulative = 0.0;
        foreach ($rest as $i => $r) {
            $band = min($n, (int) floor($cumulative / $total * $n) + 1);
            $groups["S{$band}"][$i] = $r;
            $cumulative += $r['amount'];
        }

        return $groups;
    }

    /** The rest by month (its date's year-month; an entry without a date apart). */
    private static function byMonth(array $rest): array
    {
        $groups = [];
        foreach ($rest as $i => $r) {
            $groups[preg_match('/^(\d{4}-\d{2})/', (string) ($r['date'] ?? ''), $m) ? $m[1] : '—'][$i] = $r;
        }
        ksort($groups);

        return $groups;
    }

    /** The sample's size shared out by the strata's value: one entry at least in each, never more than it has. */
    private static function share(array $groups, int $size): array
    {
        $values = array_map(fn ($g) => array_sum(array_column($g, 'amount')), $groups);
        $total = array_sum($values);
        // Fewer to draw than there are strata (the office's cap): one each in the strata of the most value, none in the rest.
        if ($size < count($groups)) {
            arsort($values);
            $top = array_slice(array_keys($values), 0, $size);

            return array_map(fn ($g) => in_array($g, $top, true) ? 1 : 0, array_combine(array_keys($groups), array_keys($groups)));
        }
        $sizes = [];
        foreach ($groups as $g => $members) {
            $sizes[$g] = min(count($members), max(1, (int) round($size * $values[$g] / $total)));
        }
        // A stratum taken whole leaves the rest of the size to the others: given, one by one, to the stratum with the most value
        // per entry sampled that still has entries left (and taken back the same way when the rounding gave too many).
        $capacity = array_map('count', $groups);
        while (array_sum($sizes) < $size && array_filter($sizes, fn ($n, $g) => $n < $capacity[$g], ARRAY_FILTER_USE_BOTH) !== []) {
            $open = array_filter($sizes, fn ($n, $g) => $n < $capacity[$g], ARRAY_FILTER_USE_BOTH);
            $best = null;
            foreach (array_keys($open) as $k) {
                if ($best === null || $values[$k] / max(1, $sizes[$k]) > $values[$best] / max(1, $sizes[$best])) {
                    $best = $k;
                }
            }
            $sizes[$best]++;
        }
        while (array_sum($sizes) > $size && array_filter($sizes, fn ($n) => $n > 1) !== []) {
            $worst = null;
            foreach ($sizes as $k => $n) {
                if ($n > 1 && ($worst === null || $values[$k] / $n < $values[$worst] / $sizes[$worst])) {
                    $worst = $k;
                }
            }
            $sizes[$worst]--;
        }

        return $sizes;
    }

    /**
     * A sample with its entries, its results and what they come to: tested value and coverage, the misstatement found (book
     * less audited, the entries given), the projection on the population, and the conclusion against the materiality.
     */
    public static function evaluate(array $s, array $sales, ?float $performance, ?float $trivial): array
    {
        $rows = $sales['rows'] ?? [];
        $results = (array) ($s['results'] ?? []);
        $items = [];
        foreach ($s['selected'] as $x) {
            $row = $x['row'] ?? ($rows[$x['index']] ?? []);
            unset($x['row']);
            $r = $results[(string) $x['index']] ?? null;
            $audited = isset($r['audited']) && $r['audited'] !== null ? (float) $r['audited'] : null;
            $items[] = $x + $row + ['audited' => $audited, 'note' => $r['note'] ?? null,
                'misstatement' => $audited === null ? null : round((float) ($row['amount'] ?? 0) - $audited, 2)];
        }
        $tested = array_filter($items, fn ($i) => $i['audited'] !== null);
        $found = round(array_sum(array_map(fn ($i) => $i['misstatement'], $tested)), 2);
        $projected = 0.0;
        $method = $s['method'] ?? 'mus';
        foreach ($tested as $i) {
            if (in_array($i['reason'], ['key', 'all'], true)) {
                $projected += $i['misstatement'];   // tested in full: as found
            } elseif ($method === 'mus' && ($s['interval'] ?? null) && $i['amount'] > 0) {
                $projected += $i['amount'] >= $s['interval'] ? $i['misstatement'] : $i['misstatement'] / $i['amount'] * $s['interval'];
            }
        }
        if ($method !== 'mus') {
            foreach ($s['strata'] ?? [] as $band) {
                $in = array_filter($tested, fn ($i) => ($i['stratum'] ?? null) === $band['label']);
                $value = array_sum(array_column($in, 'amount'));
                if ($value > 0) {
                    $projected += array_sum(array_column($in, 'misstatement')) / $value * $band['total'];
                }
            }
        }
        $projected = round($projected, 2);
        $total = array_sum(array_map(fn ($i) => (float) ($i['amount'] ?? 0), $items));
        $population = $s['population']['total'] ?? null;
        $conclusion = match (true) {
            $tested === [] => 'pending',
            count($tested) < count($items) => 'partial',
            $performance !== null && abs($projected) >= $performance => 'material',
            $trivial !== null && abs($projected) > $trivial => 'above_trivial',
            default => 'acceptable',
        };

        return $s + ['items' => $items, 'total' => round($total, 2), 'coverage' => $population ? round($total / $population * 100, 1) : null,
            'tested' => count($tested), 'found' => $found, 'projected' => $projected, 'conclusion' => $conclusion,
            'stale' => $performance !== null && abs(($s['performance'] ?? 0) - $performance) > 0.005];
    }
}
