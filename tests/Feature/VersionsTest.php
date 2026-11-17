<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * PR 12: versions and the final gate (plan §4.1, §4.5, Q19, Q20): a draft can always be made; a final only with no
 * errors, every warning acknowledged, nothing missing and nothing pending; versions never change; a later final
 * supersedes the earlier; a run corrected afterwards is a warning to acknowledge.
 */
class VersionsTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** Cash, capital, payables, revenue and rent, closing balances only: a first year with a profit of 20. */
    private function rows(array $extra = []): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 150]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -200]),
            $this->account(6, 'إيجارات', ['closing' => 180]),
            ...$extra,
        ];
    }

    private function report(array $rows): string
    {
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة الاختبار', 'city' => 'الرياض', 'cr_number' => '1010000000',
            'cr_date_hijri' => '1440/01/01', 'unified_number' => '7000000000', 'activity' => 'التجارة', 'head_office' => 'الرياض'])->assertCreated()->json('id');
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, ['closing'])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'company_id' => $company, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated()->json('id');
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk();

        return $id;
    }

    /** Every question answered and every suggestion confirmed: only warnings may stand between it and a final. */
    private function ready(array $rows = [], bool $confirm = true): string
    {
        $id = $this->report($rows ?: $this->rows());
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => [
            'zakat.zero_reason' => 'الشركة في أول سنة ولا زكاة عليها.', 'oci.has_items' => false, 'approval.body' => 'الشركاء',
            'approval.date' => '2026-02-15', 'partners' => [['name' => 'الشريك الأول', 'shares' => 10, 'share_value' => 10]],
            'first_application.this_period' => false, 'policies.revenue' => ['goods'],
        ]])->assertOk();
        $pending = array_filter($this->state($id)['suggestions'], fn ($s) => $s['status'] === 'pending');
        if ($pending && $confirm) {
            $this->postJson(self::URL."/{$id}/suggestions", ['decisions' => array_values(array_map(fn ($s) => ['key' => $s['key'], 'action' => 'confirm'], $pending))])->assertOk();
        }

        return $id;
    }

    private function state(string $id): array
    {
        return $this->getJson(self::URL."/{$id}")->assertOk()->json();
    }

    private function version(string $id, string $kind): TestResponse
    {
        return $this->postJson(self::URL."/{$id}/versions", ['kind' => $kind]);
    }

    private function acknowledge(string $id, array $keys): TestResponse
    {
        return $this->putJson(self::URL."/{$id}/acknowledgements", ['acknowledgements' => array_map(fn ($k) => ['key' => $k], $keys)]);
    }

    private function warnings(array $state): array
    {
        return array_values(array_filter($state['checks'], fn ($c) => $c['severity'] === 'warning'));
    }

    private function reasons(array $state): array
    {
        return array_column($state['blocking'], 'count', 'reason');
    }

    public function test_a_ready_report_goes_final(): void
    {
        $id = $this->ready();
        $state = $this->state($id);
        $this->assertSame([], $state['blocking'], json_encode([$state['blocking'], $state['missing'], array_column($state['checks'], 'message_ar')], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($state['can_finalize']);
        $this->assertSame(['count' => 0, 'latest' => null, 'final' => null], $state['versions']);
        $this->assertNull($state['changed_since_final']);

        $v = $this->version($id, 'final')->assertCreated()->json();
        $this->assertSame([1, 'final', null], [$v['version'], $v['kind'], $v['superseded_by']]);
        $this->assertSame(['final', 1, 'final'], [$v['payload']['status'], $v['payload']['version'], $v['payload']['kind']]);
        $this->assertNotNull($v['payload']['finalized_at']);
        $this->assertArrayNotHasKey('versions', $v['payload']);

        $state = $this->state($id);
        $this->assertSame('final', $state['status']);
        $this->assertNotNull($state['finalized_at']);
        $this->assertSame(1, $state['versions']['final']['version']);
        $this->assertFalse($state['changed_since_final']);
    }

    public function test_a_draft_is_always_allowed_and_a_final_is_blocked_with_its_reasons(): void
    {
        $id = $this->report($this->rows());
        $state = $this->state($id);
        $this->assertFalse($state['can_finalize']);
        $reasons = $this->reasons($state);
        $this->assertGreaterThan(0, $reasons['errors']);
        $this->assertGreaterThan(0, $reasons['missing']);
        foreach ($state['blocking'] as $b) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $b['label_ar']);
        }

        $blocked = $this->version($id, 'final')->assertStatus(422)->json();
        $this->assertSame($state['blocking'], $blocked['blocking']);
        $this->assertSame([], array_filter($blocked['checks'], fn ($c) => $c['severity'] === 'info'));
        $this->assertStringContainsString('Errors to be fixed', $blocked['message']);
        $this->assertStringContainsString('أخطاء يجب تصحيحها', $this->postJson(self::URL."/{$id}/versions", ['kind' => 'final'], ['Accept-Language' => 'ar'])->assertStatus(422)->json('message'));
        $this->assertSame(0, FinancialReportVersion::count());
        $this->assertSame('draft', $this->state($id)['status']);

        $this->assertSame(1, $this->version($id, 'draft')->assertCreated()->json('version'));
        $this->assertSame(2, $this->version($id, 'draft')->assertCreated()->json('version'));
        $this->assertSame('draft', $this->state($id)['status']);
        $this->version($id, 'other')->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_missing_figures_and_pending_suggestions_block_a_final(): void
    {
        // The movement suggestions left pending.
        $id = $this->ready([], false);
        $state = $this->state($id);
        $pending = count(array_filter($state['suggestions'], fn ($s) => $s['status'] === 'pending'));
        $this->assertGreaterThan(0, $pending);
        $this->assertSame(['pending_suggestions' => $pending], $this->reasons($state));
        $this->version($id, 'final')->assertStatus(422)->assertJsonPath('blocking.0.reason', 'pending_suggestions');
        $this->postJson(self::URL."/{$id}/suggestions", ['decisions' => array_values(array_map(fn ($s) => ['key' => $s['key'], 'action' => 'confirm'],
            array_filter($state['suggestions'], fn ($s) => $s['status'] === 'pending')))])->assertOk();
        $this->assertTrue($this->state($id)['can_finalize']);

        // A missing figure: a detail of the company that the notes print (OCI is derived here, so it is not what is missing).
        $company = $this->state($id)['company']['id'];
        $this->patchJson('/api/v1/companies/'.$company, ['unified_number' => null])->assertOk();
        $state = $this->state($id);
        $this->assertContains('notes.company', array_column($state['missing'], 'key'));
        $this->assertArrayHasKey('missing', $this->reasons($state));
        $this->version($id, 'final')->assertStatus(422);

        $this->patchJson('/api/v1/companies/'.$company, ['unified_number' => '7000000000'])->assertOk();
        $this->assertTrue($this->state($id)['can_finalize']);
    }

    public function test_a_mapping_waiting_for_the_user_blocks_a_final(): void
    {
        // Periodic stock: the opening and closing stock accounts are proposed for cost of sales, pending.
        $rows = [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 0, 'period' => [1000, 0]]),
            $this->account(3, 'رأس المال', ['opening' => -500, 'period' => [0, 0]]),
            $this->account(9, 'موردون', ['opening' => 0, 'period' => [0, 700]]),
            $this->account(10, 'مخزون بضاعة', ['opening' => 500, 'period' => [0, 0]]),
            $this->account(11, 'مخزون بضاعة', ['opening' => 0, 'period' => [500, 0]]),
            $this->account(12, 'مخزون بضاعة', ['opening' => 0, 'period' => [0, 500]]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [0, 1000]]),
            $this->account(6, 'تكلفة المشتريات', ['period' => [700, 0]]),
        ];
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows)]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->assertSame(2, $this->reasons($this->state($id))['pending_mappings'] ?? null);

        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [
            ['row' => 11, 'line_key' => 'is.cost_of_revenue'], ['row' => 12, 'line_key' => 'is.cost_of_revenue'],
        ]])->assertOk()->json();
        $this->assertArrayNotHasKey('pending_mappings', $this->reasons($state));
    }

    public function test_warnings_need_an_acknowledgement_that_follows_their_amounts(): void
    {
        // Two rows the classifier wants reviewed: one warning, with their count.
        $rows = $this->rows([$this->account(7, 'موردون', ['closing' => -10], ['needs_review' => true]), $this->account(8, 'إيجارات', ['closing' => 10], ['needs_review' => true])]);
        $id = $this->ready($rows);
        $state = $this->state($id);
        $warnings = $this->warnings($state);
        $this->assertNotEmpty($warnings, json_encode(array_column($state['checks'], 'code')));
        $this->assertSame(count($warnings), $this->reasons($state)['warnings_unacknowledged'] ?? null);
        $this->assertFalse($warnings[0]['acknowledged']);

        // Errors and info can't be acknowledged; nor can a key the report doesn't have.
        $this->acknowledge($id, ['nope:0'])->assertStatus(422)->assertJsonValidationErrors('acknowledgements.0.key');
        $info = collect($state['checks'])->firstWhere('severity', '!=', 'warning');
        if ($info) {
            $this->acknowledge($id, [$info['key']])->assertStatus(422);
        }

        $state = $this->putJson(self::URL."/{$id}/acknowledgements", ['acknowledgements' => array_map(fn ($w) => ['key' => $w['key'], 'note' => 'تمت مراجعته.'], $warnings)])->assertOk()->json();
        $this->assertSame([], $state['blocking']);
        $this->assertTrue(collect($this->warnings($state))->every(fn ($w) => $w['acknowledged']));
        $this->assertSame('تمت مراجعته.', $state['acknowledgements'][0]['note']);
        $at = $state['acknowledgements'][0]['acknowledged_at'];

        // Sent again, an acknowledgement keeps its time.
        $this->travel(5)->minutes();
        $state = $this->acknowledge($id, array_column($warnings, 'key'))->assertOk()->json();
        $this->assertSame($at, $state['acknowledgements'][0]['acknowledged_at']);

        // The count changes (one row reviewed): a new key, and the warning needs a new acknowledgement.
        $report = FinancialReport::find($id);
        $result = $report->run->result;
        $result['data'][6]['needs_review'] = false;
        $report->run->forceFill(['result' => $result])->save();
        $state = $this->acknowledge($id, [collect($this->warnings($this->state($id)))->firstWhere('code', 'run_changed')['key']])->assertOk()->json();
        $changed = collect($this->warnings($state))->firstWhere('code', $warnings[0]['code']);
        $this->assertNotSame($warnings[0]['key'], $changed['key']);
        $this->assertFalse($changed['acknowledged']);
        $this->assertArrayHasKey('warnings_unacknowledged', $this->reasons($state));

        // Withdrawn all at once.
        $this->assertSame([], $this->acknowledge($id, [])->assertOk()->json('acknowledgements'));
    }

    public function test_a_corrected_run_is_a_warning_until_acknowledged(): void
    {
        $id = $this->ready();
        $report = FinancialReport::find($id);
        $result = $report->run->result;
        $result['data'][0]['account_name'] = 'نقدية لدى البنك الأهلي';
        $report->run->forceFill(['result' => $result])->save();

        $state = $this->state($id);
        $this->assertTrue($state['run_changed']);
        $check = collect($state['checks'])->firstWhere('code', 'run_changed');
        $this->assertSame('warning', $check['severity']);
        $this->assertSame(['warnings_unacknowledged' => 1], $this->reasons($state));

        $state = $this->acknowledge($id, [$check['key']])->assertOk()->json();
        $this->assertFalse($state['run_changed']);
        $this->assertNull(collect($state['checks'])->firstWhere('code', 'run_changed'));
        $this->assertSame([], $state['acknowledgements']);
        $this->assertTrue($state['can_finalize']);
    }

    public function test_a_later_final_supersedes_and_versions_never_change(): void
    {
        $id = $this->ready();
        $this->version($id, 'final')->assertCreated();
        $first = FinancialReportVersion::where('report_id', $id)->firstOrFail();
        $payload = $first->payload;

        // Edited after the final: the report differs from it, and the version stays as it was.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['approval.date' => '2026-03-01']])->assertOk();
        $state = $this->state($id);
        $this->assertTrue($state['changed_since_final']);
        $this->assertSame('final', $state['status']);
        $this->assertSame($payload, $first->fresh()->payload);
        $this->assertStringContainsString('15 فبراير 2026م', $payload);

        $this->version($id, 'draft')->assertCreated();
        $this->assertSame(3, $this->version($id, 'final')->assertCreated()->json('version'));
        $list = $this->getJson(self::URL."/{$id}/versions")->assertOk()->json('data');
        $this->assertSame([[3, 'final', null], [2, 'draft', null], [1, 'final', 3]], array_map(fn ($v) => [$v['version'], $v['kind'], $v['superseded_by']], $list));
        $this->assertArrayNotHasKey('payload', $list[0]);
        $this->assertSame(3, $this->getJson(self::URL."/{$id}/versions/1")->assertOk()->json('superseded_by'));
        $this->assertStringContainsString('1 مارس 2026م', json_encode($this->getJson(self::URL."/{$id}/versions/3")->json('payload.narrative'), JSON_UNESCAPED_UNICODE));
        $this->assertFalse($this->state($id)['changed_since_final']);
        $this->getJson(self::URL."/{$id}/versions/4")->assertNotFound();
        $this->getJson(self::URL.'/'.Str::uuid().'/versions/1')->assertNotFound();

        try {
            $first->update(['kind' => 'draft']);
            $this->fail('A version was updated.');
        } catch (LogicException) {
        }
        try {
            $first->delete();
            $this->fail('A version was deleted.');
        } catch (LogicException) {
        }
        $this->assertSame(['final', $payload], [$first->fresh()->kind, $first->fresh()->payload]);
    }

    public function test_a_first_year_misses_no_prior_year_in_its_notes(): void
    {
        $state = $this->state($this->report($this->rows()));
        $this->assertSame([], array_filter($state['missing'], fn ($m) => in_array($m['reason'], ['prior_year_incomplete', 'prior_year_source_required'], true)));
        // Each shown table with a year heading has the current year's only.
        foreach (array_filter($state['notes'], fn ($n) => $n['visible']) as $n) {
            foreach (array_filter($n['blocks'], fn ($b) => $b['type'] === 'table') as $b) {
                $cells = json_encode($b['rows'], JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString('2024م', $cells, $n['key']);
            }
        }
        $this->assertStringNotContainsString('[ناقص]', json_encode(collect($state['notes'])->firstWhere('key', 'cash')['blocks'], JSON_UNESCAPED_UNICODE));
    }
}
