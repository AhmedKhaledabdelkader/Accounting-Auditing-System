<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** PR 10: the statement of changes in equity and the cash flow; checks 9–13, 24; partners' current accounts (Q23); overdraft (Q25). */
class EquityAndCashFlowTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private function report(array $rows, array $groups = ['opening', 'period', 'closing'], string $start = '2025-01-01', string $end = '2025-12-31'): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);
        $id  = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => $start, 'period_end' => $end])->assertCreated()->json('id');
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['oci.has_items' => false]])->assertOk();

        return $id;
    }

    private function state(string $id): array
    {
        return $this->getJson(self::URL."/{$id}")->assertOk()->json();
    }

    /** [amount, status] of a cash-flow line. */
    private function cf(array $state, string $key, string $year = 'current'): array
    {
        $f = collect($state['statements']['cash_flows'])->firstWhere('key', $key)[$year];

        return [$f['amount'] === null ? null : round($f['amount'], 2) + 0.0, $f['status']];
    }

    /** A column of a row of the changes in equity, as an amount. */
    private function soce(array $state, string $row, string $column, string $year = 'current'): ?float
    {
        $f = collect($state['statements']['changes_in_equity'][$year])->firstWhere('key', $row)['cells'][$column];

        return $f['amount'] === null ? null : round($f['amount'], 2) + 0.0;
    }

    private function codes(array $state): array
    {
        return array_column($state['checks'], 'code');
    }

    private function decide(string $id, array $decisions): array
    {
        return $this->postJson(self::URL."/{$id}/suggestions", ['decisions' => $decisions])->assertOk()->json();
    }

    /**
     * 2025: capital paid in 200; 30 to the statutory reserve; dividends of 100 declared, 60 paid; sales 900 in cash, rent
     * 600 (550 paid). Cash 1,000 → 1,490.
     */
    private function rows(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 1000, 'period' => [1100, 610]]),
            $this->account(3, 'رأس المال', ['opening' => -500, 'period' => [0, 200]]),
            $this->account(4, 'احتياطي نظامي', ['opening' => -50, 'period' => [0, 30]]),
            $this->account(5, 'أرباح محتجزة', ['opening' => -450, 'period' => [130, 0]]),
            $this->account(6, 'توزيعات أرباح مستحقة', ['opening' => 0, 'period' => [60, 100]]),
            $this->account(7, 'موردون', ['opening' => 0, 'period' => [0, 50]]),
            $this->account(8, 'إيرادات المبيعات', ['period' => [0, 900]]),
            $this->account(9, 'إيجارات', ['period' => [600, 0]]),
        ];
    }

    public function test_movements_give_the_changes_in_equity_and_a_cash_flow_that_reaches_the_cash(): void
    {
        $state = $this->state($this->report($this->rows()));

        // Changes in equity: suggestions from the equity accounts' movements, closing = the balance sheet.
        $this->assertSame(['share_capital', 'unpaid_capital', 'statutory_reserve', 'other_reserves', 'retained_earnings', 'total'],
            array_column($state['statements']['changes_in_equity']['columns'], 'key'));   // partners' current: a liability (default)
        $this->assertSame([200.0, 30.0, -30.0, -100.0, 300.0], [$this->soce($state, 'capital_increase', 'share_capital'), $this->soce($state, 'transfer_to_reserve', 'statutory_reserve'),
            $this->soce($state, 'transfer_to_reserve', 'retained_earnings'), $this->soce($state, 'dividends', 'retained_earnings'), $this->soce($state, 'net_profit', 'retained_earnings')]);
        $this->assertSame([700.0, 80.0, 620.0, 1400.0], [$this->soce($state, 'closing', 'share_capital'), $this->soce($state, 'closing', 'statutory_reserve'),
            $this->soce($state, 'closing', 'retained_earnings'), $this->soce($state, 'closing', 'total')]);
        $this->assertSame(['pending', 'pending', 'pending'], array_column(array_values(array_filter($state['suggestions'], fn ($s) => str_starts_with($s['key'], 'soce.'))), 'status'));

        // Cash flow: profit 300 + payables 50; capital 200; dividends paid 60. 1,000 → 1,490 = the balance sheet's cash.
        $this->assertSame([[300.0, 'ok'], [50.0, 'ok'], [490.0, 'pending_confirmation']], [$this->cf($state, 'cf.profit_before_zakat'),
            $this->cf($state, 'cf.wc_trade_payables'), $this->cf($state, 'cf.net_change')]);
        $this->assertSame([200.0, -60.0, 1000.0, 1490.0], [$this->cf($state, 'cf.capital_increase')[0], $this->cf($state, 'cf.dividends_paid')[0],
            $this->cf($state, 'cf.opening_cash')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        foreach (['soce_closing_mismatch', 're_roll', 'cash_flow_closing_mismatch', 're_cleared_no_dividend'] as $code) {
            $this->assertNotContains($code, $this->codes($state), $code);
        }

        // Confirmed: no longer pending. A wrong dividend: retained earnings no longer roll (11), and the cash flow misses (12).
        $state = $this->decide($this->idOf($state), [['key' => 'soce.capital_increase', 'action' => 'confirm'], ['key' => 'soce.transfer_to_reserve', 'action' => 'confirm'],
            ['key' => 'soce.dividends', 'action' => 'confirm']]);
        $this->assertSame([490.0, 'ok'], $this->cf($state, 'cf.net_change'));
        $state = $this->decide($this->idOf($state), [['key' => 'soce.dividends', 'action' => 'edit', 'amount' => 90]]);
        $re = collect($state['checks'])->firstWhere('code', 're_roll');
        $this->assertSame([320 + 300, 630], [$re['expected'], $re['actual']]);
        $this->assertSame(10, collect($state['checks'])->firstWhere('code', 'cash_flow_closing_mismatch')['difference']);
        // A wrong capital increase: check 9 on that column.
        $state = $this->decide($this->idOf($state), [['key' => 'soce.dividends', 'action' => 'reset'], ['key' => 'soce.capital_increase', 'action' => 'edit', 'amount' => 150]]);
        $this->assertSame(['share_capital'], array_column(array_filter($state['checks'], fn ($c) => $c['code'] === 'soce_closing_mismatch'), 'column'));
    }

    private function idOf(array $state): string
    {
        return $state['id'];
    }

    /** Retained earnings of 500 cleared to the partners' current account (credited 500, 100 drawn). */
    private function partnerRows(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 500, 'period' => [0, 100]]),
            $this->account(3, 'أرباح محتجزة', ['opening' => -500, 'period' => [500, 0]]),
            $this->account(4, 'جاري الشريك', ['opening' => 0, 'period' => [100, 500]]),
        ];
    }

    public function test_retained_earnings_cleared_to_the_partners_with_no_dividend(): void
    {
        $id    = $this->report($this->partnerRows());
        $state = $this->state($id);

        // 24: a warning until the distribution is confirmed; suggested as dividends credited to the partners (non-cash).
        $this->assertContains('re_cleared_no_dividend', $this->codes($state));
        $this->assertSame([-500.0, 500.0], [$this->soce($state, 'dividends', 'retained_earnings'), $this->cf($state, 'cf.noncash_distributions_to_partners')[0]]);
        $this->assertSame([0.0, -100.0, 400.0], [$this->cf($state, 'cf.dividends_paid')[0], $this->cf($state, 'cf.partners_current')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $state = $this->decide($id, [['key' => 'soce.dividends', 'action' => 'confirm']]);
        $this->assertNotContains('re_cleared_no_dividend', $this->codes($state));

        // Partners' current accounts in equity (Q23): a transfer to them, and their net movement; a column of their own.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['partners_current' => 'equity']])->assertOk();
        $state = $this->decide($id, [['key' => 'soce.dividends', 'action' => 'reset']]);
        $this->assertContains('partners_current', array_column($state['statements']['changes_in_equity']['columns'], 'key'));
        $this->assertSame([0.0, 500.0, -100.0, 400.0], [$this->soce($state, 'dividends', 'retained_earnings'), $this->soce($state, 'transfer_to_partners_current', 'partners_current'),
            $this->soce($state, 'partners_current_movements', 'partners_current'), $this->soce($state, 'closing', 'partners_current')]);
        $this->assertSame([-100.0, 500.0, 400.0], [$this->cf($state, 'cf.partners_current')[0], $this->cf($state, 'cf.noncash_distributions_to_partners')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertNotContains('soce_closing_mismatch', $this->codes($state));
        $this->assertNotContains('cash_flow_closing_mismatch', $this->codes($state));
    }

    public function test_a_retained_earnings_movement_with_no_dividend_behind_it_is_unexplained_until_the_user_names_it(): void
    {
        // Retained earnings debited 100 against the payables: no dividends payable, no partner credited. Not a dividend by guess.
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 1000]),
            $this->account(3, 'أرباح محتجزة', ['opening' => -1000, 'period' => [100, 0]]),
            $this->account(4, 'موردون', ['opening' => 0, 'period' => [0, 100]]),
        ]);
        $state = $this->state($id);
        $row = fn (array $state, string $key) => collect($state['statements']['changes_in_equity']['current'])->firstWhere('key', $key);
        $cfRow = fn (array $state, string $key) => collect($state['statements']['cash_flows'])->firstWhere('key', $key);

        $this->assertSame([0.0, 0.0, -100.0], [$this->soce($state, 'dividends', 'retained_earnings'), $this->soce($state, 'prior_year_adjustments', 'retained_earnings'),
            $this->soce($state, 'other_retained_movements', 'retained_earnings')]);
        $this->assertSame('حركة غير مفسرة في الأرباح المبقاة', $row($state, 'other_retained_movements')['label_ar']);
        $this->assertSame('حركة غير مفسرة في الأرباح المبقاة', $cfRow($state, 'cf.other_retained_movements')['label_ar']);
        // Not a cash outflow: a non-cash line in operating; dividends paid «-»; the cash still reaches the balance sheet's.
        $this->assertSame([-100.0, 100.0, 0.0, 1000.0], [$this->cf($state, 'cf.other_retained_movements')[0], $this->cf($state, 'cf.wc_trade_payables')[0],
            $this->cf($state, 'cf.dividends_paid')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $check = collect($state['checks'])->firstWhere('code', 'retained_unexplained');
        $this->assertSame(['warning', [3], 100, 'soce.retained_movement'], [$check['severity'], $check['rows'], $check['amount'], $check['input_key']]);
        $this->assertStringContainsString('100.00', $check['message_ar']);
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'soce.retained_movement');
        $this->assertSame(['dividends', 'prior_year_adjustment', 'other'], $field['options']);

        // A prior-year adjustment: its own row, non-cash; no flag.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['soce.retained_movement' => 'prior_year_adjustment']])->assertOk()->json();
        $this->assertSame([-100.0, 0.0], [$this->soce($state, 'prior_year_adjustments', 'retained_earnings'), $this->soce($state, 'other_retained_movements', 'retained_earnings')]);
        $this->assertSame([-100.0, 0.0, 1000.0], [$this->cf($state, 'cf.prior_year_adjustments')[0], $this->cf($state, 'cf.dividends_paid')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertNotContains('retained_unexplained', $this->codes($state));
        // Flagged instead: Section 10 would restate the comparatives (from the approved statements); pending the accountant.
        $check = collect($state['checks'])->firstWhere('code', 'prior_year_adjustment_pending');
        $this->assertSame(['warning', [3], 100, 'soce.retained_movement'], [$check['severity'], $check['rows'], $check['amount'], $check['input_key']]);
        $this->assertStringContainsString('القسم 10', $check['message_ar']);

        // Dividends paid: financing.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['soce.retained_movement' => 'dividends']])->assertOk()->json();
        $this->assertNotContains('prior_year_adjustment_pending', $this->codes($state));
        $this->assertSame([-100.0, -100.0, 0.0, 1000.0], [$this->soce($state, 'dividends', 'retained_earnings'), $this->cf($state, 'cf.dividends_paid')[0],
            $this->cf($state, 'cf.prior_year_adjustments')[0], $this->cf($state, 'cf.closing_cash')[0]]);

        // Another entry: the row's own label, non-cash.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['soce.retained_movement' => 'other']])->assertOk()->json();
        $this->assertSame('حركات أخرى في الأرباح المبقاة', $row($state, 'other_retained_movements')['label_ar']);
        $this->assertSame([-100.0, 0.0], [$this->cf($state, 'cf.other_retained_movements')[0], $this->cf($state, 'cf.dividends_paid')[0]]);
        $this->assertNotContains('retained_unexplained', $this->codes($state));
        $this->assertNotContains('cash_flow_closing_mismatch', $this->codes($state));

        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['soce.retained_movement' => 'gift']])->assertStatus(422);
    }

    /**
     * A dividend of 100 declared from retained earnings (cash 1,000, retained earnings 1,000 at the start); its other side
     * and what was paid differ by case. Dividends paid = declared − the payable's increase − the partners' credits.
     */
    public function test_a_dividend_declared_is_not_a_dividend_paid(): void
    {
        $case = fn (array $other, float $paidOut) => $this->state($this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 1000, 'period' => [0, $paidOut]]),
            $this->account(3, 'أرباح محتجزة', ['opening' => -1000, 'period' => [100, 0]]),
            $other,
        ]));
        $lines = fn (array $state) => array_map(fn ($k) => $this->cf($state, $k)[0],
            ['cf.dividends_paid', 'cf.partners_current', 'cf.noncash_distributions_to_partners', 'cf.net_change', 'cf.closing_cash']);
        $closes = function (array $state, float $cash) {
            $this->assertSame(-100.0, $this->soce($state, 'dividends', 'retained_earnings'));
            $this->assertSame($cash, (float) collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.cash')['current']['amount']);
            foreach (['cash_flow_closing_mismatch', 'retained_unexplained'] as $code) {
                $this->assertNotContains($code, $this->codes($state), $code);
            }
        };

        // Declared and paid in full: the payable credited and debited 100.
        $state = $case($this->account(4, 'توزيعات أرباح مستحقة', ['opening' => 0, 'period' => [100, 100]]), 100);
        $this->assertSame([-100.0, 0.0, 0.0, -100.0, 900.0], $lines($state));
        $closes($state, 900.0);

        // Declared, unpaid: the payable up 100, no cash out; the payable's change is nowhere else in the statement.
        $state = $case($this->account(4, 'توزيعات أرباح مستحقة', ['opening' => 0, 'period' => [0, 100]]), 0);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 1000.0], $lines($state));
        $closes($state, 1000.0);

        // Partly paid: 40 of 100.
        $state = $case($this->account(4, 'توزيعات أرباح مستحقة', ['opening' => 0, 'period' => [40, 100]]), 40);
        $this->assertSame([-40.0, 0.0, 0.0, -40.0, 960.0], $lines($state));
        $closes($state, 960.0);

        // Credited to the partners' current accounts: non-cash; 30 drawn from them this year is their own financing outflow.
        $state = $case($this->account(4, 'جاري الشريك', ['opening' => 0, 'period' => [30, 100]]), 30);
        $this->assertSame([0.0, -30.0, 100.0, -30.0, 970.0], $lines($state));
        $closes($state, 970.0);

        // Opening and closing balances only: the accounts grew 70, less than the 100, so not a dividend by itself (C+); named
        // one, as much of it as the accounts grew by is the non-cash credit, the rest paid (credited and drawn: the same cash).
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 1000, 'closing' => 970]),
            $this->account(3, 'أرباح محتجزة', ['opening' => -1000, 'closing' => -900]),
            $this->account(4, 'جاري الشريك', ['opening' => 0, 'closing' => -70]),
        ], ['opening', 'closing']);
        $this->assertContains('retained_unexplained', $this->codes($this->state($id)));
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['soce.retained_movement' => 'dividends']])->assertOk()->json();
        $this->assertSame([-30.0, 0.0, 70.0, -30.0, 970.0], $lines($state));
        $this->assertSame('pending_confirmation', $this->cf($state, 'cf.noncash_distributions_to_partners')[1]);
        $closes($state, 970.0);
    }

    public function test_opening_and_closing_only(): void
    {
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 500, 'closing' => 1200]),
            $this->account(3, 'رأس المال', ['opening' => -500, 'closing' => -700]),
            $this->account(4, 'قروض طويلة الأجل', ['opening' => 0, 'closing' => -200]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -900]),
            $this->account(6, 'إيجارات', ['closing' => 600]),
        ], ['opening', 'closing']);
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['loans.current_portion' => 0]])->assertOk();
        $state = $this->state($id);

        // From the net changes: the capital increase is suggested, no dividends are left over; loans are the user's.
        $this->assertSame(['soce.capital_increase' => 200.0], array_column(array_map(fn ($s) => ['k' => $s['key'], 'a' => (float) $s['amount']],
            array_filter($state['suggestions'], fn ($s) => str_starts_with($s['key'], 'soce.'))), 'a', 'k'));
        $this->assertSame(0.0, $this->soce($state, 'dividends', 'retained_earnings'));
        $this->assertSame([[null, 'missing'], [null, 'missing']], [$this->cf($state, 'cf.loans_drawn'), $this->cf($state, 'cf.closing_cash')]);
        $this->assertContains(['key' => 'cf.loans_drawn', 'reason' => 'movement_required', 'input_key' => null], $state['missing']);

        $state = $this->decide($id, [['key' => 'cf.loans_drawn', 'action' => 'edit', 'amount' => 200], ['key' => 'cf.loans_repaid', 'action' => 'edit', 'amount' => 0]]);
        $this->assertSame(1200.0, $this->cf($state, 'cf.closing_cash')[0]);
        $this->assertNotContains('cash_flow_closing_mismatch', $this->codes($state));
        $state = $this->decide($id, [['key' => 'cf.loans_drawn', 'action' => 'edit', 'amount' => 150]]);
        $this->assertSame(-50, collect($state['checks'])->firstWhere('code', 'cash_flow_closing_mismatch')['difference']);
    }

    public function test_closing_only_needs_the_prior_year(): void
    {
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 1000]),
            $this->account(3, 'رأس المال', ['closing' => -700]),
            $this->account(4, 'إيرادات المبيعات', ['closing' => -900]),
            $this->account(5, 'إيجارات', ['closing' => 600]),
        ], ['closing']);
        $state = $this->state($id);
        $this->assertContains(['key' => 'changes_in_equity', 'reason' => 'prior_year_source_required', 'input_key' => null], $state['missing']);
        $this->assertContains(['key' => 'cash_flows', 'reason' => 'prior_year_source_required', 'input_key' => null], $state['missing']);
        $this->assertNull($this->cf($state, 'cf.net_change')[0]);
        $this->assertNotContains('soce.capital_increase', array_column($state['missing'], 'key'));   // one item, not one per movement

        // With the prior year's balance sheet: the changes from it.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 500, 'sofp.share_capital' => 500]])->assertOk()->json();
        $this->assertNotContains('cash_flows', array_column($state['missing'], 'key'));
        $this->assertSame([500.0, 200.0, 1000.0], [$this->cf($state, 'cf.opening_cash')[0], $this->cf($state, 'cf.capital_increase')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertSame([500.0, 'prior_year_manual'], [$this->soce($state, 'opening', 'share_capital'),
            collect($state['statements']['changes_in_equity']['current'])->firstWhere('key', 'opening')['cells']['share_capital']['origin']]);
    }

    public function test_a_first_year_starts_from_nothing(): void
    {
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 0, 'period' => [800, 0]]),
            $this->account(3, 'رأس المال', ['opening' => 0, 'period' => [0, 500]]),
            $this->account(4, 'إيرادات المبيعات', ['period' => [0, 300]]),
        ]);
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk()->json();
        $this->assertNull($state['statements']['changes_in_equity']['comparative']);
        $this->assertArrayNotHasKey('comparative', collect($state['statements']['cash_flows'])->firstWhere('key', 'cf.closing_cash'));
        $this->assertSame([0.0, 500.0, 0.0, 800.0], [$this->soce($state, 'opening', 'total'), $this->soce($state, 'capital_increase', 'share_capital'),
            $this->cf($state, 'cf.opening_cash')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertSame([], array_values(array_filter($state['missing'], fn ($m) => str_contains($m['key'], 'equity') || str_contains($m['key'], 'cash_flows'))));
    }

    public function test_opening_vs_prior_closing_in_equity_and_cash(): void
    {
        $id    = $this->report($this->rows());
        $prior = ['source' => 'manual', 'figures' => ['sofp.cash' => 900, 'sofp.share_capital' => 500, 'sofp.statutory_reserve' => 50, 'sofp.retained_earnings' => 350]];
        $state = $this->putJson(self::URL."/{$id}/prior-year", $prior)->assertOk()->json();
        $soce  = collect($state['checks'])->firstWhere('code', 'soce_opening_mismatch');
        $this->assertSame(['error', 'retained_earnings', 100], [$soce['severity'], $soce['column'], $soce['difference']]);
        $this->assertSame(['error', 100], [collect($state['checks'])->firstWhere('code', 'cash_flow_opening_mismatch')['severity'],
            collect($state['checks'])->firstWhere('code', 'cash_flow_opening_mismatch')['difference']]);

        $state = $this->putJson(self::URL."/{$id}/prior-year", $prior + ['restated' => true, 'restatement_note' => 'تصحيح خطأ'])->assertOk()->json();
        $this->assertSame(['warning', 'warning'], [collect($state['checks'])->firstWhere('code', 'soce_opening_mismatch')['severity'],
            collect($state['checks'])->firstWhere('code', 'cash_flow_opening_mismatch')['severity']]);
    }

    public function test_an_overdraft_in_or_out_of_cash(): void
    {
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 300, 'period' => [0, 400]]),   // overdrawn by 100 at the end
            $this->account(3, 'نقدية بالصندوق', ['opening' => 0, 'period' => [100, 0]]),
            $this->account(4, 'رأس المال', ['opening' => -300]),
            $this->account(5, 'إيجارات', ['period' => [300, 0]]),
        ]);
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'cash_flow.overdraft_in_cash');
        $this->assertSame(['boolean', false, false], [$field['type'], $field['required'], $field['default']]);

        // Default (Q25): out of cash; its change is financing.
        $state = $this->state($id);
        $this->assertSame([100.0, 300.0, 100.0], [$this->cf($state, 'cf.bank_overdraft')[0], $this->cf($state, 'cf.opening_cash')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertNotContains('cash_flow_closing_mismatch', $this->codes($state));

        // In cash: cash less the overdraft.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['cash_flow.overdraft_in_cash' => true]])->assertOk()->json();
        $this->assertSame([0.0, 0.0], [$this->cf($state, 'cf.bank_overdraft')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertNotContains('cash_flow_closing_mismatch', $this->codes($state));
    }

    public function test_the_comparatives_come_from_the_prior_report(): void
    {
        $prior = $this->report($this->rows(), start: '2024-01-01', end: '2024-12-31');
        $id    = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 1490, 'period' => [100, 0]]),
            $this->account(3, 'رأس المال', ['opening' => -700]),
            $this->account(4, 'احتياطي نظامي', ['opening' => -80]),
            $this->account(5, 'أرباح محتجزة', ['opening' => -620]),
            $this->account(6, 'توزيعات أرباح مستحقة', ['opening' => -40]),
            $this->account(7, 'موردون', ['opening' => -50]),
            $this->account(8, 'إيرادات المبيعات', ['period' => [0, 100]]),
        ]);
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'report', 'prior_report_id' => $prior])->assertOk()->json();

        $this->assertSame([200.0, 'prior_year_report'], [$this->soce($state, 'capital_increase', 'share_capital', 'comparative'),
            collect($state['statements']['changes_in_equity']['comparative'])->firstWhere('key', 'capital_increase')['cells']['share_capital']['origin']]);
        $this->assertSame([1490.0, 'pending_confirmation'], $this->cf($state, 'cf.closing_cash', 'comparative'));
        $this->assertSame(1590.0, $this->cf($state, 'cf.closing_cash')[0]);
        $this->assertSame([], array_values(array_filter(array_column($state['missing'], 'key'), fn ($k) => in_array($k, ['comparative.changes_in_equity', 'comparative.cash_flows'], true))));
        $this->assertNotContains('cash_flow_opening_mismatch', $this->codes($state));
    }

    public function test_a_revaluation_surplus_is_non_cash(): void
    {
        // Buildings: 100 bought, 200 revalued (debited to cost, credited to a revaluation reserve).
        $id = $this->report([
            $this->account(2, 'مباني وإنشاءات', ['opening' => 1000, 'period' => [300, 0]]),
            $this->account(3, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [0, 100]]),
            $this->account(4, 'رأس المال', ['opening' => -1100]),
            $this->account(5, 'احتياطيات أخرى', ['opening' => 0, 'period' => [0, 200]]),
        ]);
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['oci.has_items' => true, 'oci.revaluation' => 200]])->assertOk()->json();
        $ppe   = collect($state['schedules'])->firstWhere('key', 'ppe');
        $row   = fn (string $r, string $c = 'buildings') => collect($ppe['rows'])->firstWhere('key', $r)['cells'][$c]['amount'];
        $this->assertSame([100, 200, 1300], [$row('additions'), $row('revaluation'), $row('cost_closing')]);
        $this->assertSame([-100.0, 0.0], [$this->cf($state, 'cf.ppe_purchases')[0], $this->cf($state, 'cf.closing_cash')[0]]);
        $this->assertSame(200.0, $this->soce($state, 'oci', 'other_reserves'));
        foreach (['asset_rollforward', 'cash_flow_closing_mismatch', 'soce_closing_mismatch'] as $code) {
            $this->assertNotContains($code, $this->codes($state), $code);
        }

        // Two categories: the user splits the surplus; a split that doesn't add up is check 19.
        $id = $this->report([
            $this->account(2, 'مباني وإنشاءات', ['opening' => 1000, 'period' => [250, 0]]),
            $this->account(3, 'سيارات ووسائل نقل', ['opening' => 0, 'period' => [50, 0]]),
            $this->account(4, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [0, 100]]),
            $this->account(5, 'رأس المال', ['opening' => -1100]),
            $this->account(6, 'احتياطيات أخرى', ['opening' => 0, 'period' => [0, 200]]),
        ]);
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['oci.has_items' => true, 'oci.revaluation' => 200]])->assertOk()->json();
        $this->assertContains(['key' => 'ppe.revaluation.buildings', 'reason' => 'movement_required', 'input_key' => null], $state['missing']);
        $state = $this->decide($id, [['key' => 'ppe.revaluation.buildings', 'action' => 'edit', 'amount' => 150], ['key' => 'ppe.revaluation.vehicles', 'action' => 'edit', 'amount' => 0]]);
        $this->assertSame([['revaluation', -50]], array_map(fn ($c) => [$c['kind'], $c['difference']], array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'split_total_mismatch'))));
        $state = $this->decide($id, [['key' => 'ppe.revaluation.buildings', 'action' => 'edit', 'amount' => 200]]);
        $this->assertNotContains('split_total_mismatch', $this->codes($state));
    }

    public function test_comparatives_typed_from_last_years_statements(): void
    {
        $id   = $this->report($this->rows());
        $body = ['source' => 'manual', 'figures' => ['sofp.cash' => 1000, 'sofp.share_capital' => 500, 'sofp.statutory_reserve' => 50, 'sofp.retained_earnings' => 450, 'is.revenue' => 150],
            'changes_in_equity' => ['opening' => ['share_capital' => 500, 'statutory_reserve' => 50, 'retained_earnings' => 300], 'net_profit' => ['retained_earnings' => 150]],
            'cash_flows' => ['cf.profit_before_zakat' => 150, 'cf.opening_cash' => 850]];
        $state = $this->putJson(self::URL."/{$id}/prior-year", $body)->assertOk()->json();

        $this->assertSame([450.0, 'prior_year_manual'], [$this->soce($state, 'closing', 'retained_earnings', 'comparative'),
            collect($state['statements']['changes_in_equity']['comparative'])->firstWhere('key', 'net_profit')['cells']['retained_earnings']['origin']]);
        $this->assertSame([1000.0, 'ok'], $this->cf($state, 'cf.closing_cash', 'comparative'));
        $this->assertSame([], array_values(array_filter(array_column($state['missing'], 'key'), fn ($k) => in_array($k, ['comparative.changes_in_equity', 'comparative.cash_flows'], true))));
        $this->assertSame([], array_values(array_filter($state['checks'], fn ($c) => $c['scope'] === 'comparative' && in_array($c['code'], ['soce_closing_mismatch', 're_roll', 'cash_flow_closing_mismatch'], true))));
        $this->assertSame(['cf.profit_before_zakat' => 150, 'cf.opening_cash' => 850], $state['comparative']['cash_flows']);

        // Typed figures that don't agree with last year's balance sheet are reported, scope `comparative`.
        $body['changes_in_equity']['net_profit']['retained_earnings'] = 100;
        $body['cash_flows']['cf.opening_cash'] = 800;
        $state = $this->putJson(self::URL."/{$id}/prior-year", $body)->assertOk()->json();
        $cmp = array_values(array_filter($state['checks'], fn ($c) => $c['scope'] === 'comparative' && in_array($c['code'], ['re_roll', 'cash_flow_closing_mismatch'], true)));
        $this->assertSame([['re_roll', -50], ['cash_flow_closing_mismatch', -50]], array_map(fn ($c) => [$c['code'], $c['difference']], $cmp));

        // Validation.
        foreach ([['changes_in_equity' => ['closing' => ['share_capital' => 1]]], ['changes_in_equity' => ['opening' => ['nope' => 1]]],
            ['cash_flows' => ['cf.net_change' => 1]], ['cash_flows' => ['cf.opening_cash' => 'كتير']]] as $bad) {
            $this->putJson(self::URL."/{$id}/prior-year", $bad + $body)->assertStatus(422);
        }
        $prior = $this->report($this->rows(), start: '2024-01-01', end: '2024-12-31');
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'report', 'prior_report_id' => $prior, 'cash_flows' => ['cf.opening_cash' => 1]])->assertStatus(422);
    }
}
