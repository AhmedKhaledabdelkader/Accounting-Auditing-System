<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** PR 9: movement suggestions, asset notes, provision roll-forwards, the loans note; checks 15–19, 23, 26b, 26c (plan §3.6, Q8, Q36). */
class MovementSchedulesTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private function report(array $rows, array $groups = ['opening', 'period', 'closing']): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    private function decide(string $id, array $decisions)
    {
        return $this->postJson(self::URL."/{$id}/suggestions", ['decisions' => $decisions]);
    }

    private function overrides(string $id, array $overrides)
    {
        return $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => $overrides]);
    }

    private function schedule(array $state, string $key): array
    {
        return collect($state['schedules'])->firstWhere('key', $key) ?? $this->fail("no schedule {$key}");
    }

    /** [amount, origin, status] of a cell. */
    private function cell(array $state, string $schedule, string $row, string $column = 'total'): array
    {
        $f = collect($this->schedule($state, $schedule)['rows'])->firstWhere('key', $row)['cells'][$column];

        return [$f['amount'] === null ? null : (float) $f['amount'], $f['origin'], $f['status']];
    }

    private function suggestion(array $state, string $key): ?array
    {
        return collect($state['suggestions'])->firstWhere('key', $key);
    }

    private function checks(array $state, string $code): array
    {
        return array_values(array_filter($state['checks'], fn ($c) => $c['code'] === $code));
    }

    /** Vehicles bought 50 and sold 10 (accumulated depreciation 4); the year's depreciation 15; a loss on disposal of 3. */
    private function assetRows(): array
    {
        return [
            $this->account(2, 'سيارات ووسائل نقل', ['opening' => 100, 'period' => [50, 10]]),
            $this->account(3, 'مجمع إهلاك الأصول الثابتة', ['opening' => -20, 'period' => [4, 15]]),
            $this->account(4, 'نقدية لدى البنوك', ['opening' => 20, 'period' => [3, 50]]),
            $this->account(5, 'رأس المال', ['opening' => -100]),
            $this->account(6, 'إهلاك السيارات', ['period' => [15, 0]]),
            $this->account(7, 'خسائر بيع أصول ثابتة', ['period' => [3, 0]]),
        ];
    }

    public function test_movements_are_suggestions_the_user_confirms_edits_or_rejects(): void
    {
        $id    = $this->report($this->assetRows());
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        // The accumulated depreciation account has no category: its side shows the total only (Q8).
        $this->assertSame(['cost' => true, 'accumulated' => false], $this->schedule($state, 'ppe')['split']);
        $this->assertContains(['key' => 'ppe.accumulated', 'reason' => 'category_split_required', 'input_key' => null], $state['missing']);
        $this->assertSame([null, 'missing', 'missing'], $this->cell($state, 'ppe', 'acc_closing', 'vehicles'));
        $this->assertSame([31.0, 'trial_balance', 'ok'], $this->cell($state, 'ppe', 'acc_closing'));
        $this->assertSame([15.0, 'suggested_from_movement', 'pending_confirmation'], $this->cell($state, 'ppe', 'depreciation'));

        $state = $this->overrides($id, [['row' => 3, 'category' => 'vehicles']])->assertOk()->json();
        $this->assertSame(['cost' => true, 'accumulated' => true], $this->schedule($state, 'ppe')['split']);
        $this->assertSame(['vehicles', 'total'], array_column($this->schedule($state, 'ppe')['columns'], 'key'));
        foreach (['additions' => 50.0, 'disposals' => 10.0, 'depreciation' => 15.0, 'acc_disposals' => 4.0] as $row => $amount) {
            $this->assertSame([$amount, 'suggested_from_movement', 'pending_confirmation'], $this->cell($state, 'ppe', $row, 'vehicles'), $row);
            $this->assertSame('pending', $this->suggestion($state, "ppe.{$row}.vehicles")['status']);
        }
        $this->assertSame([[140.0, 'derived'], [109.0, 'derived'], [80.0, 'derived']], [array_slice($this->cell($state, 'ppe', 'cost_closing'), 0, 2),
            array_slice($this->cell($state, 'ppe', 'nbv_closing'), 0, 2), array_slice($this->cell($state, 'ppe', 'nbv_opening'), 0, 2)]);
        $this->assertSame([0.0, 'derived', 'ok'], $this->cell($state, 'ppe', 'transfers', 'vehicles'));   // no CWIP
        $this->assertNull($this->suggestion($state, 'ppe.transfers.vehicles'));
        $this->assertSame([], $this->checks($state, 'asset_rollforward'));
        $this->assertSame([], $this->checks($state, 'depreciation_mismatch'));

        // Confirm, edit, reject, reset.
        $state = $this->decide($id, [['key' => 'ppe.additions.vehicles', 'action' => 'confirm'], ['key' => 'ppe.disposals.vehicles', 'action' => 'edit', 'amount' => 12],
            ['key' => 'ppe.depreciation.vehicles', 'action' => 'reject']])->assertOk()->json();
        $this->assertSame([50.0, 'derived_from_movement', 'ok'], $this->cell($state, 'ppe', 'additions', 'vehicles'));
        $this->assertSame([12.0, 'user_input', 'ok'], $this->cell($state, 'ppe', 'disposals', 'vehicles'));
        $this->assertSame(['edited', 12], [$this->suggestion($state, 'ppe.disposals.vehicles')['status'], $this->suggestion($state, 'ppe.disposals.vehicles')['value']]);
        $this->assertSame([null, 'missing', 'missing'], $this->cell($state, 'ppe', 'depreciation', 'vehicles'));
        $this->assertContains(['key' => 'ppe.depreciation.vehicles', 'reason' => 'suggestion_rejected', 'input_key' => null], $state['missing']);
        // The edited disposals break the cost roll-forward (an error); the rejected charge leaves its own unchecked.
        $this->assertSame([['asset_rollforward', 'error', 'vehicles', 'cost', -2]], array_map(fn ($c) => [$c['code'], $c['severity'], $c['column'], $c['kind'], $c['difference']],
            $this->checks($state, 'asset_rollforward')));

        $state = $this->decide($id, [['key' => 'ppe.disposals.vehicles', 'action' => 'reset'], ['key' => 'ppe.depreciation.vehicles', 'action' => 'edit', 'amount' => 14]])->assertOk()->json();
        $this->assertSame('pending', $this->suggestion($state, 'ppe.disposals.vehicles')['status']);
        $this->assertSame(['ppe.additions.vehicles' => ['action' => 'confirm', 'amount' => 50], 'ppe.depreciation.vehicles' => ['action' => 'edit', 'amount' => 14]], $state['movement_decisions']);
        // 14 against an expense of 15: check 16, and the accumulated depreciation no longer rolls (15).
        $this->assertSame([[15, 14, -1]], array_map(fn ($c) => [$c['expected'], $c['actual'], $c['difference']], $this->checks($state, 'depreciation_mismatch')));
        $this->assertSame(['accumulated'], array_column($this->checks($state, 'asset_rollforward'), 'kind'));

        // A confirmation is of an amount: when the trial balance suggests another, the suggestion is pending again.
        $report = FinancialReport::find($id);
        $report->update(['movement_decisions' => ['ppe.additions.vehicles' => ['action' => 'confirm', 'amount' => 49]]]);
        $this->assertSame('pending', $this->suggestion($this->getJson(self::URL."/{$id}")->json(), 'ppe.additions.vehicles')['status']);

        // Refused: a key that isn't a movement here, confirming a movement with no suggestion, edit without an amount.
        foreach ([[['key' => 'ppe.nope.vehicles', 'action' => 'confirm']], [['key' => 'ppe.transfers.vehicles', 'action' => 'confirm']],
            [['key' => 'ppe.additions.vehicles', 'action' => 'edit']], [['key' => 'ppe.additions.vehicles', 'action' => 'confirm', 'amount' => 3]],
            [['key' => 'ppe.additions.vehicles', 'action' => 'drop']]] as $decisions) {
            $this->decide($id, $decisions)->assertStatus(422);
        }
        $this->decide($id, [])->assertStatus(422);
        $this->decide((string) Str::uuid(), [['key' => 'x', 'action' => 'reset']])->assertNotFound();
    }

    public function test_disposals_need_proceeds(): void
    {
        $id    = $this->report($this->assetRows());
        $state = $this->overrides($id, [['row' => 3, 'category' => 'vehicles']])->assertOk()->json();

        // Check 18: disposals and no proceeds; the intake asks for them.
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'ppe.disposal_proceeds');
        $this->assertSame([true, 'disposal_without_proceeds'], [$field['required'], $field['check']]);
        $this->assertSame([['error', 'ppe.disposal_proceeds']], array_map(fn ($c) => [$c['severity'], $c['input_key']], $this->checks($state, 'disposal_without_proceeds')));

        // Proceeds 3 − net book value 6 = a loss of 3, as in the trial balance.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['ppe.disposal_proceeds' => 3]])->assertOk()->json();
        $this->assertSame([], $this->checks($state, 'disposal_without_proceeds'));
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['ppe.disposal_proceeds' => 5]])->assertOk()->json();
        $this->assertSame([['warning', 'gain_loss', -1, -3]], array_map(fn ($c) => [$c['severity'], $c['kind'], $c['expected'], $c['actual']], $this->checks($state, 'disposal_without_proceeds')));
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['ppe.disposal_proceeds' => -1]])->assertStatus(422)->assertJsonValidationErrors('inputs.ppe.disposal_proceeds');

        // No disposals: not asked.
        $state = $this->decide($id, [['key' => 'ppe.disposals.vehicles', 'action' => 'edit', 'amount' => 0]])->assertOk()->json();
        $this->assertNotContains('ppe.disposal_proceeds', array_column($this->getJson(self::URL."/{$id}/intake")->json('fields'), 'key'));
    }

    public function test_a_disposal_result_with_nothing_disposed_is_flagged_and_kept_as_other_income_by_the_users_click(): void
    {
        // A gain on disposal of 80 received in cash, and no asset disposed: the cash flow would take 80 out of operating and
        // look for proceeds that aren't there.
        $id = $this->report([
            $this->account(2, 'سيارات ووسائل نقل', ['opening' => 100]),
            $this->account(3, 'نقدية لدى البنوك', ['opening' => 20, 'period' => [80, 0]]),
            $this->account(4, 'رأس المال', ['opening' => -120]),
            $this->account(5, 'أرباح بيع أصول ثابتة', ['period' => [0, 80]]),
        ]);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $cf    = fn (array $state, string $key) => collect($state['statements']['cash_flows'])->firstWhere('key', $key)['current']['amount'];
        $line  = fn (array $state, string $key) => collect($state['statements']['comprehensive_income'])->firstWhere('key', $key)['current']['amount'];

        // Flagged with its amount and account; never moved by itself.
        $this->assertSame([['warning', [5], 80]], array_map(fn ($c) => [$c['severity'], $c['rows'], $c['amount']], $this->checks($state, 'disposal_unmatched')));
        $this->assertStringContainsString('80', $this->checks($state, 'disposal_unmatched')[0]['message_ar']);
        $this->assertEquals(-80, $cf($state, 'cf.disposal_gain_loss'));
        $this->assertSame([], $this->checks($state, 'disposal_pending'));

        // The user's click: other income, out of the disposal results; flagged until the accountant confirms it.
        $state = $this->overrides($id, [['row' => 5, 'tags' => ['not_disposal']]])->assertOk()->json();
        $this->assertSame([], $this->checks($state, 'disposal_unmatched'));
        $this->assertSame([['warning', [5], 80]], array_map(fn ($c) => [$c['severity'], $c['rows'], $c['amount']], $this->checks($state, 'disposal_pending')));
        $this->assertEquals(80, $line($state, 'is.other_income'));
        $this->assertEquals(0, $cf($state, 'cf.disposal_gain_loss'));
        $this->assertEquals(80, $cf($state, 'cf.net_change'));
        $this->assertSame([], $this->checks($state, 'cash_flow_closing_mismatch'));

        // Undone with one click: a disposal result again.
        $state = $this->overrides($id, [])->assertOk()->json();
        $this->assertCount(1, $this->checks($state, 'disposal_unmatched'));
        $this->assertSame([], $this->checks($state, 'disposal_pending'));
    }

    public function test_a_disposal_result_with_an_asset_disposed_is_not_flagged_as_unmatched(): void
    {
        $id    = $this->report($this->assetRows());
        $state = $this->overrides($id, [['row' => 3, 'category' => 'vehicles']])->assertOk()->json();

        $this->assertSame([], $this->checks($state, 'disposal_unmatched'));
    }

    public function test_cwip_credits_are_suggested_as_transfers(): void
    {
        $id = $this->report([
            $this->account(2, 'مشروعات تحت التنفيذ', ['opening' => 0, 'period' => [30, 30]]),
            $this->account(3, 'مباني وإنشاءات', ['opening' => 0, 'period' => [30, 0]]),
            $this->account(4, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [0, 30]]),
            $this->account(5, 'رأس المال', ['opening' => -100]),
        ]);
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame(['buildings', 'cwip', 'total'], array_column($this->schedule($state, 'ppe')['columns'], 'key'));
        $this->assertSame([-30.0, 'suggested_from_movement', 'pending_confirmation'], $this->cell($state, 'ppe', 'transfers', 'cwip'));
        $this->assertSame([0.0, 'derived_from_movement', 'ok'], $this->cell($state, 'ppe', 'disposals', 'cwip'));
        // Where the transfer went is the user's: the building's debit is suggested as an addition, its transfer is missing.
        $this->assertSame([null, 'missing', 'missing'], $this->cell($state, 'ppe', 'transfers', 'buildings'));

        $state = $this->decide($id, [['key' => 'ppe.transfers.cwip', 'action' => 'confirm'], ['key' => 'ppe.additions.buildings', 'action' => 'edit', 'amount' => 0],
            ['key' => 'ppe.transfers.buildings', 'action' => 'edit', 'amount' => 30], ['key' => 'ppe.additions.cwip', 'action' => 'confirm']])->assertOk()->json();
        $this->assertSame([[30.0, 0.0, 30.0], 0.0], [[$this->cell($state, 'ppe', 'additions')[0], $this->cell($state, 'ppe', 'transfers')[0], $this->cell($state, 'ppe', 'cost_closing')[0]],
            $this->cell($state, 'ppe', 'transfers', 'cwip')[0] + 30]);
        $this->assertSame([], $this->checks($state, 'asset_rollforward'));
    }

    public function test_opening_and_closing_only_the_movements_are_the_users(): void
    {
        $id = $this->report([
            $this->account(2, 'سيارات ووسائل نقل', ['opening' => 100, 'closing' => 140]),
            $this->account(3, 'مجمع إهلاك الأصول الثابتة', ['opening' => -20, 'closing' => -35]),
            $this->account(4, 'إهلاك السيارات', ['closing' => 15]),
            $this->account(5, 'مخصص نهاية الخدمة', ['opening' => -10, 'closing' => -13]),
            $this->account(6, 'مصروف مكافأة نهاية الخدمة', ['closing' => 8]),
        ], ['opening', 'closing']);
        $state = $this->getJson(self::URL."/{$id}")->json();

        $this->assertSame([], $state['suggestions']);
        $this->assertSame([null, 'missing', 'missing'], $this->cell($state, 'ppe', 'additions', 'vehicles'));
        $this->assertContains(['key' => 'ppe.disposals.vehicles', 'reason' => 'movement_required', 'input_key' => null], $state['missing']);
        // One column of accumulated depreciation: the charge is the expense, disposals follow (20 + 15 − 35).
        $this->assertSame([[15.0, 'trial_balance'], [0.0, 'derived']], [array_slice($this->cell($state, 'ppe', 'depreciation'), 0, 2), array_slice($this->cell($state, 'ppe', 'acc_disposals'), 0, 2)]);
        // End-of-service: the charge is the expense, paid follows (10 + 8 − 13).
        $this->assertSame([[8.0, 'trial_balance'], [5.0, 'derived'], [0.0, 'derived']], [array_slice($this->cell($state, 'employee_benefits', 'charge', 'amount'), 0, 2),
            array_slice($this->cell($state, 'employee_benefits', 'paid', 'amount'), 0, 2), array_slice($this->cell($state, 'employee_benefits', 'reversal', 'amount'), 0, 2)]);

        // The user's split of the net change (40): checked against it (19, not 15).
        $state = $this->decide($id, [['key' => 'ppe.additions.vehicles', 'action' => 'edit', 'amount' => 50], ['key' => 'ppe.disposals.vehicles', 'action' => 'edit', 'amount' => 5]])->assertOk()->json();
        $this->assertSame([['error', 'cost', 5]], array_map(fn ($c) => [$c['severity'], $c['kind'], $c['difference']], $this->checks($state, 'split_total_mismatch')));
        $this->assertSame([], $this->checks($state, 'asset_rollforward'));
        $state = $this->decide($id, [['key' => 'ppe.disposals.vehicles', 'action' => 'edit', 'amount' => 10]])->assertOk()->json();
        $this->assertSame([], $this->checks($state, 'split_total_mismatch'));
        $this->assertNotContains('ppe.additions.vehicles', array_column($state['missing'], 'key'));
    }

    public function test_closing_only_the_opening_comes_from_the_prior_year_or_the_user(): void
    {
        $id = $this->report([
            $this->account(2, 'سيارات ووسائل نقل', ['closing' => 140]),
            $this->account(3, 'مخصص نهاية الخدمة', ['closing' => -13]),
            $this->account(4, 'مصروف مكافأة نهاية الخدمة', ['closing' => 8]),
        ], ['closing']);
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame([[null, 'missing'], [null, 'missing']], [array_slice($this->cell($state, 'employee_benefits', 'opening', 'amount'), 0, 2),
            array_slice($this->cell($state, 'employee_benefits', 'paid', 'amount'), 0, 2)]);
        $this->assertContains('ppe.cost_opening.vehicles', array_column($state['missing'], 'key'));

        // The prior year's balance sheet gives a provision's opening; an asset's opening by category is the user's.
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.employee_benefits' => 10, 'sofp.ppe' => 100]])->assertOk();
        $state = $this->decide($id, [['key' => 'ppe.cost_opening.vehicles', 'action' => 'edit', 'amount' => 100]])->assertOk()->json();
        $this->assertSame([[10.0, 'prior_year_manual'], [5.0, 'derived']], [array_slice($this->cell($state, 'employee_benefits', 'opening', 'amount'), 0, 2),
            array_slice($this->cell($state, 'employee_benefits', 'paid', 'amount'), 0, 2)]);
        $this->assertSame([100.0, 'user_input', 'ok'], $this->cell($state, 'ppe', 'cost_opening', 'vehicles'));
    }

    public function test_provisions_reversals_and_their_pl_counterparts(): void
    {
        $id = $this->report([
            // End-of-service: charged 53, of which 1 reversed (credited back to the expense); nothing paid.
            $this->account(2, 'مخصص نهاية الخدمة', ['opening' => -63, 'period' => [1, 53]]),
            $this->account(3, 'مصروف مكافأة نهاية الخدمة', ['period' => [53, 1]]),
            // Impairment of receivables: 0.7 charged with no expense account; 12 written off.
            $this->account(4, 'مخصص ديون مشكوك في تحصيلها', ['opening' => -130, 'period' => [12, 0.7]]),
            $this->account(5, 'عملاء', ['opening' => 500, 'period' => [100, 50]]),
            // Zakat provision used, no zakat for the year.
            $this->account(6, 'مخصص الزكاة الشرعية', ['opening' => -134, 'period' => [134, 0]]),
            // Warranty: charged 200, used 191, its expense in an account of another class.
            $this->account(7, 'مخصص ضمان', ['opening' => -13, 'period' => [191, 200]]),
            $this->account(8, 'صيانة وإصلاحات', ['period' => [160, 0]]),
        ]);
        $state = $this->getJson(self::URL."/{$id}")->json();

        // 26c: the end-of-service debit is a reversal, not a payment.
        $this->assertSame([[53.0, 'pending_confirmation'], [1.0, 'pending_confirmation'], [0.0, 'ok']], [
            [$this->cell($state, 'employee_benefits', 'charge', 'amount')[0], $this->cell($state, 'employee_benefits', 'charge', 'amount')[2]],
            [$this->cell($state, 'employee_benefits', 'reversal', 'amount')[0], $this->cell($state, 'employee_benefits', 'reversal', 'amount')[2]],
            [$this->cell($state, 'employee_benefits', 'paid', 'amount')[0], $this->cell($state, 'employee_benefits', 'paid', 'amount')[2]]]);
        $this->assertSame([['info', 'employee_benefits', 1]], array_map(fn ($c) => [$c['severity'], $c['schedule'], $c['actual']], $this->checks($state, 'reversal_as_payment')));
        $this->assertSame([], $this->checks($state, 'provision_rollforward'));

        // 26b: the impairment charge has no expense; the warranty provision has no linked expense account.
        $this->assertEqualsCanonicalizing([['receivables_impairment', 0, 0.7], ['other_provisions', null, 200]],
            array_map(fn ($c) => [$c['schedule'], $c['expected'], $c['actual']], $this->checks($state, 'provision_without_pl_counterpart')));
        $this->assertSame([12.0, 'written_off'], [$this->cell($state, 'receivables_impairment', 'written_off', 'amount')[0], $this->schedule($state, 'receivables_impairment')['decrease']]);

        // 23: zakat used, no charge for the year.
        $this->assertSame([['warning', 134]], array_map(fn ($c) => [$c['severity'], $c['actual']], $this->checks($state, 'zakat_used_no_charge')));

        // Tagging the warranty expense links it: 200 charged vs 160 expensed.
        $state = $this->overrides($id, [['row' => 8, 'tags' => ['provision_charge']]])->assertOk()->json();
        $this->assertSame([['other_provisions', 160, 200, 40]], array_map(fn ($c) => [$c['schedule'], $c['expected'], $c['actual'], $c['difference']],
            array_values(array_filter($this->checks($state, 'provision_without_pl_counterpart'), fn ($c) => $c['schedule'] === 'other_provisions'))));

        // The zakat charge input reaches the roll-forward and clears 23.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.charge' => 90]])->assertOk()->json();
        $this->assertSame([[90.0, 'derived'], [90.0, 'derived']], [array_slice($this->cell($state, 'zakat', 'charge', 'amount'), 0, 2), array_slice($this->cell($state, 'zakat', 'closing', 'amount'), 0, 2)]);
        $this->assertSame([], $this->checks($state, 'zakat_used_no_charge'));
        $this->assertSame([], $this->checks($state, 'provision_rollforward'));
    }

    public function test_no_zakat_with_its_reason_says_the_provisions_movement_once(): void
    {
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 50, 'period' => [100, 40]]),
            $this->account(3, 'مخصص الزكاة الشرعية', ['opening' => -50, 'period' => [40, 20]]),   // 20 recognised, 40 used, no expense
            $this->account(4, 'إيرادات المبيعات', ['period' => [0, 100]]),
            $this->account(5, 'إيجارات', ['period' => [20, 0]]),
        ]);
        $codes = fn (array $s) => array_column($s['checks'], 'code');
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertContains('zakat_used_no_charge', $codes($state));
        $this->assertSame(['zakat'], array_column($this->checks($state, 'provision_without_pl_counterpart'), 'schedule'));

        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.zero_reason' => 'سبب تجريبي']])->assertOk()->json();
        $this->assertNotContains('zakat_used_no_charge', $codes($state));
        $this->assertNotContains('provision_without_pl_counterpart', $codes($state));
        $this->assertNotContains('zakat_charge_missing', $codes($state));
        $info = $this->checks($state, 'zakat_zero_movements');
        $this->assertSame([['info', 30]], array_map(fn ($c) => [$c['severity'], $c['actual']], $info));
        $this->assertSame('لا توجد زكاة على السنة (سببها مكتوب في إيضاح الزكاة). حركة مخصص الزكاة: أول السنة 50.00، المكوّن 20.00، المستخدم أو المسدد 40.00، آخر السنة 30.00.', $info[0]['message_ar']);
    }

    public function test_the_loans_note_and_the_current_portion(): void
    {
        $id = $this->report([
            $this->account(2, 'قروض طويلة الأجل', ['opening' => -400, 'period' => [100, 50]]),
            $this->account(3, 'مصروفات مدفوعة مقدماً', ['opening' => 40, 'period' => [0, 10]]),   // the deferred finance cost
            $this->account(4, 'نقدية لدى البنوك', ['opening' => 460, 'period' => [50, 100]]),
            $this->account(5, 'رأس المال', ['opening' => -100]),
            $this->account(6, 'فوائد قروض', ['period' => [10, 0]]),
        ]);
        $this->assertSame('sofp.prepayments_other_receivables', collect($this->getJson(self::URL."/{$id}")->json('statements.financial_position'))->firstWhere('key', 'sofp.prepayments_other_receivables')['key']);

        // Q36: tagged, the deferred finance cost is netted against the loans, with no wrong-side warning.
        $state = $this->overrides($id, [['row' => 3, 'tags' => ['deferred_finance_cost']]])->assertOk()->json();
        $loans = collect($this->schedule($state, 'loans')['rows'])->mapWithKeys(fn ($r) => [$r['key'] => [(float) $r['cells']['current']['amount'], (float) $r['cells']['comparative']['amount']]])->all();
        $this->assertSame(['gross' => [350.0, 400.0], 'deferred_finance_cost' => [30.0, 40.0], 'net' => [320.0, 360.0], 'non_current' => [320.0, 360.0], 'current' => [0.0, 0.0]], $loans);
        $this->assertNotContains('wrong_side_balance', array_column($state['checks'], 'code'));
        $line = fn (array $s, string $k) => collect($s['statements']['financial_position'])->firstWhere('key', $k)['current']['amount'] + 0.0;
        $this->assertSame([320.0, 0.0], [$line($state, 'sofp.murabaha_loans_non_current'), $line($state, 'sofp.prepayments_other_receivables')]);

        // The current portion is required, and moves out of the long-term line.
        $this->assertContains('loans.current_portion', $state['required_inputs']);
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['loans.current_portion' => 120]])->assertOk()->json();
        $this->assertSame([200.0, 120.0], [$line($state, 'sofp.murabaha_loans_non_current'), $line($state, 'sofp.murabaha_loans_current')]);
        $this->assertSame($line($state, 'sofp.total_assets'), $line($state, 'sofp.total_equity_and_liabilities'));
        $this->assertSame([], $this->checks($state, 'split_total_mismatch'));

        // 19: more than the loans.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['loans.current_portion' => 400]])->assertOk()->json();
        $this->assertSame([['error', 'loans.current_portion', -80]], array_map(fn ($c) => [$c['severity'], $c['input_key'], $c['actual']], $this->checks($state, 'split_total_mismatch')));
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['loans.current_portion' => -5]])->assertStatus(422);
    }

    public function test_entries_by_account_reach_the_note_and_entries_by_line_are_flagged(): void
    {
        $id = $this->report($this->assetRows());
        $this->overrides($id, [['row' => 3, 'category' => 'vehicles']])->assertOk();

        // Depreciation not booked: Dr the expense account, Cr the accumulated depreciation account.
        $state = $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [['reason' => 'إهلاك غير مسجل', 'lines' => [
            ['row' => 6, 'debit' => 5, 'credit' => 0], ['row' => 3, 'debit' => 0, 'credit' => 5]]]]])->assertOk()->json();
        $this->assertSame([20.0, 'derived', 'pending_confirmation'], $this->cell($state, 'ppe', 'depreciation', 'vehicles'));
        $this->assertSame([36.0, 'derived'], array_slice($this->cell($state, 'ppe', 'acc_closing', 'vehicles'), 0, 2));
        $this->assertSame([], $this->checks($state, 'asset_rollforward'));
        $this->assertSame([], $this->checks($state, 'depreciation_mismatch'));

        // An entry on the line itself can't be placed in a category: the note no longer matches the line (15).
        $state = $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [['reason' => 'إعادة تبويب', 'lines' => [
            ['line_key' => 'sofp.ppe', 'debit' => 7, 'credit' => 0], ['line_key' => 'sofp.cash', 'debit' => 0, 'credit' => 7]]]]])->assertOk()->json();
        $this->assertSame([['statement_line', 7]], array_map(fn ($c) => [$c['kind'], $c['difference']], $this->checks($state, 'asset_rollforward')));
    }

    public function test_override_validation(): void
    {
        $id = $this->report($this->assetRows());
        foreach ([
            [['row' => 4, 'category' => 'vehicles']],          // cash: no asset note
            [['row' => 2, 'category' => 'software']],          // not a PPE category
            [['row' => 2, 'tags' => ['nope']]],
            [['row' => 2]],
            [['row' => 2, 'line_key' => 'sofp.ppe', 'shares' => ['sofp.ppe' => 0.5, 'sofp.cash' => 0.5]]],
        ] as $overrides) {
            $this->overrides($id, $overrides)->assertStatus(422);
        }
        // A row moved to intangible assets takes that note's categories.
        $state = $this->overrides($id, [['row' => 2, 'line_key' => 'sofp.intangible_assets', 'category' => 'software']])->assertOk()->json();
        $this->assertSame(['software', 'total'], array_column($this->schedule($state, 'intangible_assets')['columns'], 'key'));
        $this->assertSame([['row' => 2, 'line_key' => 'sofp.intangible_assets', 'category' => 'software']], $state['account_overrides']);
    }
}
