<?php

namespace App\Services\Statements;

/**
 * This year's opening balances from last year's approved statements (Step B, the accountant's decision, a setting of
 * the report: `prior_figures.approved_opening`, on unless turned off): with last year's statements confirmed from a file,
 * each line's closing this year = last year's approved closing + this year's movement in the trial balance (closing −
 * opening, per account, on the account's line), even where the trial balance's openings differ.
 *
 * How: the statements are built once from the trial balance as it is; then each difference (approved closing − the
 * trial balance's opening) becomes a row of its own, its opening and closing the difference and no movement, on its line:
 * per note line of last year's (R11) when the line's note was read, per PPE category and side (R13), and the rest on the
 * line itself. Built again with these rows, everything that reads the trial balance (the statements, the notes' lines,
 * the PPE table, the changes in equity, the cash flow's changes, the zakat base, the going-concern figures) starts from
 * the approved figures without knowing it. The rows are never the user's accounts: no mapping, no proposal, no party.
 *
 * An account on another line than last year (a comparability proposal, ComparableLines) takes its difference with it:
 * its line is compared without it and last year's line with it, so neither line is zeroed or turned over by it. A line
 * presented with the other sign, or at nothing, because of a difference is a warning, never silent.
 *
 * A trial balance without opening balances has no movement to add: the method can't apply, and the report says so.
 */
final class ApprovedOpening
{
    /** The rows' numbers start here, past any trial balance's. */
    public const FIRST_ROW = 1_000_000;

    public static function isRow(int|string $n): bool
    {
        return (int) $n >= self::FIRST_ROW;
    }

    /** Whether the report asks for it: last year's statements from a file in use, the setting not turned off. */
    public static function wanted(array $prior): bool
    {
        return ($prior['source'] ?? null) === 'statements' && ($prior['approved_opening'] ?? true) !== false && ! ($prior['fixed'] ?? false);
    }

    /**
     * The rows that bring each line's opening to last year's approved closing, from the statements built on the trial
     * balance as it is.
     *
     * @param array $raw CurrentYearStatements::build() without them
     * @return array{status: string, rows: list<array>, mapping: array<int, array>, categories: array<int, string>, items: array<string, array<string, string>>, ppe: array<string, string>, remainders: array<string, array>}
     *         status: `applied`, `equal` (nothing differs) or `no_opening` (the trial balance has no opening balances)
     */
    /**
     * The accounts on another line than last year's statements put them (ComparableLines' proposals, not accepted): each
     * with its opening (debit − credit), this year's line and last year's. Never the PPE line (its categories do that).
     *
     * @return list<array{row: int, name: string, from: string, to: string, raw: float, by: string, item: ?string}>
     */
    public static function moves(array $raw, array $prior): array
    {
        if (! $raw['profile']->has('opening') || $raw['opening'] === null || ! isset($prior['file'])) {
            return [];
        }
        $catalogue = $raw['context']->catalogue;
        $check = [];
        foreach ($raw['statements']['financial_position'] as $r) {
            $opening = $r['type'] === 'line' ? self::openingOf($raw, $r['key'], $r['comparative'] ?? Figure::missing()) : null;
            if ($opening === null || ($r['comparative'] ?? Figure::missing())->isMissing()) {
                continue;
            }
            [$a, $b] = [round($opening->amount, 2), round($r['comparative']->amount, 2)];
            $check[] = ['line' => $r['key'], 'label' => $r['label_ar'], 'opening' => $a + 0.0, 'prior' => $b + 0.0, 'difference' => round($a - $b, 2) + 0.0,
                'kind' => OpeningCheck::kind($a, $b)];
        }
        $out = [];
        foreach (ComparableLines::proposals($catalogue, $raw['context'], $check, $prior['file'], $prior['file_rows'] ?? []) as $p) {
            foreach ($p['accounts'] as $a) {
                if ($p['line'] !== 'sofp.ppe' && $a['from'] !== 'sofp.ppe') {
                    $out[] = ['row' => $a['row'], 'name' => $a['name'], 'from' => $a['from'], 'to' => $p['line'],
                        'raw' => ($catalogue->side($p['line']) === 'debit' ? 1 : -1) * $a['amount'], 'by' => $a['by'], 'item' => $a['item']];
                }
            }
        }

        return $out;
    }

    /** A line's opening in the trial balance; a line with no account opens at nothing where last year's has a figure. */
    private static function openingOf(array $raw, string $line, Figure $approved): ?Figure
    {
        $opening = $raw['opening'][$line] ?? null;
        if ($opening !== null && ! $opening->isMissing()) {
            return $opening;
        }

        return ! $approved->isMissing() && abs($approved->amount) >= 0.005 && $raw['context']->rowsOn($line) === [] ? new Figure(0.0, 'derived') : null;
    }

    /** @param list<array> $moves self::moves() */
    public static function plan(array $raw, array $moves = []): array
    {
        $out = ['status' => 'equal', 'rows' => [], 'mapping' => [], 'categories' => [], 'items' => [], 'ppe' => [], 'remainders' => [], 'moves' => $moves, 'kept' => []];
        if (! $raw['profile']->has('opening') || $raw['opening'] === null) {
            return ['status' => 'no_opening'] + $out;
        }
        $ctx = $raw['context'];
        $catalogue = $ctx->catalogue;
        $next = self::FIRST_ROW;
        $add = function (string $line, float $amount, ?string $subClass, bool $contra, array $extra = []) use (&$out, &$next, $catalogue) {
            // $amount on the line's side (positive: the line's normal balance); the row's debit − credit from it.
            $net = round(($catalogue->side($line) === 'debit' ? 1 : -1) * ($contra ? -1 : 1) * $amount, 2);
            $n = $next++;
            $out['rows'][] = ['row' => $n, 'account_number' => null, 'account_name' => __('statements.approved_opening.row', [], 'ar'),
                'financial_statement' => 'قائمة المركز المالي', 'main_class' => null, 'sub_class' => $subClass,
                'opening_debit' => max($net, 0.0), 'opening_credit' => max(-$net, 0.0), 'period_debit' => 0.0, 'period_credit' => 0.0,
                'adjustment_debit' => 0.0, 'adjustment_credit' => 0.0, 'closing_debit' => max($net, 0.0), 'closing_credit' => max(-$net, 0.0),
                'data_flags' => [], 'needs_review' => false, 'approved_opening' => true];
            $out['mapping'][$n] = ['lines' => ['closing' => [$line => 1.0], 'opening' => [$line => 1.0]], 'source' => 'approved_opening', 'status' => 'ok',
                'reasons' => [], 'contra' => $contra];

            return $n;
        };
        // The class of the line's largest account: a row of the rest groups with it in a note by classes.
        $classOf = function (array $rows) use ($ctx): ?string {
            $best = null;
            foreach ($rows as $n) {
                $size = abs((float) ($ctx->profile->opening($ctx->rows[$n]) ?? 0.0)) + abs((float) ($ctx->profile->closing($ctx->rows[$n]) ?? 0.0));
                if (! self::isRow($n) && ($best === null || $size > $best[1])) {
                    $best = [$ctx->rows[$n]['sub_class'] ?? null, $size];
                }
            }

            return $best[0] ?? null;
        };
        $notes = [];
        foreach ($raw['breakdowns'] ?? [] as $note => $b) {
            if (count($b['lines']) === 1 && isset($raw['note_items'][$note])) {
                $notes[$b['lines'][0]] = $note;
            }
        }
        // A moved account's opening counts on last year's line, not this year's (its amount as each line shows it).
        $shown = fn (string $line, float $raw) => $catalogue->side($line) === 'debit' ? $raw : -$raw;
        $shift = [];
        $movedRows = [];
        $movedIn = [];
        foreach ($moves as $m) {
            $shift[$m['from']] = ($shift[$m['from']] ?? 0.0) - $shown($m['from'], $m['raw']);
            $shift[$m['to']] = ($shift[$m['to']] ?? 0.0) + $shown($m['to'], $m['raw']);
            $movedRows[$m['row']] = true;
            $movedIn[$m['to']][] = $m;
        }
        $out['shift'] = array_map(fn ($v) => round($v, 2) + 0.0, $shift);
        $carry = 0.0;
        $comparative = [];
        foreach ($raw['statements']['financial_position'] as $r) {
            $r['type'] === 'line' && $comparative[$r['key']] = $r['comparative'] ?? Figure::missing();
        }

        foreach ($comparative as $line => $approved) {
            $opening = self::openingOf($raw, $line, $approved);
            if ($approved->isMissing() || $opening === null) {
                continue;
            }
            $rest = round($approved->amount - $opening->amount - ($shift[$line] ?? 0.0), 2);
            if (abs($rest) < 0.005) {
                continue;
            }
            // Under a riyal (last year's statements print whole riyals) on a line tied to an outside source (WholeRiyals::NEVER:
            // the bank, the related parties, the zakat assessment): the line keeps its own amount; retained earnings take it.
            if (abs($rest) < 1 && in_array($line, WholeRiyals::NEVER, true) && $line !== 'sofp.retained_earnings') {
                $out['kept'][$line] = $rest;
                $carry += $catalogue->side($line) === 'debit' ? -$rest : $rest;
                continue;
            }
            $onLine = array_keys($ctx->rowsOn($line));
            if ($line === 'sofp.ppe' && ($raw['ppe_prior']['categories'] ?? []) !== []) {
                // Per category and side: last year's closing − this year's opening of the accounts placed on it.
                $accounts = array_column($raw['ppe_prior']['accounts'], null, 'row');
                foreach ($raw['ppe_prior']['categories'] as $c) {
                    foreach (['cost' => ['cost_closing', 'cost_opening', false], 'accumulated' => ['acc_closing', 'acc_opening', true]] as $side => [$last, $open, $contra]) {
                        $d = round(($c['last'][$last] ?? 0.0) - ($c['current'][$open] ?? 0.0), 2);
                        if (abs($d) < 0.005) {
                            continue;
                        }
                        $mine = array_keys(array_filter($accounts, fn ($a) => $a['category'] === $c['key'] && $a['side'] === $side));
                        $n = $add($line, $d, $classOf($mine) ?? $classOf(array_keys(array_filter($accounts, fn ($a) => $a['category'] === $c['key']))), $contra);
                        $out['ppe'][(string) $n] = $c['key'];
                        $c['own'] !== null && $out['categories'][$n] = $c['own'];
                        $rest = round($rest - ($contra ? -$d : $d), 2);
                    }
                }
                // An account on no category: last year's categories hold its balance, so it starts from nothing, on its
                // side; its movement stays its own (and it still asks for a category).
                foreach ($accounts as $n => $a) {
                    $open = $a['category'] === null ? $ctx->sum([$n => 1.0], $a['side'] === 'cost' ? 'debit' : 'credit')['opening'] : null;
                    if ($open !== null && abs($open) >= 0.005) {
                        $contra = $a['side'] === 'accumulated';
                        $row = $add($line, -$open, $ctx->rows[$n]['sub_class'] ?? null, $contra);
                        $out['ppe'][(string) $row] = 'none';
                        $rest = round($rest + ($contra ? -$open : $open), 2);
                    }
                }
            } elseif (isset($notes[$line])) {
                // Per note line of last year's: its amount − the openings of the accounts placed on it; the accounts on no
                // line of last year's (new items, «-» last year) start from nothing.
                $note = $notes[$line];
                $info = $raw['note_items'][$note];
                $info['accounts'] = array_filter($info['accounts'], fn ($a) => ! isset($movedRows[(int) ($a['ref'] ?? 0)]) || ! ctype_digit((string) ($a['ref'] ?? '')));
                // The accounts moved here from another line: on last year's note line naming them, else on its largest.
                $largestKey = array_reduce($info['items'], fn ($m, $i) => $m === null || abs($i['prior']) > abs($m['prior']) ? $i : $m)['key'] ?? null;
                $inItem = [];
                foreach ($movedIn[$line] ?? [] as $m) {
                    $key = array_values(array_filter($info['items'], fn ($i) => $m['item'] !== null && $i['label'] === $m['item']))[0]['key'] ?? $largestKey;
                    $inItem[$key] = ($inItem[$key] ?? 0.0) + $shown($line, $m['raw']);
                }
                foreach ($info['items'] as $item) {
                    $on = array_filter($info['accounts'], fn ($a) => $a['item'] === $item['key']);
                    $d = round($item['prior'] - array_sum(array_map(fn ($a) => (float) ($a['opening'] ?? 0.0), $on)) - ($inItem[$item['key']] ?? 0.0), 2);
                    if (abs($d) >= 0.005) {
                        $n = $add($line, $d, $classOf(array_map('intval', array_filter(array_map('strval', array_column($on, 'ref')), 'ctype_digit'))), false);
                        $out['items'][$note][(string) $n] = $item['key'];
                        $rest = round($rest - $d, 2);
                    }
                }
                $new = [];
                foreach ($info['accounts'] as $a) {
                    if ($a['item'] === 'new' && abs((float) ($a['opening'] ?? 0.0)) >= 0.005) {
                        $new[$a['class']] = round(($new[$a['class']] ?? 0.0) - (float) $a['opening'], 2);
                    }
                }
                foreach ($new as $class => $d) {
                    if (abs($d) >= 0.005) {
                        $n = $add($line, $d, $class, false);
                        $out['items'][$note][(string) $n] = 'new';
                        $rest = round($rest - $d, 2);
                    }
                }
                // What last year's note doesn't add up to its line (a misprint of the file's): on its largest line, shown.
                if (abs($rest) >= 0.005 && $info['items'] !== []) {
                    $largest = array_reduce($info['items'], fn ($m, $i) => $m === null || abs($i['prior']) > abs($m['prior']) ? $i : $m);
                    $n = $add($line, $rest, null, false);
                    $out['items'][$note][(string) $n] = $largest['key'];
                    $out['remainders'][$line] = ['amount' => $rest, 'item' => $largest['label']];
                    $rest = 0.0;
                }
            }
            if (abs($rest) >= 0.005) {
                $add($line, $rest, $classOf($onLine), false);
            }
        }
        if (abs($carry = round($carry, 2)) >= 0.005) {
            $add('sofp.retained_earnings', $carry, $classOf(array_keys($ctx->rowsOn('sofp.retained_earnings'))), false);
            $out['kept']['sofp.retained_earnings'] = -$carry;
        }
        if ($out['rows'] !== []) {
            $out['status'] = 'applied';
        }

        return $out;
    }

    /**
     * The reconciliation of each balance-sheet line: the trial balance's opening, last year's approved closing, the
     * difference (trial balance − approved), the trial balance's closing and the closing presented (approved + this
     * year's movement); the totals' differences apart from the lines'.
     *
     * @param array $raw  the statements on the trial balance as it is
     * @param array $built the statements from the approved openings (the same when nothing applied)
     */
    public static function summary(array $raw, array $built, array $plan, bool $enabled): array
    {
        $rows = fn (array $b) => array_column(array_filter($b['statements']['financial_position'], fn ($r) => in_array($r['type'], ['line', 'total'], true)), null, 'key');
        $catalogue = $raw['context']->catalogue;
        [$tb, $shown] = [$rows($raw), $rows($built)];
        $amount = fn (?Figure $f) => $f === null || $f->isMissing() ? null : round($f->amount, 2) + 0.0;
        // The accounts the user put on another line than their classification's: last year's approved closing has them on
        // the line they were on then, which may be the other one, so they can explain a line's difference.
        $reclassified = [];
        foreach ($raw['mapping']['rows'] ?? [] as $m) {
            $from = array_keys($m['original']['lines'] ?? []);
            $to = array_keys($m['lines']['closing'] ?? []);
            if (! isset($m['original']) || count($from) !== 1 || count($to) !== 1 || $from === $to || ! str_starts_with($to[0], 'sofp.')) {
                continue;
            }
            $row = $raw['context']->rows[$m['row']] ?? [];
            $opening = $raw['context']->sum([$m['row'] => 1.0], $catalogue->side($to[0]))['opening'];
            foreach ([$from[0], $to[0]] as $line) {
                $reclassified[$line][] = ['row' => $m['row'], 'name' => trim((string) ($row['account_name'] ?? ''), " .\u{00A0}"), 'from' => $from[0], 'to' => $to[0],
                    'opening' => $opening];
            }
        }
        $lines = [];
        foreach ($tb as $key => $r) {
            if ($r['type'] !== 'line') {
                continue;
            }
            $open = $amount($raw['opening'][$key] ?? null);
            $approved = $amount($r['comparative'] ?? null);
            [$tbClose, $presented] = [$amount($r['current']), $amount($shown[$key]['current'] ?? null)];
            if (array_filter([$open, $approved, $tbClose, $presented], fn ($v) => $v !== null && abs($v) >= 0.005) === []) {
                continue;
            }
            $difference = $open === null || $approved === null ? null : round($open - $approved, 2) + 0.0;
            $lines[] = ['line' => $key, 'label' => $r['label_ar'], 'tb_opening' => $open, 'approved' => $approved, 'difference' => $difference,
                'kind' => $difference === null ? null : OpeningCheck::kind($open, $approved), 'tb_closing' => $tbClose, 'presented' => $presented,
                'remainder' => $plan['remainders'][$key] ?? null,
                // The moved accounts' openings as this line shows them, added to it (moved here) or taken off (moved away)
                // before comparing: the closing presented = the trial balance's − the difference − this.
                'moved_total' => $plan['shift'][$key] ?? 0.0,
                // A difference under a riyal not applied to a line tied to an outside source (its own amount kept), and
                // retained earnings' share of them (negative): the closing presented = the trial balance's − the
                // difference − `moved_total` − this.
                'rounding_kept' => round($plan['kept'][$key] ?? 0.0, 2) + 0.0,
                // The accounts on another line than last year, from or to this one: their difference follows them.
                'moved' => array_values(array_map(fn ($m) => ['row' => $m['row'], 'name' => $m['name'], 'from' => $m['from'], 'to' => $m['to'],
                    'amount' => round(($catalogue->side($m['to']) === 'debit' ? 1 : -1) * $m['raw'], 2) + 0.0],
                    array_filter($plan['moves'] ?? [], fn ($m) => $m['from'] === $key || $m['to'] === $key))),
                // The accounts the user moved to this line or away from it, with their opening on this year's line.
                'reclassified' => $reclassified[$key] ?? []];
        }
        // The totals: each one's lines' differences together, apart from the lines'.
        $sums = array_column(array_filter($catalogue->statement('financial_position'), fn ($i) => $i['type'] === 'total'), 'sum', 'key');
        $expand = function (string $key) use (&$expand, $sums): array {
            return isset($sums[$key]) ? array_merge([], ...array_map($expand, $sums[$key])) : [$key];
        };
        $byLine = array_column($lines, 'difference', 'line');
        $totals = [];
        foreach (['assets' => 'sofp.total_assets', 'equity' => 'sofp.total_equity', 'liabilities' => 'sofp.total_liabilities'] as $part => $key) {
            $difference = round(array_sum(array_map(fn ($l) => $byLine[$l] ?? 0.0, $expand($key))), 2) + 0.0;
            $approved = $amount($tb[$key]['comparative'] ?? null);
            $totals[] = ['part' => $part, 'line' => $key, 'approved' => $approved, 'tb_opening' => $approved === null ? null : round($approved + $difference, 2) + 0.0,
                'difference' => $difference, 'tb_closing' => $amount($tb[$key]['current'] ?? null), 'presented' => $amount($shown[$key]['current'] ?? null)];
        }

        // Per PPE category and side (R13): the trial balance's opening against last year's approved closing.
        $ppe = [];
        foreach ($raw['ppe_prior']['categories'] ?? [] as $c) {
            foreach ($c['opening_check'] ?? [] as $o) {
                $ppe[] = ['category' => $c['key'], 'label' => $c['label'], 'side' => $o['side'], 'tb_opening' => $o['opening'], 'approved' => $o['prior'],
                    'difference' => $o['difference'], 'kind' => $o['kind']];
            }
        }

        return ['enabled' => $enabled, 'status' => $plan['status'], 'applied' => $plan['status'] === 'applied', 'lines' => $lines, 'totals' => $totals,
            'ppe' => $ppe, 'rows' => count($plan['rows'])] + self::relations($lines, $totals, $ppe, $tb, $shown);
    }

    /**
     * What the differences mean together (the opening reconciliation's relations) and what they do to the statements:
     * - `relations`: the accounting equation of the opening balance sheet in the trial balance and in the approved
     *   statements (assets − liabilities − equity, nothing when it holds), the differences' net, the part of the equity's
     *   difference retained earnings don't explain, and the opening cash the cash flow starts from;
     * - `impact`: the lines and totals the statements present against what the trial balance alone gives;
     * - `affects`: the statements and notes a difference reaches (the balance sheet; the changes in equity through retained
     *   earnings or any equity line; the cash flow through cash; the PPE note through its categories).
     *
     * @param list<array> $lines the lines of summary()
     * @param list<array> $totals the totals of summary()
     * @param list<array> $ppe
     * @param array<string, array> $tb the balance-sheet rows of the statements on the trial balance as it is
     * @param array<string, array> $shown the same with the approved openings
     */
    private static function relations(array $lines, array $totals, array $ppe, array $tb, array $shown): array
    {
        $part = array_column($totals, null, 'part');
        $value = fn (string $p, string $k) => $part[$p][$k] ?? null;
        $equation = fn (string $k) => array_filter([$value('assets', $k), $value('liabilities', $k), $value('equity', $k)], fn ($v) => $v === null) === []
            ? round($value('assets', $k) - $value('liabilities', $k) - $value('equity', $k), 2) + 0.0 : null;
        $diff = array_column($lines, 'difference', 'line');
        $equityDifference = (float) ($part['equity']['difference'] ?? 0.0);
        $relations = [
            ['code' => 'equation_tb', 'difference' => $equation('tb_opening')],
            ['code' => 'equation_approved', 'difference' => $equation('approved')],
            ['code' => 'differences_net', 'difference' => $part === [] ? null : round((float) ($part['assets']['difference'] ?? 0.0) - (float) ($part['liabilities']['difference'] ?? 0.0) - $equityDifference, 2) + 0.0],
            ['code' => 'equity_other_than_retained', 'difference' => round($equityDifference - (float) ($diff['sofp.retained_earnings'] ?? 0.0), 2) + 0.0],
            ['code' => 'opening_cash', 'difference' => isset($diff['sofp.cash']) ? round((float) $diff['sofp.cash'], 2) + 0.0 : 0.0],
        ];
        $relations = array_map(fn ($r) => $r + ['ok' => $r['difference'] === null || abs($r['difference']) < 0.5], $relations);

        $impact = [];
        foreach (['sofp.total_assets', 'sofp.total_liabilities', 'sofp.total_equity', 'sofp.retained_earnings', 'sofp.cash', 'sofp.ppe'] as $key) {
            $before = isset($tb[$key]['current']) && ! $tb[$key]['current']->isMissing() ? round($tb[$key]['current']->amount, 2) + 0.0 : null;
            $after = isset($shown[$key]['current']) && ! $shown[$key]['current']->isMissing() ? round($shown[$key]['current']->amount, 2) + 0.0 : null;
            if ($before !== null && $after !== null) {
                $impact[] = ['line' => $key, 'label' => $tb[$key]['label_ar'] ?? $key, 'tb_closing' => $before, 'presented' => $after, 'effect' => round($after - $before, 2) + 0.0];
            }
        }
        $moved = fn (array $keys) => array_filter($lines, fn ($l) => in_array($l['line'], $keys, true) && $l['difference'] !== null && abs($l['difference']) >= 0.5) !== [];
        $equityLines = ['sofp.share_capital', 'sofp.statutory_reserve', 'sofp.other_reserves', 'sofp.retained_earnings', 'sofp.partners_current_equity'];
        $affects = array_values(array_filter([
            array_filter($lines, fn ($l) => $l['difference'] !== null && abs($l['difference']) >= 0.5) !== [] ? 'financial_position' : null,
            $moved($equityLines) ? 'changes_in_equity' : null,
            $moved(['sofp.cash']) ? 'cash_flows' : null,
            array_filter($ppe, fn ($p) => abs($p['difference']) >= 0.5) !== [] || $moved(['sofp.ppe']) ? 'notes_ppe' : null,
        ]));

        return ['relations' => $relations, 'impact' => $impact, 'affects' => $affects];
    }

    /**
     * What the report says of it: applied (info: the totals' differences, the reconciliation on the prior-year step), or
     * impossible without the trial balance's opening balances (a warning: this year's figures are its closing balances,
     * never rolled silently from something else).
     */
    public static function checks(array $summary): array
    {
        $money = fn (?float $v) => number_format($v ?? 0.0, 2);
        $totals = array_column($summary['totals'], 'difference', 'part');

        // A line the differences turn over or bring to nothing: a warning with the line, the amount and the cause.
        $turned = [];
        foreach ($summary['applied'] ? $summary['lines'] : [] as $l) {
            [$tb, $shown] = [$l['tb_closing'], $l['presented']];
            if ($tb === null || $shown === null || abs($shown - $tb) < 0.005) {
                continue;
            }
            if ((abs($shown) < 0.005 && abs($tb) >= 0.005) || $shown * $tb < 0 || ($shown <= -0.005 && $tb > -0.005)) {
                // Accounts the user moved to or from the line: named, the likely cause (last year's statements had them where
                // they were then); which line they belong on is the accountant's, never changed here.
                $moved = array_map(fn ($a) => "«{$a['name']}» (".$money($a['opening']).')', $l['reclassified'] ?? []);
                $turned[] = ReportChecks::make('approved_opening_sign', 'warning', $tb, $shown, round($shown - $tb, 2) + 0.0,
                    ReportChecks::msg((abs($shown) < 0.005 ? 'approved_opening_zero' : 'approved_opening_sign').($moved === [] ? '' : '_reclassified'), ['line' => $l['label'], 'presented' => $money($shown),
                        'tb_closing' => $money($tb), 'difference' => $money($shown - $tb), 'approved' => $money($l['approved']), 'opening' => $money($l['tb_opening']),
                        'accounts' => implode('، ', $moved)]),
                    ['line_key' => $l['line'], 'moved' => $l['moved'], 'reclassified' => $l['reclassified'] ?? []]);
            }
        }

        return match ($summary['status']) {
            'applied' => [ReportChecks::make('approved_opening', 'info', null, null, null, ReportChecks::msg('approved_opening',
                ['assets' => $money($totals['assets'] ?? null), 'equity' => $money($totals['equity'] ?? null), 'liabilities' => $money($totals['liabilities'] ?? null)])), ...$turned],
            'no_opening' => [ReportChecks::make('approved_opening_unavailable', 'warning', null, null, null, ReportChecks::msg('approved_opening_unavailable'))],
            default => [],
        };
    }

    /** The plan of a report that doesn't use it (the setting off): nothing applied, the reconciliation still shown. */
    public const OFF = ['status' => 'off', 'rows' => [], 'mapping' => [], 'categories' => [], 'items' => [], 'ppe' => [], 'remainders' => [], 'moves' => []];
}
