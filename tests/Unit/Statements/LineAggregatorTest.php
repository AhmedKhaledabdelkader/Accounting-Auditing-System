<?php

namespace Tests\Unit\Statements;

use App\Services\Statements\LineAggregator;
use App\Services\Statements\LineMapper;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

class LineAggregatorTest extends TestCase
{
    use ClassifiedRows;

    private function lines(array $rows, array $groups = ['opening', 'period', 'closing'], array $options = []): array
    {
        $profile = $this->profile($rows, $groups);
        return app(LineAggregator::class)->aggregate($rows, app(LineMapper::class)->map($rows, $profile, $options), $profile);
    }

    public function test_amounts_are_signed_by_the_line_side(): void
    {
        $lines = $this->lines([
            $this->account(2, 'عملاء', ['opening' => 900, 'period' => [400, 300]]),
            $this->account(3, 'مخصص ديون مشكوك في تحصيلها', ['opening' => -100, 'period' => [0, 50]]),
            $this->account(4, 'موردون', ['opening' => -700, 'period' => [200, 260]]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [10, 1000]]),
            $this->account(6, 'مردودات ومسموحات المبيعات', ['period' => [40, 0]]),
        ]);

        $receivables = $lines['sofp.trade_receivables'];
        $this->assertSame([850.0, 'trial_balance', 'ok'], [$receivables['closing']->amount, $receivables['closing']->origin, $receivables['closing']->status]);
        $this->assertSame([800.0, 'trial_balance_opening'], [$receivables['opening']->amount, $receivables['opening']->origin]);
        $this->assertSame([[], [2, 3]], [$receivables['wrong_side'], $receivables['rows']]);   // the allowance is a contra, not a wrong side
        $this->assertSame([['type' => 'row', 'row' => 2], ['type' => 'row', 'row' => 3]], $receivables['closing']->refs);
        $this->assertSame([400.0, 350.0], [$receivables['period']['debit']->amount, $receivables['period']['credit']->amount]);
        $this->assertNull($receivables['adjustment']);

        $this->assertSame(760.0, $lines['sofp.trade_payables']['closing']->amount);
        $this->assertSame(950.0, $lines['is.revenue']['closing']->amount);   // 990 sales − 40 returns, credit-positive
    }

    public function test_wrong_side_rows_are_listed_not_netted_away(): void
    {
        $lines = $this->lines([
            $this->account(2, 'عملاء', ['closing' => 1000]),
            $this->account(3, 'عملاء', ['closing' => -150]),                // a customer in credit
            $this->account(4, 'مخصص ديون مشكوك في تحصيلها', ['closing' => 20]), // an allowance in debit
        ], ['closing']);

        $receivables = $lines['sofp.trade_receivables'];
        $this->assertSame(870.0, $receivables['closing']->amount);
        $this->assertSame([['row' => 3, 'group' => 'closing', 'amount' => -150.0], ['row' => 4, 'group' => 'closing', 'amount' => 20.0]], $receivables['wrong_side']);
    }

    public function test_accumulated_amortisation_and_accumulated_losses_are_not_on_the_wrong_side(): void
    {
        $lines = $this->lines([
            $this->account(2, 'أصول أخرى', ['closing' => 500]),
            $this->account(3, 'مجمع إطفاء أصول أخرى', ['closing' => -200]),   // a contra, its class's sides tied one to one
            $this->account(4, 'أرباح أو خسائر سنوات سابقة', ['closing' => 900]),   // accumulated losses, in debit
            $this->account(5, 'رأس المال', ['closing' => -2000]),
        ], ['closing']);

        $this->assertSame([300.0, []], [$lines['sofp.other_assets']['closing']->amount, $lines['sofp.other_assets']['wrong_side']]);
        $this->assertSame([-900.0, []], [$lines['sofp.retained_earnings']['closing']->amount, $lines['sofp.retained_earnings']['wrong_side']]);
    }

    public function test_layouts_give_only_what_the_file_has(): void
    {
        $rows = [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [100, 100], 'closing' => 100]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'period' => [0, 0], 'closing' => -100]),
        ];

        $c = $this->lines($rows, ['closing'])['sofp.cash'];
        $this->assertSame([100.0, null, null], [$c['closing']->amount, $c['opening'], $c['period']]);

        $oc = $this->lines($rows, ['opening', 'closing'])['sofp.cash'];
        $this->assertSame([100.0, 100.0, null], [$oc['closing']->amount, $oc['opening']->amount, $oc['period']]);

        $omc = $this->lines($rows, ['opening', 'period', 'adjustment', 'closing'])['sofp.cash'];
        $this->assertSame([100.0, 100.0], [$omc['period']['debit']->amount, $omc['period']['credit']->amount]);
        $this->assertSame([0.0, 0.0], [$omc['adjustment']['debit']->amount, $omc['adjustment']['credit']->amount]);
    }

    public function test_a_side_rule_splits_a_bank_between_years(): void
    {
        $lines = $this->lines([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => -300, 'closing' => 200]),
            $this->account(3, 'نقدية بالصندوق', ['opening' => 50, 'closing' => 60]),
        ], ['opening', 'closing']);

        $this->assertSame([260.0, 50.0], [$lines['sofp.cash']['closing']->amount, $lines['sofp.cash']['opening']->amount]);
        $this->assertSame([0.0, 300.0], [$lines['sofp.bank_overdraft']['closing']->amount, $lines['sofp.bank_overdraft']['opening']->amount]);
        $this->assertSame([], $lines['sofp.bank_overdraft']['wrong_side']);   // an overdraft on its own line is not a wrong side
    }

    public function test_shares_and_pending_rows_carry_through(): void
    {
        $lines = $this->lines([
            $this->account(2, 'أجور ورواتب', ['period' => [1000, 0]]),
            $this->account(3, 'إيجارات', ['period' => [300, 0]], ['data_flags' => [['type' => 'statement_mismatch', 'severity' => 'high', 'message' => 'm']]]),
        ], options: ['overrides' => [2 => ['is.cost_of_revenue' => 0.25, 'is.general_admin' => 0.75]]]);

        $this->assertSame([250.0, [['type' => 'row', 'row' => 2, 'share' => 0.25]]], [$lines['is.cost_of_revenue']['closing']->amount, $lines['is.cost_of_revenue']['closing']->refs]);
        $this->assertSame(1050.0, $lines['is.general_admin']['closing']->amount);
        $this->assertSame('pending_confirmation', $lines['is.general_admin']['closing']->status);
        $this->assertSame('ok', $lines['is.cost_of_revenue']['closing']->status);
    }

    public function test_unmapped_and_excluded_rows_stay_out_of_every_line(): void
    {
        $lines = $this->lines([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100]),
            $this->account(3, 'نقدية لدى البنوك', ['closing' => 100], ['data_flags' => [['type' => 'possible_parent', 'message' => 'm']]]),
            $this->account(4, null, ['closing' => 9]),
            $this->account(5, 'استثمارات طويلة الأجل', ['closing' => 40]),
        ], ['closing']);

        $this->assertSame(['sofp.cash'], array_keys($lines));
        $this->assertSame([100.0, [2]], [$lines['sofp.cash']['closing']->amount, $lines['sofp.cash']['rows']]);
    }
}
