<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * The statement of cash flows, indirect method (plan §3.5, Q25, Q36). Every balance-sheet line's change between the
 * start and the end of the year lands on one row, so the statement reaches the balance sheet's cash unless a figure
 * disagrees (check 12 says by how much). Displayed: inflows +, outflows −.
 *
 * - Non-cash items: the notes' depreciation and amortisation, the end-of-service and impairment charges (less
 *   reversals), finance cost, gains and losses on disposal, the share of associates.
 * - Working capital: each line's change (receivables before the impairment charge).
 * - End-of-service, zakat and income tax paid: the change in the provision less its charge.
 * - Investing: the notes' additions (CWIP apart), the disposal proceeds entered; the other investments' net change.
 * - Murabaha loans (Q36, profit paid in financing): drawn = gross credits − new deferred finance cost, repaid = gross
 *   debits; finance cost paid = finance cost − deferred finance cost amortised. Suggestions with movement columns, the
 *   user's otherwise (finance cost paid is suggested when nothing is deferred).
 * - Equity: the changes-in-equity movements. A dividend in the changes in equity is declared, not necessarily paid:
 *   paid = declared − credited to the partners' current accounts (a non-cash transfer to a liability) − the increase in
 *   dividends payable; what is paid out of the partners' current accounts is their change less those credits.
 * - Cash: the cash line, less the overdraft when the user counts it in cash (`cash_flow.overdraft_in_cash`, Q25);
 *   otherwise the overdraft's change is financing.
 * Without the balance sheet at the start of the year, every change is missing, and so is the statement's result.
 */
final class CashFlowBuilder
{
    public function __construct(private StatementBuilder $builder)
    {
    }

    /**
     * @param array<string, Figure>  $sofp      the current balance sheet's figures
     * @param ?array<string, Figure> $base      the balance sheet at the start of the year (zeros in a first year), null when unknown
     * @param array<string, Figure>  $is        the income statement's figures
     * @param list<array>            $schedules MovementSchedules
     * @param array<string, Figure>  $equity    EquityStatementBuilder movements
     * @param ?array<string, Figure> $comparative the prior year's cash flow lines, null in a first year
     */
    public function build(ScheduleContext $ctx, MovementSuggester $s, array $sofp, ?array $base, array $is, array $schedules, array $equity,
        Figure $disposalResult, array $inputs, bool $partnersInEquity, ?array $comparative, bool $unexplained = false): array
    {
        $zero  = fn () => new Figure(0.0, 'derived');
        $neg   = [AssetNotesBuilder::class, 'neg'];
        $sum   = fn (Figure ...$parts) => Figure::sum($parts);
        $delta = fn (string ...$lines) => $base === null ? Figure::missing()
            : Figure::sum(array_merge(...array_map(fn ($l) => [$sofp[$l], AssetNotesBuilder::neg($base[$l] ?? new Figure(0.0, 'derived'))], $lines)),
                array_map(fn ($l) => ['type' => 'line', 'key' => $l], $lines));
        $byKey = array_column($schedules, null, 'key');
        $cell  = fn (string $schedule, string $row, string $col = 'total') => isset($byKey[$schedule]) ? (MovementSchedules::cell($byKey[$schedule], $row, $col) ?? $zero()) : $zero();
        $where = fn (string $k) => ['schedule' => 'cash_flows', 'row' => $k, 'column' => 'amount',
            'label_ar' => 'قائمة التدفقات النقدية — '.collect($ctx->catalogue->statement('cash_flows'))->firstWhere('key', $k)['label_ar']];
        $l = [];

        // Operating.
        $l['cf.profit_before_zakat'] = $is['is.profit_before_zakat'];
        $l['cf.depreciation_ppe'] = $cell('ppe', 'depreciation');
        $l['cf.depreciation_investment_property'] = $cell('investment_property', 'depreciation');
        $l['cf.amortisation_intangibles'] = $cell('intangible_assets', 'depreciation');
        // Other assets (pre-operating expenses, net): the year's amortisation is their accumulated amortisation's increase
        // (its credit movement, else closing − opening; 0 when neither is known); what was paid is the rest of the change.
        $acc = $ctx->sum($ctx->rowsOf($ctx->catalogue->otherAssetsAmortisation()), 'credit');
        $l['cf.amortisation_other_assets'] = match (true) {
            $acc['increase'] !== null => new Figure(round($acc['increase'], 2), 'trial_balance', 'ok', $acc['refs']),
            $acc['opening'] !== null  => new Figure(round($acc['closing'] - $acc['opening'], 2), 'derived', 'ok', $acc['refs']),
            default                   => $zero(),
        };
        $l['cf.eosb_charge'] = $sum($cell('employee_benefits', 'charge', 'amount'), $neg($cell('employee_benefits', 'reversal', 'amount')));
        $l['cf.receivables_impairment'] = $sum($cell('receivables_impairment', 'charge', 'amount'), $neg($cell('receivables_impairment', 'reversal', 'amount')));
        $l['cf.inventory_writedown'] = $zero();   // inside the inventory line's change
        $l['cf.finance_cost'] = $neg($is['is.finance_cost']);
        $l['cf.disposal_gain_loss'] = $neg($disposalResult);
        $l['cf.fx_gain_loss'] = $zero();
        $l['cf.provisions_released'] = $zero();   // inside the other provisions' change
        $l['cf.share_of_associates'] = $neg($is['is.share_of_associates']);
        // What retained earnings lose besides the result, the transfers and dividends: a prior-year adjustment or another
        // entry, not cash paid (its other side is in the lines' changes); unexplained until the user says (P3).
        $l['cf.prior_year_adjustments'] = $neg($equity['prior_year_adjustments']);
        $l['cf.other_retained_movements'] = $neg($equity['other_retained_movements']);
        foreach (['inventory', 'contract_assets', 'prepayments_other_receivables', 'due_from_related_parties'] as $asset) {
            $l["cf.wc_{$asset}"] = $neg($delta("sofp.{$asset}"));
        }
        $l['cf.wc_trade_receivables'] = $sum($neg($delta('sofp.trade_receivables')), $neg($l['cf.receivables_impairment']));
        foreach (['trade_payables', 'accruals_other_payables', 'contract_liabilities', 'due_to_related_parties', 'other_provisions'] as $liability) {
            $l["cf.wc_{$liability}"] = $delta("sofp.{$liability}");
        }
        $l['cf.eosb_paid'] = $sum($delta('sofp.employee_benefits'), $neg($l['cf.eosb_charge']), $is['is.oci_actuarial']->isMissing() ? $zero() : $is['is.oci_actuarial']);
        $l['cf.zakat_paid'] = $sum($delta('sofp.zakat_provision'), $is['is.zakat']);
        $l['cf.income_tax_paid'] = $sum($delta('sofp.income_tax_provision'), $is['is.income_tax']);

        // Murabaha loans (Q36): profit paid in financing.
        $loanRows = [];
        foreach (['sofp.murabaha_loans_non_current', 'sofp.murabaha_loans_current'] as $line) {
            $loanRows += $ctx->rowsOn($line);
        }
        $deferredRows = array_intersect_key($loanRows, $ctx->tagged('deferred_finance_cost'));
        $gross    = $ctx->sum(array_diff_key($loanRows, $deferredRows), 'credit');
        $deferred = $ctx->sum($deferredRows, 'debit');
        $financeCost = $l['cf.finance_cost'];
        $amortised   = $ctx->movements() ? $deferred['decrease'] : ($deferredRows === [] ? 0.0 : null);
        $l['cf.finance_cost_paid'] = match (true) {
            $financeCost->isMissing()                     => Figure::missing(),
            abs($financeCost->amount) < 0.005             => $zero(),
            $amortised !== null                           => $s->suggest('cf.finance_cost_paid', -($financeCost->amount - $amortised),
                Phrase::make('report_messages.basis.finance_cost_paid'), $deferred['refs'], $where('cf.finance_cost_paid')),
            default                                       => $s->entered('cf.finance_cost_paid'),
        };
        if ($loanRows === []) {
            $l['cf.loans_drawn'] = $l['cf.loans_repaid'] = $zero();
        } elseif ($ctx->movements()) {
            $l['cf.loans_drawn'] = $s->suggest('cf.loans_drawn', $gross['increase'] - $deferred['increase'],
                Phrase::make('report_messages.basis.loans_drawn'), [...$gross['refs'], ...$deferred['refs']], $where('cf.loans_drawn'));
            $l['cf.loans_repaid'] = $s->suggest('cf.loans_repaid', -$gross['decrease'], Phrase::make('report_messages.basis.loans_repaid'), $gross['refs'], $where('cf.loans_repaid'));
        } else {
            $l['cf.loans_drawn'] = $s->entered('cf.loans_drawn');
            $l['cf.loans_repaid'] = $s->entered('cf.loans_repaid');
        }

        // Investing.
        $cwipAdditions = isset($byKey['ppe']) && in_array('cwip', array_column($byKey['ppe']['columns'], 'key'), true) && $byKey['ppe']['split']['cost']
            ? $cell('ppe', 'additions', 'cwip') : $zero();
        $l['cf.ppe_purchases'] = $neg($sum($cell('ppe', 'additions'), $neg($cwipAdditions)));
        $l['cf.cwip_payments'] = $neg($cwipAdditions);
        foreach (['ppe' => 'cf.ppe_disposal_proceeds', 'investment_property' => 'cf.investment_property_disposal_proceeds', 'intangible_assets' => 'cf.intangible_disposal_proceeds'] as $schedule => $key) {
            $disposals = $cell($schedule, 'disposals');
            $proceeds  = $inputs["{$schedule}.disposal_proceeds"] ?? null;
            $l[$key] = match (true) {
                $disposals->isMissing()                  => Figure::missing(),
                $disposals->amount <= 0.005               => $zero(),
                $proceeds === null                       => Figure::missing(),
                default                                  => new Figure((float) $proceeds, 'user_input', 'ok', [['type' => 'input', 'key' => "{$schedule}.disposal_proceeds"]]),
            };
        }
        $l['cf.investment_property_purchases'] = $neg($cell('investment_property', 'additions'));
        $l['cf.intangible_purchases'] = $neg($cell('intangible_assets', 'additions'));
        $l['cf.other_assets_payments'] = $neg($sum($delta('sofp.other_assets'), $l['cf.amortisation_other_assets']));
        $equityNet = $neg($sum($delta('sofp.fvoci_investments'), $is['is.oci_fvoci']->isMissing() ? $zero() : $neg($is['is.oci_fvoci'])));
        $l['cf.equity_investment_purchases'] = $equityNet->isMissing() ? $equityNet : ($equityNet->amount < 0 ? $equityNet : $zero());
        $l['cf.equity_investment_proceeds'] = $equityNet->isMissing() ? $equityNet : ($equityNet->amount > 0 ? $equityNet : $zero());
        $l['cf.associates_investment'] = $neg($sum($delta('sofp.associates'), $neg($is['is.share_of_associates']), $is['is.oci_associates']->isMissing() ? $zero() : $neg($is['is.oci_associates'])));
        $l['cf.amortised_cost_investments'] = $neg($delta('sofp.amortised_cost_non_current', 'sofp.amortised_cost_current'));
        $l['cf.murabaha_deposits'] = $neg($delta('sofp.murabaha_deposits'));

        // Financing.
        $partnersRows = $ctx->rowsOn('sofp.partners_current_liability');
        $dividends    = $equity['dividends'];
        if ($partnersInEquity) {
            $noncash = $equity['transfer_to_partners_current'];
        } elseif ($partnersRows === [] || $dividends->isMissing() || abs($dividends->amount) < 0.005) {
            $noncash = $zero();
        } elseif ($ctx->movements()) {
            $credits = $ctx->sum($partnersRows, 'credit')['increase'];
            $noncash = $s->suggest('cf.noncash_distributions_to_partners', min($dividends->amount, $credits),
                Phrase::make('report_messages.basis.noncash_distributions'), $ctx->sum($partnersRows, 'credit')['refs'], $where('cf.noncash_distributions_to_partners'));
        } elseif (($p = $ctx->sum($partnersRows, 'credit'))['opening'] !== null) {
            // Opening and closing only: as much of the dividend as the accounts grew by (what is paid out of them is the rest).
            $noncash = $s->suggest('cf.noncash_distributions_to_partners', min($dividends->amount, max(0.0, $p['closing'] - $p['opening'])),
                Phrase::make('report_messages.basis.noncash_distributions_change'), $p['refs'], $where('cf.noncash_distributions_to_partners'));
        } else {
            $noncash = $s->entered('cf.noncash_distributions_to_partners');
        }
        $l['cf.noncash_distributions_to_partners'] = $noncash;
        $l['cf.lease_payments'] = $delta('sofp.lease_liabilities_non_current', 'sofp.lease_liabilities_current');
        $l['cf.capital_increase'] = $equity['capital_increase'];
        $l['cf.partners_current'] = $partnersInEquity ? $equity['partners_current_movements']
            : $sum($delta('sofp.partners_current_liability'), $neg($noncash));
        $l['cf.due_to_related_parties_non_current'] = $delta('sofp.due_to_related_parties_non_current');
        // Dividends paid: declared − those credited to the partners (a liability) − the increase in dividends payable. A
        // transfer to the partners' current accounts in equity is not a dividend.
        $l['cf.dividends_paid'] = $sum($delta('sofp.dividends_payable'), $neg($dividends), $partnersInEquity ? $zero() : $noncash);
        $inCash = ($inputs['cash_flow.overdraft_in_cash'] ?? false) === true;
        $l['cf.bank_overdraft'] = $inCash ? $zero() : $delta('sofp.bank_overdraft');

        // Cash at the start of the year.
        $l['cf.opening_cash'] = $base === null ? Figure::missing()
            : Figure::sum([$base['sofp.cash'], $inCash ? AssetNotesBuilder::neg($base['sofp.bank_overdraft']) : $zero()], [['type' => 'line', 'key' => 'sofp.cash']]);

        $built = $this->builder->build($ctx->catalogue->statement('cash_flows'), $l, [], $comparative);
        if ($unexplained) {
            $built['rows'] = array_map(fn ($r) => ($r['key'] ?? null) === 'cf.other_retained_movements'
                ? ['label_ar' => __('statements.unexplained_retained_movement', [], 'ar'), 'label_set' => true] + $r : $r, $built['rows']);
        }
        $built['cash'] = $inCash ? Figure::sum([$sofp['sofp.cash'], AssetNotesBuilder::neg($sofp['sofp.bank_overdraft'])]) : $sofp['sofp.cash'];

        return $built;
    }
}
