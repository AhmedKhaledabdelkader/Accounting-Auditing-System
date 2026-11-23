<?php

namespace App\Services\Statements;

use App\Models\Company;
use App\Models\FinancialReport;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Support\ArabicNumberWords;
use App\Support\ImportNormalizer;
use Carbon\CarbonInterface;

/**
 * The notes of a report (PR 11, plan §3.6, §4.2 step 10, Q13, Q14, Q6): which notes and policies show, their numbers,
 * the statements' note references, every placeholder of the template (text and amounts), and checks 14, 20, 25, 26e, 27.
 *
 * - A note with a block shows when one of its lines is ≠ 0 in either year (a missing current figure shows it; a missing
 *   comparative doesn't, as only a draft can have one), or for its own reason (restated, going concern, …); the user may force it on or off. Notes without a block
 *   always show. Shown notes are numbered 1…N in template order; the statements' «إيضاح» column and `${note_no_*}` read
 *   the same numbers.
 * - Policies tied to a line follow it the same way (Q13); the other conditional wording follows the user's answers.
 * - Going concern (Q14): four indicators from the statements; when any is hit the paragraph is proposed with the
 *   amounts, and the user includes it (optionally rewording the mitigation) or excludes it with a reason.
 * - Early application (Q6, E1): the paragraph shows when the period starts before the edition's effective date.
 * Amounts come from the statements, the schedules, the note tables and the user's inputs; nothing is computed by AI.
 */
final class NotesBuilder
{
    /** «حصة» as a count gives it (ArabicNumberWords::counted). */
    private const SHARE_NOUN = ['gender' => 'f', 'singular' => 'حصة', 'dual' => ['حصتان', 'حصتين'], 'plural' => 'حصص'];

    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /** Template rows of the asset tables → schedule rows; per table the placeholder prefix and the depreciation words. */
    private const ASSET_TABLES = [
        'ppe' => ['schedule' => 'ppe', 'acc' => 'acc_dep', 'charge' => 'depreciation'],
        'intangibles' => ['schedule' => 'intangible_assets', 'acc' => 'acc_amort', 'charge' => 'amortisation'],
        'investment_property' => ['schedule' => 'investment_property', 'acc' => 'acc_dep', 'charge' => 'depreciation'],
    ];

    /** The PPE tables' rows: template row → the schedule's row, and the rows of last year's categories (PriorPpe). */
    private const PPE_ROWS = ['cost_opening' => 'cost_opening', 'additions' => 'additions', 'transfers' => 'transfers', 'revaluation' => 'revaluation',
        'disposals' => 'disposals', 'cost_closing' => 'cost_closing', 'acc_dep_opening' => 'acc_opening', 'depreciation' => 'depreciation',
        'acc_dep_disposals' => 'acc_disposals', 'acc_dep_closing' => 'acc_closing', 'nbv_closing' => 'nbv_closing', 'nbv_comparative' => 'nbv_opening'];

    /** Provision tables: placeholder prefix → schedule, and template row → schedule row. */
    private const PROVISION_TABLES = [
        'eosb' => ['employee_benefits', ['opening' => 'opening', 'charge' => 'charge', 'paid' => 'paid', 'reversal' => 'reversal', 'closing' => 'closing']],
        'zakat' => ['zakat', ['opening' => 'opening', 'charge' => 'charge', 'prior_year_adjustments' => 'prior_year_adjustments', 'paid' => 'paid', 'reversal' => 'reversal', 'closing' => 'closing']],
        'provisions' => ['other_provisions', ['opening' => 'opening', 'charge' => 'charge', 'used' => 'used', 'reversal' => 'reversal', 'closing' => 'closing']],
    ];

    /** Useful-life placeholders → [schedule, category]. */
    /** The template's useful-life rows, used without last year's PPE categories: key → [schedule, category]. */
    private const USEFUL_LIVES = [
        'buildings' => ['ppe', 'buildings'], 'machinery' => ['ppe', 'machinery'], 'furniture' => ['ppe', 'furniture'],
        'computers' => ['ppe', 'computers'], 'vehicles' => ['ppe', 'vehicles'],
    ];

    /** The intangibles' lives, in the policy's text. */
    private const INTANGIBLE_LIVES = [
        'software' => ['intangible_assets', 'software'], 'licences' => ['intangible_assets', 'licences'], 'other_intangibles' => ['intangible_assets', 'other'],
    ];

    public const GOING_CONCERN = ['net_loss', 'accumulated_losses', 'current_liabilities', 'total_liabilities'];

    public function __construct(private NarrativeRenderer $renderer, private ReportIntake $intake, private TaxonomyRepositoryInterface $taxonomy)
    {
    }

    /**
     * @param array $built   CurrentYearStatements::build() of the report (with its prior year)
     * @param array $inputs  the report's inputs (or those being validated)
     * @param array $options the user's choices on the notes: {visibility: {note or block: on|off}, texts: {paragraph: {text, base}}}
     * @return array{notes: list<array>, references: array<string, int>, narrative: array, checks: list<array>, missing: list<array>, intake: list<array>}
     */
    public function build(FinancialReport $report, array $built, array $inputs, array $options): array
    {
        $catalogue = new LineCatalogue($report->template_version);
        $template  = NotesTemplate::load($report->template_version);
        $config    = $catalogue->notes();
        $keys      = array_keys($config);
        if (count($keys) !== count($template['notes'])) {
            throw new \LogicException('The notes config and the notes template differ in number of notes.');
        }
        $forced  = $options['visibility'] ?? [];
        $source  = $built['comparative']['source'];
        $firstYear = $source === 'first_year';
        $ctx     = $built['context'];
        $tol     = 0.005;

        // Every line and total of both statements, both years.
        $cur = $cmp = [];
        foreach (['financial_position', 'comprehensive_income'] as $st) {
            foreach ($built['statements'][$st] as $r) {
                if (isset($r['current'])) {
                    $cur[$r['key']] = $r['current'];
                    $cmp[$r['key']] = $r['comparative'] ?? null;
                }
            }
        }
        // A figure ≠ 0 shows its note; a missing current figure too (not known to be 0). A missing comparative doesn't: a
        // report with one is a draft only (Q20), and the final version has every comparative.
        $nz  = fn (?Figure $f) => $f !== null && ($f->isMissing() || abs($f->amount) > $tol);
        $kz  = fn (?Figure $f) => $f !== null && ! $f->isMissing() && abs($f->amount) > $tol;
        $any = fn (array $lines) => array_filter($lines, fn ($l) => $nz($cur[$l] ?? null) || (! $firstYear && $kz($cmp[$l] ?? null))) !== [];
        $schedules = array_column($built['schedules'], null, 'key');
        $cmpCells  = $built['schedules_comparative'] ?? [];
        $categoryNz = function (string $schedule, string $category) use ($schedules, $kz) {
            $s = $schedules[$schedule] ?? null;
            if ($s === null) {
                return false;
            }
            $rows = array_column($s['rows'], null, 'key');
            return $kz($rows['cost_closing']['cells'][$category] ?? null) || $kz($rows['cost_opening']['cells'][$category] ?? null);
        };
        $lineOrCategory = fn (array $refs) => array_filter($refs, fn ($r) => str_contains($r, ':') ? $categoryNz(...explode(':', $r, 2)) : $any([$r])) !== [];

        // The prior year's answers: a prior report's, else what last year's statements confirmed from a PDF say.
        $priorInputs = $report->priorReport?->inputs ?? PriorPdf\PdfDefaults::inputs($report);
        // Useful lives: one per category of last year's PPE note, each its own (100 / its rate, Phase 6); without last
        // year's categories, the template's.
        $lifeRows = $this->usefulLives($built['ppe_prior']['categories'] ?? [], $categoryNz, $schedules);
        if ($report->priorReport === null) {
            foreach ($lifeRows as $r) {
                if ($r['years'] !== null) {
                    $priorInputs["useful_life.{$r['key']}"] ??= $r['years'];
                }
            }
        }
        $sameEdition = $source === 'report' && $report->priorReport?->template_version === $report->template_version;
        $in = fn (string $k) => $inputs[$k] ?? null;

        // The zakat base as last year's zakat note computed it (with last year's statements confirmed from a PDF).
        $priorFile = ($report->prior_figures['source'] ?? null) === 'statements' ? ($report->prior_figures['file'] ?? null) : null;
        // This year's zakat calculation (the accountants' sheet, ZakatCalculator) gives the base's additions, deductions and net base.
        try {
            $zakatCalc = ZakatCalculator::of($built['statements'], (array) ($report->zakat_tax['inputs'] ?? []),
                ['start' => $report->period_start->format('Y-m-d'), 'end' => $report->period_end->format('Y-m-d')]);
        } catch (\Throwable) {
            $zakatCalc = null;
        }
        $zakatBase = ZakatBase::of($priorFile, $cur, $built['schedules'], $inputs, $cmp, $catalogue, $zakatCalc);

        // The partners (R14): last year's capital note's, shown till the accountant saves the table (P2).
        $pdfPartners = $priorFile === null ? null : PriorPdf\Partners::read($priorFile, $report->prior_figures['rows'] ?? []);
        // Without last year's, the articles of association's (notes 10–12), when they give each partner's shares and share value.
        $articles = array_values(array_filter((array) ($report->company?->profile['articles']['partners'] ?? []),
            fn ($p) => is_array($p) && ($p['shares'] ?? null) !== null && ($p['share_value'] ?? null) !== null));
        if (! isset($priorInputs['partners']) && $articles !== [] && $report->priorReport === null) {
            $priorInputs['partners'] = array_map(fn ($p) => ['name' => $p['name'], 'shares' => $p['shares'], 'share_value' => $p['share_value']], $articles);
        }
        $shown = $inputs + ($report->priorReport === null && isset($priorInputs['partners']) ? ['partners' => $priorInputs['partners']] : []);

        // Going concern (Q14): this year's indicators.
        $indicators = $this->goingConcern($cur);
        $gcDecision = $in('going_concern.decision');

        // Related parties: the user's list, or one proposal per related-party account (Q40).
        // With the approved openings (Step B): last year's balances from the approved statements, never the openings.
        $approved = (bool) ($built['approved_opening']['applied'] ?? false);
        $rpLines = ['due_from' => ['sofp.due_from_related_parties'], 'due_to' => ['sofp.due_to_related_parties', 'sofp.due_to_related_parties_non_current']];
        $rpRows  = [];
        foreach ($ctx->mapping['rows'] as $n => $m) {
            $on = array_intersect(array_keys([...$m['lines']['closing'], ...($m['lines']['opening'] ?? [])]), [...$rpLines['due_from'], ...$rpLines['due_to']]);
            $balance = abs($ctx->profile->closing($ctx->rows[$n]) ?? 0.0) > $tol || abs($ctx->profile->opening($ctx->rows[$n]) ?? 0.0) > $tol;
            if ($on && $balance && ! ApprovedOpening::isRow($n)) {
                $rpRows[$n] = true;
            }
        }
        // Last year's note's parties with their accounts, else one party per account (R15).
        $pdfParties = $priorFile === null ? null : PriorPdf\RelatedParties::read($priorFile, $report->prior_figures['rows'] ?? []);
        $priorLines = [];
        foreach ($rpLines as $table => $lines) {
            $amounts = array_filter(array_map(fn ($l) => isset($cmp[$l]) && ! $cmp[$l]->isMissing() ? $cmp[$l]->amount : null, $lines), fn ($v) => $v !== null);
            $priorLines[$table] = $amounts === [] ? null : array_sum($amounts);
        }
        ['parties' => $proposals, 'unmatched' => $unmatchedParties, 'cleaned' => $cleanedParties, 'line_on_one' => $lineOnOne] = PriorPdf\RelatedParties::proposals($pdfParties, $rpRows, $ctx, $rpLines, $firstYear ? [] : $priorLines, $approved);
        $parties = is_array($in('related_parties')) ? $in('related_parties') : $proposals;
        $volumes = array_filter($parties, fn ($p) => abs((float) ($p['volume_cy'] ?? 0)) > $tol || abs((float) ($p['volume_py'] ?? 0)) > $tol) !== [];

        // Visibility and numbers. A note last year's file printed for one of its lines stays, «-» or not (R11).
        $printed = fn (array $lines) => $lines !== [] && isset($priorFile['document'])
            && PriorNoteItems::section($priorFile['document'], $priorFile, $report->prior_figures['rows'] ?? [], $lines) !== null;
        $meta = [];
        $number = 0;
        foreach ($config as $key => $cfg) {
            if (! isset($cfg['block'])) {
                $meta[$key] = ['visible' => true, 'visibility' => 'always'];
            } else {
                $auto = $any($cfg['lines'] ?? []) || $printed($cfg['lines'] ?? []) || match ($cfg['show'] ?? null) {
                    'restated'           => (bool) $built['comparative']['restated'],
                    'first_application'  => ! $sameEdition && $in('first_application.this_period') === true,
                    'going_concern'      => $indicators !== [] && $gcDecision !== 'exclude',
                    'related_parties'    => $volumes,
                    'significant_events' => $in('events.significant') !== null,
                    default              => false,
                };
                $f = $forced[$key] ?? null;
                $meta[$key] = ['visible' => $f === null ? $auto : $f === 'on', 'visibility' => $f === null ? 'auto' : ($f === 'on' ? 'forced_on' : 'forced_off'), 'auto' => $auto];
            }
            $meta[$key]['number']     = $meta[$key]['visible'] ? ++$number : null;
            $meta[$key]['references'] = $cfg['lines'] ?? [];
        }
        // The accountant's order of the notes (`order`, the freedom to arrange them): the shown notes numbered 1…N in it; a note
        // it doesn't name keeps its place after the one before it in the template. The data stays in the template's order.
        $order = self::order($keys, (array) ($options['order'] ?? []));
        if ($order !== $keys) {
            $number = 0;
            foreach ($order as $key) {
                $meta[$key]['number'] = $meta[$key]['visible'] ? ++$number : null;
            }
        }
        $references = [];
        foreach ($config as $key => $cfg) {
            foreach ($cfg['lines'] ?? [] as $l) {
                if ($meta[$key]['visible']) {
                    $references[$l] = $meta[$key]['number'];
                }
            }
        }

        // Blocks.
        $policy = function (string $block, bool $auto) use ($forced) {
            $f = $forced[$block] ?? null;
            return $f === null ? $auto : $f === 'on';
        };
        $fx = array_filter($ctx->rowsOf($catalogue->foreignCurrency()), fn ($s, $n) => abs($ctx->profile->closing($ctx->rows[$n]) ?? 0.0) > $tol
            || abs($ctx->profile->opening($ctx->rows[$n]) ?? 0.0) > $tol, ARRAY_FILTER_USE_BOTH) !== [];
        $fxPolicy = $policy('policy_foreign_currency', $in('policies.foreign_currency') ?? $fx);
        $loans    = $any(['sofp.murabaha_loans_non_current', 'sofp.murabaha_loans_current']);
        $leases   = $any(['sofp.lease_liabilities_non_current', 'sofp.lease_liabilities_current']);
        // The revenue streams: the user's answer, else last year's policy, else what the company's activity and its revenue accounts say (note 14).
        $revenueNames   = array_map(fn ($n) => (string) ($ctx->rows[$n]['account_name'] ?? ''), array_keys($ctx->rowsOn('is.revenue')));
        $revenueDefault = array_values((array) ($priorInputs['policies.revenue'] ?? null) ?: RevenueStreams::infer($report->company?->activity, $revenueNames));
        $revenue        = (array) ($in('policies.revenue') ?? $revenueDefault);
        $blocks = [];
        foreach ($config as $key => $cfg) {
            if (isset($cfg['block'])) {
                $blocks[$cfg['block']] = $meta[$key]['visible'];
            }
        }
        foreach ($catalogue->policies() as $block => $refs) {
            $blocks[$block] = $policy($block, $lineOrCategory($refs));
        }
        $effect = $in('first_application.effect');
        $blocks += [
            'early_application'           => $report->period_start->format('Y-m-d') < $catalogue->editionEffective(),
            // The reference to the first-application note only while that note shows (a later year of early application).
            'early_application_reference' => $meta['first_application']['visible'],
            // The approval date: with the Hijri date when given, else the Gregorian date alone.
            'approval_hijri'              => $in('approval.date_hijri') !== null,
            'approval_gregorian'          => $in('approval.date_hijri') === null,
            'first_application_no_effect' => $effect === 'none',
            'first_application_effect'    => $effect === 'effect',
            'first_application_reliefs'   => $in('first_application.reliefs') !== null,
            'policies_consistent'         => ! $firstYear,
            'policies_first_period'       => $firstYear,
            'inventory_general'           => ($in('policies.inventory') ?? 'general') === 'general',
            'inventory_manufacturing'     => ($in('policies.inventory') ?? 'general') === 'manufacturing',
            'policy_subsidiaries'         => $policy('policy_subsidiaries', $in('policies.subsidiaries') === true),
            'policy_foreign_currency'     => $fxPolicy,
            'policy_leases_lessor'        => $policy('policy_leases_lessor', $in('policies.lessor') === true),
            'eosb_actuarial'              => ($in('policies.employee_benefits') ?? 'labour_law') === 'actuarial',
            'eosb_labour_law'             => ($in('policies.employee_benefits') ?? 'labour_law') === 'labour_law',
            'cf_overdraft_in_cash'        => $in('cash_flow.overdraft_in_cash') === true,
            'cf_murabaha_finance_cost'    => $loans,
            'risk_no_interest_bearing'    => ! $loans && ! $leases,
            'risk_no_foreign_currency'    => ! $fxPolicy,
            'supplier_finance'            => $in('supplier_finance.has') === true,
            'contract_balances'           => $any(['sofp.contract_assets', 'sofp.contract_liabilities']),
            'cost_periodic_inventory'     => ($built['breakdowns']['cost_of_revenue']['build_up'] ?? null) !== null,
            'zakat_zero'                  => ZakatBase::none($inputs, $cur),
            'subsequent_events_none'      => $in('events.subsequent') === null,
            'subsequent_events'           => $in('events.subsequent') !== null,
        ];
        foreach (ReportIntake::REVENUE_STREAMS as $stream) {
            $blocks["revenue_{$stream}"] = in_array($stream, $revenue, true);
        }
        $partyBalances = $this->partyBalances($parties, $ctx, $rpLines, $firstYear, $approved);
        $blocks['related_parties_due_from'] = $any($rpLines['due_from']) || array_filter($partyBalances, fn ($p) => $p['table'] === 'due_from') !== [];
        $blocks['related_parties_due_to']   = $any($rpLines['due_to']) || array_filter($partyBalances, fn ($p) => $p['table'] === 'due_to') !== [];

        // Values: text, then amounts.
        [$values, $items, $sources, $columns, $ppeOwn] = $this->values($report, $shown, $built, $cur, $cmp, $meta, $indicators, $partyBalances, $schedules, $cmpCells, $firstYear, $categoryNz, $lifeRows);

        // The zakat base as last year's note computed it (ZakatBase): its lines and totals, this year's and last year's.
        $items['zakat_base_label'] = array_map(fn ($r) => ['zakat_base_label' => $r['label'], 'zakat_base_cy' => $r['current'],
            'zakat_base_py' => $firstYear ? new Figure(0.0, 'derived') : new Figure((float) $r['prior'], 'prior_year_statements')], $zakatBase['rows'] ?? []);

        $rendered = $this->renderer->render($template, $keys, $meta, $blocks, $values, $items, $options['texts'] ?? [], $firstYear);
        // The template's incorporation sentence names a limited liability company: a sole proprietorship says so.
        if (($values['legal_form'] ?? null) !== null && $values['legal_form'] !== Company::LEGAL_FORMS['llc']) {
            foreach ($rendered['notes'] as $i => $n) {
                if ($n['key'] !== 'incorporation') {
                    continue;
                }
                foreach ($n['blocks'] as $j => $b) {
                    foreach (['text_ar', 'template_ar'] as $field) {
                        if (isset($b[$field]) && is_string($b[$field])) {
                            $rendered['notes'][$i]['blocks'][$j][$field] = str_replace(Company::LEGAL_FORMS['llc'], $values['legal_form'], $b[$field]);
                        }
                    }
                }
            }
        }

        // Intake: the notes' own questions, driven by what shows.
        $fields = $this->intake->noteFields([
            'first_year' => $firstYear, 'same_edition' => $sameEdition, 'indicators' => $indicators !== [],
            'policy_inventory' => $blocks['policy_inventory'], 'revenue' => $any(['is.revenue']), 'revenue_default' => $revenueDefault, 'policy_ppe' => $blocks['policy_ppe'],
            'policy_intangibles' => $blocks['policy_intangibles'],
            'useful_lives' => [...array_column($lifeRows, 'printed', 'key'), ...array_fill_keys(array_keys(array_filter(self::INTANGIBLE_LIVES, fn ($sc) => $categoryNz(...$sc))), null)],
            'related_parties' => $meta['related_parties']['visible'], 'proposals' => $proposals,
            'other_provisions' => $meta['other_provisions']['visible'], 'trade_payables' => $meta['trade_payables']['visible'],
            'supplier_finance' => $this->supplierFinance($ctx),
            'fx' => $fx,
            'zakat_base' => array_values(array_filter($zakatBase['rows'] ?? [], fn ($r) => in_array($r['from'], ['input', 'derived', 'calculation'], true))),
        ], $inputs, $priorInputs);

        // The kinds of revenue worked out from the activity and the accounts, not from last year or the user: said, never silent (P3).
        $suggestedStreams = $in('policies.revenue') === null && ! isset($priorInputs['policies.revenue']) ? $revenueDefault : [];

        $checks = [
            ...($suggestedStreams === [] ? [] : [$this->check('revenue_streams_suggested', 'info', null, null, null,
                ReportChecks::msg('revenue_streams_suggested', ['streams' => implode('، ', array_map(fn ($k) => (string) __("statements.choices.policies.revenue.{$k}", [], 'ar'), $suggestedStreams))]),
                ['note' => 'revenue', 'input_key' => 'policies.revenue'])]),
            ...$this->totalChecks($built, $cur, $cmp, $meta, $partyBalances, $rpLines, $firstYear),
            ...$this->capitalCheck($shown, $cur),
            ...$this->partnersChecks($pdfPartners, $shown, $inputs, $ctx),
            // Last year's related parties no account of this year's trial balance is found for (R15): their figures this
            // year are «-» till the accountant gives them their accounts.
            // A party named from an account's name cleaned: never silently, until the accountant saves the list.
            ...(is_array($in('related_parties')) ? [] : array_map(fn ($c) => $this->check('related_party_name_cleaned', 'warning', null, null, null,
                ReportChecks::msg($c['role'] === null ? 'related_party_name_cleaned' : 'related_party_name_cleaned_role', ['account' => trim($c['account']), 'name' => $c['name'], 'role' => (string) $c['role']]),
                ['note' => 'related_parties', 'rows' => [$c['row']], 'name' => $c['name'], 'role' => $c['role'], 'input_key' => 'related_parties']), $cleanedParties)),
            ...(is_array($in('related_parties')) ? [] : array_map(fn ($l) => $this->check('related_party_line_on_one', 'warning', $l['line'], $l['opening'], round($l['line'] - $l['opening'], 2),
                ReportChecks::msg('related_party_line_on_one', ['name' => $l['name'], 'line' => number_format($l['line']), 'opening' => number_format($l['opening'], 2),
                    'difference' => number_format($l['line'] - $l['opening'], 2)]),
                ['note' => 'related_parties', 'rows' => [$l['row']], 'input_key' => 'related_parties']), $lineOnOne)),
            ...$this->relatedElsewhere($pdfParties, $rpRows, $ctx, $rpLines),
            ...($pdfParties !== null && $unmatchedParties !== [] && ! is_array($in('related_parties'))
                ? [$this->check('related_parties_unmatched', 'warning', count($pdfParties['parties']), count($pdfParties['parties']) - count($unmatchedParties), count($unmatchedParties),
                    ReportChecks::msg('related_parties_unmatched', ['page' => $pdfParties['page'], 'names' => implode('، ', $unmatchedParties)]), ['note' => 'related_parties', 'names' => $unmatchedParties])]
                : []),
            ...$this->goingConcernChecks($indicators, $gcDecision),
            // A zakat base line proposed from last year's components: said, with them, to check.
            ...array_map(fn ($r) => $this->check('zakat_base_derived', 'warning', null, $r['current']->amount, null,
                ReportChecks::msg('zakat_base_derived', ['line' => $r['label'], 'components' => implode(' + ', $r['components'])]), ['note' => 'zakat']),
                array_values(array_filter($zakatBase['rows'] ?? [], fn ($r) => $r['from'] === 'derived'))),
            ...$this->samePartyCheck($ctx, $parties, $inputs),
            // Last year's PPE categories whose figures don't add up to the note's own: the tables by the note's own (P3).
            ...($ppeOwn !== null && $meta['ppe']['visible'] ? [$this->check('ppe_columns_own', 'warning', null, null, null,
                ReportChecks::msg('ppe_columns_own', ['rows' => implode('، ', array_map(fn ($r) => __("statements.ppe_rows.{$r}", [], 'ar'), $ppeOwn['rows']))]), ['note' => 'ppe'])] : []),
            ...$this->additionsDepreciation($built['ppe_prior']['categories'] ?? []),
            ...$this->livesNotDerived($lifeRows, $built['ppe_prior']['categories'] ?? [], $inputs),
            ...$this->livesEstimated($lifeRows, $built['ppe_prior']['categories'] ?? [], $inputs),
            ...$this->companyIdentity($report, $priorFile),
            ...$this->referenceChecks($config, $meta, $cur, $cmp, $rendered['note_refs'], $firstYear, $kz),
            ...$this->outdatedChecks($rendered['notes']),
        ];

        // A shown note with a figure or a wording still missing; the company's profile when it is what's missing. A number
        // of a hidden note is check 27's; the prior year's figures are reported with the comparatives when those are missing.
        $missing = [];
        $cmpMissing = array_filter($built['missing'], fn ($m) => str_starts_with($m['key'], 'comparative.')) !== [];
        foreach ($rendered['missing'] as $note => $placeholders) {
            $placeholders = array_filter($placeholders, fn ($p) => ! str_starts_with($p, 'note_no_'));
            $prior = array_filter($placeholders, fn ($p) => preg_match('/(_py$|_py_|^py)/', $p));
            $placeholders = array_diff($placeholders, $prior);
            if ($prior && ! $cmpMissing) {
                $missing["{$note}.comparative"] = ['key' => "notes.{$note}", 'reason' => $source === 'none' ? 'prior_year_source_required' : 'prior_year_incomplete', 'input_key' => null];
            }
            $company = array_intersect($placeholders, array_keys(array_filter($sources, fn ($s) => $s === 'company')));
            if ($company) {
                // Which of the company's details the notes still need (its profile's fields), never filled by a guess.
                $companyFields = array_values(array_unique(array_merge($missing['company']['fields'] ?? [], array_map(fn ($p) => $p === 'company_name' ? 'name_ar' : $p, $company))));
                $missing['company'] = ['key' => 'notes.company', 'reason' => $report->company ? 'company_profile_incomplete' : 'company_required', 'input_key' => null, 'fields' => $companyFields];
            }
            if (array_diff($placeholders, $company)) {
                $missing[$note] = ['key' => "notes.{$note}", 'reason' => 'note_incomplete', 'input_key' => null];
            }
        }

        return [
            'notes' => $rendered['notes'],
            'references' => $references,
            'narrative' => ['blocks' => $blocks, 'values' => array_map(fn ($v) => $v instanceof Figure ? $v->toArray() : $v, $values),
                'items' => array_map(fn ($list) => array_map(fn ($i) => array_map(fn ($v) => $v instanceof Figure ? $v->toArray() : $v, $i), $list), $items),
                // Tables' repeated columns (the PPE categories): each set's values, as the Excel file's PPE sheet reads them.
                'columns' => array_map(fn ($list) => array_map(fn ($i) => array_map(fn ($v) => $v instanceof Figure ? $v->toArray() : $v, $i), $list), $columns),
                'note_numbers' => array_map(fn ($m) => $m['number'], $meta), 'last_note_number' => $number],
            'checks' => $checks,
            'missing' => array_values($missing),
            'intake' => $fields,
            'going_concern' => $indicators,
            'zakat_base' => $zakatBase === null ? null : ['rows' => array_map(fn ($r) => ['current' => $r['current']->toArray()] + $r, $zakatBase['rows'])] + $zakatBase,
        ];
    }

    /**
     * The four indicators (Q14), this year. Accumulated losses: at half the capital or more (the Companies Law threshold).
     *
     * @return list<array{key: string, amount: float}>
     */
    private function goingConcern(array $cur): array
    {
        $amount = fn (string $k) => isset($cur[$k]) && ! $cur[$k]->isMissing() ? $cur[$k]->amount : null;
        $out = [];
        $np = $amount('is.net_profit');
        if ($np !== null && $np < -0.005) {
            $out[] = ['key' => 'net_loss', 'amount' => round(-$np, 2)];
        }
        [$re, $capital] = [$amount('sofp.retained_earnings'), $amount('sofp.share_capital')];
        if ($re !== null && $capital !== null && $re < -0.005 && $capital > 0.005 && -$re >= $capital / 2) {
            $out[] = ['key' => 'accumulated_losses', 'amount' => round(-$re, 2), 'capital' => $capital];
        }
        [$cl, $ca] = [$amount('sofp.total_current_liabilities'), $amount('sofp.total_current_assets')];
        if ($cl !== null && $ca !== null && $cl - $ca > 0.005) {
            $out[] = ['key' => 'current_liabilities', 'amount' => round($cl - $ca, 2)];
        }
        [$tl, $ta] = [$amount('sofp.total_liabilities'), $amount('sofp.total_assets')];
        if ($tl !== null && $ta !== null && $tl - $ta > 0.005) {
            $out[] = ['key' => 'total_liabilities', 'amount' => round($tl - $ta, 2)];
        }

        return $out;
    }

    /**
     * The related parties' rows of the two tables (due from, due to): a party is in each table where its accounts have a
     * balance on that table's lines in either year (an account can be due from a party one year and due to it the next);
     * a party with no balance at all is in the due-from table, for its transactions. This year from the closing balances;
     * last year from the opening columns, else the user's `balance_py` (+ due from the party, − due to it), else missing.
     *
     * @return list<array{table: string, name: ?string, relationship: ?string, nature: ?string, cy: Figure, py: ?Figure, volume_cy: Figure, volume_py: ?Figure}>
     */
    /**
     * Each party's balances: this year's from its accounts; last year's from their opening balances, or, with the
     * approved openings (Step B), last year's balance as the approved statements give it (the note's, or the line's for
     * the one party of a file without the note), this year's = it + the year's movement of its accounts.
     */
    private function partyBalances(array $parties, ScheduleContext $ctx, array $rpLines, bool $firstYear, bool $approved = false): array
    {
        $out = [];
        foreach ($parties as $i => $p) {
            $rows = array_values(array_filter(array_map('intval', (array) ($p['rows'] ?? [])), fn ($n) => isset($ctx->mapping['rows'][$n])));
            $bal  = ['due_from' => [0.0, 0.0], 'due_to' => [0.0, 0.0]];
            $refs = [];
            foreach ($rpLines as $table => $lines) {
                foreach ($lines as $line) {
                    $side = $ctx->catalogue->side($line);
                    foreach ($rows as $n) {
                        $m = $ctx->mapping['rows'][$n];
                        if (isset($m['lines']['closing'][$line])) {
                            $bal[$table][0] += $ctx->sum([$n => $m['lines']['closing'][$line]], $side)['closing'];
                            $refs[$table][] = ['type' => 'row', 'row' => $n];
                        }
                        if ($ctx->profile->has('opening') && isset($m['lines']['opening'][$line])) {
                            $bal[$table][1] += $ctx->sum([$n => $m['lines']['opening'][$line]], $side)['opening'];
                            $refs[$table][] = ['type' => 'row', 'row' => $n];
                        }
                    }
                }
            }
            $typedPy = isset($p['balance_py']) && is_numeric($p['balance_py']) ? (float) $p['balance_py'] : null;
            $num = fn ($k) => isset($p[$k]) && is_numeric($p[$k]) ? new Figure((float) $p[$k], 'user_input', 'ok', [['type' => 'input', 'key' => "related_parties.{$i}.{$k}"]]) : Figure::missing();
            $tables = array_keys(array_filter($bal, fn ($b) => abs($b[0]) > 0.005 || abs($b[1]) > 0.005));
            if (! $ctx->profile->has('opening') && $typedPy !== null && abs($typedPy) > 0.005) {
                $tables[] = $typedPy > 0 ? 'due_from' : 'due_to';
            }
            $tables = array_values(array_unique($tables)) ?: ['due_from'];
            if ($approved) {
                // Last year's balance by the approved statements, on its side («-» for a party new this year).
                $tables = array_values(array_unique([...$tables, ...($typedPy !== null && abs($typedPy) > 0.005 ? [$typedPy > 0 ? 'due_from' : 'due_to'] : [])])) ?: ['due_from'];
            }
            foreach ($tables as $table) {
                if ($approved) {
                    // Not given by the approved statements (no note, no balance typed): a party whose accounts opened at
                    // nothing is new this year («-»); one whose accounts had a balance is missing, never taken as 0, and
                    // so is this year's figure built on it.
                    $py = match (true) {
                        $typedPy !== null => new Figure(($typedPy > 0) === ($table === 'due_from') ? abs($typedPy) : 0.0, 'prior_year_statements', 'ok',
                            [['type' => 'input', 'key' => "related_parties.{$i}.balance_py"]]),
                        abs($bal[$table][1]) < 0.005 => new Figure(0.0, 'derived'),
                        default => Figure::missing(),
                    };
                    $moved = round($bal[$table][0] - $bal[$table][1], 2);
                    // No account of the party's on this table's lines this year (moved to another line, or settled): nothing
                    // is due on it at the year end, whatever last year's balance was.
                    $cy = match (true) {
                        ($refs[$table] ?? []) === [] => new Figure(0.0, 'trial_balance'),
                        $py->isMissing()             => Figure::missing(),
                        default                      => new Figure(round($py->amount + $moved, 2) + 0.0, 'trial_balance', 'ok', $refs[$table]),
                    };
                    $out[] = ['table' => $table, 'name' => $p['name'] ?? null, 'relationship' => $p['relationship'] ?? null, 'nature' => $p['nature'] ?? null,
                        'cy' => $cy, 'py' => $py,
                        'volume_cy' => $table === $tables[0] ? $num('volume_cy') : new Figure(0.0, 'derived'),
                        'volume_py' => $table === $tables[0] ? $num('volume_py') : new Figure(0.0, 'derived')];
                    continue;
                }
                $py = match (true) {
                    $firstYear                    => null,
                    $ctx->profile->has('opening') => new Figure(round($bal[$table][1], 2) + 0.0, 'trial_balance_opening', 'ok', $refs[$table] ?? []),
                    $typedPy !== null             => new Figure(($typedPy > 0) === ($table === 'due_from') ? abs($typedPy) : 0.0, 'user_input', 'ok', [['type' => 'input', 'key' => "related_parties.{$i}.balance_py"]]),
                    default                       => Figure::missing(),
                };
                $out[] = ['table' => $table, 'name' => $p['name'] ?? null, 'relationship' => $p['relationship'] ?? null, 'nature' => $p['nature'] ?? null,
                    'cy' => new Figure(round($bal[$table][0], 2) + 0.0, 'trial_balance', 'ok', $refs[$table] ?? []), 'py' => $py,
                    // Transactions once per party: in its first table.
                    'volume_cy' => $table === $tables[0] ? $num('volume_cy') : new Figure(0.0, 'derived'),
                    'volume_py' => $firstYear ? null : ($table === $tables[0] ? $num('volume_py') : new Figure(0.0, 'derived'))];
            }
        }

        return $out;
    }

    /**
     * Every placeholder's value: text (string, null when missing, false to drop its paragraph/row) and amounts (Figure);
     * the item lists of the tables; and where each text value comes from (company, input, computed).
     *
     * @return array{0: array, 1: array, 2: array<string, string>}
     */
    /** A useful life as the notes print it: «20», «6.67», or a range «3.03 - 6.67» (from a range of rates). */
    private static function years(?float $life, ?array $range): ?string
    {
        $n = fn (float $x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');

        return $range !== null ? $n($range[0]).' - '.$n($range[1]) : ($life === null ? null : $n($life));
    }

    /**
     * The PPE tables' columns (R13): last year's categories as printed, each with this year's figures (its accounts) and
     * last year's (its printed cells); else the note's own categories. A category of last year's alone on one of the note's
     * own takes its figures (the movements as the user confirmed or gave them); several on one are used while they add
     * up to it, row by row; one of the note's own none of last year's covers is added after them, «-» last year (D3).
     * Otherwise the note's own, flagged.
     *
     * @return array{sets: list<array<string, mixed>>, prior: bool, own_flag: ?array{rows: list<string>}}
     */
    private function ppeColumns(?array $prior, ?array $schedule, callable $cell, callable $pyCell, bool $firstYear): array
    {
        $zero = fn () => new Figure(0.0, 'derived');
        $own = array_values(array_filter($schedule['columns'] ?? [], fn ($c) => $c['key'] !== 'total'));
        $labels = __('statements.ppe_columns', [], 'ar');
        $ownSets = array_map(function ($c) use ($schedule, $cell, $pyCell, $firstYear, $zero, $labels) {
            $set = ['ppe_category' => $labels[$c['key']] ?? $c['label_ar']];
            foreach (self::PPE_ROWS as $placeholder => $row) {
                $set["ppe_cy_{$placeholder}_c"] = $cell($schedule, $row, $c['key']);
                $set["ppe_py_{$placeholder}_c"] = $firstYear ? $zero() : $pyCell('ppe', $row, $c['key']);
            }

            return $set;
        }, $own);
        $categories = $prior['categories'] ?? [];
        if ($categories === [] || $schedule === null) {
            return ['sets' => $ownSets, 'prior' => false, 'own_flag' => null];
        }

        $figure = fn (?float $a, string $origin) => $a === null ? Figure::missing() : new Figure(round($a, 2) + 0.0, $origin);
        $sets = [];
        $covered = [];
        $off = [];
        foreach ($categories as $c) {
            $current = $c['current'] + ['transfers' => 0.0, 'revaluation' => 0.0];
            $current['nbv_opening'] = $current['cost_opening'] === null || $current['acc_opening'] === null ? null : $current['cost_opening'] - $current['acc_opening'];
            $set = ['ppe_category' => $c['label']];
            foreach (self::PPE_ROWS as $placeholder => $row) {
                $set["ppe_cy_{$placeholder}_c"] = $figure($current[$row] ?? null, 'trial_balance');
                $set["ppe_py_{$placeholder}_c"] = $firstYear ? $zero() : $figure((float) ($c['last'][$row] ?? 0.0), 'prior_year_statements');
            }
            $sets[] = $set;
            $covered[$c['own'] ?? ''][] = array_key_last($sets);
        }
        // Row by row, last year's categories of each of the note's own add up to it (a riyal of rounding aside).
        $nz = fn (Figure $f) => ! $f->isMissing() && abs($f->amount) >= 0.5;
        foreach ($own as $k => $o) {
            $mine = $covered[$o['key']] ?? null;
            if ($mine === null) {
                // None of last year's covers it (bought this year): after them, «-» last year (D3).
                if (array_filter(self::PPE_ROWS, fn ($row) => $nz($cell($schedule, $row, $o['key'])))) {
                    $sets[] = array_merge($ownSets[$k], array_fill_keys(array_map(fn ($p) => "ppe_py_{$p}_c", array_keys(self::PPE_ROWS)), $zero()));
                }
                continue;
            }
            foreach (self::PPE_ROWS as $placeholder => $row) {
                $target = $cell($schedule, $row, $o['key']);
                if (count($mine) === 1) {
                    // Alone on it: the note's own figure (the movements as the user confirmed or gave them).
                    $sets[$mine[0]]["ppe_cy_{$placeholder}_c"] = $target;
                    continue;
                }
                $parts = array_map(fn ($i) => $sets[$i]["ppe_cy_{$placeholder}_c"], $mine);
                if ($target->isMissing() || array_filter($parts, fn (Figure $f) => $f->isMissing())
                    || abs($target->amount - array_sum(array_map(fn (Figure $f) => $f->amount, $parts))) >= 1.0) {
                    $off[] = $row;
                }
            }
        }
        // Last year's categories whose accounts are of several of the note's own (or none): they can't be told apart.
        foreach ($covered[''] ?? [] as $i) {
            foreach (self::PPE_ROWS as $placeholder => $row) {
                if ($nz($sets[$i]["ppe_cy_{$placeholder}_c"])) {
                    $off[] = $row;
                }
            }
        }
        if ($off !== []) {
            return ['sets' => $ownSets, 'prior' => false, 'own_flag' => ['rows' => array_values(array_unique($off))]];
        }

        return ['sets' => $sets, 'prior' => true, 'own_flag' => null];
    }

    /**
     * The useful-life table's rows: each category of last year's PPE note as printed, with its own life (never folded
     * into the template's categories), a category with no cost in either year left out (D4); without last year's
     * categories, the template's rows with assets.
     *
     * @return list<array{key: string, label: string, printed: ?string, years: ?string}> printed: last year's category;
     *         years: its life, 100 / its rate
     */
    private function usefulLives(array $priorCategories, callable $categoryNz, array $schedules = []): array
    {
        $cost = fn (array $c) => abs($c['last']['cost_closing'] ?? 0.0) > 0.5 || abs($c['current']['cost_closing'] ?? 0.0) > 0.5;
        $prior = array_values(array_filter($priorCategories, $cost));
        if ($prior !== []) {
            return array_map(function ($c) {
                $years = self::years($c['life'] ?? null, $c['life_range'] ?? null);
                // A rate printed but no life from it (the file doesn't say straight-line): the accountant's, never estimated
                // (100 / rate holds only for straight-line; check useful_life_not_derived says so).
                $rated = ($c['rate'] ?? null) !== null || ($c['rate_range'] ?? null) !== null;
                $estimate = $years === null && ! $rated;

                return ['key' => $c['key'], 'label' => $c['label'], 'printed' => $c['label'], 'years' => $estimate ? self::estimatedLife($c['current'] ?? [], $c['own'] ?? null, $c['label']) : $years,
                    'estimated' => $estimate ? self::lifeBasis($c['current'] ?? [], $c['own'] ?? null, $c['label']) : null];
            }, $prior);
        }
        // The note's own categories: the life the year's depreciation implies, else the category's usual one (flagged).
        $ppe = $schedules['ppe'] ?? null;
        $cells = function (string $category) use ($ppe) {
            $out = [];
            foreach ($ppe['rows'] ?? [] as $r) {
                $f = $r['cells'][$category] ?? null;
                $out[$r['key']] = $f === null || $f->isMissing() ? null : $f->amount;
            }

            return $out;
        };

        return array_values(array_map(function ($k) use ($cells) {
            $c = $cells(self::USEFUL_LIVES[$k][1]);

            return ['key' => $k, 'label' => __("statements.useful_lives.{$k}", [], 'ar'), 'printed' => null,
                'years' => self::estimatedLife($c, $k, ''), 'estimated' => self::lifeBasis($c, $k, '')];
        }, array_keys(array_filter(self::USEFUL_LIVES, fn ($sc) => $categoryNz(...$sc)))));
    }

    /**
     * A life no statement gives (never «[ناقص]»): 100 / the rate the year's depreciation implies on the category's cost
     * (PriorPpe::impliedRate), else the usual life of its kind (config financial_statements.useful_life_defaults, by the
     * note's category or the printed name's words). Shown, and said by check `useful_life_estimated` (P3).
     */
    private static function estimatedLife(array $cells, ?string $own, string $label): ?string
    {
        $rate = PriorPpe::impliedRate($cells);
        if ($rate !== null) {
            return self::years(round(100 / $rate, 2), null);
        }
        $usual = self::usualLife($own, $label);

        return $usual === null ? null : self::years((float) $usual, null);
    }

    /** How an estimated life was found: `implied` (the year's depreciation), `usual` (the kind's usual life), or null. */
    private static function lifeBasis(array $cells, ?string $own, string $label): ?string
    {
        return PriorPpe::impliedRate($cells) !== null ? 'implied' : (self::usualLife($own, $label) !== null ? 'usual' : null);
    }

    private static function usualLife(?string $own, string $label): ?int
    {
        $defaults = (array) config('financial_statements.useful_life_defaults', []);
        if ($own !== null && isset($defaults['categories'][$own])) {
            return (int) $defaults['categories'][$own];
        }
        foreach ((array) ($defaults['words'] ?? []) as $pattern => $years) {
            if (preg_match($pattern, $label) === 1) {
                return (int) $years;
            }
        }

        return isset($defaults['other']) ? (int) $defaults['other'] : null;
    }

    private function values(FinancialReport $report, array $in, array $built, array $cur, array $cmp, array $meta, array $indicators, array $parties, array $schedules,
        array $cmpCells, bool $firstYear, callable $categoryNz, array $lifeRows): array
    {
        $company = $report->company;
        $catalogue = $built['context']->catalogue;
        $v       = [];
        $items   = [];
        $src     = [];
        $text    = fn (?string $s) => $s === null || trim($s) === '' ? null : trim($s);
        $date    = fn (?CarbonInterface $d) => $d === null ? null : $d->day.' '.self::MONTHS[$d->month - 1].' '.$d->year.'م';
        $dayMonth = fn (CarbonInterface $d) => $d->day.' '.self::MONTHS[$d->month - 1];

        // The company's facts; one its profile doesn't hold yet is last year's notes' (note 1 of the PDF), never «[ناقص]»
        // when they say it. The registration number and name stay the profile's when it has them (the identity check
        // compares the two).
        $priorFile = ($report->prior_figures['source'] ?? null) === 'statements' ? ($report->prior_figures['file'] ?? null) : null;
        $facts = isset($priorFile['document']) ? PriorPdf\CompanyFacts::of($priorFile['document'], $priorFile['document']['corrections'] ?? [], $priorFile['wording_choices'] ?? []) : [];
        // Only the company's own statements: a file naming another company (its name or registration differs) gives nothing
        // (the identity check says so, and the facts are offered in step 2, never filled on their own).
        if ($facts !== [] && PriorPdf\CompanyIdentity::of($company, $facts)['differs'] !== []) {
            $facts = [];
        }
        foreach (['company_name' => [$company?->name_ar, 'name_ar'], 'city' => [$company?->city, 'city'], 'cr_number' => [$company?->cr_number, 'cr_number'],
            'cr_date_hijri' => [$company?->cr_date_hijri, 'cr_date_hijri'], 'unified_number' => [$company?->unified_number, null],
            'activity' => [$company?->activity, 'activity'], 'head_office' => [$company?->head_office, 'head_office']] as $k => [$value, $fact]) {
            $v[$k] = $text($value);
            $src[$k] = 'company';
            if ($v[$k] === null && $fact !== null && isset($facts[$fact])) {
                $v[$k] = $text($facts[$fact]['value']);
                $src[$k] = 'prior_pdf';
            }
        }
        $v['legal_form'] = $company?->legal_form !== null || ! isset($facts['legal_form']) ? ($company?->legalFormText() ?? Company::LEGAL_FORMS['llc'])
            : Company::LEGAL_FORMS[$facts['legal_form']['value']] ?? Company::LEGAL_FORMS['llc'];
        $branches = array_values(array_filter(array_map('trim', (array) ($company?->branches ?? []))));
        $v['branches_clause'] = $branches ? '، وللشركة الفروع التالية: '.implode('، ', $branches) : '';

        // Period: the fiscal year from the period end (a short or long first period keeps the company's usual year).
        [$start, $end] = [$report->period_start, $report->period_end];
        $fyEnd = $company?->fiscal_year_end_month ? $end->copy()->setDate($end->year, $company->fiscal_year_end_month, min($company->fiscal_year_end_day ?? 31, $end->copy()->setDate($end->year, $company->fiscal_year_end_month, 1)->daysInMonth)) : $end;
        $v['fiscal_year_end_text']   = $dayMonth($fyEnd);
        $v['fiscal_year_start_text'] = $dayMonth($fyEnd->copy()->addDay());
        $v['period_end_text'] = $date($end);
        $v['cy_start_date']   = $date($start);
        $v['cy_end_date']     = $date($end);
        $v['cy_year']         = (string) $end->year;
        $v['cy']              = $end->year.'م';
        [$cs, $ce] = [$report->comparative_start, $report->comparative_end ?? ($firstYear ? null : $start->copy()->subDay())];
        $cs ??= $firstYear ? null : $start->copy()->subYear();
        $v['py_start_date'] = $date($cs);
        $v['py_end_date']   = $date($ce);
        $v['ppy_end_date']  = $date($cs?->copy()->subDay());
        $v['py_year']       = $ce ? (string) $ce->year : null;
        $v['py']            = $ce ? $ce->year.'م' : null;
        foreach (['py_start_date', 'py_end_date', 'ppy_end_date', 'py_year', 'py'] as $k) {
            $src[$k] = 'computed';
        }

        foreach ($meta as $key => $m) {
            $v["note_no_{$key}"] = $m['number'] !== null ? (string) $m['number'] : null;
        }

        // Basis, first application, going concern, restatement.
        $v['restatement_text'] = $text($report->prior_figures['restatement_note'] ?? null);
        $v['first_application_date'] = $date($start);
        $v['first_application_effect_text'] = $text($in['first_application.effect_text'] ?? null);
        $v['first_application_reliefs_text'] = $text($in['first_application.reliefs'] ?? null);
        $money = fn (float $a) => number_format(round(abs($a)));
        // [connector, clause]: the paragraph's first clause starts without its connector («بلغت», not «وبلغت»).
        $clauses = array_map(fn ($i, $n) => implode('', array_slice(match ($i['key']) {
            'net_loss'            => ['', 'خلال السنة، تكبدت الشركة صافي خسارة بمبلغ ('.$money($i['amount']).') ريال سعودي'],
            'accumulated_losses'  => ['و', 'بلغت الخسائر المتراكمة كما في '.$v['period_end_text'].' مبلغ ('.$money($i['amount']).') ريال سعودي بما تجاوز نصف رأس المال'],
            'current_liabilities' => ['كما ', 'تجاوزت الالتزامات المتداولة الأصول المتداولة بمبلغ ('.$money($i['amount']).') ريال سعودي'],
            'total_liabilities'   => ['و', 'تجاوز إجمالي الالتزامات إجمالي الأصول بمبلغ ('.$money($i['amount']).') ريال سعودي'],
        }, $n === 0 ? 1 : 0)), $indicators, array_keys($indicators));
        $v['going_concern_indicators'] = $clauses ? implode('، ', $clauses).'.' : null;
        $v['going_concern_mitigation'] = $text($in['going_concern.mitigation'] ?? null) ?? 'ومع ذلك، فقد قرر الشركاء اتخاذ التدابير النظامية والمالية المناسبة ومواصلة أعمال الشركة،';

        // Useful lives: the PPE table's rows (usefulLives), the intangibles' in the text; a category without assets drops.
        // A life not answered yet is last year's (100 / its rate), shown till the accountant changes it (P2).
        $items['useful_life_label'] = array_map(fn ($r) => ['useful_life_label' => $r['label'], 'useful_life_years' => $text($in["useful_life.{$r['key']}"] ?? $r['years'])], $lifeRows);
        foreach (self::INTANGIBLE_LIVES as $k => $sc) {
            $v["useful_life_{$k}"] = $categoryNz(...$sc) ? $text($in["useful_life.{$k}"] ?? null) : false;
        }

        // Capital (check 20): the capital line, the partners' table.
        $capital = $cur['sofp.share_capital'] ?? Figure::missing();
        $v['capital_amount'] = $capital->isMissing() ? null : number_format(round($capital->amount));
        $partners = array_values(array_filter((array) ($in['partners'] ?? []), 'is_array'));
        $shares   = array_sum(array_map(fn ($p) => (float) ($p['shares'] ?? 0), $partners));
        $values   = array_unique(array_map(fn ($p) => (float) ($p['share_value'] ?? 0), $partners));
        $v['capital_shares'] = $partners ? number_format($shares) : null;
        // In words by code, never typed or guessed (R7): «مقسم إلى أربعة آلاف ومائتي (4,200) حصة», genitive after «إلى».
        $counted = $partners && $shares > 0 && fmod($shares, 1.0) == 0 ? ArabicNumberWords::counted((int) $shares, self::SHARE_NOUN) : null;
        $v['capital_shares_words'] = $counted['words'] ?? null;
        $v['capital_shares_noun'] = $counted['noun'] ?? self::SHARE_NOUN['singular'];
        $v['share_value'] = count($values) === 1 ? number_format(reset($values), 2 * (int) (fmod(reset($values), 1.0) != 0)) : null;
        $items['partner_name'] = array_map(fn ($p, $i) => [
            'partner_name' => $text($p['name'] ?? null),
            'partner_shares' => number_format((float) ($p['shares'] ?? 0)),
            'partner_share_value' => number_format((float) ($p['share_value'] ?? 0), 2 * (int) (fmod((float) ($p['share_value'] ?? 0), 1.0) != 0)),
            'partner_percentage' => $shares > 0 ? rtrim(rtrim(number_format(100 * (float) ($p['shares'] ?? 0) / $shares, 2), '0'), '.').'%' : null,
            'partner_total' => new Figure(round((float) ($p['shares'] ?? 0) * (float) ($p['share_value'] ?? 0), 2), 'user_input', 'ok', [['type' => 'input', 'key' => "partners.{$i}"]]),
        ], $partners, array_keys($partners));
        $v['partners_total_shares'] = $partners ? number_format($shares) : null;
        $v['partners_total_percentage'] = $partners ? '100%' : null;
        $v['partners_total_total'] = $partners ? Figure::sum(array_column($items['partner_name'], 'partner_total')) : Figure::missing();

        // Other notes' wording.
        $v['provisions_description'] = $text($in['other_provisions.description'] ?? null);
        $v['revenue_disaggregation_basis'] = 'نوع الإيراد';
        foreach (['provider', 'terms', 'due_range'] as $k) {
            $v["supplier_finance_{$k}"] = $text($in["supplier_finance.{$k}"] ?? null);
        }
        foreach (['carrying_amount', 'paid_by_provider'] as $k) {
            $v["supplier_finance_{$k}"] = isset($in["supplier_finance.{$k}"]) ? number_format(round((float) $in["supplier_finance.{$k}"])) : null;
        }
        $v['zakat_zero_reason'] = $text(isset($in['zakat.zero_reason']) ? rtrim(trim($in['zakat.zero_reason']), '.') : null);   // the sentence ends with its own «.»
        $v['subsequent_events_text'] = $text($in['events.subsequent'] ?? null);
        $v['significant_events_text'] = $text($in['events.significant'] ?? null);
        $v['approval_body'] = $text($in['approval.body'] ?? __('statements.defaults.approval_body', [], 'ar'));
        $v['approval_date_hijri'] = $text($in['approval.date_hijri'] ?? null);
        $v['approval_date'] = isset($in['approval.date']) ? $date(\Carbon\Carbon::parse($in['approval.date'])) : null;

        // Amounts: item tables by class. The periodic build-up is 0 without periodic stock (its block is dropped then).
        $py = fn (?Figure $f) => $firstYear ? new Figure(0.0, 'derived') : ($f ?? Figure::missing());
        foreach ([...NoteBreakdowns::BUILD_UP, 'cogs'] as $part) {
            $v["cost_inventory_{$part}_cy"] = $v["cost_inventory_{$part}_py"] = new Figure(0.0, 'derived');
        }
        foreach ($built['breakdowns'] as $note => $b) {
            $prefix = $catalogue->notes()[$note]['breakdown'];
            $rows = $b['rows'];
            if ($note === 'trade_receivables') {
                continue;
            }
            $items["{$prefix}_label"] = array_map(fn ($r) => ["{$prefix}_label" => $r['label_ar'], "{$prefix}_cy" => $r['current'], "{$prefix}_py" => $py($r['comparative'] ?? null)], $rows);
            $v["{$prefix}_total_cy"] = Figure::sum(array_column($rows, 'current'));
            $v["{$prefix}_total_py"] = $firstYear ? new Figure(0.0, 'derived') : Figure::sum(array_map(fn ($r) => $r['comparative'] ?? Figure::missing(), $rows));
            if ($b['build_up'] !== null) {
                foreach ([...NoteBreakdowns::BUILD_UP, 'cogs'] as $part) {
                    $v["cost_inventory_{$part}_cy"] = $b['build_up'][$part]['current'];
                    $v["cost_inventory_{$part}_py"] = $py($b['build_up'][$part]['comparative'] ?? null);
                }
            }
        }
        // Trade receivables: gross less the impairment (the contra classes).
        $gross = $imp = ['cy' => [], 'py' => []];
        foreach ($built['breakdowns']['trade_receivables']['rows'] ?? [] as $r) {
            $contra = $this->taxonomy->normalSide($r['key']) === 'credit';
            ${$contra ? 'imp' : 'gross'}['cy'][] = $r['current'];
            ${$contra ? 'imp' : 'gross'}['py'][] = $r['comparative'] ?? Figure::missing();
        }
        foreach (['gross' => $gross, 'impairment' => $imp] as $k => $parts) {
            $v["receivables_{$k}_cy"] = Figure::sum($parts['cy']);
            $v["receivables_{$k}_py"] = $firstYear ? new Figure(0.0, 'derived') : Figure::sum($parts['py']);
        }
        $v['receivables_net_cy'] = $cur['sofp.trade_receivables'] ?? Figure::missing();
        $v['receivables_net_py'] = $py($cmp['sofp.trade_receivables'] ?? null);
        // Last year's split by last year's statements (the notes are theirs, not the trial balance's): the impairment their
        // note prints (none printed: nil), else the contra accounts' openings; the gross is the approved line plus it.
        if (! $firstYear && $v['receivables_gross_py']->isMissing() && ! $v['receivables_net_py']->isMissing()) {
            $impairment = $this->priorImpairment($report, $built['breakdowns']['trade_receivables']['rows'] ?? []);
            if ($impairment !== null) {
                $v['receivables_impairment_py'] = $impairment;
                $v['receivables_gross_py'] = new Figure(round($v['receivables_net_py']->amount - $impairment->amount, 2) + 0.0, 'prior_year_statements', 'ok',
                    [['type' => 'line', 'key' => 'sofp.trade_receivables']]);
            }
        }

        // Movement tables.
        // A row or category the schedule doesn't have (no accounts, no transfers, no revaluation) is 0.
        $cell = function (?array $s, string $row, string $col) {
            if ($s === null) {
                return new Figure(0.0, 'derived');
            }
            $r = collect($s['rows'])->firstWhere('key', $row);
            $hasColumn = in_array($col, array_column($s['columns'], 'key'), true);
            return $r['cells'][$col] ?? ($r === null || ! $hasColumn ? new Figure(0.0, 'derived') : Figure::missing());
        };
        $pyCell = fn (string $schedule, string $row, string $col) => $firstYear ? new Figure(0.0, 'derived')
            : ($cmpCells["{$schedule}.{$row}.{$col}"] ?? (in_array($row, ['transfers', 'revaluation', 'prior_year_adjustments'], true) && isset($schedules[$schedule]) ? new Figure(0.0, 'derived') : Figure::missing()));
        foreach (self::ASSET_TABLES as $note => $t) {
            $s    = $schedules[$t['schedule']] ?? null;
            $cols = array_keys($catalogue->schedules()[$t['schedule']]['categories']);
            $map  = ['cost_opening' => 'cost_opening', 'additions' => 'additions', 'transfers' => 'transfers', 'revaluation' => 'revaluation', 'disposals' => 'disposals',
                'cost_closing' => 'cost_closing', "{$t['acc']}_opening" => 'acc_opening', $t['charge'] => 'depreciation', "{$t['acc']}_disposals" => 'acc_disposals',
                "{$t['acc']}_closing" => 'acc_closing', 'nbv_closing' => 'nbv_closing', 'nbv_comparative' => 'nbv_opening'];
            foreach ([...$cols, 'total'] as $col) {
                foreach ($map as $placeholder => $row) {
                    if ($note === 'ppe') {
                        $v["ppe_cy_{$placeholder}_{$col}"] = $cell($s, $row, $col);
                        $v["ppe_py_{$placeholder}_{$col}"] = $pyCell($t['schedule'], $row, $col);
                    } else {
                        $v["{$note}_{$placeholder}_{$col}"] = $cell($s, $row, $col);
                    }
                }
            }
        }
        // The PPE tables' columns (Phase 9, R13): one per category, repeated in the tables (NarrativeRenderer::repeats).
        $ppe = $this->ppeColumns($built['ppe_prior'] ?? null, $schedules['ppe'] ?? null, $cell, $pyCell, $firstYear);
        $columns = ['ppe_category' => $ppe['sets']];
        if ($ppe['prior']) {
            // Last year's totals as the note's arithmetic chose them (Step A), not the sum of their columns by itself.
            foreach (self::PPE_ROWS as $placeholder => $row) {
                $total = $built['ppe_prior']['totals'][$row] ?? null;
                if ($total !== null && ! $firstYear) {
                    $v["ppe_py_{$placeholder}_total"] = new Figure((float) $total, 'prior_year_statements');
                }
            }
        }
        foreach ($columns as $sets) {
            foreach ($sets as $i => $set) {
                foreach ($set as $name => $value) {
                    $v[$name.'__'.($i + 1)] = $value;
                }
            }
        }
        foreach (self::PROVISION_TABLES as $prefix => [$schedule, $rows]) {
            $s = $schedules[$schedule] ?? null;
            foreach ($rows as $placeholder => $row) {
                $v["{$prefix}_{$placeholder}_cy"] = $cell($s, $row, 'amount');
                $v["{$prefix}_{$placeholder}_py"] = $pyCell($schedule, $row, 'amount');
            }
        }
        $loans = $schedules['loans'] ?? null;
        foreach (['gross', 'deferred_finance_cost', 'net', 'non_current', 'current'] as $row) {
            $v["loans_{$row}_cy"] = $loans ? $cell($loans, $row, 'current') : new Figure(0.0, 'derived');
            $v["loans_{$row}_py"] = $firstYear ? new Figure(0.0, 'derived') : ($loans && isset($loans['rows'][0]['cells']['comparative']) ? $cell($loans, $row, 'comparative') : Figure::missing());
        }

        // Related parties.
        foreach (['due_from' => 'rp_from', 'due_to' => 'rp_to'] as $table => $p) {
            $list = array_values(array_filter($parties, fn ($x) => $x['table'] === $table));
            $items["{$p}_name"] = array_map(fn ($x) => ["{$p}_name" => $text($x['name']), "{$p}_relationship" => $text($x['relationship']), "{$p}_nature" => $text($x['nature']),
                "{$p}_volume_cy" => $x['volume_cy'], "{$p}_volume_py" => $x['volume_py'] ?? new Figure(0.0, 'derived'), "{$p}_cy" => $x['cy'], "{$p}_py" => $x['py'] ?? new Figure(0.0, 'derived')], $list);
            $v["{$p}_total_cy"] = Figure::sum(array_column($list, 'cy'));
            $v["{$p}_total_py"] = $firstYear ? new Figure(0.0, 'derived') : Figure::sum(array_map(fn ($x) => $x['py'] ?? Figure::missing(), $list));
        }

        // Contract balances (E4).
        foreach (['trade_receivables' => 'sofp.trade_receivables', 'contract_assets' => 'sofp.contract_assets', 'contract_liabilities' => 'sofp.contract_liabilities'] as $k => $line) {
            $v["contract_balances_{$k}_cy"] = $cur[$line] ?? Figure::missing();
            $v["contract_balances_{$k}_py"] = $py($cmp[$line] ?? null);
        }

        foreach (array_keys($v) as $k) {
            $src[$k] ??= 'other';
        }

        return [$v, $items, $src, $columns, $ppe['own_flag']];
    }

    /** Check 14: each note table's total vs its statement line(s), both years (and the related parties' accounts). */
    private function totalChecks(array $built, array $cur, array $cmp, array $meta, array $parties, array $rpLines, bool $firstYear): array
    {
        $checks = [];
        // The income statement shows expenses negative; the notes list them positive.
        $catalogue = $built['context']->catalogue;
        $shown = fn (string $l, ?Figure $f) => $f === null || $f->isMissing() || ! str_starts_with($l, 'is.') || $catalogue->side($l) === 'credit' ? ($f ?? Figure::missing())
            : new Figure(-$f->amount, $f->origin, $f->status, $f->refs);
        $compare = function (string $note, array $parts, array $lines, string $scope) use (&$checks, $cur, $cmp, $shown) {
            $statement = Figure::sum(array_map(fn ($l) => $shown($l, ($scope === 'current' ? $cur : $cmp)[$l] ?? null), $lines));
            $table     = Figure::sum($parts);
            // In whole riyals, as both print (a table of 0.60 under a line of 1 ties).
            if ($table->isMissing() || $statement->isMissing() || round($table->amount) == round($statement->amount)
                || abs($table->amount - $statement->amount) <= ReportChecks::tolerance()) {   // under 2 riyals: rounding
                return;
            }
            $diff = round($table->amount - $statement->amount, 2);
            $checks[] = $this->check('note_total_mismatch', 'error', $statement->amount, $table->amount, $diff,
                ReportChecks::msg(count($lines) > 1 ? 'note_total_mismatch_lines' : 'note_total_mismatch', ['note' => __("statements.notes.{$note}", [], 'ar'),
                    'table' => number_format($table->amount, 2), 'statement' => number_format($statement->amount, 2)]),
                ['scope' => $scope, 'line_key' => $lines[0], 'note' => $note]);
        };
        foreach ($built['breakdowns'] as $note => $b) {
            if (! $meta[$note]['visible']) {
                continue;
            }
            $compare($note, array_column($b['rows'], 'current'), $b['lines'], 'current');
            if (! $firstYear && isset($b['comparative_remainder'])) {
                $r = $b['comparative_remainder'];
                $checks[] = $this->check('note_comparative_remainder', 'info', null, $r['amount'], $r['amount'],
                    ReportChecks::msg('note_comparative_remainder', ['note' => __("statements.notes.{$note}", [], 'ar'), 'amount' => number_format($r['amount'], 2), 'item' => $r['item']]),
                    ['scope' => 'comparative', 'line_key' => $b['lines'][0], 'note' => $note]);
            }
            if (! $firstYear && isset($b['prior_on_main_row'])) {
                $r = $b['prior_on_main_row'];
                $checks[] = $this->check('prior_line_on_main_row', 'info', null, $r['amount'], null,
                    ReportChecks::msg('prior_line_on_main_row', ['note' => __("statements.notes.{$note}", [], 'ar'), 'amount' => number_format($r['amount'], 2), 'item' => $r['item']]),
                    ['scope' => 'comparative', 'line_key' => $b['lines'][0], 'note' => $note]);
            }
            if (! $firstYear) {
                $compare($note, array_map(fn ($r) => $r['comparative'] ?? Figure::missing(), $b['rows']), $b['lines'], 'comparative');
            }
        }
        if ($meta['related_parties']['visible'] && $parties !== []) {
            foreach ($rpLines as $table => $lines) {
                $list = array_values(array_filter($parties, fn ($x) => $x['table'] === $table));
                $compare('related_parties', array_column($list, 'cy'), $lines, 'current');
                if (! $firstYear) {
                    $compare('related_parties', array_map(fn ($x) => $x['py'] ?? Figure::missing(), $list), $lines, 'comparative');
                }
            }
        }
        // The prior year's movement tables, typed: opening + movements = closing (this year's opening).
        $cells = $built['schedules_comparative'] ?? [];
        foreach ($built['schedules'] as $s) {
            if ($s['kind'] !== 'provision' || $firstYear || ! isset($cells["{$s['key']}.closing.amount"])) {
                continue;
            }
            $get = fn ($r) => $cells["{$s['key']}.{$r}.amount"] ?? new Figure(0.0, 'derived');
            $roll = Figure::sum([$get('opening'), $get($s['increase']), $get('prior_year_adjustments'), AssetNotesBuilder::neg($get($s['decrease'])), AssetNotesBuilder::neg($get('reversal'))]);
            $close = $get('closing');
            if (! $roll->isMissing() && ! $close->isMissing() && abs($roll->amount - $close->amount) > ReportChecks::tolerance()) {
                $checks[] = $this->check('provision_rollforward', 'error', $close->amount, $roll->amount, round($roll->amount - $close->amount, 2),
                    ReportChecks::msg('provision_rollforward_comparative', ['title' => $s['title_ar']]),
                    ['scope' => 'comparative', 'line_key' => $s['line_key'], 'schedule' => $s['key']]);
            }
        }

        return $checks;
    }

    /** Check 20: the partners' table adds up to the capital line. */
    private function capitalCheck(array $inputs, array $cur): array
    {
        $partners = array_values(array_filter((array) ($inputs['partners'] ?? []), 'is_array'));
        $capital  = $cur['sofp.share_capital'] ?? Figure::missing();
        if ($partners === [] || $capital->isMissing()) {
            return [];
        }
        $total = round(array_sum(array_map(fn ($p) => (float) ($p['shares'] ?? 0) * (float) ($p['share_value'] ?? 0), $partners)), 2);
        if (abs($total - $capital->amount) <= 0.01) {
            return [];
        }

        return [$this->check('capital_note_mismatch', 'error', $capital->amount, $total, round($total - $capital->amount, 2),
            ReportChecks::msg('capital_note_mismatch', ['total' => number_format($total, 2), 'capital' => number_format($capital->amount, 2)]),
            ['line_key' => 'sofp.share_capital', 'note' => 'capital'])];
    }

    /**
     * Partners read from last year's PDF (R14): who is on which row is confirmed by the trial balance's capital accounts
     * in the partners' names (Partners::confirm), else the table is for the accountant to review: a warning, so the
     * final version waits for it (acknowledged = reviewed; its key holds the table, so a changed table is reviewed
     * again). Names that don't pair up with the share rows are never put on a row: they are listed. Last year's
     * partners adding up to other than last year's capital is said too.
     */
    private function partnersChecks(?array $pdf, array $shown, array $inputs, ScheduleContext $ctx): array
    {
        $rows = array_values(array_filter((array) ($shown['partners'] ?? []), 'is_array'));
        if ($pdf === null || $rows === []) {
            return [];
        }
        $table = array_map(fn ($p) => ['name' => (string) ($p['name'] ?? ''), 'shares' => (float) ($p['shares'] ?? 0), 'share_value' => (float) ($p['share_value'] ?? 0),
            'amount' => round((float) ($p['shares'] ?? 0) * (float) ($p['share_value'] ?? 0), 2)], $rows);
        // Still last year's table with its names unpaired: the count mismatch stands.
        $unpaired = $pdf['status'] === 'count_mismatch' && ! isset($inputs['partners']);
        $c = PriorPdf\Partners::confirm(['status' => $unpaired ? 'count_mismatch' : 'read', 'rows' => $table], $ctx);
        $checks = [];
        if ($c['status'] === 'confirmed') {
            $checks[] = $this->check('partners_confirmed', 'info', count($table), count($c['confirmed']), 0, ReportChecks::msg('partners_confirmed', ['page' => $pdf['page']]),
                ['note' => 'capital', 'partners' => $table]);
        } else {
            $checks[] = $this->check('partners_review', 'warning', count($table), count($c['confirmed']), count($table) - count($c['confirmed']),
                ReportChecks::msg("partners_review_{$c['status']}", ['page' => $pdf['page'], 'names' => implode('، ', $pdf['names']), 'count' => count($pdf['names']),
                    'rows' => count($pdf['rows']), 'accounts' => implode('، ', array_column($c['disagree'], 'account'))]),
                ['note' => 'capital', 'line_key' => 'sofp.share_capital', 'partners' => $table]);
        }
        if (! $pdf['sum_ok']) {
            $total = array_sum(array_column($pdf['rows'], 'amount'));
            $checks[] = $this->check('partners_prior_capital', 'warning', $pdf['capital'], $total, null,
                ReportChecks::msg('partners_prior_capital', ['page' => $pdf['page'], 'total' => number_format($total), 'capital' => number_format(abs((float) $pdf['capital']))]),
                ['note' => 'capital']);
        }

        return $checks;
    }

    /** Check 25: one warning per going-concern indicator (Q14). */
    private function goingConcernChecks(array $indicators, ?string $decision): array
    {
        $checks = [];
        foreach ($indicators as $i) {
            $message = ReportChecks::msg('going_concern_indicator', [
                'indicator' => ReportChecks::msg("going_concern.{$i['key']}", ['amount' => number_format($i['amount'], 2)]),
                // No decision yet: the paragraph is included by itself (the accountant may exclude it, with a reason).
                'decision'  => ReportChecks::msg('going_concern.'.(in_array($decision, ['include', 'exclude'], true) ? $decision : 'auto_included')),
            ]);
            $checks[] = $this->check('going_concern_indicator', 'info', 0, $i['amount'], $i['amount'], $message, ['indicator' => $i['key'], 'note' => 'going_concern',
                'line_key' => ['net_loss' => 'is.net_profit', 'accumulated_losses' => 'sofp.retained_earnings', 'current_liabilities' => 'sofp.total_current_liabilities', 'total_liabilities' => 'sofp.total_liabilities'][$i['key']]]);
        }

        return $checks;
    }

    /**
     * Check 26e: a related party's or partner's name in more than one account across the related-party, partners',
     * receivable, payable, prepayment, accrual and salary classes (Q40). A hint only (info), never acted on.
     */
    /**
     * Last year's statements naming another company than the report's (its name or registration differs from the profile),
     * or another company of the app: a warning with both and the page, the report's company never changed here.
     */
    private function companyIdentity(FinancialReport $report, ?array $priorFile): array
    {
        if ($priorFile === null || ! isset($priorFile['document']) || $report->company === null) {
            return [];
        }
        $facts = PriorPdf\CompanyFacts::of($priorFile['document'], $priorFile['document']['corrections'] ?? [], $priorFile['wording_choices'] ?? []);
        $identity = PriorPdf\CompanyIdentity::of($report->company, $facts);
        if ($identity['differs'] === []) {
            return [];
        }
        $field = fn ($k) => __("report_messages.company_fields.{$k}", [], 'ar');

        return [$this->check('company_differs_from_prior', 'warning', null, null, null, ReportChecks::msg($identity['matches'] === [] ? 'company_differs_from_prior' : 'company_differs_from_prior_other', [
            'company' => $report->company->name_ar, 'page' => $identity['differs'][0]['page'],
            'differs' => implode('، ', array_map(fn ($d) => "{$field($d['field'])}: «{$d['file']}» / «{$d['company']}»", $identity['differs'])),
            'others' => implode('، ', array_map(fn ($m) => "«{$m['name_ar']}»", $identity['matches']))]),
            ['differs' => $identity['differs'], 'matches' => $identity['matches']])];
    }

    /**
     * Last year's rates read but no life derived from them: the file doesn't say the method is straight-line (100 / rate
     * holds only then). The rates are shown; each life not yet given is the accountant's (a warning naming the categories
     * and the page).
     */
    private function livesNotDerived(array $lifeRows, array $categories, array $inputs): array
    {
        $byKey = array_column($categories, null, 'key');
        $rated = array_values(array_filter($lifeRows, fn ($r) => $r['years'] === null && isset($byKey[$r['key']]) && ! isset($inputs["useful_life.{$r['key']}"])
            && ($byKey[$r['key']]['rate'] !== null || $byKey[$r['key']]['rate_range'] !== null)));
        if ($rated === []) {
            return [];
        }
        $method = $byKey[$rated[0]['key']]['method'] ?? null;

        return [$this->check('useful_life_not_derived', 'warning', null, null, null,
            ReportChecks::msg($method === 'other' ? 'useful_life_not_derived_other' : 'useful_life_not_derived', [
                'categories' => implode('، ', array_map(fn ($r) => $r['label'], $rated)), 'page' => $byKey[$rated[0]['key']]['page']]),
            ['note' => 'ppe', 'input_key' => "useful_life.{$rated[0]['key']}", 'method' => $method])];
    }

    /**
     * The notes in the accountant's order: the keys it names (known ones, once each), each note it leaves out right after the
     * note before it in the template (or first). The template's order when it names none.
     *
     * @param list<string> $keys the template's notes in order
     * @param list<string> $chosen
     * @return list<string>
     */
    public static function order(array $keys, array $chosen): array
    {
        $out = array_values(array_unique(array_values(array_filter($chosen, fn ($k) => is_string($k) && in_array($k, $keys, true)))));
        if ($out === []) {
            return $keys;
        }
        foreach ($keys as $i => $key) {
            if (in_array($key, $out, true)) {
                continue;
            }
            $after = $i === 0 ? false : array_search($keys[$i - 1], $out, true);
            array_splice($out, $after === false ? 0 : $after + 1, 0, [$key]);
        }

        return $out;
    }

    /**
     * A related party of last year's note with an account on another line this year («جاري صاحب المؤسسة - فاطمة الشوا» on the
     * partners' current accounts while last year's statements showed her balance due to related parties): the note and the
     * line lose it. A warning naming the account and its line; moving it back is the comparability step's proposal (one click).
     */
    private function relatedElsewhere(?array $pdfParties, array $rpRows, ScheduleContext $ctx, array $rpLines): array
    {
        if ($pdfParties === null) {
            return [];
        }
        $rpKeys = [...$rpLines['due_from'], ...$rpLines['due_to']];
        $labels = array_column(array_filter($ctx->catalogue->statement('financial_position'), fn ($i) => isset($i['key'])), 'label_ar', 'key');
        $out = [];
        foreach ($pdfParties['parties'] as $p) {
            if (abs((float) ($p['balance_py'] ?? 0)) <= 0.5) {
                continue;
            }
            $want = PriorPdf\Partners::telling($p['name']);
            foreach ($ctx->mapping['rows'] as $n => $m) {
                $lines = array_keys($m['lines']['closing']);
                if (isset($rpRows[$n]) || array_intersect($lines, $rpKeys) || ! array_filter($lines, fn ($l) => str_starts_with($l, 'sofp.'))) {
                    continue;
                }
                $have = PriorPdf\Partners::telling((string) ($ctx->rows[$n]['account_name'] ?? ''));
                $score = count(array_intersect($want, $have));
                $open = abs((float) ($ctx->profile->opening($ctx->rows[$n]) ?? 0.0));
                $close = abs((float) ($ctx->profile->closing($ctx->rows[$n]) ?? 0.0));
                if ($open <= 0.5 && $close <= 0.5) {
                    continue;   // nothing on it either year
                }
                if ($score === 0 || ($score < min(2, count($want)) && abs($open - abs((float) $p['balance_py'])) > PriorPdf\Tables::TOLERANCE)) {
                    continue;
                }
                $out[] = $this->check('related_party_elsewhere', 'warning', abs((float) $p['balance_py']), $open, null,
                    ReportChecks::msg('related_party_elsewhere', ['name' => $p['name'], 'account' => trim((string) ($ctx->rows[$n]['account_name'] ?? '')),
                        'line' => $labels[$lines[0]] ?? $lines[0], 'prior' => number_format(abs((float) $p['balance_py']))]),
                    ['note' => 'related_parties', 'rows' => [$n], 'name' => $p['name']]);
            }
        }

        return $out;
    }

    /**
     * Last year's impairment of trade receivables (negative): what last year's note prints on its impairment lines (nil
     * when it prints none), else the contra accounts' openings; null when neither says.
     *
     * @param list<array> $rows the trade receivables note's rows (by class)
     */
    private function priorImpairment(FinancialReport $report, array $rows): ?Figure
    {
        $file = ($report->prior_figures['source'] ?? null) === 'statements' ? ($report->prior_figures['file'] ?? null) : null;
        $doc = $file['document'] ?? null;
        if ($doc !== null && ($section = PriorNoteItems::section($doc, $file, $report->prior_figures['rows'] ?? [], ['sofp.trade_receivables'])) !== null
            && ($items = PriorNoteItems::items($doc, $section, $file)) !== []) {
            $impairment = array_sum(array_map(fn ($i) => abs($i['prior']), array_filter($items,
                fn ($i) => preg_match('/انخفاض|مخصص|مشكوك|هبوط|خسائر\s+ائتمان/u', $i['label']) === 1)));

            return new Figure(-round($impairment, 2) + 0.0, 'prior_year_statements', 'ok', [['type' => 'prior_pdf', 'page' => $doc['sections'][$section]['page']]]);
        }
        $openings = [];
        foreach ($rows as $r) {
            if ($this->taxonomy->normalSide($r['key']) === 'credit') {
                if ($r['opening'] === null) {
                    return null;
                }
                $openings[] = $r['opening'];
            }
        }

        return $openings === [] ? new Figure(0.0, 'derived') : Figure::sum($openings);
    }

    /**
     * Lives no statement gives, estimated (never «[ناقص]»): from the year's depreciation, or the kind's usual life. An info
     * check names them and how, until the accountant answers (P3). Those with last year's rates read are livesNotDerived's.
     */
    private function livesEstimated(array $lifeRows, array $categories, array $inputs): array
    {
        $byKey = array_column($categories, null, 'key');
        $rows = array_values(array_filter($lifeRows, fn ($r) => ($r['estimated'] ?? null) !== null && ! isset($inputs["useful_life.{$r['key']}"])
            && ! (isset($byKey[$r['key']]) && ($byKey[$r['key']]['rate'] !== null || $byKey[$r['key']]['rate_range'] !== null))));
        if ($rows === []) {
            return [];
        }

        return [$this->check('useful_life_estimated', 'info', null, null, null,
            ReportChecks::msg('useful_life_estimated', ['lives' => implode('، ', array_map(fn ($r) => $r['label'].' '.$r['years'].' '
                .__('report_messages.useful_life_basis.'.$r['estimated'], [], 'ar'), $rows))]),
            ['note' => 'ppe', 'input_key' => "useful_life.{$rows[0]['key']}"])];
    }

    /**
     * A category with additions this year and no depreciation at all: the additions' depreciation at last year's rate for
     * a full year, proposed (a warning), never posted; the accountant adds the entry, prorated by the addition's date.
     */
    private function additionsDepreciation(array $categories): array
    {
        $checks = [];
        foreach ($categories as $c) {
            $additions = (float) ($c['current']['additions'] ?? 0);
            if ($additions <= 0.005 || abs((float) ($c['current']['depreciation'] ?? 0)) > 0.005 || ! is_numeric($c['rate'] ?? null) || $c['rate'] <= 0) {
                continue;
            }
            $amount = round($additions * $c['rate'] / 100, 2);
            $checks[] = $this->check('ppe_additions_depreciation', 'warning', $amount, 0.0, $amount,
                ReportChecks::msg('ppe_additions_depreciation', ['category' => $c['label'], 'additions' => number_format($additions, 2),
                    'rate' => rtrim(rtrim(number_format((float) $c['rate'], 2), '0'), '.'), 'amount' => number_format($amount, 2)]),
                ['note' => 'ppe', 'line_key' => 'sofp.ppe', 'category' => $c['key'], 'amount' => $amount]);
        }

        return $checks;
    }

    /** Whether an account named as supplier finance (config `supplier_finance_words`) has a balance at the year end. */
    private function supplierFinance(ScheduleContext $ctx): bool
    {
        $words = array_map(fn ($w) => ' '.ImportNormalizer::text($w).' ', config('financial_statements.templates.'.$ctx->catalogue->version.'.supplier_finance_words', []));
        foreach ($ctx->rows as $r) {
            $name = ' '.ImportNormalizer::text((string) ($r['account_name'] ?? '')).' ';
            if (abs((float) ($ctx->profile->closing($r) ?? 0.0)) > 0.005 && array_filter($words, fn ($w) => str_contains($name, $w)) !== []) {
                return true;
            }
        }

        return false;
    }

    private function samePartyCheck(ScheduleContext $ctx, array $parties, array $inputs): array
    {
        $names = [];
        foreach ([...array_column($parties, 'name'), ...array_column(array_filter((array) ($inputs['partners'] ?? []), 'is_array'), 'name')] as $name) {
            $n = ImportNormalizer::text((string) $name);
            if (mb_strlen(str_replace(' ', '', $n)) >= 3) {
                $names[$n] = (string) $name;
            }
        }
        $accounts = [];
        $classes  = $ctx->catalogue->relatedPartyClasses();   // where a related party's accounts may sit (config related_parties)
        foreach ($ctx->rows as $n => $r) {
            if (in_array($r['main_class'] ?? null, $classes['main_classes'], true) || in_array($r['sub_class'] ?? null, $classes['sub_classes'], true)) {
                $accounts[$n] = ' '.ImportNormalizer::text((string) ($r['account_name'] ?? '')).' ';
            }
        }
        $checks = [];
        foreach ($names as $norm => $name) {
            $rows = array_keys(array_filter($accounts, fn ($a) => str_contains($a, " {$norm} ")));
            if (count($rows) > 1) {
                $checks[] = $this->check('same_party_multiple_accounts', 'info', 1, count($rows), count($rows) - 1,
                    ReportChecks::msg('same_party_multiple_accounts', ['name' => $name, 'count' => count($rows)]), ['rows' => $rows, 'name' => $name, 'note' => 'related_parties']);
            }
        }

        return $checks;
    }

    /**
     * Check 27: a statement line ≠ 0 whose note is hidden (forced off), and shown wording that refers to a hidden note
     * by number.
     */
    private function referenceChecks(array $config, array $meta, array $cur, array $cmp, array $refs, bool $firstYear, callable $nz): array
    {
        $checks = [];
        foreach ($config as $key => $cfg) {
            if ($meta[$key]['visible']) {
                continue;
            }
            foreach ($cfg['lines'] ?? [] as $l) {
                if ($nz($cur[$l] ?? null) || (! $firstYear && $nz($cmp[$l] ?? null))) {
                    $checks[] = $this->check('note_reference_missing', 'warning', 1, 0, -1,
                        ReportChecks::msg('note_reference_line', ['note' => __("statements.notes.{$key}", [], 'ar')]), ['line_key' => $l, 'note' => $key]);
                }
            }
        }
        foreach ($refs as $r) {
            if (isset($meta[$r['note']]) && ! $meta[$r['note']]['visible']) {
                $checks[] = $this->check('note_reference_missing', 'warning', 1, 0, -1,
                    ReportChecks::msg('note_reference_text', ['note' => __("statements.notes.{$r['note']}", [], 'ar')]), ['paragraph' => $r['paragraph'], 'note' => $r['note']]);
            }
        }

        return $checks;
    }

    /** The user's wording kept where the template's text has changed since (e.g. an amount moved). */
    private function outdatedChecks(array $notes): array
    {
        $checks = [];
        foreach ($notes as $n) {
            foreach ($n['blocks'] as $b) {
                if (($b['outdated'] ?? false) === true) {
                    $checks[] = $this->check('edited_text_outdated', 'warning', 0, 1, 1,
                        ReportChecks::msg('edited_text_outdated', ['note' => $n['title_ar']]), ['paragraph' => $b['key'], 'note' => $n['key']]);
                }
            }
        }

        return $checks;
    }

    private function check(string $code, string $severity, mixed $expected, mixed $actual, mixed $difference, \App\Support\Phrase $message, array $extra = []): array
    {
        return ReportChecks::make($code, $severity, $expected, $actual, $difference, $message, $extra);
    }
}
