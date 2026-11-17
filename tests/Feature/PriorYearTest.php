<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** PR 8: comparatives from a prior report, a prior trial balance or manual figures; check 21; restated; first year (Q7, Q9, Q10, Q41). */
class PriorYearTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private function doneRun(array $rows, array $groups = ['closing']): string
    {
        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx',
            'result' => $this->runResult($rows, $groups)])->id;
    }

    /** 2024, pre-closing: cash 300 = capital 100 + suppliers 50 + profit 150 (sales 400 − rent 250). */
    private function priorRows(float $cash = 300, float $sales = 400): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => $cash]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -50]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -$sales]),
            $this->account(6, 'إيجارات', ['closing' => 250]),
        ];
    }

    /** 2025 with opening columns: the 2024 closing (profit closed into retained earnings), then sales 600 − rent 350. */
    private function currentRows(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 300, 'period' => [600, 350]]),
            $this->account(3, 'رأس المال', ['opening' => -100]),
            $this->account(4, 'موردون', ['opening' => -50]),
            $this->account(7, 'أرباح محتجزة', ['opening' => -150]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [0, 600]]),
            $this->account(6, 'إيجارات', ['period' => [350, 0]]),
        ];
    }

    private function report(string $run, string $start = '2025-01-01', string $end = '2025-12-31', ?string $company = null): string
    {
        return $this->postJson(self::URL, ['classification_run_id' => $run, 'period_start' => $start, 'period_end' => $end, 'company_id' => $company])
            ->assertCreated()->json('id');
    }

    private function prior(string $id, array $body)
    {
        return $this->putJson(self::URL."/{$id}/prior-year", $body);
    }

    private function row(array $state, string $key): array
    {
        foreach (array_diff_key($state['statements'], ['changes_in_equity' => 1]) as $rows) {
            foreach ($rows as $r) {
                if (($r['key'] ?? null) === $key) {
                    return $r;
                }
            }
        }
        $this->fail("no {$key}");
    }

    private function cmp(array $state, string $key): ?array
    {
        return $this->row($state, $key)['comparative'] ?? null;
    }

    private function checks(array $state, string $code): array
    {
        return array_values(array_filter($state['checks'], fn ($c) => $c['code'] === $code));
    }

    public function test_without_a_source_the_balance_sheet_comes_from_the_opening_columns(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing'])))->json();

        $this->assertSame([300, 'trial_balance_opening'], [$this->cmp($state, 'sofp.cash')['amount'], $this->cmp($state, 'sofp.cash')['origin']]);
        $this->assertSame([150, 300], [$this->cmp($state, 'sofp.retained_earnings')['amount'], $this->cmp($state, 'sofp.total_equity_and_liabilities')['amount']]);
        $this->assertNull($this->cmp($state, 'is.revenue')['amount']);   // the income statement needs a prior source (Q41)
        $this->assertSame(['comparative.comprehensive_income', 'comparative.changes_in_equity', 'comparative.cash_flows'],
            array_column(array_values(array_filter($state['missing'], fn ($m) => str_starts_with($m['key'], 'comparative.'))), 'key'));
        $this->assertSame(['source' => 'none', 'prior_report_id' => null, 'prior_run_id' => null, 'restated' => false,
            'opening_balances' => 'present', 'opening_signal' => 'opening_column', 'prior_needed' => true, 'automatic' => false, 'skip_opening' => false, 'approved_opening' => ['enabled' => true, 'status' => null], 'restatement_note' => null, 'figures' => [], 'changes_in_equity' => [], 'cash_flows' => [], 'notes' => [], 'schedules' => [], 'upload' => null], array_diff_key($state['comparative'], ['entry' => 1, 'defaulted' => 1]));
        // What may be typed: every statement line, the changes in equity's entered rows, the cash-flow lines, the item notes' classes.
        $entry = $state['comparative']['entry'];
        $this->assertContains('sofp.cash', $entry['figures']);
        $this->assertContains('is.revenue', $entry['figures']);
        $this->assertContains('opening', $entry['changes_in_equity']['rows']);
        $this->assertContains('retained_earnings', $entry['changes_in_equity']['columns']);
        $this->assertContains('cf.depreciation_ppe', $entry['cash_flows']);
        $this->assertSame(['opening', 'purchases', 'purchase_costs', 'closing'], $entry['build_up']);
        $this->assertIsArray($entry['notes']);

        // A closing-only file: no comparative at all until a source is given.
        $closing = $this->getJson(self::URL.'/'.$this->report($this->doneRun($this->priorRows())))->json();
        $this->assertNull($this->cmp($closing, 'sofp.cash')['amount']);
        $this->assertSame(['comparative.financial_position', 'comparative.comprehensive_income', 'comparative.changes_in_equity', 'comparative.cash_flows'],
            array_values(array_filter(array_column($closing['missing'], 'key'), fn ($k) => str_starts_with($k, 'comparative.'))));
    }

    public function test_pl_accounts_still_open_at_the_start_roll_into_opening_retained_earnings(): void
    {
        $rows = $this->currentRows();
        $rows[3] = $this->account(7, 'أرباح محتجزة', ['opening' => 0]);                          // 2024 not closed …
        $rows[4] = $this->account(5, 'إيرادات المبيعات', ['opening' => -400, 'period' => [0, 600]]);   // … its P&L still open
        $rows[5] = $this->account(6, 'إيجارات', ['opening' => 250, 'period' => [350, 0]]);
        $state = $this->getJson(self::URL.'/'.$this->report($this->doneRun($rows, ['opening', 'period', 'closing'])))->json();

        $this->assertSame([150, 'derived'], [$this->cmp($state, 'sofp.retained_earnings')['amount'], $this->cmp($state, 'sofp.retained_earnings')['origin']]);
        $this->assertSame(300, $this->cmp($state, 'sofp.total_equity_and_liabilities')['amount']);
    }

    public function test_a_first_year_has_one_column(): void
    {
        // A closing-only file with nothing from before the year: a first year is a first year.
        $id    = $this->report($this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 250]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -50]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -600]),
            $this->account(6, 'إيجارات', ['closing' => 500]),
        ], ['closing']));
        $state = $this->prior($id, ['source' => 'first_year'])->assertOk()->json();

        $this->assertArrayNotHasKey('comparative', $this->row($state, 'sofp.cash'));
        $this->assertSame([], array_filter(array_column($state['missing'], 'key'), fn ($k) => str_starts_with($k, 'comparative.')));
        $this->assertSame([null, null], [$state['comparative_start'], $state['comparative_end']]);
        $this->assertSame([], $this->checks($state, 'opening_vs_prior_closing'));
    }

    public function test_opening_balances_mean_a_comparative_year_even_when_first_year_is_chosen(): void
    {
        $id    = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']));
        $state = $this->prior($id, ['source' => 'first_year'])->assertOk()->json();

        $this->assertSame(['none', 300], [$state['comparative']['source'], $this->cmp($state, 'sofp.cash')['amount']]);
        $info = collect($state['checks'])->firstWhere('code', 'comparative_by_opening');
        $this->assertSame(['info', 'يحتوي الميزان على أرصدة افتتاحية، لذلك تتضمن القوائم سنة مقارنة (لم يُطبَّق اختيار «أول سنة»).'], [$info['severity'], $info['message_ar']]);
    }

    public function test_without_opening_balances_no_prior_year_is_asked_and_the_user_can_give_one(): void
    {
        // R8: an opening column, all zero: a first year by itself, nothing asked; an info check says why.
        $rows = array_map(fn ($r) => ['opening_debit' => 0, 'opening_credit' => 0] + $r, $this->currentRows());
        $id = $this->report($this->doneRun($rows, ['opening', 'period', 'closing']));
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertSame(['first_year', 'absent', false, true], [$state['comparative']['source'], $state['comparative']['opening_balances'],
            $state['comparative']['prior_needed'], $state['comparative']['automatic']]);
        $this->assertArrayNotHasKey('comparative', $this->row($state, 'sofp.cash'));
        $this->assertSame([], array_filter(array_column($state['missing'], 'key'), fn ($k) => str_starts_with($k, 'comparative.')));
        $this->assertSame('لا يحتوي الميزان على أرصدة أول المدة، فهذه هي السنة الأولى ولا تحتاج إلى قوائم سنة سابقة. إذا وُجدت سنة سابقة، فاختر مصدرها في خطوة السنة السابقة.',
            collect($state['checks'])->firstWhere('code', 'comparative_by_opening')['message_ar']);

        // The user's override: a prior year all the same.
        $state = $this->prior($id, ['source' => 'manual', 'figures' => ['sofp.cash' => 100]])->assertOk()->json();
        $this->assertSame(['manual', false, 100], [$state['comparative']['source'], $state['comparative']['automatic'], $this->cmp($state, 'sofp.cash')['amount']]);

    }

    public function test_without_an_opening_column_the_balances_tell_whether_there_was_a_prior_year(): void
    {
        $signal = function (array $rows, array $groups) {
            $c = $this->getJson(self::URL.'/'.$this->report($this->doneRun($rows, $groups)))->assertOk()->json('comparative');

            return [$c['opening_balances'], $c['opening_signal'], $c['prior_needed'], $c['source']];
        };
        $cash = fn (array $a) => $this->account(2, 'نقدية لدى البنوك', $a);

        // Movements and closing balances: the opening is closing − movements.
        $this->assertSame(['present', 'implied_opening', true, 'none'], $signal($this->currentRows(), ['period', 'closing']));
        $this->assertSame(['absent', 'implied_opening', false, 'first_year'], $signal([$cash(['period' => [100, 0]]), $this->account(3, 'رأس المال', ['period' => [0, 100]])], ['period', 'closing']));
        // Closing balances only: retained earnings before closing …
        $this->assertSame(['present', 'retained_earnings', true, 'none'],
            $signal([$cash(['closing' => 250]), $this->account(7, 'أرباح محتجزة', ['closing' => -150]), $this->account(3, 'رأس المال', ['closing' => -100])], ['closing']));
        // … or accumulated depreciation above this year's charge (a first year's is its own charge: no signal).
        $assets = fn (int $accumulated) => [$cash(['closing' => 100]), $this->account(14, 'سيارات ووسائل نقل', ['closing' => 200]),
            $this->account(15, 'مجمع إهلاك الأصول الثابتة', ['closing' => -$accumulated]), $this->account(18, 'إهلاك السيارات', ['closing' => 20]),
            $this->account(3, 'رأس المال', ['closing' => -(320 - $accumulated)])];
        $this->assertSame(['present', 'accumulated_depreciation', true, 'none'], $signal($assets(60), ['closing']));
        $this->assertSame(['unknown', null, true, 'none'], $signal($assets(20), ['closing']));
    }

    public function test_with_opening_balances_a_first_year_stands_only_when_the_user_says_so(): void
    {
        $id = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']));
        // R8's override: a first year although the trial balance has opening balances.
        $state = $this->prior($id, ['source' => 'first_year', 'skip_opening' => true])->assertOk()->json();
        $this->assertSame(['first_year', 'present', true, true], [$state['comparative']['source'], $state['comparative']['opening_balances'],
            $state['comparative']['prior_needed'], $state['comparative']['skip_opening']]);
        $this->assertArrayNotHasKey('comparative', $this->row($state, 'sofp.cash'));
        $this->assertNull(collect($state['checks'])->firstWhere('code', 'comparative_by_opening'));

        $this->prior($id, ['source' => 'manual', 'figures' => [], 'skip_opening' => true])->assertStatus(422)->assertJsonValidationErrors('skip_opening');
    }

    public function test_comparatives_from_a_prior_report(): void
    {
        $company = Company::create(['name_ar' => 'شركة المثال'])->id;
        $priorId = $this->report($this->doneRun($this->priorRows()), '2024-01-01', '2024-12-31', $company);
        $this->patchJson(self::URL."/{$priorId}/inputs", ['inputs' => ['zakat.zero_reason' => 'خسائر مرحلة', 'oci.has_items' => false]])->assertOk();
        $id = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']), company: $company);

        $state = $this->prior($id, ['source' => 'report', 'prior_report_id' => $priorId])->assertOk()->json();
        $this->assertSame([400, 'prior_year_report', [['type' => 'prior_report', 'id' => $priorId]]],
            [$this->cmp($state, 'is.revenue')['amount'], $this->cmp($state, 'is.revenue')['origin'], $this->cmp($state, 'is.revenue')['refs']]);
        $this->assertSame([150, 150, 0], [$this->cmp($state, 'is.net_profit')['amount'], $this->cmp($state, 'is.total_comprehensive_income')['amount'],
            $this->cmp($state, 'is.oci_total')['amount']]);
        $this->assertSame(150, $this->cmp($state, 'sofp.retained_earnings')['amount']);
        $this->assertSame(['2024-01-01', '2024-12-31'], [$state['comparative_start'], $state['comparative_end']]);
        $this->assertSame([], $this->checks($state, 'opening_vs_prior_closing'));   // the opening columns match the prior closing
        $this->assertSame([], array_filter(array_column($state['missing'], 'key'), fn ($k) => str_starts_with($k, 'comparative.')));

        // Deleting the prior report leaves the comparatives missing, never 0.
        FinancialReport::find($priorId)->delete();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertNull($this->cmp($state, 'sofp.cash')['amount']);
        $this->assertContains(['key' => 'comparative.financial_position', 'reason' => 'prior_year_incomplete', 'input_key' => null], $state['missing']);
    }

    public function test_opening_vs_prior_closing_is_an_error_until_fixed_or_restated(): void
    {
        $id      = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']));
        $priorRun = $this->doneRun($this->priorRows(280, 380));   // cash 280 and profit 130: not what the opening columns say

        $state  = $this->prior($id, ['source' => 'run', 'prior_run_id' => $priorRun])->assertOk()->json();
        $checks = $this->checks($state, 'opening_vs_prior_closing');
        $this->assertSame(['sofp.cash', 'sofp.retained_earnings'], array_column($checks, 'line_key'));
        $this->assertSame([['error', 'comparative', 280, 300, 20]], array_map(fn ($c) => [$c['severity'], $c['scope'], $c['expected'], $c['actual'], $c['difference']], [$checks[0]]));
        $this->assertSame([280, 'prior_year_trial_balance'], [$this->cmp($state, 'sofp.cash')['amount'], $this->cmp($state, 'sofp.cash')['origin']]);   // never adjusted
        $this->assertSame(['2024-01-01', '2024-12-31'], [$state['comparative_start'], $state['comparative_end']]);

        // OCI is never in a trial balance, but the prior year's books show none (no reserve), so it is derived, not missing.
        $this->assertNotContains(['key' => 'comparative.comprehensive_income', 'reason' => 'prior_year_incomplete', 'input_key' => null], $state['missing']);
        $state = $this->prior($id, ['source' => 'run', 'prior_run_id' => $priorRun, 'figures' => [],
            'restated' => true, 'restatement_note' => 'تصحيح خطأ في رصيد البنك في السنة السابقة'])->assertOk()->json();
        $this->assertSame(130, $this->cmp($state, 'is.total_comprehensive_income')['amount']);
        $this->assertSame(['warning', 'warning'], array_column($this->checks($state, 'opening_vs_prior_closing'), 'severity'));
        $this->assertSame([true, 'تصحيح خطأ في رصيد البنك في السنة السابقة'], [$state['comparative']['restated'], $state['comparative']['restatement_note']]);

        $this->prior($id, ['source' => 'run', 'prior_run_id' => $priorRun, 'restated' => true])->assertStatus(422)->assertJsonValidationErrors('restatement_note');
        $this->prior($id, ['source' => 'run', 'prior_run_id' => $priorRun, 'figures' => ['sofp.cash' => 1]])->assertStatus(422)->assertJsonValidationErrors('figures.sofp.cash');
    }

    public function test_manual_figures(): void
    {
        $id    = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']));
        $state = $this->prior($id, ['source' => 'manual', 'comparative_start' => '2024-04-01', 'comparative_end' => '2024-12-31', 'figures' => [
            'sofp.cash' => 300, 'sofp.share_capital' => 100, 'sofp.trade_payables' => 50, 'sofp.retained_earnings' => 150,
            'is.revenue' => 400, 'is.general_admin' => -250,
        ]])->assertOk()->json();

        $this->assertSame([-250, 'prior_year_manual'], [$this->cmp($state, 'is.general_admin')['amount'], $this->cmp($state, 'is.general_admin')['origin']]);
        $this->assertSame([0, 150, 300], [$this->cmp($state, 'is.oci_total')['amount'], $this->cmp($state, 'is.net_profit')['amount'], $this->cmp($state, 'sofp.total_assets')['amount']]);
        $this->assertSame(['2024-04-01', '2024-12-31'], [$state['comparative_start'], $state['comparative_end']]);   // a short prior period
        $this->assertSame([], $this->checks($state, 'opening_vs_prior_closing'));
        $this->assertSame([], $this->checks($state, 'sofp_unbalanced'));

        // Figures that don't balance are reported for the comparative year.
        $state = $this->prior($id, ['source' => 'manual', 'figures' => ['sofp.cash' => 310, 'sofp.share_capital' => 100]])->assertOk()->json();
        $this->assertSame(['comparative'], array_column($this->checks($state, 'sofp_unbalanced'), 'scope'));
        $this->assertSame(['2024-01-01', '2024-12-31'], [$state['comparative_start'], $state['comparative_end']]);
    }

    public function test_manual_cells_left_empty_are_listed_as_counted_zero(): void
    {
        $id    = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']));
        $state = $this->prior($id, ['source' => 'manual', 'figures' => ['sofp.cash' => 300, 'sofp.share_capital' => 100, 'is.revenue' => 400],
            'changes_in_equity' => ['opening' => ['share_capital' => 100]], 'cash_flows' => ['cf.profit_before_zakat' => 150]])->assertOk()->json();
        $d = $state['comparative']['defaulted'];

        // Typed lines aren't listed; every other line of both statements is, and counts as 0.
        $this->assertNotContains('sofp.cash', $d['figures']);
        $this->assertContains('sofp.trade_payables', $d['figures']);
        $this->assertContains('is.general_admin', $d['figures']);
        $this->assertSame(0, $this->cmp($state, 'sofp.trade_payables')['amount']);
        $this->assertNotContains('sofp.total_assets', $d['figures']);   // a total is a sum, not a default
        $this->assertNotContains(['row' => 'opening', 'column' => 'share_capital'], $d['changes_in_equity']);
        $this->assertContains(['row' => 'opening', 'column' => 'retained_earnings'], $d['changes_in_equity']);
        $this->assertNotContains('total', array_column($d['changes_in_equity'], 'column'));
        $this->assertNotContains('cf.profit_before_zakat', $d['cash_flows']);
        $this->assertNotEmpty($d['cash_flows']);
        $this->assertEquals((object) [], (object) $d['notes']);   // no note typed: those stay missing, not 0

        // A section with nothing typed stays missing: not listed.
        $state = $this->prior($id, ['source' => 'manual', 'figures' => ['sofp.cash' => 300]])->assertOk()->json();
        $this->assertSame([[], []], [$state['comparative']['defaulted']['changes_in_equity'], $state['comparative']['defaulted']['cash_flows']]);

        // Another source: nothing counted as 0.
        $state = $this->prior($id, ['source' => 'first_year'])->assertOk()->json();
        $this->assertSame([], array_merge(...array_values(array_map(fn ($v) => (array) $v, $state['comparative']['defaulted']))));
    }

    public function test_validation(): void
    {
        $company = Company::create(['name_ar' => 'أ'])->id;
        $run     = $this->doneRun($this->currentRows(), ['opening', 'period', 'closing']);
        $id      = $this->report($run, company: $company);
        $other   = $this->report($this->doneRun($this->priorRows()), '2024-01-01', '2024-12-31', Company::create(['name_ar' => 'ب'])->id);
        $later   = $this->report($this->doneRun($this->priorRows()), '2025-06-01', '2026-05-31', $company);
        $waiting = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'needs_confirmation', 'original_name' => 'x.xlsx'])->id;

        foreach ([
            ['source' => 'yesterday'],
            ['source' => 'report'],
            ['source' => 'report', 'prior_report_id' => $other],
            ['source' => 'report', 'prior_report_id' => $later],
            ['source' => 'report', 'prior_report_id' => $id],
            ['source' => 'report', 'prior_report_id' => $other, 'figures' => ['sofp.cash' => 1]],
            ['source' => 'run', 'prior_run_id' => $waiting],
            ['source' => 'run', 'prior_run_id' => $run],
            ['source' => 'manual'],
            ['source' => 'manual', 'figures' => ['sofp.nope' => 1]],
            ['source' => 'manual', 'figures' => ['sofp.cash' => 'كتير']],
            ['source' => 'manual', 'figures' => ['sofp.cash' => 1], 'comparative_end' => '2025-03-31'],
            ['source' => 'first_year', 'restated' => true, 'restatement_note' => 'سبب'],
        ] as $body) {
            $this->prior($id, $body)->assertStatus(422);
        }
        $this->prior(Str::uuid(), ['source' => 'none'])->assertNotFound();
        $this->assertSame('none', $this->getJson(self::URL."/{$id}")->json('comparative.source'));
    }

    public function test_period_checks(): void
    {
        $id   = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']));
        $kind = fn (array $state) => array_map(fn ($c) => [$c['kind'], $c['severity'], $c['scope']], $this->checks($state, 'period_invalid'));

        // A 12-month year, the comparative the year before, no approval date yet: nothing.
        $state = $this->prior($id, ['source' => 'manual', 'figures' => ['sofp.cash' => 300, 'sofp.share_capital' => 300]])->assertOk()->json();
        $this->assertSame([], $kind($state));

        // A short comparative period that ends the day before: only its length, a warning.
        $state = $this->prior($id, ['source' => 'manual', 'comparative_start' => '2024-04-01', 'comparative_end' => '2024-12-31', 'figures' => ['sofp.cash' => 300, 'sofp.share_capital' => 300]])->assertOk()->json();
        $this->assertSame([['length', 'warning', 'comparative']], $kind($state));
        $this->assertSame([12, 9, -3], [$this->checks($state, 'period_invalid')[0]['expected'], $this->checks($state, 'period_invalid')[0]['actual'], $this->checks($state, 'period_invalid')[0]['difference']]);
        $this->assertSame('فترة القوائم أو تاريخ الاعتماد غير سليم', $this->checks($state, 'period_invalid')[0]['title_ar']);

        // A gap before this period: an error.
        $state = $this->prior($id, ['source' => 'manual', 'comparative_start' => '2023-12-01', 'comparative_end' => '2024-11-30', 'figures' => ['sofp.cash' => 300, 'sofp.share_capital' => 300]])->assertOk()->json();
        $this->assertSame([['comparative_gap', 'error', 'comparative']], $kind($state));

        // Approved on or before the period end: an error; after it: nothing. A bad date is refused.
        $this->prior($id, ['source' => 'first_year'])->assertOk();
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['approval.date' => '31/03/2026']])->assertStatus(422)->assertJsonValidationErrors('inputs.approval.date');
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['approval.date' => '2026-02-30']])->assertStatus(422);
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['approval.date' => '2025-12-31']])->assertOk()->json();
        $this->assertSame([['approval_date', 'error', 'current']], $kind($state));
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['approval.date' => '2026-03-31']])->assertOk()->json();
        $this->assertSame([], $kind($state));

        // A long first period (14 months): a warning on the current year.
        $long  = $this->report($this->doneRun($this->currentRows(), ['opening', 'period', 'closing']), '2024-11-01', '2025-12-31');
        $state = $this->prior($long, ['source' => 'first_year'])->assertOk()->json();
        $this->assertSame([['length', 'warning', 'current']], $kind($state));
        $this->assertSame(14, $this->checks($state, 'period_invalid')[0]['actual']);
    }
}
