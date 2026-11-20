<?php

namespace Tests\Unit\Statements;

use App\Models\ClassificationRun;
use App\Services\Statements\CurrentYearStatements;
use App\Services\Statements\Figure;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

class CurrentYearStatementsTest extends TestCase
{
    use ClassifiedRows;

    /** The report's answers default to no OCI, zakat confirmed zero and a first year, so each test sees only what it sets up. */
    private function build(array $rows, array $groups = ['opening', 'period', 'closing'], array $options = []): array
    {
        $run = (new ClassificationRun)->forceFill(['status' => 'done', 'result' => $this->runResult($rows, $groups)]);
        $options['inputs'] = ($options['inputs'] ?? []) + ['oci.has_items' => false, 'zakat.zero_reason' => 'لا وعاء زكوي'];
        $options['prior'] ??= ['source' => 'first_year', 'fixed' => true];   // not the opening-balances rule: tested on its own
        if (array_key_exists('zakat.charge', $options['inputs'])) {
            unset($options['inputs']['zakat.zero_reason']);
        }

        return app(CurrentYearStatements::class)->build($run, 'sa-ifrs-smes-3', $options);
    }

    private function figure(array $built, string $key): Figure
    {
        foreach (array_diff_key($built['statements'], ['changes_in_equity' => 1]) as $rows) {
            foreach ($rows as $r) {
                if (($r['key'] ?? null) === $key) {
                    return $r['current'];
                }
            }
        }
        $this->fail("no {$key}");
    }

    private function codes(array $built): array
    {
        return array_map(fn ($c) => [$c['code'], $c['severity']], $built['checks']);
    }

    /** Cash 350 = capital 100 + suppliers 50 + the year's profit 200 (sales 500 − rent 300), pre-closing. */
    private function balanced(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [550, 300]]),
            $this->account(3, 'رأس المال', ['opening' => -100]),
            $this->account(4, 'إيرادات المبيعات', ['period' => [0, 500]]),
            $this->account(5, 'إيجارات', ['period' => [300, 0]]),
            $this->account(8, 'موردون', ['period' => [0, 50]]),
        ];
    }

    public function test_a_balanced_pre_closing_trial_balance(): void
    {
        $built = $this->build($this->balanced());

        $this->assertSame([500.0, -300.0, 200.0, 200.0], [$this->figure($built, 'is.revenue')->amount, $this->figure($built, 'is.general_admin')->amount,
            $this->figure($built, 'is.operating_profit')->amount, $this->figure($built, 'is.net_profit')->amount]);
        $re = $this->figure($built, 'sofp.retained_earnings');
        $this->assertSame([200.0, 'derived'], [$re->amount, $re->origin]);
        $this->assertContains(['type' => 'line', 'key' => 'is.net_profit'], $re->refs);
        $this->assertSame([350.0, 350.0, 100.0], [$this->figure($built, 'sofp.total_assets')->amount,
            $this->figure($built, 'sofp.total_equity_and_liabilities')->amount, $this->figure($built, 'sofp.share_capital')->amount]);
        $this->assertSame([0.0, 'trial_balance'], [$this->figure($built, 'sofp.inventory')->amount, $this->figure($built, 'sofp.inventory')->origin]);
        $this->assertSame([], $built['checks']);
        $this->assertSame([], $built['missing']);

        // The same from a closing-only file.
        $closing = array_map(fn ($r) => $this->account($r['row'], $r['sub_class'], ['closing' => $r['closing_debit'] - $r['closing_credit']]), $this->balanced());
        $c = $this->build($closing, ['closing']);
        $this->assertSame([200.0, 350.0, []], [$this->figure($c, 'is.net_profit')->amount, $this->figure($c, 'sofp.total_equity_and_liabilities')->amount, $c['checks']]);
    }

    public function test_an_unbalanced_trial_balance_and_unmapped_accounts(): void
    {
        $rows   = $this->balanced();
        $rows[0] = $this->account(2, 'نقدية لدى البنوك', ['closing' => 310]);
        $rows[]  = $this->account(6, 'استثمارات طويلة الأجل', ['closing' => 40]);   // bought from cash; no line until the user picks one
        $rows[]  = $this->account(7, null, ['closing' => 9]);                         // unclassified, with no counterpart
        $built   = $this->build($rows, ['closing']);

        $codes = $this->codes($built);
        $this->assertContains(['tb_unbalanced', 'error'], $codes);
        $this->assertContains(['unclassified_rows', 'error'], $codes);
        $this->assertContains(['unmapped_accounts', 'error'], $codes);
        $unmapped = collect($built['checks'])->firstWhere('code', 'unmapped_accounts');
        $this->assertSame([[6, 7], 49.0], [$unmapped['rows'], $unmapped['amount']]);
        $tb = collect($built['checks'])->firstWhere('code', 'tb_unbalanced');
        $this->assertSame(['closing', 9.0], [$tb['group'], $tb['difference']]);
        // The unmapped investment is left out of every line, so the assets fall short: reported, never forced.
        $sofp = collect($built['checks'])->firstWhere('code', 'sofp_unbalanced');
        $this->assertSame([350.0, 310.0, -40.0], [$sofp['expected'], $sofp['actual'], $sofp['difference']]);
    }

    public function test_a_post_closing_trial_balance_has_no_income_statement(): void
    {
        $built = $this->build([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 350]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(7, 'موردون', ['closing' => -50]),
            $this->account(4, 'أرباح محتجزة', ['closing' => -200]),       // the year's profit already closed into it
            $this->account(5, 'إيرادات المبيعات', ['closing' => 0]),
            $this->account(6, 'إيجارات', ['closing' => 0]),
        ], ['closing']);

        $this->assertTrue($this->figure($built, 'is.revenue')->isMissing());
        $this->assertTrue($this->figure($built, 'is.net_profit')->isMissing());
        $this->assertSame([200.0, 'trial_balance'], [$this->figure($built, 'sofp.retained_earnings')->amount, $this->figure($built, 'sofp.retained_earnings')->origin]);
        $this->assertSame(350.0, $this->figure($built, 'sofp.total_equity_and_liabilities')->amount);
        $this->assertSame([['post_closing_tb', 'error']], $this->codes($built));
        // No income statement: the year's dividends can't be read from the change in retained earnings; the user's.
        $this->assertSame([['key' => 'comprehensive_income', 'reason' => 'post_closing_tb', 'input_key' => null],
            ['key' => 'soce.dividends', 'reason' => 'movement_required', 'input_key' => null]], $built['missing']);
        $this->assertSame([], array_column($built['intake'], 'key'));   // no zakat or OCI to ask for without an income statement (approval: the notes')
    }

    /**
     * Post-closing with movements (Q22, decided 2026-10-14): cash 100 → 280 (sales 500, rent 300, a distribution of 20);
     * the P&L accounts' closing is 0, their result closed into retained earnings within the movement columns.
     */
    private function closedWithMovements(array $retained, array $pl = [[0, 500], [300, 0]], int $distribution = 20): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [$pl[0][1] + $pl[1][1], $pl[0][0] + $pl[1][0] + $distribution]]),
            $this->account(3, 'رأس المال', ['opening' => -50, 'closing' => -50]),
            $this->account(7, 'موردون', ['opening' => -20, 'closing' => -20]),
            ...$retained,
            $this->account(5, 'إيرادات المبيعات', ['period' => $pl[0], 'closing' => 0]),
            $this->account(6, 'إيجارات', ['period' => $pl[1], 'closing' => 0]),
        ];
    }

    public function test_post_closing_with_the_profit_as_the_retained_earnings_credit_movement_builds_the_income_statement(): void
    {
        // Retained earnings: credited 200 by the closing entry, debited 20 by a distribution (net change 180).
        $built = $this->build($this->closedWithMovements([$this->account(4, 'أرباح محتجزة', ['opening' => -30, 'period' => [20, 200]])]));

        $this->assertSame([true, 'movement'], [$built['profile']->plFromMovement, $built['profile']->closingEntry]);
        $this->assertFalse($built['profile']->postClosing);
        $this->assertSame([500.0, -300.0, 200.0], [$this->figure($built, 'is.revenue')->amount, $this->figure($built, 'is.general_admin')->amount, $this->figure($built, 'is.net_profit')->amount]);
        // Retained earnings already hold the profit: not added twice.
        $this->assertSame(210.0, $this->figure($built, 'sofp.retained_earnings')->amount);
        $this->assertSame([280.0, 280.0], [$this->figure($built, 'sofp.total_assets')->amount, $this->figure($built, 'sofp.total_equity_and_liabilities')->amount]);
        $codes = $this->codes($built);
        $this->assertNotContains(['post_closing_tb', 'error'], $codes);
        $this->assertContains(['pl_from_movements', 'info'], $codes);
        $this->assertNotContains(['net_income_mismatch', 'error'], $codes);
        $this->assertNotContains(['sofp_unbalanced', 'error'], $codes);
        $info = collect($built['checks'])->firstWhere('code', 'pl_from_movements');
        $this->assertStringContainsString('صافي الربح (200.00) يساوي تمامًا الحركة الدائنة على الأرباح المبقاة', $info['message_ar']);
    }

    public function test_a_parent_p_and_l_row_is_not_counted_in_the_profit_from_movements(): void
    {
        // Row 8 is the revenue's parent by the file's levels: counted, the profit would be 700 and match nothing.
        $parent = $this->account(8, 'إيرادات المبيعات', ['period' => [0, 500], 'closing' => 0], ['data_flags' => [['type' => 'parent_level', 'difference' => null, 'rows' => [5]]]]);
        $built = $this->build([...$this->closedWithMovements([$this->account(4, 'أرباح محتجزة', ['opening' => -30, 'period' => [20, 200]])]), $parent]);

        $this->assertSame([true, 200.0], [$built['profile']->plFromMovement, $built['profile']->movementProfit]);
        $this->assertSame(200.0, $this->figure($built, 'is.net_profit')->amount);
    }

    public function test_other_assets_net_of_their_amortisation_and_their_cash_flow(): void
    {
        // Pre-operating expenses 50 → 70 (20 paid), their amortisation 10 → 15 (5 this year, in rent here for simplicity).
        $built = $this->build([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [0, 20]]),
            $this->account(3, 'أصول أخرى', ['opening' => 50, 'period' => [20, 0]]),
            $this->account(4, 'مجمع إطفاء أصول أخرى', ['opening' => -10, 'period' => [0, 5]]),
            $this->account(5, 'رأس المال', ['opening' => -140]),
            $this->account(6, 'إيجارات', ['period' => [5, 0]]),
        ]);

        $this->assertSame([55.0, 55.0], [$this->figure($built, 'sofp.other_assets')->amount, $this->figure($built, 'sofp.total_non_current_assets')->amount]);
        $this->assertSame([5.0, -20.0, -20.0], [$this->figure($built, 'cf.amortisation_other_assets')->amount,
            $this->figure($built, 'cf.other_assets_payments')->amount, $this->figure($built, 'cf.net_change')->amount]);
        $this->assertNotContains('cash_flow_closing_mismatch', array_column($built['checks'], 'code'));
    }

    public function test_a_loss_is_compared_with_the_debit_movement(): void
    {
        // Sales 100, rent 300: a loss of 200 debited to retained earnings; no distribution.
        $built = $this->build($this->closedWithMovements([$this->account(4, 'أرباح محتجزة', ['opening' => -300, 'period' => [200, 0]])], [[0, 100], [300, 0]], 0));

        $this->assertTrue($built['profile']->plFromMovement);
        $this->assertSame(-200.0, $this->figure($built, 'is.net_profit')->amount);
        $this->assertStringContainsString('صافي الخسارة (200.00) يساوي تمامًا الحركة المدينة', collect($built['checks'])->firstWhere('code', 'pl_from_movements')['message_ar']);
    }

    public function test_the_current_year_profit_account_alone_may_match(): void
    {
        // The distribution is on the prior years' account, the closing entry on the current-year account.
        $built = $this->build($this->closedWithMovements([
            $this->account(4, 'أرباح أو خسائر سنوات سابقة', ['opening' => -30, 'period' => [20, 0]]),
            $this->account(8, 'أرباح أو خسائر العام الحالي', ['opening' => 0, 'period' => [0, 200]]),
        ]));
        $this->assertTrue($built['profile']->plFromMovement);
        $this->assertSame(200.0, $this->figure($built, 'is.net_profit')->amount);
    }

    /** The closing entry posted after the movement columns: only the closing balance holds it (the gap). */
    public function test_the_closing_entry_found_in_the_closing_balance(): void
    {
        // Profit 200: retained earnings 30 → 230 credit, nothing in their movement columns.
        $built = $this->build($this->closedWithMovements([$this->account(4, 'أرباح محتجزة', ['opening' => -30, 'closing' => -230])], distribution: 0));
        $this->assertSame([true, 'closing_gap', 200.0], [$built['profile']->plFromMovement, $built['profile']->closingEntry, $this->figure($built, 'is.net_profit')->amount]);
        $this->assertSame(230.0, $this->figure($built, 'sofp.retained_earnings')->amount);
        $this->assertNotContains(['sofp_unbalanced', 'error'], $this->codes($built));
        $this->assertStringContainsString('وُجد قيد الإقفال في الرصيد الختامي', collect($built['checks'])->firstWhere('code', 'pl_from_movements')['message_ar']);

        // Loss 200: a debit gap of 200.
        $built = $this->build($this->closedWithMovements([$this->account(4, 'أرباح محتجزة', ['opening' => -300, 'closing' => -100])], [[0, 100], [300, 0]], 0));
        $this->assertSame([true, 'closing_gap', -200.0], [$built['profile']->plFromMovement, $built['profile']->closingEntry, $this->figure($built, 'is.net_profit')->amount]);
        $this->assertStringContainsString('صافي الخسارة (200.00) يساوي تمامًا الفرق المدين', collect($built['checks'])->firstWhere('code', 'pl_from_movements')['message_ar']);

        // A distribution of 20 in the movement columns, the closing entry (credit 200) in the closing balance: 30 − 20 + 200.
        $built = $this->build($this->closedWithMovements([$this->account(4, 'أرباح محتجزة', ['opening' => -30, 'period' => [20, 0], 'closing' => -210])]));
        $this->assertSame([true, 'closing_gap', 200.0], [$built['profile']->plFromMovement, $built['profile']->closingEntry, $this->figure($built, 'is.net_profit')->amount]);
        $this->assertSame([280.0, 280.0], [$this->figure($built, 'sofp.total_assets')->amount, $this->figure($built, 'sofp.total_equity_and_liabilities')->amount]);
    }

    public function test_post_closing_movements_that_dont_match_keep_the_error(): void
    {
        // Credited 180, not the 200 the P&L movements give; a credit gap of 190; a debit gap where a profit needs a credit.
        foreach ([['period' => [20, 180]], ['period' => [20, 0], 'closing' => -200], ['opening' => -230, 'closing' => -30]] as $re) {
            $built = $this->build($this->closedWithMovements([$this->account(4, 'أرباح محتجزة', $re + ['opening' => -30])]));

            $this->assertFalse($built['profile']->plFromMovement, json_encode($re));
            $this->assertTrue($built['profile']->postClosing);
            $this->assertTrue($this->figure($built, 'is.net_profit')->isMissing());
            $this->assertContains(['post_closing_tb', 'error'], $this->codes($built));
            $this->assertNotContains(['pl_from_movements', 'info'], $this->codes($built));
        }
    }

    public function test_a_partial_trial_balance(): void
    {
        $built = $this->build([
            $this->account(2, 'عملاء', ['closing' => 500]),
            $this->account(3, 'موردون', ['closing' => -200]),
        ], ['closing']);

        // No tb_unbalanced: expected in a partial file. An unbalanced balance sheet can't give a cash flow that reaches its cash.
        $this->assertSame([['tb_partial', 'error'], ['sofp_unbalanced', 'error'], ['cash_flow_closing_mismatch', 'error']], $this->codes($built));
    }

    public function test_net_income_mismatch_when_a_pl_account_reaches_no_line(): void
    {
        // A safety net over the rules: a P&L class with no line leaves the income statement short.
        config(['financial_statements.templates.sa-ifrs-smes-3.main_classes.المصروفات الإدارية والعمومية' => ['choose' => ['is.general_admin', 'is.cost_of_revenue'], 'reason' => 'اختار.']]);
        $built = $this->build($this->balanced());

        $check = collect($built['checks'])->firstWhere('code', 'net_income_mismatch');
        $this->assertSame([200.0, 500.0, 300.0, 'error'], [$check['expected'], $check['actual'], $check['difference'], $check['severity']]);
    }

    public function test_an_override_off_the_income_statement_is_the_users_decision(): void
    {
        $built = $this->build($this->balanced(), options: ['overrides' => [5 => 'sofp.prepayments_other_receivables']]);

        $this->assertSame(500.0, $this->figure($built, 'is.net_profit')->amount);
        $this->assertNotContains('net_income_mismatch', array_column($built['checks'], 'code'));
    }

    public function test_row_level_checks_are_carried_to_their_lines(): void
    {
        $rows = $this->balanced();
        $rows[0] = $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [500, 300]],
            ['data_flags' => [['type' => 'reconciliation', 'difference' => 5.0, 'message' => 'm']], 'needs_review' => true]);
        $rows[] = $this->account(6, 'عملاء', ['opening' => 0, 'period' => [0, 80]]);           // a customer in credit
        $rows[] = $this->account(7, 'موردون', ['opening' => 0, 'period' => [80, 0]]);          // a supplier in debit
        $built = $this->build($rows, ['opening', 'period', 'adjustment', 'closing']);

        $codes = $this->codes($built);
        $this->assertContains(['row_reconciliation', 'warning'], $codes);
        $this->assertContains(['rows_need_review', 'warning'], $codes);
        $this->assertContains(['empty_group', 'info'], $codes);
        $wrong = collect($built['checks'])->where('code', 'wrong_side_balance')->pluck('line_key')->sort()->values()->all();
        $this->assertSame(['sofp.trade_payables', 'sofp.trade_receivables'], $wrong);

        $cash = collect($built['statements']['financial_position'])->firstWhere('key', 'sofp.cash');
        $this->assertSame(['row_reconciliation'], $cash['check_codes']);
        $this->assertSame([2], $cash['rows']);
    }

    public function test_pending_rows_make_their_totals_pending(): void
    {
        $rows   = $this->balanced();
        $rows[] = $this->account(6, 'إيرادات مستحقة', ['closing' => 0]);   // a confirm rule
        $built  = $this->build($rows);

        $this->assertSame('pending_confirmation', $this->figure($built, 'sofp.contract_assets')->status);
        $this->assertSame('pending_confirmation', $this->figure($built, 'sofp.total_assets')->status);
        $this->assertSame('ok', $this->figure($built, 'sofp.total_equity_and_liabilities')->status);
    }

    /** Cash and customers 100.40 each: 100 + 100 shown under a total of 200.80 → 201. Capital 150.80, suppliers 50. */
    private function roundingRows(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100.4]),
            $this->account(3, 'عملاء', ['closing' => 100.4]),
            $this->account(4, 'رأس المال', ['closing' => -150.8]),
            $this->account(7, 'موردون', ['closing' => -50]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -10]),
            $this->account(6, 'إيجارات', ['closing' => 10]),
        ];
    }

    private function shown(array $built, string $key): ?int
    {
        return collect($built['statements']['financial_position'])->firstWhere('key', $key)['shown']['current'];
    }

    public function test_a_total_s_rounding_difference_goes_on_the_largest_line_under_it(): void
    {
        $built = $this->build($this->roundingRows(), ['closing']);

        $check = collect($built['checks'])->firstWhere('code', 'rounding_difference');
        $this->assertSame(['sofp.total_current_assets', 'info', 200.0, 201.0, 1.0, 'sofp.trade_receivables'],
            [$check['line_key'], $check['severity'], $check['expected'], $check['actual'], $check['difference'], $check['plugged_into']]);
        $this->assertSame('وُضع فرق التقريب 1.00 في «إجمالي الأصول المتداولة» على «الذمم التجارية المدينة» ليطابق الإجمالي مجموع بنوده (يمكنك اختيار بند آخر).', $check['message_ar']);
        $this->assertSame([101, 100, 201, 201, 151, 201], array_map(fn ($k) => $this->shown($built, $k),
            ['sofp.trade_receivables', 'sofp.cash', 'sofp.total_current_assets', 'sofp.total_assets', 'sofp.total_equity', 'sofp.total_equity_and_liabilities']));
        $this->assertSame(200.8, $this->figure($built, 'sofp.total_current_assets')->amount);   // the amounts themselves are untouched
        $field = collect($built['intake'])->firstWhere('key', 'rounding.sofp.total_current_assets');
        $this->assertSame([false, 'sofp.trade_receivables'], [$field['required'], $field['default']]);
        $this->assertNotContains('sofp.cash', $field['options']);   // cash ties to the bank: never offered

        // Nor taken when asked for.
        $chosen = $this->build($this->roundingRows(), ['closing'], ['inputs' => ['rounding.sofp.total_current_assets' => 'sofp.cash']]);
        $this->assertSame([101, 100, 201], array_map(fn ($k) => $this->shown($chosen, $k), ['sofp.trade_receivables', 'sofp.cash', 'sofp.total_current_assets']));
    }

    public function test_a_rounding_difference_never_lands_on_capital(): void
    {
        // Cash 100.40 and customers 50.40 (150.80 → 151); capital 100.40, the largest line on the other side, and suppliers 50.40.
        $rows = [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100.4]),
            $this->account(3, 'عملاء', ['closing' => 50.4]),
            $this->account(4, 'رأس المال', ['closing' => -100.4]),
            $this->account(7, 'موردون', ['closing' => -50.4]),
        ];
        $built = $this->build($rows, ['closing']);

        $this->assertSame([100, 1, 50, 151], array_map(fn ($k) => $this->shown($built, $k),
            ['sofp.share_capital', 'sofp.retained_earnings', 'sofp.trade_payables', 'sofp.total_equity_and_liabilities']));
        $field = collect($built['intake'])->firstWhere('key', 'rounding.sofp.total_equity_and_liabilities');
        $this->assertSame('sofp.retained_earnings', $field['default']);
        $this->assertNotContains('sofp.share_capital', $field['options']);

        // The changes in equity end at what the balance sheet shows: the riyal on retained earnings there too.
        $closing = collect($built['statements']['changes_in_equity']['current'])->firstWhere('key', 'closing');
        $this->assertSame([100, 1, 101], [$closing['shown']['share_capital'], $closing['shown']['retained_earnings'], $closing['shown']['total']]);

        // Not even by choice.
        $chosen = $this->build($rows, ['closing'], ['inputs' => ['rounding.sofp.total_equity_and_liabilities' => 'sofp.share_capital']]);
        $this->assertSame([100, 1], array_map(fn ($k) => $this->shown($chosen, $k), ['sofp.share_capital', 'sofp.retained_earnings']));
    }

    public function test_a_rounding_difference_never_lands_on_a_line_tied_to_an_outside_source(): void
    {
        // Cash 200.40 (the bank's statement), customers 100.40: 300.80 → 301 on the customers, not on cash, though larger.
        // Suppliers 50.40, a related party 150.40 and zakat 100.00 (confirmed and assessed outside): the other side's riyal
        // on the suppliers.
        $built = $this->build([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 200.4]),
            $this->account(3, 'عملاء', ['closing' => 100.4]),
            $this->account(7, 'موردون', ['closing' => -50.4]),
            $this->account(8, 'اطراف ذات علاقة دائنة', ['closing' => -150.4]),
            $this->account(9, 'مخصص الزكاة الشرعية', ['closing' => -100]),
        ], ['closing']);

        $this->assertSame([200, 101, 301], array_map(fn ($k) => $this->shown($built, $k), ['sofp.cash', 'sofp.trade_receivables', 'sofp.total_assets']));
        $this->assertSame(150, $this->shown($built, 'sofp.due_to_related_parties'));
        $this->assertSame(100, $this->shown($built, 'sofp.zakat_provision'));
        $field = collect($built['intake'])->firstWhere('key', 'rounding.sofp.total_current_assets');
        $this->assertNotContains('sofp.cash', $field['options']);
    }

    public function test_a_rounding_difference_above_the_limit_goes_nowhere_and_is_an_error(): void
    {
        config(['financial_statements.rounding_limit' => 0.5]);
        $built = $this->build($this->roundingRows(), ['closing']);

        $check = collect($built['checks'])->firstWhere('code', 'rounding_over_limit');
        $this->assertSame(['sofp.total_current_assets', 'error', 1.0], [$check['line_key'], $check['severity'], $check['difference']]);
        $this->assertSame([100, 100, 200], array_map(fn ($k) => $this->shown($built, $k), ['sofp.trade_receivables', 'sofp.cash', 'sofp.total_current_assets']));
    }
}
