<?php

namespace App\Services\Statements;

use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;

/**
 * The statement of financial position and statement of comprehensive income of a run, with their checks and the report's
 * own part (plan §6, PRs 6–8): map (with the user's overrides) → aggregate → the user's adjusting entries and zakat
 * charge → income statement → balance sheet (retained earnings take the year's result) → movement schedules (notes'
 * roll-forwards, PR 9) → changes in equity and cash flows (PR 10) → intake → checks.
 *
 * Comparatives (Q7, Q41, §5), by the report's prior-year source:
 * - `first_year`: none, one column;
 * - `none` (no source yet): the balance sheet from the trial balance's opening columns when it has them, the income
 *   statement missing;
 * - `report` / `run` / `manual`: the figures PriorYearResolver gives, with check 21 against the opening columns.
 */
final class CurrentYearStatements
{
    public function __construct(
        private ReportSource $source,
        private TaxonomyRepositoryInterface $taxonomy,
        private LineAdjustments $adjustments,
        private IncomeStatementBuilder $income,
        private BalanceSheetBuilder $balance,
        private ReportIntake $intake,
        private ReportChecks $checks,
        private MovementSchedules $schedules,
        private EquityStatementBuilder $equity,
        private CashFlowBuilder $cashFlows,
        private NoteBreakdowns $breakdowns,
        private NoteComparatives $noteComparatives,
    ) {
    }

    public const SOURCES = ['none', 'first_year', 'report', 'run', 'manual', 'statements'];

    /**
     * @param array{overrides?: array, categories?: array, tags?: array, inputs?: array, adjustments?: list<array>, decisions?: array, prior?: array{source: string, figures?: ?array, restated?: bool}} $report
     *        the report's own data (overrides in LineMapper form: row => line | [line => share]; categories: row => category;
     *        tags: row => [tag]; decisions on the movement suggestions; prior figures: every line key → Figure)
     * @return array{profile: LayoutProfile, mapping: array, statements: array, checks: list<array>, missing: list<array>, intake: list<array>, required_inputs: list<string>, schedules: list<array>, suggestions: list<array>, cells: array,
     *               breakdowns: array, schedules_comparative: ?array, context: ScheduleContext}
     */
    public function build(ClassificationRun $run, string $templateVersion, array $report = []): array
    {
        if (array_key_exists('approved_opening', $report)) {
            return $this->statements($run, $templateVersion, $report);
        }
        // This year's openings from last year's approved statements (Step B, a setting on by default): built on the trial
        // balance as it is, then again with each line's difference from last year's approved closing as rows of its own.
        $prior = $report['prior'] ?? ['source' => 'none'];
        $raw = $this->statements($run, $templateVersion, ['approved_opening' => null] + $report);
        if ($raw['comparative']['source'] !== 'statements') {
            return $raw + ['approved_opening' => null];
        }
        if (! ApprovedOpening::wanted($prior)) {
            return $raw + ['approved_opening' => ApprovedOpening::summary($raw, $raw, ApprovedOpening::OFF, false)];
        }
        $plan = ApprovedOpening::plan($raw, ApprovedOpening::moves($raw, $prior));
        $built = $plan['status'] === 'applied' ? $this->statements($run, $templateVersion, ['approved_opening' => $plan] + $report) : $raw;
        // The PPE categories' openings against last year's closing stay the trial balance's: every difference shown.
        foreach ($built['ppe_prior']['categories'] ?? [] as $i => $c) {
            $built['ppe_prior']['categories'][$i]['opening_check'] = $raw['ppe_prior']['categories'][$i]['opening_check'] ?? [];
        }
        $built['approved_opening'] = $summary = ApprovedOpening::summary($raw, $built, $plan, true);
        // Check 21 on a line whose opening is the approved closing but for the accounts on another line last year: explained
        // by them (the reconciliation lists them), not a difference.
        $moved = array_column($summary['lines'], 'moved_total', 'line');
        $built['checks'] = array_values(array_filter($built['checks'], fn ($c) => $c['code'] !== 'opening_vs_prior_closing' || abs($moved[$c['line_key'] ?? ''] ?? 0.0) < 0.005
            || abs((float) $c['actual'] - ((float) $c['expected'] - $moved[$c['line_key']])) >= 0.01));
        // The changes in equity opening off last year's closing by the sub-riyal differences retained earnings took for the
        // lines tied to an outside source (`rounding_kept`): explained, not a mismatch.
        // The same for the cash flow's opening cash, which keeps the bank's own amount.
        $kept = array_column($summary['lines'], 'rounding_kept', 'line');
        [$keptRe, $keptCash] = [$kept['sofp.retained_earnings'] ?? 0.0, $kept['sofp.cash'] ?? 0.0];
        $built['checks'] = array_values(array_filter($built['checks'], fn ($c) => match ($c['code']) {
            'soce_opening_mismatch'      => ($c['column'] ?? null) !== 'retained_earnings' || abs($keptRe) < 0.005 || abs((float) $c['difference'] + $keptRe) >= 0.01,
            'cash_flow_opening_mismatch' => abs($keptCash) < 0.005 || abs((float) $c['difference'] + $keptCash) >= 0.01,
            default                      => true,
        }));
        $built['checks'] = [...$built['checks'], ...ApprovedOpening::checks($summary)];
        // D1 sets each line's accounts against the line: the approved openings' part of the line is shown apart, so what
        // is left is the report's entries and computed figures, not a difference to look for.
        foreach ($summary['applied'] ? $summary['lines'] : [] as $l) {
            if ($l['presented'] !== null && $l['tb_closing'] !== null && abs($d = round($l['presented'] - $l['tb_closing'], 2)) >= 0.005) {
                $built['line_totals'][$l['line']] = ['approved_opening' => $d + 0.0] + ($built['line_totals'][$l['line']] ?? ['amount' => 0.0, 'accounts' => 0]);
            }
        }

        return $built;
    }

    /** The statements of a run, with the rows of the approved openings when given (`approved_opening`: ApprovedOpening::plan()). */
    private function statements(ClassificationRun $run, string $templateVersion, array $report): array
    {
        $inputs  = $report['inputs'] ?? [];
        $entries = $report['adjustments'] ?? [];
        // Where the partners' current account is shown: the user's answer, else where last year's balance sheet showed it.
        $priorPartners   = self::priorPartnersCurrent($report['prior']['figures'] ?? null);
        $partnersCurrent = in_array($inputs['partners_current'] ?? null, ['liability', 'equity'], true) ? $inputs['partners_current'] : $priorPartners;
        ['catalogue' => $catalogue, 'profile' => $profile, 'mapping' => $mapping, 'rows' => $rows] = $this->source->read($run, $templateVersion,
            ['overrides' => $report['overrides'] ?? [], 'excluded' => $report['excluded'] ?? [], 'tags' => $report['tags'] ?? [], 'partners_current' => $partnersCurrent]);
        // The approved openings' rows (Step B) count everywhere but where the trial balance itself is read: its checks,
        // the accounts step's totals (D1), whether it has opening balances, and its openings against last year's.
        $plan = $report['approved_opening'] ?? null;
        [$tbRows, $tbMapping] = [$rows, $mapping];
        if ($plan !== null) {
            $rows = [...$rows, ...$plan['rows']];
            $mapping['rows'] = $mapping['rows'] + $plan['mapping'];
            $report['categories'] = $plan['categories'] + ($report['categories'] ?? []);
            $report['notes']['ppe'] = $plan['ppe'] + ($report['notes']['ppe'] ?? []);
            foreach ($plan['items'] as $note => $choices) {
                $report['notes']['items'][$note] = $choices + ($report['notes']['items'][$note] ?? []);
            }
        }
        $aggregated = (new LineAggregator($catalogue, $this->taxonomy))->aggregate($rows, $mapping, $profile);
        $tbAggregated = $plan === null ? $aggregated : (new LineAggregator($catalogue, $this->taxonomy))->aggregate($tbRows, $tbMapping, $profile);

        $tbZakat     = $aggregated['is.zakat']['closing']?->amount ?? 0.0;
        $zakatCharge = $profile->postClosing ? null : ($inputs['zakat.charge'] ?? null);
        $portion     = is_int($inputs['loans.current_portion'] ?? null) || is_float($inputs['loans.current_portion'] ?? null) ? (float) $inputs['loans.current_portion'] : null;
        // The charges the zakat and income tax dialog took into the statements: only where nothing else charges them (the
        // trial balance, an entry, or a charge typed in the intake), so they are never charged twice.
        $applied = $profile->postClosing ? [] : (array) ($report['zakat_tax']['applied'] ?? []);
        $taxCharge = null;
        if ($applied !== []) {
            $tolerance = (float) config('classification.balance_tolerance', 0.01);
            $entriesCharge = $this->adjustments->apply($aggregated, $mapping, $catalogue, $entries, null, null)['zakat'];
            if ($zakatCharge === null && isset($applied['zakat']) && abs($tbZakat) <= $tolerance && abs($entriesCharge) <= $tolerance) {
                $zakatCharge = (float) $applied['zakat'];
                $inputs['zakat.charge'] = $zakatCharge;
            }
            if (isset($applied['income_tax']) && abs($aggregated['is.income_tax']['closing']?->amount ?? 0.0) <= $tolerance) {
                $taxCharge = (float) $applied['income_tax'];
            }
        }
        // The amount the accountant adopted for each (the zakat step): the calculated one, their own, or what the books carry. The
        // statements carry it: the difference from what the trial balance and the entries book is posted (Dr expense, Cr provision).
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $entriesZakat = $this->adjustments->apply($aggregated, $mapping, $catalogue, $entries, null, null)['zakat'];
        $tbTax = $aggregated['is.income_tax']['closing']?->amount ?? 0.0;
        $booked = ['zakat' => round($tbZakat + $entriesZakat, 2), 'income_tax' => round($tbTax, 2)];
        $adopt = $profile->postClosing ? [] : (array) ($report['zakat_tax']['adopt'] ?? []);
        $manual = (array) ($report['zakat_tax']['manual'] ?? []);
        foreach (['zakat', 'income_tax'] as $charge) {
            $choice = $adopt[$charge] ?? null;
            $target = match ($choice) {
                'calculated' => isset($applied[$charge]) ? (float) $applied[$charge] : null,
                'manual' => isset($manual[$charge]) ? (float) $manual[$charge] : null,
                'booked' => $booked[$charge],
                default => false,
            };
            if ($target === false) {
                continue;   // not chosen: as before (charged where nothing else charges it)
            }
            $delta = $target === null ? null : round($target - $booked[$charge], 2);
            $delta = $delta !== null && abs($delta) > $tolerance ? $delta : null;
            if ($charge === 'zakat') {
                $zakatCharge = $delta;
                if ($delta === null) {
                    unset($inputs['zakat.charge']);
                } else {
                    $inputs['zakat.charge'] = $delta;
                }
            } else {
                $taxCharge = $delta;
            }
        }
        $adjusted    = $this->adjustments->apply($aggregated, $mapping, $catalogue, $entries, $zakatCharge, $portion, $taxCharge);

        $prior    = $report['prior'] ?? ['source' => 'none'];
        // Last year's statements are asked for only when the trial balance has opening balances (R8): `present`, `absent`
        // or `unknown` (no signal either way: asked), and the signal that decided it.
        [$openingBalances, $openingSignal] = $this->openingBalances($tbRows, $tbMapping, $profile, $tbAggregated, $catalogue);
        // Opening balances → a comparative year: a «first year» choice is not applied, unless the user chose it knowing
        // (`skip_opening`, R8's override). No opening balances and no source chosen → a first year, nothing asked; the user
        // can still give a prior year. A prior year's own build keeps its first year (fixed).
        $byOpening = null;
        if (! ($prior['fixed'] ?? false) && $prior['source'] === 'first_year' && $openingBalances === 'present' && ! ($prior['skip_opening'] ?? false)) {
            [$prior, $byOpening] = [['source' => 'none'], 'opening'];
        } elseif (! ($prior['fixed'] ?? false) && $prior['source'] === 'none' && $openingBalances === 'absent') {
            [$prior, $byOpening] = [['source' => 'first_year'], 'no_opening'];
        }
        $source   = $prior['source'];
        $restated = (bool) ($prior['restated'] ?? false);
        $opening  = $this->openingFigures($catalogue, $aggregated, $profile);
        $tbOpening = $plan === null ? $opening : $this->openingFigures($catalogue, $tbAggregated, $profile);
        $keys     = fn (string $statement) => array_column(array_filter($catalogue->statement($statement), fn ($i) => $i['type'] === 'line'), 'key');
        $pick     = fn (?array $figures, string $statement) => array_combine($keys($statement), array_map(fn ($k) => $figures[$k] ?? Figure::missing(), $keys($statement)));
        [$cmpSofp, $cmpIs] = match ($source) {
            'first_year' => [null, null],
            'none'       => [$opening ?? $pick(null, 'financial_position'), $pick(null, 'comprehensive_income')],
            default      => [$pick($prior['figures'] ?? null, 'financial_position'), $pick($prior['figures'] ?? null, 'comprehensive_income')],
        };

        // Other comprehensive income is derived, not asked, when the books show none (the user's answer still wins).
        $ociDerived = false;
        if (! array_key_exists('oci.has_items', $inputs) && OciDerivation::hasNone($adjusted['aggregated'], $cmpIs, $profile) === false) {
            [$inputs['oci.has_items'], $ociDerived] = [false, true];
        }
        $is      = $this->income->build($catalogue, $adjusted['aggregated'], $profile, $inputs, $cmpIs, $ociDerived);
        $sofp    = $this->balance->build($catalogue, $adjusted['aggregated'], $is['figures']['is.net_profit'], $profile, $cmpSofp);
        $figures = $sofp['figures'] + $is['figures'];
        $byRow   = array_column($rows, null, 'row');
        $makeContext = fn (array $categories, array $priorPpe = []) => new ScheduleContext($byRow, $profile, $mapping, $catalogue, new LineAggregator($catalogue, $this->taxonomy), $adjusted['postings'],
            $categories, $report['tags'] ?? [], $cmpSofp, $adjusted['aggregated'], $is['figures']['is.oci_revaluation'], $priorPpe);
        $context = $makeContext($report['categories'] ?? []);
        // With last year's PPE note from a PDF: an account of no category of its own (accumulated depreciation, mostly) takes
        // the note's category of last year's category it is on (Q8 without asking); the user's choice still wins.
        if ($source === 'statements' && isset($prior['file']['document'])
            && ($guess = PriorPpe::build($context, $prior['file'], $prior['file_rows'] ?? [], $report['notes']['ppe'] ?? [])) !== null) {
            $owns = array_column($guess['categories'], 'own', 'key');
            $found = [];
            foreach ($guess['accounts'] as $a) {
                $own = $a['category'] === null ? null : ($owns[$a['category']] ?? null);
                if ($own !== null && ! isset($report['categories'][$a['row']]) && $catalogue->category('ppe', $byRow[$a['row']]['sub_class'] ?? null) === null) {
                    $found[$a['row']] = $own;
                }
            }
            // Last year's closing per category of the note: this year's opening accumulated depreciation is read from it (the
            // accountant's rule: the opening from last year's statements, the year's charge from the trial balance).
            $priorPpe = [];
            foreach ($guess['comparative'] as $cell => $amount) {
                if (preg_match('/^ppe\.(acc_closing|cost_closing)\.(.+)$/', $cell, $m)) {
                    $priorPpe[$m[2]][$m[1]] = $amount;
                }
            }
            if ($found !== [] || $priorPpe !== []) {
                $context = $makeContext(($report['categories'] ?? []) + $found, $priorPpe);
            }
        }
        $suggester = new MovementSuggester($report['decisions'] ?? []);
        $notes   = $this->schedules->build($context, $suggester);
        // A disposal result the user tagged `not_disposal` (its classification pending) stays on its line, out of the cash
        // flow's adjustment: other income or expenses, never a disposal looking for proceeds.
        $results = $context->rowsOf($catalogue->disposalResults());
        $pending = $context->sum(array_intersect_key($results, $context->tagged('not_disposal')), 'credit');
        $result  = $context->sum(array_diff_key($results, $context->tagged('not_disposal')), 'credit');
        $disposalResult  = new Figure($result['closing'], 'trial_balance', 'ok', $result['refs']);
        $disposalPending = new Figure($pending['closing'], 'trial_balance', 'ok', $pending['refs']);

        // Changes in equity and cash flows: from the balance sheet at the start of the year. The changes in equity start
        // from the opening columns (check 10 compares them with the prior closing); the cash flow from the comparative
        // balance sheet it reconciles with. A first year starts from nothing, unless the trial balance says otherwise.
        $partnersInEquity = $partnersCurrent === 'equity';
        $start   = $source === 'first_year' ? ($opening ?? array_map(fn () => new Figure(0.0, 'derived'), $sofp['figures'])) : null;
        $base    = $start ?? $cmpSofp;
        // A line that kept its own amount under the approved openings (a sub-riyal difference left on retained earnings,
        // ApprovedOpening): the cash flow moves it from its actual opening, so the cash flow closes on the bank's cash.
        foreach ($base !== null && $opening !== null ? array_keys($plan['kept'] ?? []) : [] as $line) {
            $base[$line] = $opening[$line] ?? $base[$line];
        }
        $equity  = $this->equity->build($context, $suggester, $sofp['figures'], $is['figures'], $start ?? $opening ?? $cmpSofp, $partnersInEquity,
            is_string($inputs['soce.retained_movement'] ?? null) ? $inputs['soce.retained_movement'] : null);
        $retained = $equity['retained'];
        $unexplained = $retained['nature'] === null && ! $retained['amount']->isMissing() && abs($retained['amount']->amount) >= 0.005;
        $cfLines = array_column(array_filter($catalogue->statement('cash_flows'), fn ($i) => $i['type'] === 'line'), 'key');
        // Comparative cash flow: the prior report's, or typed from last year's issued statements (a line not given is 0).
        $typedCf = $prior['cash_flows_entered'] ?? null;
        $cmpCf   = $source === 'first_year' ? null : array_combine($cfLines, array_map(fn ($k) => $prior['cash_flows'][$k] ?? ($typedCf === null ? Figure::missing()
            : (isset($typedCf[$k]) ? new Figure((float) $typedCf[$k], 'prior_year_manual', 'ok', [['type' => 'input', 'key' => "prior.{$k}"]]) : new Figure(0.0, 'prior_year_manual'))), $cfLines));
        $cf      = $this->cashFlows->build($context, $suggester, $sofp['figures'], $base, $is['figures'], $notes['schedules'], $equity['movements'],
            $disposalResult, $inputs, $partnersInEquity, $cmpCf, $unexplained);
        $equity['comparative'] = match (true) {
            $source === 'first_year'                         => null,
            isset($prior['changes_in_equity'])               => $prior['changes_in_equity'],
            isset($prior['changes_in_equity_entered'])       => $this->equity->entered($catalogue, $equity['columns'], $prior['changes_in_equity_entered']),
            default                                          => array_map(fn ($r) => ['cells' => array_map(fn () => Figure::missing(), $r['cells'])] + $r, $equity['rows']),
        };

        $overdraft = abs($sofp['figures']['sofp.bank_overdraft']->amount ?? 0.0) > 0.005 || abs(($base['sofp.bank_overdraft'] ?? null)?->amount ?? 0.0) > 0.005;
        $fields  = $this->intake->fields($profile, $mapping, $tbZakat, $adjusted['zakat'], $is['figures']['is.profit_before_zakat']->amount, $inputs, $catalogue,
            $notes['schedules'], ($aggregated['sofp.murabaha_loans_non_current']['closing'] ?? null)?->amount ?? 0.0, $overdraft,
            $retained['amount']->isMissing() ? 0.0 : $retained['amount']->amount, $priorPartners);

        // Whole riyals (Q27): each total's rounding difference on one line under it, the user's choice or the largest.
        $limit   = (float) config('financial_statements.rounding_limit', 5);
        $targets = [];
        foreach ($inputs as $k => $v) {
            if (str_starts_with($k, 'rounding.') && is_string($v)) {
                $targets[substr($k, strlen('rounding.'))] = $v;
            }
        }
        $settled = [];
        foreach (['financial_position' => $sofp['rows'], 'comprehensive_income' => $is['rows']] as $statement => $statementRows) {
            foreach ($source === 'first_year' ? ['current'] : ['current', 'comparative'] as $scope) {
                $settled[$statement][$scope] = WholeRiyals::settle($statementRows, $scope, $targets, $limit);
            }
        }
        // The changes in equity in the same whole riyals: they end (and start) at what the balance sheet shows.
        $bsShown = fn (string $scope) => $settled['financial_position'][$scope]['shown'] ?? [];
        $profitShown = fn (string $scope, ?Figure $f) => $f === null || $f->isMissing() || ($settled['comprehensive_income'][$scope]['shown']['is.net_profit'] ?? null) === null
            ? null : ['amount' => $f->amount, 'shown' => $settled['comprehensive_income'][$scope]['shown']['is.net_profit']];
        $equity['rows'] = EquityStatementBuilder::shown($equity['rows'], $equity['columns'], $bsShown('current'), $source === 'first_year' ? [] : $bsShown('comparative'),
            $profitShown('current', $is['figures']['is.net_profit'] ?? null));
        if (is_array($equity['comparative'])) {
            $equity['comparative'] = EquityStatementBuilder::shown($equity['comparative'], $equity['columns'], $bsShown('comparative'), [],
                $profitShown('comparative', $cmpIs['is.net_profit'] ?? null));
        }
        $fields = [...$fields, ...$this->intake->roundingFields(array_merge(...array_values(array_map('array_values', $settled))), [...$sofp['rows'], ...$is['rows']], $inputs)];
        $shown = fn (array $items, string $statement) => array_map(fn ($i) => in_array($i['type'], ['line', 'total'], true)
            ? $i + ['shown' => array_map(fn ($s) => $s['shown'][$i['key']] ?? null, $settled[$statement])] : $i, $items);

        $checks = [
            ...($ociDerived ? [ReportChecks::make('oci_derived', 'info', null, null, null, ReportChecks::msg('oci_derived'), ['line_key' => 'is.oci_total'])] : []),
            ...$this->checks->run($tbRows, $profile, $tbMapping, $tbAggregated, $figures, $entries, $zakatCharge),
            ...$this->checks->inputs($fields, $figures, $adjusted['unmapped']),
            ...$this->checks->rounding($settled['financial_position']['current'], $sofp['rows'], 'current', $limit),
            ...$this->checks->rounding($settled['comprehensive_income']['current'], $is['rows'], 'current', $limit),
            ...$this->checks->schedules($notes['schedules'], $profile, $inputs, $figures, $disposalResult, $disposalPending),
            ...$this->checks->equityAndCash($context, $equity, $cf, $sofp['figures'], $source !== 'first_year' && $profile->has('opening') ? $opening : null,
                in_array($source, ['report', 'run', 'manual', 'statements'], true) ? $cmpSofp : null, $restated),
            ...($cmpSofp !== null ? $this->checks->comparativeEquityAndCash($equity, $cf, $cmpSofp, ($inputs['cash_flow.overdraft_in_cash'] ?? false) === true) : []),
        ];
        // The retained earnings' movement nobody named yet (P3: not a dividend by guess), with its accounts.
        if ($unexplained) {
            $checks[] = ReportChecks::make('retained_unexplained', 'warning', null, $retained['amount']->amount, null,
                ReportChecks::msg('retained_unexplained', ['amount' => ReportChecks::money(abs($retained['amount']->amount))]),
                ['line_key' => 'sofp.retained_earnings', 'rows' => $retained['rows'], 'amount' => $retained['amount']->amount, 'input_key' => 'soce.retained_movement']);
        }
        // Named a prior-year adjustment: kept as a current-year line, but Section 10 would restate the comparatives, which
        // come from the approved statements; flagged until the accountant decides (no restatement built).
        if ($retained['nature'] === 'prior_year_adjustment' && ! $retained['amount']->isMissing() && abs($retained['amount']->amount) >= 0.005) {
            $checks[] = ReportChecks::make('prior_year_adjustment_pending', 'warning', null, $retained['amount']->amount, null,
                ReportChecks::msg('prior_year_adjustment_pending', ['amount' => ReportChecks::money(abs($retained['amount']->amount))]),
                ['line_key' => 'sofp.retained_earnings', 'rows' => $retained['rows'], 'amount' => $retained['amount']->amount, 'input_key' => 'soce.retained_movement']);
        }
        if ($byOpening !== null) {
            $checks[] = ReportChecks::make('comparative_by_opening', 'info', null, null, null, ReportChecks::msg("comparative_by_opening_{$byOpening}"));
        }
        if ($source !== 'first_year') {
            $checks = [
                ...$checks,
                ...$this->checks->comparative($sofp['comparative'], $opening, $source, $restated),
                ...$this->checks->rounding($settled['financial_position']['comparative'], $sofp['rows'], 'comparative', $limit),
                ...$this->checks->rounding($settled['comprehensive_income']['comparative'], $is['rows'], 'comparative', $limit),
            ];
        }

        // Each line lists the codes of the checks about it.
        $codes = [];
        foreach ($checks as $c) {
            if ($c['line_key'] !== null) {
                $codes[$c['line_key']][$c['code']] = true;
            }
        }
        $tag = fn (array $items) => array_map(fn ($i) => $i['type'] === 'line' ? ['check_codes' => array_keys($codes[$i['key']] ?? [])] + $i : $i, $items);

        $missing = match (true) {
            $profile->postClosing                       => [['key' => 'comprehensive_income', 'reason' => 'post_closing_tb', 'input_key' => null]],
            $figures['is.oci_total']->isMissing()       => [['key' => 'is.oci_total', 'reason' => 'input_required', 'input_key' => 'oci.has_items']],
            default                                     => [],
        };
        // A comparative that can't be shown: no source yet, or a source that doesn't give it (never 0).
        if ($source !== 'first_year') {
            $reason = $source === 'none' ? 'prior_year_source_required' : 'prior_year_incomplete';
            foreach (['financial_position' => [$sofp['comparative'], 'sofp.total_equity_and_liabilities'], 'comprehensive_income' => [$is['comparative'], 'is.total_comprehensive_income']] as $statement => [$cmp, $total]) {
                if ($cmp[$total]->isMissing()) {
                    $missing[] = ['key' => "comparative.{$statement}", 'reason' => $reason, 'input_key' => null];
                }
            }
        }
        if ($source !== 'first_year') {
            if (! isset($prior['changes_in_equity']) && ! isset($prior['changes_in_equity_entered'])) {
                $missing[] = ['key' => 'comparative.changes_in_equity', 'reason' => $source === 'none' ? 'prior_year_source_required' : 'prior_year_incomplete', 'input_key' => null];
            }
            if (! isset($prior['cash_flows']) && $typedCf === null) {
                $missing[] = ['key' => 'comparative.cash_flows', 'reason' => $source === 'none' ? 'prior_year_source_required' : 'prior_year_incomplete', 'input_key' => null];
            }
            // The year's changes in equity and cash flow need the balance sheet at its start (Q41).
            if (collect($equity['rows'])->firstWhere('key', 'opening')['cells']['total']->isMissing()) {
                $missing[] = ['key' => 'changes_in_equity', 'reason' => 'prior_year_source_required', 'input_key' => null];
            }
            if ($base['sofp.cash']->isMissing()) {
                $missing[] = ['key' => 'cash_flows', 'reason' => 'prior_year_source_required', 'input_key' => null];
            }
        }
        $missing = [...$missing, ...$notes['missing'], ...$suggester->missing()];

        // D1: each line's accounts as the trial balance has them, before the report's entries, signed as the statements show
        // them, so the accounts step can show each line's accounts adding up to it.
        $lineTotals = [];
        foreach (['financial_position', 'comprehensive_income'] as $statement) {
            foreach ($catalogue->statement($statement) as $i) {
                $amount = $i['type'] === 'line' && ! $i['input'] ? ($tbAggregated[$i['key']]['closing'] ?? null)?->amount : null;
                if ($amount !== null) {
                    $signed = $statement === 'comprehensive_income' && $catalogue->side($i['key']) === 'debit' ? -$amount : $amount;
                    $lineTotals[$i['key']] = ['amount' => round($signed, 2) + 0.0, 'accounts' => count($tbAggregated[$i['key']]['rows'])];
                }
            }
        }

        // The notes' tables (PR 11): items by class and the movement tables' prior year.
        $breakdowns = $this->noteComparatives->breakdowns($this->breakdowns->build($context), $source, $prior);
        // With last year's statements from a PDF: each note's rows are last year's note lines, as printed (R11, R12, D3).
        $noteItems = [];
        if ($source === 'statements' && isset($prior['file']['document'])) {
            $accounts = array_map(fn ($b) => $this->breakdowns->accounts($context, $b['lines']), $breakdowns);
            [$breakdowns, $noteItems] = PriorNoteItems::apply($breakdowns, $accounts, $prior['file'], $prior['file_rows'] ?? [], $report['notes']['items'] ?? []);
            $breakdowns = PriorNoteItems::fromLines($breakdowns, ($cmpSofp ?? []) + ($cmpIs ?? []), $catalogue);
        }
        // With last year's statements from a PDF: the PPE note by last year's categories (R13); its movement is the note's
        // comparative where nothing is typed (P2).
        $ppePrior = $source === 'statements' && isset($prior['file']['document'])
            ? PriorPpe::build($context, $prior['file'], $prior['file_rows'] ?? [], $report['notes']['ppe'] ?? []) : null;
        if ($ppePrior !== null && $ppePrior['comparative'] !== []) {
            $prior['schedules_entered'] = ($prior['schedules_entered'] ?? []) + $ppePrior['comparative'];
        }
        // The provisions' movement last year (end of service, zakat, …) as last year's notes print it, where nothing is typed.
        if ($source === 'statements' && isset($prior['file']['document'])) {
            $provisions = PriorPdf\Provisions::comparative($prior['file'], $prior['file_rows'] ?? [], $catalogue->schedules());
            if ($provisions !== []) {
                $prior['schedules_entered'] = ($prior['schedules_entered'] ?? []) + $provisions;
            }
        }
        $cmpSchedules = $this->noteComparatives->schedules($notes['schedules'], $source, $prior, $cmpSofp ?? []);

        return [
            'profile'         => $profile,
            'mapping'         => $tbMapping,
            'line_totals'     => $lineTotals,
            // What the books charge for the year's zakat and income tax (the trial balance and the entries), before what the accountant adopts.
            'zakat_booked'    => $booked,
            'statements'      => ['financial_position' => $tag($shown($sofp['rows'], 'financial_position')), 'comprehensive_income' => $tag($shown($is['rows'], 'comprehensive_income')),
                'changes_in_equity' => ['columns' => $equity['columns'], 'current' => $equity['rows'], 'comparative' => $equity['comparative']],
                'cash_flows' => $tag($cf['rows'])],
            'checks'          => $checks,
            'missing'         => $missing,
            'intake'          => $fields,
            'required_inputs' => $this->intake->requiredMissing($fields),
            'comparative'     => ['source' => $source, 'restated' => $restated, 'opening_balances' => $openingBalances, 'opening_signal' => $openingSignal,
                // Whether last year's statements are needed (R8): with opening balances, or when it can't be told.
                'prior_needed' => $openingBalances !== 'absent', 'automatic' => $byOpening === 'no_opening'],
            'schedules'       => $notes['schedules'],
            'suggestions'     => $suggester->suggestions(),
            'cells'           => $suggester->cells(),
            'breakdowns'      => $breakdowns,
            'note_items'      => $noteItems,
            'ppe_prior'       => $ppePrior,
            'schedules_comparative' => $cmpSchedules,
            'context'         => $context,
            // The balance sheet at the start of the year from the opening columns (null without them): last year's
            // closing, line by line, must equal it (OpeningCheck). The trial balance's, before any approved opening.
            'opening'         => $tbOpening,
        ];
    }

    /**
     * Whether the trial balance shows balances from before the year (R8), and the signal: `opening_column` (an opening
     * column: any balance, or all zero → absent); `implied_opening` (no opening column but movements: closing − movements
     * of a balance-sheet account); then, closing balances only, `retained_earnings` (≠ 0 before closing; after closing it
     * holds this year's result too) or `accumulated_depreciation` (accumulated depreciation and amortisation above this
     * year's charge: a first year's is its own charge). Nothing of these: unknown.
     *
     * @return array{0: string, 1: ?string}
     */
    private function openingBalances(array $rows, array $mapping, LayoutProfile $profile, array $aggregated, LineCatalogue $catalogue): array
    {
        $mapped = array_intersect_key(array_column($rows, null, 'row'), $mapping['rows']);
        if ($profile->has('opening')) {
            return [array_filter($mapped, fn ($r) => abs((float) ($profile->opening($r) ?? 0.0)) > 0.005) !== [] ? 'present' : 'absent', 'opening_column'];
        }
        $onSofp = fn (int $n) => array_filter(array_keys($mapping['rows'][$n]['lines']['closing'] ?? []), fn ($l) => str_starts_with($l, 'sofp.')) !== [];
        if ($profile->has('period') && $profile->has('closing')) {
            $implied = array_filter($mapped, fn ($r) => $onSofp($r['row'])
                && abs((float) $profile->closing($r) - (float) $profile->net($r, 'period') - (float) ($profile->net($r, 'adjustment') ?? 0.0)) > 0.005);

            return [$implied !== [] ? 'present' : 'absent', 'implied_opening'];
        }
        if (! $profile->postClosing && abs($aggregated['sofp.retained_earnings']['closing']?->amount ?? 0.0) > 0.005) {
            return ['present', 'retained_earnings'];
        }
        $accumulated = 0.0;
        $aggregator = new LineAggregator($catalogue, $this->taxonomy);
        foreach ($mapped as $r) {
            $lines = array_keys($mapping['rows'][$r['row']]['lines']['closing'] ?? []);
            if (array_intersect($lines, ['sofp.ppe', 'sofp.intangible_assets', 'sofp.investment_property', 'sofp.other_assets'])
                && ($aggregator->isContra($r['sub_class'] ?? null) || in_array($r['sub_class'] ?? null, $catalogue->otherAssetsAmortisation(), true))) {
                $accumulated -= (float) ($profile->closing($r) ?? 0.0);   // a credit balance
            }
        }
        // This year's depreciation and amortisation expense (a debit line: positive on its side).
        $charge = (($aggregated['is.depreciation']['closing'] ?? null)?->amount ?? 0.0) + (($aggregated['is.amortisation']['closing'] ?? null)?->amount ?? 0.0);
        if ($accumulated > abs($charge) + 0.005) {
            return ['present', 'accumulated_depreciation'];
        }

        return ['unknown', null];
    }

    /**
     * The balance sheet at the start of the year from the trial balance's opening columns, or null without them. P&L
     * accounts still open at the start (the prior year not closed) belong in opening retained earnings.
     *
     * @return ?array<string, Figure>
     */
    private function openingFigures(LineCatalogue $catalogue, array $aggregated, LayoutProfile $profile): ?array
    {
        if (! $profile->has('opening')) {
            return null;
        }
        $figures = [];
        foreach ($catalogue->statement('financial_position') as $item) {
            if ($item['type'] === 'line') {
                $figures[$item['key']] = $aggregated[$item['key']]['opening'] ?? new Figure(0.0, 'trial_balance_opening');
            }
        }
        $open = [];
        foreach ($catalogue->statement('comprehensive_income') as $item) {
            $f = $item['type'] === 'line' && ! $item['input'] ? ($aggregated[$item['key']]['opening'] ?? null) : null;
            if ($f !== null && abs($f->amount) > 0.005) {
                $open[] = $catalogue->side($item['key']) === 'credit' ? $f : new Figure(-$f->amount, $f->origin, $f->status, $f->refs);
            }
        }
        if ($open) {
            $re = $figures['sofp.retained_earnings'];
            $figures['sofp.retained_earnings'] = Figure::sum([$re, ...$open], [...$re->refs, ...array_merge(...array_map(fn ($f) => $f->refs, $open))]);
        }

        return $figures;
    }

    /**
     * `liability` or `equity` by where last year's comparative balance sheet carried the partners' current account (the line
     * with a balance); null when it carried none, or the comparatives are missing.
     *
     * @param ?array<string, Figure> $figures
     */
    private static function priorPartnersCurrent(?array $figures): ?string
    {
        foreach (['equity' => 'sofp.partners_current_equity', 'liability' => 'sofp.partners_current_liability'] as $where => $line) {
            $f = $figures[$line] ?? null;
            if ($f instanceof Figure && ! $f->isMissing() && abs($f->amount) >= 0.005) {
                return $where;
            }
        }

        return null;
    }
}
