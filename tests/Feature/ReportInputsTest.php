<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** PR 7: the intake, the user's inputs (zakat Q38, OCI, partners Q23), account overrides and adjusting entries (Q39). */
class ReportInputsTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /**
     * Cash 650 + stock 300 = capital 100 + partners 400 + suppliers 50 + profit 400 (sales 1000 − rent 600); two periodic
     * stock accounts labelled inventory (rows 7, 8) that cancel out on the balance sheet.
     */
    private function rows(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 650]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'جاري الشريك', ['closing' => -400]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -1000]),
            $this->account(6, 'إيجارات', ['closing' => 600]),
            $this->account(9, 'موردون', ['closing' => -50]),
            $this->account(10, 'مخزون بضاعة', ['closing' => 300]),
            $this->account(7, 'مخزون بضاعة', ['closing' => 300]),      // opening stock
            $this->account(8, 'مخزون بضاعة', ['closing' => -300]),     // closing stock
        ];
    }

    private function report(?array $rows = null, array $groups = ['closing']): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx',
            'result' => $this->runResult($rows ?? $this->rows(), $groups)]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated()->json('id');
    }

    private function figure(array $state, string $key): array
    {
        foreach (array_diff_key($state['statements'], ['changes_in_equity' => 1]) as $rows) {
            foreach ($rows as $r) {
                if (($r['key'] ?? null) === $key) {
                    return $r['current'];
                }
            }
        }
        $this->fail("no {$key}");
    }

    private function codes(array $state): array
    {
        return array_column($state['checks'], 'code');
    }

    /** The statements' own fields, without the notes' questions (PR 11, NotesTest). */
    private function statementKeys(array $keys): array
    {
        return array_values(array_filter($keys, fn ($k) => ! preg_match('/^(approval\.|partners$|capital\.|first_application\.|going_concern\.|policies\.|useful_life\.|related_parties$|other_provisions\.|supplier_finance\.|ppe\.comparative_table$|dividends\.|events\.)/', $k)));
    }

    private function inputs(string $id, array $inputs): TestResponse
    {
        return $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => $inputs]);
    }

    /* ---------- intake ---------- */

    public function test_the_intake_asks_only_what_the_run_needs(): void
    {
        $id     = $this->report();
        $fields = array_column($this->getJson(self::URL."/{$id}/intake")->assertOk()->json('fields'), null, 'key');
        $this->assertSame(['partners_current', 'zakat.charge', 'zakat.zero_reason', 'oci.has_items'], $this->statementKeys(array_keys($fields)));
        $this->assertTrue($fields['approval.date']['required']);   // the approval note (PR 11)
        $this->assertSame([true, 'zakat_charge_missing'], [$fields['zakat.charge']['required'], $fields['zakat.charge']['check']]);   // a profit and no charge
        $this->assertSame(['liability', ['liability', 'equity']], [$fields['partners_current']['default'], $fields['partners_current']['options']]);

        // The trial balance has a zakat charge, no partners' account, and a loss: nothing about zakat or partners.
        $other = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 40]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -10]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -50]),
            $this->account(6, 'إيجارات', ['closing' => 110]),
            $this->account(7, 'مصروف الزكاة الشرعية', ['closing' => 10]),
        ]);
        $this->assertSame(['oci.has_items'], $this->statementKeys(array_column($this->getJson(self::URL."/{$other}/intake")->json('fields'), 'key')));

        // A loss without a zakat charge: asked, not required.
        $loss = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 50]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -10]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -50]),
            $this->account(6, 'إيجارات', ['closing' => 110]),
        ]);
        $zakat = collect($this->getJson(self::URL."/{$loss}/intake")->json('fields'))->firstWhere('key', 'zakat.charge');
        $this->assertFalse($zakat['required']);

        // Post-closing: no income statement, so no zakat or OCI question.
        $closed = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100]),
            $this->account(3, 'موردون', ['closing' => -100]),
            $this->account(4, 'رأس المال', ['closing' => 0]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => 0]),
            $this->account(6, 'إيجارات', ['closing' => 0]),
        ]);
        $this->assertSame([], $this->statementKeys(array_column($this->getJson(self::URL."/{$closed}/intake")->json('fields'), 'key')));
        $this->getJson(self::URL.'/'.Str::uuid().'/intake')->assertNotFound();
    }

    /* ---------- zakat (Q38) ---------- */

    public function test_zakat_is_an_error_until_entered_or_confirmed_zero(): void
    {
        $id    = $this->report();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertContains('zakat_charge_missing', $this->codes($state));
        // OCI is not asked: the books show none (OciDerivation).
        $this->assertSame(['zakat.charge'], $this->statementKeys($state['required_inputs']));

        // The charge: Dr zakat expense, Cr zakat provision, both from the input.
        $state = $this->inputs($id, ['zakat.charge' => 25, 'oci.has_items' => false])->assertOk()->json();
        $this->assertNotContains('zakat_charge_missing', $this->codes($state));
        $this->assertSame([-25, 'user_input'], [$this->figure($state, 'is.zakat')['amount'], $this->figure($state, 'is.zakat')['origin']]);
        $this->assertSame([25, 'user_input'], [$this->figure($state, 'sofp.zakat_provision')['amount'], $this->figure($state, 'sofp.zakat_provision')['origin']]);
        $this->assertSame([375, 375], [$this->figure($state, 'is.net_profit')['amount'], $this->figure($state, 'is.total_comprehensive_income')['amount']]);
        $this->assertSame($this->figure($state, 'sofp.total_assets')['amount'], $this->figure($state, 'sofp.total_equity_and_liabilities')['amount']);
        $this->assertNotContains('net_income_mismatch', $this->codes($state));
        $this->assertSame([], $this->statementKeys($state['required_inputs']));
        // Comparatives: PriorYearTest. Closing balances only and no prior source: the zakat provision's opening (PR 9), the
        // changes in equity and the cash flow (PR 10) wait for the prior year.
        $this->assertSame([['key' => 'changes_in_equity', 'reason' => 'prior_year_source_required', 'input_key' => null],
            ['key' => 'cash_flows', 'reason' => 'prior_year_source_required', 'input_key' => null],
            ['key' => 'zakat.opening', 'reason' => 'movement_required', 'input_key' => null]],
            array_values(array_filter($state['missing'], fn ($m) => ! str_starts_with($m['key'], 'comparative.') && ! str_starts_with($m['key'], 'notes.'))));

        // Or zero with a reason (mandatory: an empty or blank reason is refused); not both.
        $this->inputs($id, ['zakat.charge' => null, 'zakat.zero_reason' => ''])->assertStatus(422)->assertJsonValidationErrors('inputs.zakat.zero_reason');
        $this->inputs($id, ['zakat.charge' => null, 'zakat.zero_reason' => '   '])->assertStatus(422)->assertJsonValidationErrors('inputs.zakat.zero_reason');
        $this->inputs($id, ['zakat.zero_reason' => 'خسائر مرحلة'])->assertStatus(422)->assertJsonValidationErrors('inputs.zakat.zero_reason');
        $state = $this->inputs($id, ['zakat.charge' => null, 'zakat.zero_reason' => 'خسائر مرحلة تغطي الوعاء'])->assertOk()->json();
        $this->assertNotContains('zakat_charge_missing', $this->codes($state));
        $this->assertSame(0, $this->figure($state, 'is.zakat')['amount']);

        $this->inputs($id, ['zakat.zero_reason' => null, 'zakat.charge' => 0])->assertStatus(422)->assertJsonValidationErrors('inputs.zakat.charge');
        $this->inputs($id, ['zakat.charge' => 'ألف'])->assertStatus(422)->assertJsonValidationErrors('inputs.zakat.charge');
    }

    public function test_zakat_posted_by_an_adjusting_entry_settles_the_rule(): void
    {
        $id    = $this->report();
        $state = $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [['reason' => 'زكاة السنة من الإقرار', 'lines' => [
            ['line_key' => 'is.zakat', 'debit' => 30, 'credit' => 0], ['line_key' => 'sofp.zakat_provision', 'debit' => 0, 'credit' => 30],
        ]]]])->assertOk()->json();

        $this->assertNotContains('zakat_charge_missing', $this->codes($state));
        $this->assertNotContains('zakat.charge', $state['required_inputs']);
        $this->assertSame([-30, 'user_adjustment'], [$this->figure($state, 'is.zakat')['amount'], $this->figure($state, 'is.zakat')['origin']]);
    }

    /* ---------- other inputs ---------- */

    /** Where last year's balance sheet carried the partners' current account is where this year's does, until the user answers. */
    public function test_partners_current_follows_last_years_balance_sheet(): void
    {
        $id = $this->report();
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.partners_current_equity' => 350]])->assertOk();
        $fields = array_column($this->getJson(self::URL."/{$id}/intake")->json('fields'), null, 'key');
        $this->assertSame('equity', $fields['partners_current']['default']);
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame([400, 0], [$this->figure($state, 'sofp.partners_current_equity')['amount'], $this->figure($state, 'sofp.partners_current_liability')['amount']]);

        // The user's answer wins.
        $state = $this->inputs($id, ['partners_current' => 'liability'])->assertOk()->json();
        $this->assertSame([0, 400], [$this->figure($state, 'sofp.partners_current_equity')['amount'], $this->figure($state, 'sofp.partners_current_liability')['amount']]);

        // Last year's liability line, or neither: the liability default.
        $other = $this->report();
        $this->putJson(self::URL."/{$other}/prior-year", ['source' => 'manual', 'figures' => ['sofp.partners_current_liability' => 90]])->assertOk();
        $this->assertSame('liability', array_column($this->getJson(self::URL."/{$other}/intake")->json('fields'), null, 'key')['partners_current']['default']);
    }

    public function test_oci_and_partners_inputs(): void
    {
        $id = $this->report();
        // No reserve moved, so no OCI: derived from the books, not asked.
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame([0, 'derived'], [$this->figure($state, 'is.oci_total')['amount'], $this->figure($state, 'is.oci_revaluation')['origin']]);

        $state = $this->inputs($id, ['oci.has_items' => true, 'oci.revaluation' => 120.5, 'oci.actuarial' => -20])->assertOk()->json();
        $this->assertSame([100.5, 'derived'], [$this->figure($state, 'is.oci_total')['amount'], $this->figure($state, 'is.oci_total')['origin']]);
        $this->assertSame([120.5, 'user_input'], [$this->figure($state, 'is.oci_revaluation')['amount'], $this->figure($state, 'is.oci_revaluation')['origin']]);
        $this->assertSame(500.5, $this->figure($state, 'is.total_comprehensive_income')['amount']);

        // Saying there is none drops the items entered.
        $state = $this->inputs($id, ['oci.has_items' => false])->assertOk()->json();
        $this->assertSame(['oci.has_items' => false], $state['inputs']);
        $this->inputs($id, ['oci.revaluation' => 5])->assertStatus(422);      // not asked any more
        $this->inputs($id, ['oci.has_items' => 'yes'])->assertStatus(422);

        $state = $this->inputs($id, ['partners_current' => 'equity'])->assertOk()->json();
        $this->assertSame(400, $this->figure($state, 'sofp.partners_current_equity')['amount']);
        $this->assertSame(0, $this->figure($state, 'sofp.partners_current_liability')['amount']);
        $this->inputs($id, ['partners_current' => 'both'])->assertStatus(422);

        $this->inputs($id, ['unknown' => 1])->assertStatus(422)->assertJsonValidationErrors('inputs.unknown');
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => [1, 2]])->assertStatus(422);
        $this->patchJson(self::URL.'/'.Str::uuid().'/inputs', ['inputs' => ['oci.has_items' => true]])->assertNotFound();
    }

    /* ---------- account overrides ---------- */

    public function test_overrides_confirm_the_periodic_stock_proposals(): void
    {
        $rows = [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 0, 'period' => [1000, 0]]),
            $this->account(3, 'رأس المال', ['opening' => -500, 'period' => [0, 0]]),
            $this->account(9, 'موردون', ['opening' => 0, 'period' => [0, 700]]),
            $this->account(10, 'مخزون بضاعة', ['opening' => 500, 'period' => [0, 0]]),       // the stock account, last year's figure
            $this->account(11, 'مخزون بضاعة', ['opening' => 0, 'period' => [500, 0]]),       // opening stock
            $this->account(12, 'مخزون بضاعة', ['opening' => 0, 'period' => [0, 500]]),       // closing stock
            $this->account(5, 'إيرادات المبيعات', ['period' => [0, 1000]]),
            $this->account(6, 'تكلفة المشتريات', ['period' => [700, 0]]),
        ];
        $id    = $this->report($rows, ['opening', 'period', 'closing']);
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertContains('periodic_inventory_pair', $this->codes($state));
        $this->assertSame('pending_confirmation', $this->figure($state, 'sofp.inventory')['status']);

        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [
            ['row' => 11, 'line_key' => 'is.cost_of_revenue'], ['row' => 12, 'line_key' => 'is.cost_of_revenue'],
        ]])->assertOk()->json();
        $this->assertNotContains('periodic_inventory_pair', $this->codes($state));
        $this->assertSame([], $state['mapping']['proposals']);
        $this->assertSame([500, 'ok'], [$this->figure($state, 'sofp.inventory')['amount'], $this->figure($state, 'sofp.inventory')['status']]);
        $this->assertSame(-700, $this->figure($state, 'is.cost_of_revenue')['amount']);   // 700 purchases + 500 opening − 500 closing
        $this->assertNotContains('net_income_mismatch', $this->codes($state));
        $this->assertNotContains('sofp_unbalanced', $this->codes($state));
        $this->assertSame([['row' => 11, 'line_key' => 'is.cost_of_revenue'], ['row' => 12, 'line_key' => 'is.cost_of_revenue']], $state['account_overrides']);
    }

    public function test_override_shares_and_validation(): void
    {
        $id    = $this->report();
        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 6, 'shares' => ['is.cost_of_revenue' => 0.25, 'is.general_admin' => 0.75]]]])->assertOk()->json();
        $this->assertSame([-150, -450], [$this->figure($state, 'is.cost_of_revenue')['amount'], $this->figure($state, 'is.general_admin')['amount']]);

        foreach ([
            [['row' => 6, 'shares' => ['is.cost_of_revenue' => 0.5, 'is.general_admin' => 0.4]]],          // not 1
            [['row' => 6, 'line_key' => 'is.general_admin', 'shares' => ['is.cost_of_revenue' => 0.5, 'is.general_admin' => 0.5]]],
            [['row' => 6]],
            [['row' => 999, 'line_key' => 'is.general_admin']],                                              // not in the trial balance
            [['row' => 6, 'line_key' => 'is.net_profit']],                                                   // a total, not a line
            [['row' => 6, 'line_key' => 'is.general_admin'], ['row' => 6, 'line_key' => 'is.cost_of_revenue']],
        ] as $bad) {
            $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => $bad])->assertStatus(422);
        }
        $this->putJson(self::URL."/{$id}/account-overrides", [])->assertStatus(422);
        $this->assertSame([], $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => []])->assertOk()->json('account_overrides'));
    }

    public function test_moved_rows_keep_where_the_classification_puts_them(): void
    {
        $id = $this->report([...$this->rows(), $this->account(11, null, ['closing' => 0])]);
        $this->assertSame([], $this->getJson(self::URL."/{$id}")->json('mapping.overridden'));

        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [
            ['row' => 6, 'shares' => ['is.cost_of_revenue' => 0.25, 'is.general_admin' => 0.75]],
            ['row' => 11, 'line_key' => 'is.other_expenses'],      // no line before: unclassified
        ]])->assertOk()->json();
        $moved = array_column($state['mapping']['overridden'], null, 'row');
        $this->assertSame([6, 11], array_keys($moved));
        $this->assertEquals(['is.cost_of_revenue' => 0.25, 'is.general_admin' => 0.75], $moved[6]['lines']);
        $this->assertNull($moved[6]['original']['reason']);
        $this->assertCount(1, $moved[6]['original']['lines']);
        $this->assertSame(['1006', 'حساب 6'], [$moved[6]['account_number'], $moved[6]['account_name']]);
        $this->assertSame([null, 'unclassified'], [$moved[11]['original']['lines'], $moved[11]['original']['reason']]);
    }

    /* ---------- adjusting entries (Q39) ---------- */

    public function test_adjusting_entries_reach_the_statements(): void
    {
        $id    = $this->report();
        $state = $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [
            ['reason' => 'مخصص ضمان حسب تقدير الإدارة', 'lines' => [
                ['line_key' => 'is.other_expenses', 'debit' => 40, 'credit' => 0], ['line_key' => 'sofp.other_provisions', 'debit' => 0, 'credit' => 40]]],
            ['reason' => 'إيجار مقدم محمل على السنة', 'lines' => [
                ['row' => 2, 'debit' => 0, 'credit' => 60], ['row' => 6, 'debit' => 60, 'credit' => 0]]],
        ]])->assertOk()->json();

        $this->assertSame([1, 2], array_column($state['adjustments'], 'number'));
        $other = $this->figure($state, 'is.other_expenses');
        $this->assertSame([-40, 'user_adjustment', [['type' => 'adjustment', 'entry' => 1]]], [$other['amount'], $other['origin'], $other['refs']]);
        $this->assertSame(40, $this->figure($state, 'sofp.other_provisions')['amount']);
        $cash = $this->figure($state, 'sofp.cash');
        $this->assertSame([590, 'user_adjustment'], [$cash['amount'], $cash['origin']]);
        $this->assertContains(['type' => 'row', 'row' => 2], $cash['refs']);
        $this->assertSame(-660, $this->figure($state, 'is.general_admin')['amount']);
        $this->assertSame(300, $this->figure($state, 'is.net_profit')['amount']);   // 400 − 40 − 60
        $this->assertNotContains('net_income_mismatch', $this->codes($state));
        $this->assertNotContains('sofp_unbalanced', $this->codes($state));
        $this->assertSame([], $this->putJson(self::URL."/{$id}/adjustments", ['entries' => []])->assertOk()->json('adjustments'));
    }

    public function test_adjusting_entries_must_balance_and_be_complete(): void
    {
        $id   = $this->report();
        $good = ['reason' => 'تسوية', 'lines' => [['row' => 2, 'debit' => 10, 'credit' => 0], ['line_key' => 'sofp.trade_payables', 'debit' => 0, 'credit' => 10]]];
        $put  = fn (array $entry) => $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [$entry]]);

        $put(array_replace_recursive($good, ['lines' => [1 => ['credit' => 9]]]))->assertStatus(422)->assertJsonValidationErrors('entries.0.lines');
        $put(['reason' => '', 'lines' => $good['lines']])->assertStatus(422)->assertJsonValidationErrors('entries.0.reason');
        $put(['reason' => 'تسوية', 'lines' => [$good['lines'][0]]])->assertStatus(422);                                         // one line
        $put(array_replace_recursive($good, ['lines' => [0 => ['credit' => 10]]]))->assertStatus(422);                           // debit and credit
        $put(array_replace_recursive($good, ['lines' => [0 => ['line_key' => 'sofp.cash']]]))->assertStatus(422);                // row and line_key
        $put(array_replace_recursive($good, ['lines' => [0 => ['row' => 999]]]))->assertStatus(422)->assertJsonValidationErrors('entries.0.lines.0.row');
        $put(array_replace_recursive($good, ['lines' => [1 => ['line_key' => 'sofp.nope']]]))->assertStatus(422)->assertJsonValidationErrors('entries.0.lines.1.line_key');
        $put(array_replace_recursive($good, ['lines' => [0 => ['debit' => -10]]]))->assertStatus(422);
        $this->putJson(self::URL.'/'.Str::uuid().'/adjustments', ['entries' => [$good]])->assertNotFound();
        $this->assertSame([], $this->getJson(self::URL."/{$id}")->json('adjustments'));
    }

    public function test_an_entry_on_an_account_with_no_line_is_reported(): void
    {
        $rows   = $this->rows();
        $rows[] = $this->account(20, 'استثمارات طويلة الأجل', ['closing' => 0]);   // unmapped until the user picks a line
        $id     = $this->report($rows);
        $state  = $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [['reason' => 'شراء استثمار', 'lines' => [
            ['row' => 20, 'debit' => 70, 'credit' => 0], ['row' => 2, 'debit' => 0, 'credit' => 70]]]]])->assertOk()->json();

        $check = collect($state['checks'])->firstWhere('code', 'adjustment_unmapped_row');
        $this->assertSame(['error', [20], 1], [$check['severity'], $check['rows'], $check['entry']]);
        $this->assertContains('sofp_unbalanced', $this->codes($state));   // the debit reached no line: shown, never forced
    }
}
