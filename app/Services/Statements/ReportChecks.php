<?php

namespace App\Services\Statements;

use App\Services\TrialBalanceTotals;
use App\Support\Phrase;
use Carbon\CarbonInterface;

/**
 * The report's checks (plan §4.3): 1–8, 21, 26, 26a, 26d, 28, the zakat rule (Q38), adjusting entries on unmapped rows
 * and `run_changed` (PR 12).
 * Shape: {code, title_ar (lang/ar/statements.php), title, severity, scope, line_key, rows, expected, actual, difference, message_ar, message}.
 * `title_ar` / `message_ar` are Arabic; `title` / `message` the same in the request's language (lang/{ar,en}/report_messages.php).
 * An error blocks a final version, a warning needs an acknowledgement, info is shown only.
 * Every check reports; none changes a figure.
 */
final class ReportChecks
{
    private const PL = 'قائمة الدخل';

    /**
     * @param list<array>           $rows       the run's rows
     * @param array                 $mapping    LineMapper::map()
     * @param array<string, array>  $aggregated LineAggregator::aggregate()
     * @param array<string, Figure> $figures    every line and total of both statements, as displayed
     * @param list<array>           $entries    the report's adjusting entries
     * @return list<array>
     */
    public function run(array $rows, LayoutProfile $profile, array $mapping, array $aggregated, array $figures, array $entries = [], ?float $zakatCharge = null): array
    {
        $tolerance   = (float) config('classification.balance_tolerance', 0.01);
        $materiality = (float) config('classification.materiality_threshold', 1.00);
        $byRow       = array_column($rows, null, 'row');
        $closing     = fn (array $r) => (float) ($profile->closing($r) ?? 0.0);
        $checks      = [];

        // 1. The trial balance balances, per real group (a partial one is check 2's business). Rows left out of the
        // statements (parents, the user's exclusions) are left out here too.
        if (! $profile->isPartial()) {
            $counted = array_values(array_filter($rows, fn ($r) => ! isset($mapping['excluded'][$r['row']])));
            foreach (TrialBalanceTotals::sums($counted, $profile->groups) as $group => [$debit, $credit]) {
                $diff = round($debit - $credit, 2);
                if (abs($diff) > $tolerance) {
                    $rounding = abs($diff) <= $materiality;
                    $checks[] = $this->check('tb_unbalanced', $rounding ? 'info' : 'error', $debit, $credit, $diff,
                        self::msg($rounding ? 'tb_unbalanced_rounding' : 'tb_unbalanced', ['group' => Phrase::make("statements.groups.{$group}"), 'diff' => self::money($diff)]),
                        ['group' => $group]);
                }
            }
        }

        // 2. Partial trial balance.
        if ($profile->isPartial()) {
            $checks[] = $this->check('tb_partial', 'error', 5, count($profile->families), count($profile->families) - 5,
                self::msg('tb_partial', ['families' => $profile->families ? array_map([self::class, 'family'], $profile->families) : self::msg('tb_partial_none')]));
        }

        // 3. Unclassified, needs-review and unmapped rows (rows left out of the statements don't count).
        $included     = array_filter($rows, fn ($r) => ! isset($mapping['excluded'][$r['row']]));
        $unclassified = array_values(array_filter($included, fn ($r) => $r['sub_class'] === null));
        if ($unclassified) {
            $checks[] = $this->check('unclassified_rows', 'error', 0, count($unclassified), count($unclassified),
                self::msg('unclassified_rows', ['count' => count($unclassified)]), ['rows' => array_column($unclassified, 'row'),
                    'amount' => round(array_sum(array_map(fn ($r) => abs($closing($r)), $unclassified)), 2)]);
        }
        $review = array_values(array_filter($included, fn ($r) => $r['sub_class'] !== null && ($r['needs_review'] ?? false)));
        if ($review) {
            $checks[] = $this->check('rows_need_review', 'warning', 0, count($review), count($review),
                self::msg('rows_need_review', ['count' => count($review)]), ['rows' => array_column($review, 'row')]);
        }
        $unmapped = array_values(array_filter($mapping['unmapped'], fn ($u) => abs($closing($byRow[$u['row']])) > $tolerance));
        if ($unmapped) {
            $amount   = round(array_sum(array_map(fn ($u) => abs($closing($byRow[$u['row']])), $unmapped)), 2);
            $checks[] = $this->check('unmapped_accounts', 'error', 0, count($unmapped), count($unmapped),
                self::msg('unmapped_accounts', ['count' => count($unmapped), 'amount' => self::money($amount)]),
                ['rows' => array_column($unmapped, 'row'), 'amount' => $amount]);
        }

        // 4. The classifier's reconciliation flags, per line.
        $reconciliation = [];
        foreach ($rows as $r) {
            foreach ($r['data_flags'] as $flag) {
                if ($flag['type'] === 'reconciliation' && ($line = $this->lineOf($mapping, $r['row']))) {
                    $reconciliation[$line][] = ['row' => $r['row'], 'difference' => (float) ($flag['difference'] ?? 0)];
                }
            }
        }
        foreach ($reconciliation as $line => $items) {
            $diff     = round(array_sum(array_column($items, 'difference')), 2);
            $checks[] = $this->check('row_reconciliation', 'warning', 0, $diff, $diff,
                self::msg('row_reconciliation'), ['line_key' => $line, 'rows' => array_column($items, 'row')]);
        }

        // 5. Balances on the wrong side of their line (side rules and contra accounts already applied).
        foreach ($aggregated as $line => $a) {
            $wrong = array_values(array_filter($a['wrong_side'], fn ($w) => $w['group'] === 'closing'));
            if ($wrong) {
                $amount   = round(array_sum(array_column($wrong, 'amount')), 2);
                $checks[] = $this->check('wrong_side_balance', 'warning', 0, $amount, $amount,
                    self::msg('wrong_side_balance', ['count' => count($wrong)]), ['line_key' => $line, 'rows' => array_column($wrong, 'row')]);
            }
        }

        // 6. Post-closing trial balance: no P&L to build (Q22), unless the movements give it and retained earnings agree.
        if ($profile->postClosing) {
            $checks[] = $this->check('post_closing_tb', 'error', null, 0, null,
                self::msg('post_closing_tb'));
        }
        if ($profile->plFromMovement) {
            $checks[] = $this->check('pl_from_movements', 'info', $profile->movementProfit, $profile->movementProfit, 0.0,
                self::msg('pl_from_movements_'.($profile->closingEntry === 'closing_gap' ? 'gap' : 'movement').'_'.($profile->movementProfit > 0 ? 'profit' : 'loss'),
                    ['amount' => self::money(abs($profile->movementProfit))]));
        }

        // 7. Assets = equity + liabilities.
        [$assets, $equityLiabilities] = [$figures['sofp.total_assets'], $figures['sofp.total_equity_and_liabilities']];
        if (! $assets->isMissing() && ! $equityLiabilities->isMissing() && abs($diff = round($assets->amount - $equityLiabilities->amount, 2)) > $tolerance) {
            $checks[] = $this->check('sofp_unbalanced', 'error', $equityLiabilities->amount, $assets->amount, $diff,
                self::msg('sofp_unbalanced', ['diff' => self::money($diff)]), ['line_key' => 'sofp.total_assets']);
        }

        // 8. Net profit = the P&L accounts' balances (credit − debit), with the user's entries and zakat charge. A P&L account
        // is one classified in the income statement, or one the user put on income-statement lines (an override).
        $net = $figures['is.net_profit'];
        if (! $net->isMissing()) {
            $weight = function (array $r) use ($mapping): float {
                $m = $mapping['rows'][$r['row']] ?? null;
                if (($m['source'] ?? null) === 'override') {
                    return array_sum(array_filter($m['lines']['closing'], fn ($k) => str_starts_with($k, 'is.'), ARRAY_FILTER_USE_KEY));
                }
                return $r['financial_statement'] === self::PL && ! isset($mapping['excluded'][$r['row']]) ? 1.0 : 0.0;
            };
            $sum = -array_sum(array_map(fn ($r) => $weight($r) * $closing($r), $rows));
            foreach ($entries as $entry) {
                foreach ($entry['lines'] as $l) {
                    $effect = (float) $l['credit'] - (float) $l['debit'];
                    $sum   += $l['line_key'] !== null ? (str_starts_with($l['line_key'], 'is.') ? $effect : 0.0) : $weight($byRow[$l['row']]) * $effect;
                }
            }
            $sum = round($sum - ($zakatCharge ?? 0.0), 2);
            if (abs($diff = round($net->amount - $sum, 2)) > $tolerance) {
                $checks[] = $this->check('net_income_mismatch', 'error', $sum, $net->amount, $diff,
                    self::msg('net_income_mismatch', ['net' => self::money($net->amount), 'sum' => self::money($sum)]), ['line_key' => 'is.net_profit']);
            }
        }

        // 26a. Periodic opening / closing stock labelled as inventory: an error until the user decides.
        foreach ($mapping['proposals'] as $p) {
            $checks[] = $this->check('periodic_inventory_pair', 'error', null, $p['amount'], null, PeriodicInventoryPairs::message($p['kind'], $p['matches'], $byRow),
                ['line_key' => $p['current_line'], 'rows' => [$p['row'], ...$p['matches']]]);
        }

        // 26d. A column group the file has, with nothing in it.
        foreach ($profile->emptyGroups as $group) {
            $checks[] = $this->check('empty_group', 'info', null, 0, null,
                self::msg('empty_group', ['group' => Phrase::make("statements.groups.{$group}")]), ['group' => $group]);
        }

        return $checks;
    }

    /**
     * The user's part: the zakat rule (Q38), required inputs (28), and adjusting entries on rows with no line.
     *
     * @param list<array>                       $fields           ReportIntake::fields()
     * @param list<array{entry: int, row: int}> $unmappedEntries  LineAdjustments::apply()['unmapped']
     */
    public function inputs(array $fields, array $figures, array $unmappedEntries): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $checks    = [];
        foreach ($fields as $f) {
            if (! $f['required'] || $f['value'] !== null) {
                continue;
            }
            if ($f['check'] === 'zakat_charge_missing') {
                // Profit and no charge anywhere: the charge from the zakat return, or zero with a reason.
                $zakat = $figures['is.zakat'];
                if (! $zakat->isMissing() && abs($zakat->amount) <= $tolerance) {
                    $checks[] = $this->check('zakat_charge_missing', 'error', null, 0, null,
                        self::msg('zakat_charge_missing'), ['line_key' => 'is.zakat', 'input_key' => $f['key']]);
                }
                continue;
            }
            // A suggested answer (last year's, or from the accounts) shown and not yet confirmed is not missing information:
            // a warning of its own, «to confirm», that no acknowledgement clears (only saving the answer does), so it still
            // stops a final version. An empty answer with nothing suggested stays an error.
            $suggested = $f['default'] !== null && $f['default'] !== [];
            $label = ['label' => Phrase::text(['ar' => $f['label_ar'], app()->getLocale() => $f['label'] ?? $f['label_ar']])];
            $checks[] = $suggested
                ? $this->check('input_to_confirm', 'warning', null, null, null, self::msg('input_to_confirm', $label), ['input_key' => $f['key'], 'suggested' => true])
                : $this->check('empty_required_input', 'error', null, null, null, self::msg('empty_required_input', $label), ['input_key' => $f['key'], 'suggested' => false]);
        }
        foreach ($unmappedEntries as $u) {
            $checks[] = $this->check('adjustment_unmapped_row', 'error', null, null, null,
                self::msg('adjustment_unmapped_row', ['entry' => $u['entry']]), ['rows' => [$u['row']], 'entry' => $u['entry']]);
        }

        return $checks;
    }

    /**
     * 26. Displayed figures are whole riyals (Q27): a total's rounding difference is put on one line under it (info, the
     * line named), up to the limit; above it nothing is put anywhere and it's an error (WholeRiyals).
     *
     * @param array $settled       WholeRiyals::settle()
     * @param list<array> $statementRows StatementBuilder rows (their labels)
     */
    public function rounding(array $settled, array $statementRows, string $scope, float $limit): array
    {
        $label  = array_column(array_filter($statementRows, fn ($r) => isset($r['key'])), 'label_ar', 'key');
        $checks = [];
        foreach ($settled['plugs'] as $p) {
            $checks[] = $this->check('rounding_difference', 'info', (float) $p['before'], (float) $p['after'], (float) $p['diff'],
                self::msg('rounding_difference', ['diff' => self::money($p['diff']), 'line' => $label[$p['line']], 'total' => $label[$p['total']]]),
                ['line_key' => $p['total'], 'scope' => $scope, 'plugged_into' => $p['line']]);
        }
        foreach ($settled['over'] as $o) {
            $checks[] = $this->check('rounding_over_limit', 'error', (float) $o['before'], (float) $o['after'], (float) $o['diff'],
                self::msg('rounding_over_limit', ['diff' => self::money($o['diff']), 'total' => $label[$o['total']], 'limit' => self::money($limit)]),
                ['line_key' => $o['total'], 'scope' => $scope]);
        }

        return $checks;
    }

    /**
     * The comparative year: its balance sheet balances (7), and, when the trial balance has opening balances and the
     * comparatives come from a prior source, each line's opening = the prior closing (21, Q9). A difference is an error
     * until the user fixes the prior figures, or a warning once the comparatives are marked restated; never adjusted.
     *
     * @param array<string, Figure>  $comparative every line and total of the comparative balance sheet
     * @param ?array<string, Figure> $opening     the trial balance's opening figures per balance-sheet line
     */
    public function comparative(array $comparative, ?array $opening, string $source, bool $restated): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $checks    = [];
        [$assets, $equityLiabilities] = [$comparative['sofp.total_assets'] ?? null, $comparative['sofp.total_equity_and_liabilities'] ?? null];
        if ($assets && $equityLiabilities && ! $assets->isMissing() && ! $equityLiabilities->isMissing()
            && abs($diff = round($assets->amount - $equityLiabilities->amount, 2)) > $tolerance) {
            $checks[] = $this->check('sofp_unbalanced', 'error', $equityLiabilities->amount, $assets->amount, $diff,
                self::msg('sofp_unbalanced_comparative', ['diff' => self::money($diff)]), ['line_key' => 'sofp.total_assets', 'scope' => 'comparative']);
        }
        if ($opening !== null && in_array($source, ['report', 'run', 'manual', 'statements'], true)) {
            foreach ($opening as $line => $open) {
                $prior = $comparative[$line] ?? null;
                if ($prior === null || $prior->isMissing() || $open->isMissing() || abs($diff = round($open->amount - $prior->amount, 2)) <= $tolerance
                    || ($source === 'statements' && OpeningCheck::kind($open->amount, $prior->amount) === 'equal')) {
                    continue;   // the same in whole riyals, as last year's statements print them
                }
                // A riyal either way is probably rounding: still shown, as a warning (never hidden).
                $rounding = abs($diff) <= OpeningCheck::ROUNDING;
                $checks[] = $this->check('opening_vs_prior_closing', $restated || $rounding ? 'warning' : 'error', $prior->amount, $open->amount, $diff,
                    self::msg($restated ? 'opening_vs_prior_closing_restated' : ($rounding ? 'opening_vs_prior_closing_rounding' : 'opening_vs_prior_closing'),
                        ['opening' => self::money($open->amount), 'prior' => self::money($prior->amount)]),
                    ['line_key' => $line, 'scope' => 'comparative']);
            }
        }

        return $checks;
    }

    /**
     * The periods (22): each period is 12 months (a warning: a short or long period is allowed once confirmed), the
     * comparative period ends the day before this one starts, and the statements are approved after the period ends.
     * Only what is given is checked: no comparative period in a first year, no approval date until entered.
     */
    /**
     * Check `prior_file_total`: each total of an uploaded file of last year's statements (PR 17) equals the same total
     * built from the file's lines, in whole riyals. A total missing on either side is left to the missing figures. With the
     * file's expenses shown positive (and turned negative on confirmation) the sizes are compared.
     *
     * @param list<array{key: string, amount: ?float, ref: array}> $totals
     */
    public function priorFileTotals(array $totals, array $statements, bool $expensesPositive): array
    {
        $built = $labels = [];
        foreach (['financial_position', 'comprehensive_income', 'cash_flows'] as $statement) {
            foreach ($statements[$statement] ?? [] as $r) {
                if (isset($r['key'])) {
                    $built[$r['key']] = $r['comparative'] ?? null;
                    $labels[$r['key']] = $r['label_ar'];
                }
            }
        }
        $checks = [];
        foreach ($totals as $t) {
            $f = $built[$t['key']] ?? null;
            if ($t['amount'] === null || ! $f instanceof Figure || $f->isMissing()) {
                continue;
            }
            [$file, $ours] = $expensesPositive && str_starts_with($t['key'], 'is.') ? [abs($t['amount']), abs($f->amount)] : [$t['amount'], $f->amount];
            $diff = round($ours - $file);
            if (abs($diff) >= 1) {
                $checks[] = $this->check('prior_file_total', 'error', $file, $ours, $diff,
                    self::msg('prior_file_total', ['label' => $labels[$t['key']] ?? '', 'file' => self::money($file), 'ours' => self::money($ours),
                        'row' => $t['ref']['row'], 'sheet' => $t['ref']['sheet']]),
                    ['scope' => 'comparative', 'line_key' => $t['key'], 'file_row' => $t['ref']]);
            }
        }

        return $checks;
    }

    public function period(CarbonInterface $start, CarbonInterface $end, ?CarbonInterface $cmpStart, ?CarbonInterface $cmpEnd, ?string $approval): array
    {
        $checks = [];
        foreach (['current' => [$start, $end], 'comparative' => [$cmpStart, $cmpEnd]] as $scope => [$from, $to]) {
            if ($from === null || $to === null) {
                continue;
            }
            if ($from->copy()->addYearNoOverflow()->subDay()->format('Y-m-d') !== $to->format('Y-m-d')) {
                $months   = round($from->diffInMonths($to->copy()->addDay()), 1);
                $checks[] = $this->check('period_invalid', 'warning', 12, $months, round($months - 12, 1),
                    self::msg('period_length', ['period' => self::msg("period_{$scope}"), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]),
                    ['scope' => $scope, 'kind' => 'length']);
            }
        }
        if ($cmpEnd !== null && $cmpEnd->format('Y-m-d') !== $start->copy()->subDay()->format('Y-m-d')) {
            $checks[] = $this->check('period_invalid', 'error', null, null, null,
                self::msg('period_comparative_gap', ['end' => $cmpEnd->format('Y-m-d'), 'expected' => $start->copy()->subDay()->format('Y-m-d')]),
                ['scope' => 'comparative', 'kind' => 'comparative_gap']);
        }
        if ($approval !== null && $approval <= $end->format('Y-m-d')) {
            $checks[] = $this->check('period_invalid', 'error', null, null, null,
                self::msg('period_approval_date', ['approval' => $approval, 'end' => $end->format('Y-m-d')]), ['kind' => 'approval_date']);
        }

        return $checks;
    }

    /**
     * The movement schedules (PR 9):
     * - 15 `asset_rollforward`: opening + additions + transfers − disposals = closing, per category (cost and accumulated
     *   depreciation), and the note's net book value = the statement line; 17 `provision_rollforward`: opening +
     *   increase − decrease − reversal = closing. With opening and closing columns only, the movements are the user's
     *   split of the net change, so a difference is 19 `split_total_mismatch` instead;
     * - 16 `depreciation_mismatch`: the note's charge vs the depreciation expense (a warning);
     * - 18 `disposal_without_proceeds`: disposals and no proceeds entered (an error); with proceeds, proceeds − net book
     *   value disposed vs the gains and losses on disposal in the trial balance (a warning);
     * - `disposal_unmatched`: a gain or loss on disposal with no asset disposed and no proceeds (a warning: the
     *   classification is the accountant's to confirm; never moved by itself); `disposal_pending`: the accounts the user
     *   took out of the disposal results (tag `not_disposal`), other income or expenses until confirmed (a warning);
     * - 19 `split_total_mismatch` also: a loans' current portion above the long-term loans;
     * - 23 `zakat_used_no_charge`: the zakat provision was used and the year has no zakat charge (a warning);
     * - 26b `provision_without_pl_counterpart`: a provision's credits ≠ the debits of its P&L counterpart (a warning);
     * - 26c `reversal_as_payment`: a debit on a provision read as a reversal, not a payment (info);
     * - `zakat_zero_movements`: no zakat for the year, with its reason: the zakat provision's movements, said once (info),
     *   in place of 23 and 26b.
     * Only figures that are there are checked: a missing one is reported as missing, not here.
     *
     * @param array<string, Figure> $figures the statements' figures
     */
    public function schedules(array $schedules, LayoutProfile $profile, array $inputs, array $figures, Figure $disposalResult, ?Figure $disposalPending = null): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $split     = $profile->layout === 'OC';
        $checks    = [];
        $results   = [];
        $diff      = fn (array $plus, array $minus) => array_filter([...$plus, ...$minus], fn ($f) => $f === null || $f->isMissing()) ? null
            : round(array_sum(array_map(fn ($f) => $f->amount, $plus)) - array_sum(array_map(fn ($f) => $f->amount, $minus)), 2);
        $cell = fn (array $s, string $row, string $col) => MovementSchedules::cell($s, $row, $col);

        foreach ($schedules as $s) {
            $title = $s['title_ar'];
            if ($s['kind'] === 'asset') {
                foreach (['cost' => ['cost_opening', ['additions', 'transfers', 'revaluation'], 'disposals', 'cost_closing'],
                    'accumulated' => ['acc_opening', ['depreciation'], 'acc_disposals', 'acc_closing']] as $side => [$open, $ups, $down, $close]) {
                    $columns = $s['split'][$side] && count($s['columns']) > 1 ? array_slice(array_column($s['columns'], 'key'), 0, -1) : ['total'];
                    foreach ($columns as $col) {
                        $in = array_map(fn ($r) => $cell($s, $r, $col), array_filter([$open, ...$ups], fn ($r) => $cell($s, $r, $col) !== null));
                        $d  = $diff($in, [$cell($s, $down, $col), $cell($s, $close, $col)]);
                        if ($d !== null && abs($d) > $tolerance) {
                            $label = collect($s['columns'])->firstWhere('key', $col)['label_ar'];
                            $checks[] = $this->check($split ? 'split_total_mismatch' : 'asset_rollforward', 'error', $cell($s, $close, $col)->amount, round($cell($s, $close, $col)->amount + $d, 2), $d,
                                self::msg('asset_rollforward_side', ['title' => $title, 'column' => $label, 'side' => self::msg("side_{$side}"), 'diff' => self::money($d)]),
                                ['line_key' => $s['line_key'], 'schedule' => $s['key'], 'column' => $col, 'kind' => $side]);
                        }
                    }
                }
                // The user's split of the revaluation surplus across categories = the surplus (19).
                $reval = $s['facts']['revaluation'] ?? null;
                $spread = $cell($s, 'revaluation', 'total');
                if ($reval !== null && $spread !== null && ! $spread->isMissing() && abs($d = round($spread->amount - $reval->amount, 2)) > $tolerance) {
                    $checks[] = $this->check('split_total_mismatch', 'error', $reval->amount, $spread->amount, $d,
                        self::msg('revaluation_split', ['title' => $title, 'diff' => self::money($d)]),
                        ['line_key' => $s['line_key'], 'schedule' => $s['key'], 'kind' => 'revaluation']);
                }
                $nbv = $cell($s, 'nbv_closing', 'total');
                if (! $nbv->isMissing() && abs($d = round($s['facts']['line']->amount - $nbv->amount, 2)) > $tolerance) {
                    $checks[] = $this->check('asset_rollforward', 'error', $s['facts']['line']->amount, $nbv->amount, $d,
                        self::msg($s['facts']['line_postings'] ? 'nbv_vs_line_postings' : 'nbv_vs_line', ['title' => $title, 'diff' => self::money($d)]),
                        ['line_key' => $s['line_key'], 'schedule' => $s['key'], 'kind' => 'statement_line']);
                }
                $charge = $cell($s, 'depreciation', 'total');
                if ($s['facts']['expense'] !== null && ! $charge->isMissing() && abs($d = round($charge->amount - $s['facts']['expense']->amount, 2)) > $tolerance) {
                    $checks[] = $this->check('depreciation_mismatch', 'warning', $s['facts']['expense']->amount, $charge->amount, $d,
                        self::msg($s['key'] === 'intangible_assets' ? 'amortisation_mismatch' : 'depreciation_mismatch', ['title' => $title, 'charge' => self::money($charge->amount), 'expense' => self::money($s['facts']['expense']->amount)]),
                        ['line_key' => $s['line_key'], 'schedule' => $s['key']]);
                }
                $disposals = $cell($s, 'disposals', 'total');
                if (! $disposals->isMissing() && $disposals->amount > $tolerance) {
                    $proceeds = $inputs["{$s['key']}.disposal_proceeds"] ?? null;
                    if ($proceeds === null) {
                        $checks[] = $this->check('disposal_without_proceeds', 'error', null, $disposals->amount, null,
                            self::msg('disposal_without_proceeds', ['title' => $title, 'amount' => self::money($disposals->amount)]),
                            ['line_key' => $s['line_key'], 'schedule' => $s['key'], 'input_key' => "{$s['key']}.disposal_proceeds"]);
                    } elseif (! ($accDisposals = $cell($s, 'acc_disposals', 'total'))->isMissing()) {
                        $results[] = $proceeds - ($disposals->amount - $accDisposals->amount);
                    }
                }
                continue;
            }

            if ($s['kind'] === 'provision') {
                $rows = ['opening', $s['increase'], 'prior_year_adjustments', $s['decrease'], 'reversal', 'closing'];
                [$o, $up, $pya, $down, $rev, $close] = array_map(fn ($r) => $cell($s, $r, 'amount'), $rows);
                $d = $diff(array_filter([$o, $up, $pya]), [$down, $rev, $close]);
                if ($d !== null && abs($d) > $tolerance) {
                    $checks[] = $this->check($split ? 'split_total_mismatch' : 'provision_rollforward', 'error', $close->amount, round($close->amount + $d, 2), $d,
                        self::msg('provision_rollforward', ['title' => $title, 'diff' => self::money($d)]), ['line_key' => $s['line_key'], 'schedule' => $s['key']]);
                }
                $f = $s['facts'];
                if ($s['key'] === 'zakat' && ZakatBase::none($inputs, $figures)) {
                    $credit = $profile->has('period') ? $f['credit'] : ($up->isMissing() ? 0.0 : $up->amount);
                    $debit  = $profile->has('period') ? $f['debit'] : ($down->isMissing() ? 0.0 : $down->amount);
                    $ends   = array_map(fn ($c) => $c->isMissing() ? 0.0 : $c->amount, [$o, $close]);
                    if (max(array_map('abs', [...$ends, $credit, $debit])) > $tolerance) {
                        $checks[] = $this->check('zakat_zero_movements', 'info', null, $ends[1], null,
                            self::msg('zakat_zero_movements', ['opening' => self::money($ends[0]), 'credit' => self::money($credit), 'debit' => self::money($debit), 'closing' => self::money($ends[1])]),
                            ['line_key' => 'sofp.zakat_provision', 'schedule' => 'zakat']);
                    }
                    continue;
                }
                if ($profile->has('period') && $f['counterpart_expected'] && $f['credit'] > $tolerance) {
                    if ($f['counterpart'] === null) {
                        $checks[] = $this->check('provision_without_pl_counterpart', 'warning', null, $f['credit'], null,
                            self::msg('provision_no_counterpart', ['title' => $title, 'amount' => self::money($f['credit'])]),
                            ['line_key' => $s['line_key'], 'schedule' => $s['key']]);
                    } elseif (abs($d = round($f['credit'] - $f['counterpart'], 2)) > $tolerance) {
                        $checks[] = $this->check('provision_without_pl_counterpart', 'warning', $f['counterpart'], $f['credit'], $d,
                            self::msg('provision_counterpart_mismatch', ['title' => $title, 'credit' => self::money($f['credit']), 'counterpart' => self::money($f['counterpart'])]),
                            ['line_key' => $s['line_key'], 'schedule' => $s['key']]);
                    }
                }
                if ($f['matched'] > $tolerance) {
                    $checks[] = $this->check('reversal_as_payment', 'info', null, $f['matched'], null,
                        self::msg('reversal_as_payment', ['title' => $title, 'amount' => self::money($f['matched'])]),
                        ['line_key' => $s['line_key'], 'schedule' => $s['key']]);
                }
                if ($s['key'] === 'zakat' && ($figures['is.zakat'] ?? null)?->amount !== null && abs($figures['is.zakat']->amount) <= $tolerance) {
                    $used = $profile->has('period') ? $f['debit'] : ($down->isMissing() ? null : $down->amount);
                    if ($used !== null && $used > $tolerance) {
                        $checks[] = $this->check('zakat_used_no_charge', 'warning', null, $used, null,
                            self::msg('zakat_used_no_charge', ['amount' => self::money($used)]),
                            ['line_key' => 'sofp.zakat_provision', 'schedule' => 'zakat']);
                    }
                }
                continue;
            }

            if ($s['kind'] === 'loans' && ($nc = $cell($s, 'non_current', 'current')) && ! $nc->isMissing() && $nc->amount < -$tolerance) {
                $checks[] = $this->check('split_total_mismatch', 'error', 0, $nc->amount, $nc->amount,
                    self::msg('loans_current_portion', ['amount' => self::money(-$nc->amount)]),
                    ['line_key' => 'sofp.murabaha_loans_non_current', 'schedule' => 'loans', 'input_key' => 'loans.current_portion']);
            }
        }

        $rowsOf   = fn (Figure $f) => array_column(array_filter($f->refs, fn ($r) => ($r['type'] ?? null) === 'row'), 'row');
        $disposed = array_filter($schedules, fn ($s) => $s['kind'] === 'asset' && ($c = $cell($s, 'disposals', 'total')) !== null && ! $c->isMissing() && abs($c->amount) > $tolerance);
        $proceeds = array_filter($schedules, fn ($s) => $s['kind'] === 'asset' && ($inputs["{$s['key']}.disposal_proceeds"] ?? null) !== null);
        if (! $disposalResult->isMissing() && abs($disposalResult->amount) > $tolerance && $disposed === [] && $proceeds === []) {
            $checks[] = $this->check('disposal_unmatched', 'warning', null, $disposalResult->amount, null,
                self::msg($disposalResult->amount > 0 ? 'disposal_unmatched_gain' : 'disposal_unmatched_loss', ['amount' => self::money(abs($disposalResult->amount))]),
                ['rows' => $rowsOf($disposalResult), 'amount' => $disposalResult->amount]);
        }
        if ($disposalPending !== null && $rowsOf($disposalPending) !== []) {
            $checks[] = $this->check('disposal_pending', 'warning', null, $disposalPending->amount, null,
                self::msg('disposal_pending', ['amount' => self::money(abs($disposalPending->amount))]),
                ['rows' => $rowsOf($disposalPending), 'amount' => $disposalPending->amount]);
        }

        if ($results !== [] && ! $disposalResult->isMissing() && abs($d = round(array_sum($results) - $disposalResult->amount, 2)) > $tolerance) {
            $checks[] = $this->check('disposal_without_proceeds', 'warning', round(array_sum($results), 2), $disposalResult->amount, $d,
                self::msg('disposal_gain_loss', ['recorded' => self::money($disposalResult->amount), 'computed' => self::money(array_sum($results))]),
                ['kind' => 'gain_loss']);
        }

        return $checks;
    }

    /**
     * Changes in equity and cash flows (PR 10):
     * - 9 `soce_closing_mismatch`: a column's closing (opening + the year's movements) ≠ its balance-sheet line;
     *   11 `re_roll` for retained earnings;
     * - 10 `soce_opening_mismatch`: a column's opening (the opening columns) ≠ the prior closing (a prior source); an
     *   error, a warning once the comparatives are restated;
     * - 12 `cash_flow_closing_mismatch`: the cash flow's closing cash ≠ the balance sheet's cash;
     * - 13 `cash_flow_opening_mismatch`: the opening columns' cash ≠ the prior closing cash the cash flow starts from;
     * - 24 `re_cleared_no_dividend`: retained earnings debited, matched by credits on the partners' current accounts, and
     *   no distribution confirmed (a warning: ask whether it is a declared distribution).
     *
     * @param ?array<string, Figure> $opening    the opening columns' balance sheet, when there is one and a prior source
     * @param ?array<string, Figure> $priorClose the prior closing balance sheet from a prior source
     */
    public function equityAndCash(ScheduleContext $ctx, array $equity, array $cf, array $sofp, ?array $opening, ?array $priorClose, bool $restated): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $checks    = [];
        $closing   = collect($equity['rows'])->firstWhere('key', 'closing')['cells'];
        foreach ($equity['columns'] as $col) {
            if ($col['line_key'] === null || $col['key'] === 'total') {
                continue;
            }
            [$computed, $line] = [$closing[$col['key']], $sofp[$col['line_key']]];
            if (! $computed->isMissing() && ! $line->isMissing() && abs($d = round($computed->amount - $line->amount, 2)) > $tolerance) {
                $re = $col['key'] === 'retained_earnings';
                $checks[] = $this->check($re ? 're_roll' : 'soce_closing_mismatch', 'error', $line->amount, $computed->amount, $d,
                    self::msg($re ? 're_roll' : 'soce_closing_mismatch', ['column' => $col['label_ar'], 'diff' => self::money($d)]),
                    ['line_key' => $col['line_key'], 'column' => $col['key']]);
            }
            if ($opening !== null && $priorClose !== null && ! ($o = $opening[$col['line_key']] ?? Figure::missing())->isMissing()
                && ! ($p = $priorClose[$col['line_key']] ?? Figure::missing())->isMissing() && abs($d = round($o->amount - $p->amount, 2)) > $tolerance) {
                $checks[] = $this->check('soce_opening_mismatch', $restated ? 'warning' : 'error', $p->amount, $o->amount, $d,
                    self::msg('soce_opening_mismatch', ['column' => $col['label_ar'], 'diff' => self::money($d)]),
                    ['line_key' => $col['line_key'], 'column' => $col['key']]);
            }
        }

        $closingCash = $cf['figures']['cf.closing_cash'];
        if (! $closingCash->isMissing() && ! $cf['cash']->isMissing() && abs($d = round($closingCash->amount - $cf['cash']->amount, 2)) > $tolerance) {
            $checks[] = $this->check('cash_flow_closing_mismatch', 'error', $cf['cash']->amount, $closingCash->amount, $d,
                self::msg('cash_flow_closing_mismatch', ['diff' => self::money($d)]), ['line_key' => 'cf.closing_cash']);
        }
        if ($opening !== null && $priorClose !== null && ! $opening['sofp.cash']->isMissing() && ! $priorClose['sofp.cash']->isMissing()
            && abs($d = round($opening['sofp.cash']->amount - $priorClose['sofp.cash']->amount, 2)) > $tolerance) {
            $checks[] = $this->check('cash_flow_opening_mismatch', $restated ? 'warning' : 'error', $priorClose['sofp.cash']->amount, $opening['sofp.cash']->amount, $d,
                self::msg('cash_flow_opening_mismatch', ['diff' => self::money($d)]), ['line_key' => 'cf.opening_cash']);
        }

        // 24: retained earnings cleared to the partners' current accounts with no distribution confirmed.
        if ($ctx->movements()) {
            $re       = $ctx->sum($ctx->rowsOn('sofp.retained_earnings'), 'credit');
            $partners = $ctx->sum($ctx->rowsOn('sofp.partners_current_liability') + $ctx->rowsOn('sofp.partners_current_equity'), 'credit');
            $settled  = fn (Figure $f) => $f->status === 'ok' && ! $f->isMissing() && $f->amount > $tolerance;
            if ($re['decrease'] > $tolerance && $partners['increase'] >= $re['decrease'] - $tolerance
                && ! $settled($equity['movements']['dividends']) && ! $settled($equity['movements']['transfer_to_partners_current'])) {
                $checks[] = $this->check('re_cleared_no_dividend', 'warning', null, $re['decrease'], null,
                    self::msg('re_cleared_no_dividend', ['amount' => self::money($re['decrease']), 'partners' => self::money($partners['increase'])]), ['line_key' => 'sofp.retained_earnings', 'rows' => array_column($re['refs'], 'row')]);
            }
        }

        return $checks;
    }

    /**
     * The comparative year's changes in equity and cash flow (a prior report's, or typed from last year's issued
     * statements) against the comparative balance sheet: each equity column's closing = its line (9, 11 for retained
     * earnings), the closing cash = the cash (12, less the overdraft when it counts in cash). Scope `comparative`.
     *
     * @param array<string, Figure> $cmpSofp the comparative balance sheet
     */
    public function comparativeEquityAndCash(array $equity, array $cf, array $cmpSofp, bool $overdraftInCash): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $checks    = [];
        $closing   = $equity['comparative'] ? collect($equity['comparative'])->firstWhere('key', 'closing')['cells'] : [];
        foreach ($equity['columns'] as $col) {
            if ($col['line_key'] === null || $col['key'] === 'total' || ! isset($closing[$col['key']])) {
                continue;
            }
            [$computed, $line] = [$closing[$col['key']], $cmpSofp[$col['line_key']] ?? Figure::missing()];
            if (! $computed->isMissing() && ! $line->isMissing() && abs($d = round($computed->amount - $line->amount, 2)) > $tolerance) {
                $re = $col['key'] === 'retained_earnings';
                $checks[] = $this->check($re ? 're_roll' : 'soce_closing_mismatch', 'error', $line->amount, $computed->amount, $d,
                    self::msg('soce_closing_mismatch_comparative', ['column' => $col['label_ar'], 'diff' => self::money($d)]),
                    ['line_key' => $col['line_key'], 'column' => $col['key'], 'scope' => 'comparative']);
            }
        }
        $closingCash = $cf['comparative']['cf.closing_cash'] ?? null;
        $cash = Figure::sum([$cmpSofp['sofp.cash'], $overdraftInCash ? AssetNotesBuilder::neg($cmpSofp['sofp.bank_overdraft']) : new Figure(0.0, 'derived')]);
        if ($closingCash !== null && ! $closingCash->isMissing() && ! $cash->isMissing() && abs($d = round($closingCash->amount - $cash->amount, 2)) > $tolerance) {
            $checks[] = $this->check('cash_flow_closing_mismatch', 'error', $cash->amount, $closingCash->amount, $d,
                self::msg('cash_flow_closing_mismatch_comparative', ['diff' => self::money($d)]),
                ['line_key' => 'cf.closing_cash', 'scope' => 'comparative']);
        }

        return $checks;
    }

    /** The line a row's closing balance sits on (its largest share). */
    private function lineOf(array $mapping, int $row): ?string
    {
        $lines = $mapping['rows'][$row]['lines']['closing'] ?? [];
        arsort($lines);

        return array_key_first($lines);
    }

    /**
     * The run was corrected after the report last took it in (plan §5): a warning; acknowledging it takes the run as it
     * is now (PR 12).
     */
    public function runChanged(?string $stored, ?string $current): array
    {
        return $stored === $current ? [] : [$this->check('run_changed', 'warning', null, null, null,
            self::msg('run_changed'), ['run_result_hash' => $current])];
    }

    /**
     * A check's identity (PR 12): its code and every field but the wording, amounts included, so an acknowledgement
     * stops matching once the amounts change.
     */
    public static function key(array $check): string
    {
        return $check['code'].':'.substr(sha1(json_encode(array_diff_key($check, self::WORDING))), 0, 16);
    }

    /** The wording of a check, left out of its key: the same check has the same key in every language. */
    private const WORDING = ['title_ar' => 1, 'message_ar' => 1, 'title' => 1, 'message' => 1, 'key' => 1, 'acknowledged' => 1];

    /**
     * A check: its name and message in Arabic (`title_ar`, `message_ar`) and in the request's language (`title`, `message`).
     * Shared with NotesBuilder.
     */
    public static function make(string $code, string $severity, mixed $expected, mixed $actual, mixed $difference, Phrase $message, array $extra = []): array
    {
        $locale = app()->getLocale();

        return ['code' => $code, 'title_ar' => __("statements.checks.{$code}", [], 'ar'), 'severity' => $severity, 'scope' => $extra['scope'] ?? 'current',
            'line_key' => $extra['line_key'] ?? null, 'rows' => $extra['rows'] ?? [],
            'expected' => $expected, 'actual' => $actual, 'difference' => $difference, 'message_ar' => $message->in('ar')]
            + array_diff_key($extra, ['line_key' => 1, 'rows' => 1, 'scope' => 1])
            + ['title' => __("statements.checks.{$code}", [], $locale), 'message' => $message->in($locale)];
    }

    private function check(string $code, string $severity, mixed $expected, mixed $actual, mixed $difference, Phrase $message, array $extra = []): array
    {
        return self::make($code, $severity, $expected, $actual, $difference, $message, $extra);
    }

    /** A check message (lang/{ar,en}/report_messages.php `checks`). */
    public static function msg(string $key, array $params = []): Phrase
    {
        return Phrase::make("report_messages.checks.{$key}", $params);
    }

    /** A statement family (أصول، …) in any language. */
    private static function family(string $family): Phrase|string
    {
        $key = ['أصول' => 'assets', 'التزامات' => 'liabilities', 'حقوق الملكية' => 'equity', 'إيرادات' => 'revenue', 'مصروفات' => 'expenses'][$family] ?? null;

        return $key === null ? $family : Phrase::make("report_messages.families.{$key}");
    }

    public static function money(float $v): string
    {
        return number_format($v, 2);
    }
}
