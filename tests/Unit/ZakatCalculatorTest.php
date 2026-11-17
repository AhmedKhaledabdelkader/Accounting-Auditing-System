<?php

namespace Tests\Unit;

use App\Services\Statements\Figure;
use App\Services\Statements\ZakatCalculator;
use PHPUnit\Framework\TestCase;

/** The zakat and income tax calculation: the accountants' sheet (أحتساب الزكاه) under the Zakat Collection Regulation 1445. */
class ZakatCalculatorTest extends TestCase
{
    private const PERIOD = ['start' => '2025-01-01', 'end' => '2025-12-31'];

    /** @param array<string, float> $figures line key → amount */
    private function statements(array $figures): array
    {
        $rows = ['financial_position' => [], 'comprehensive_income' => []];
        foreach ($figures as $key => $amount) {
            $rows[str_starts_with($key, 'is.') ? 'comprehensive_income' : 'financial_position'][] = ['type' => 'line', 'key' => $key, 'label_ar' => $key,
                'current' => new Figure($amount, 'trial_balance')];
        }

        return $rows;
    }

    /** The sheet's own example: capital 100,000, retained earnings 1,559,154.42, a related party's 2,124,537.01, fixed assets 651,609.20. */
    private function sheet(array $extra = []): array
    {
        return $extra + [
            'sofp.share_capital' => 100000.0, 'sofp.retained_earnings' => 1559154.42, 'sofp.due_to_related_parties' => 2124537.01,
            'sofp.trade_payables' => 259227.93, 'sofp.accruals_other_payables' => 327443.77,
            'sofp.ppe' => 651609.20,
            'sofp.trade_receivables' => 456983.84, 'sofp.prepayments_other_receivables' => 30000.01, 'sofp.inventory' => 1221681.49, 'sofp.cash' => 2074526.36,
            'is.profit_before_zakat' => 1623592.16,
        ];
    }

    public function test_it_reproduces_the_sheet(): void
    {
        $z = ZakatCalculator::of($this->statements($this->sheet()), [], self::PERIOD);
        $this->assertSame(3783691.43, $z['equity']['total']);
        $this->assertSame(3132082.23, $z['base']['total']);
        $this->assertSame(1623592.16, $z['minimum']['total']);     // the lower of the current assets and the adjusted profit
        $this->assertSame(3783691.43, $z['maximum']['total']);
        $this->assertSame(3132082.23, $z['final_base']);
        $this->assertSame('base', $z['binding']);
        $this->assertSame(365, $z['period']['days']);
        $this->assertSame(80735.17, $z['zakat']['amount']);        // 3,132,082.23 × 2.5% × 365 ÷ 354, as the sheet's D32
        $this->assertSame(0.0, $z['income_tax']['amount']);        // all Saudi
    }

    public function test_what_the_report_charged_already_changes_nothing(): void
    {
        $plain = ZakatCalculator::of($this->statements($this->sheet()), [], self::PERIOD);
        // The year's zakat charged: retained earnings after it, its provision, its expense (negative as the statement shows it).
        $booked = $this->sheet(['sofp.retained_earnings' => 1559154.42 - 80735.17, 'sofp.zakat_provision' => 80735.17, 'is.zakat' => -80735.17]);
        $z = ZakatCalculator::of($this->statements($booked), [], self::PERIOD, ['zakat_source' => 'dialog', 'applied' => ['zakat' => 80735.17]]);
        $this->assertSame($plain['final_base'], $z['final_base']);
        $this->assertSame($plain['zakat']['amount'], $z['zakat']['amount']);
        $this->assertSame([80735.17, 'dialog', 80735.17], [$z['charged']['zakat'], $z['charged']['zakat_source'], $z['applied']['zakat']]);
        $this->assertTrue($z['can_apply']['zakat']);   // the dialog's own charge may be updated
        $locked = ZakatCalculator::of($this->statements($booked), [], self::PERIOD, ['zakat_source' => 'trial_balance']);
        $this->assertFalse($locked['can_apply']['zakat']);   // the trial balance's is never charged twice
    }

    public function test_the_minimum_base_holds_when_the_base_is_lower(): void
    {
        $figures = ['sofp.share_capital' => 100000.0, 'sofp.ppe' => 95000.0, 'sofp.trade_payables' => 20000.0, 'sofp.cash' => 25000.0, 'is.profit_before_zakat' => 40000.0];
        $z = ZakatCalculator::of($this->statements($figures), [], self::PERIOD);
        $this->assertSame(5000.0, $z['base']['total']);
        $this->assertSame([25000.0, 'minimum'], [$z['final_base'], $z['binding']]);   // the undeducted assets, below the profit
    }

    public function test_a_negative_base_without_a_profit_has_no_zakat(): void
    {
        $figures = ['sofp.share_capital' => 100000.0, 'sofp.retained_earnings' => -150000.0, 'sofp.cash' => 10000.0, 'sofp.trade_payables' => 60000.0 - 60000.0 + 1.0,
            'is.profit_before_zakat' => -5000.0];
        $z = ZakatCalculator::of($this->statements($figures), [], self::PERIOD);
        $this->assertSame([0.0, 'none', 0.0], [$z['final_base'], $z['binding'], $z['zakat']['amount']]);
    }

    public function test_the_allocation_brings_current_liabilities_in_by_the_deducted_asset(): void
    {
        $figures = ['sofp.share_capital' => 1000000.0, 'sofp.ppe' => 600000.0, 'sofp.inventory' => 900000.0, 'sofp.trade_payables' => 500000.0,
            'sofp.murabaha_loans_non_current' => 200000.0, 'is.profit_before_zakat' => 100000.0];
        $z = ZakatCalculator::of($this->statements($figures), ['deducted_current_assets' => 300000.0], self::PERIOD);
        // A third of the current assets deducted: a third of the current liabilities added (less than the asset itself).
        $this->assertSame(166666.67, $z['allocation']['current_liabilities_added']);
        $this->assertSame(466666.67, $z['base']['total']);   // 1,000,000 + (200,000 + 166,666.67) − (600,000 + 300,000)
        $this->assertSame(466666.67, $z['final_base']);
    }

    public function test_the_foreign_share_pays_income_tax_after_the_losses_within_a_quarter(): void
    {
        $figures = $this->sheet(['is.profit_before_zakat' => 1000000.0]);
        $z = ZakatCalculator::of($this->statements($figures), ['saudi_share' => 60.0, 'loss_carryforward' => 500000.0], self::PERIOD);
        // 40% of 1,000,000; the losses' 40% (200,000) limited to a quarter of it (100,000); 20% of the rest.
        $this->assertSame([400000.0, 100000.0, 300000.0, 60000.0], [$z['income_tax']['profit'], $z['income_tax']['loss_offset'], $z['income_tax']['taxable'], $z['income_tax']['amount']]);
        $this->assertSame(round($z['final_base'] * 0.025 * 365 / 354 * 0.6, 2), $z['zakat']['amount']);   // zakat on the Saudi share only
    }

    public function test_the_answers_are_checked(): void
    {
        $this->assertSame([], ZakatCalculator::errors(['saudi_share' => 50, 'loss_carryforward' => null, 'related_as_liability' => true, 'deduct_lines' => ['sofp.ppe']]));
        $this->assertSame(['saudi_share' => 'zakat_input_share', 'profit_additions' => 'zakat_input_amount', 'related_as_liability' => 'boolean',
            'deduct_lines' => 'zakat_input_lines', 'nope' => 'zakat_input_unknown'],
            ZakatCalculator::errors(['saudi_share' => 101, 'profit_additions' => -1, 'related_as_liability' => 'yes', 'deduct_lines' => ['sofp.cash'], 'nope' => 1]));
    }
}
