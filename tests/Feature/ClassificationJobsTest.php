<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Repositories\Contracts\AccountClassifierRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\RecordingClassifier;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Background runs (queue is `sync` in tests, so the job runs during the POST) and batch concurrency. */
class ClassificationJobsTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    /** @return list<array> n accounts, rows 2..n+1 */
    private function accounts(int $n): array
    {
        return array_map(fn ($i) => ['1101'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), "صندوق {$i}", 0, 0, 10, 0, 10, 0], range(1, $n));
    }

    private function recording(): RecordingClassifier
    {
        $fake = new RecordingClassifier($this->standardStructure());
        $this->app->instance(AccountClassifierRepositoryInterface::class, $fake);
        return $fake;
    }

    public function test_submit_returns_202_then_poll_returns_the_same_result_as_the_sync_endpoint(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);
        $file = fn () => $this->trialBalance([['110101', 'الصندوق', 0, 0, 100, 0, 100, 0]]);

        $submit = $this->post('/api/v1/classify/jobs', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonStructure(['id', 'status', 'stage', 'progress' => ['done', 'total'], 'error', 'status_url', 'result']);

        $poll = $this->getJson($submit->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('error', null)
            ->assertJsonPath('progress', ['done' => 1, 'total' => 1]);

        $sync = $this->postJson('/api/v1/classify/trial-balance', ['file' => $file()])->assertOk();
        $this->assertSame($sync->json(), $poll->json('result'));
    }

    public function test_uploaded_file_is_deleted_after_the_run(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])->json('id');

        $this->assertNull(ClassificationRun::find($id)->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles('classification'));
    }

    public function test_failed_run_reports_the_error(): void
    {
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->xlsxUpload([])])->json('id');

        $this->getJson("/api/v1/classify/jobs/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'No account rows found in the file.')
            ->assertJsonPath('result', null);
    }

    public function test_the_company_chosen_at_upload_goes_with_the_run_and_its_report(): void
    {
        $company = \App\Models\Company::create(['name_ar' => 'شركة المثال'])->id;
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]);
        $id = $this->postJson('/api/v1/classify/jobs', ['company_id' => $company, 'file' => $this->trialBalance([
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0], ['3101', 'رأس المال', 0, 0, 0, 100, 0, 100],
        ])])->assertStatus(202)->assertJsonPath('company_id', $company)->json('id');

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('company_id', $company);
        $this->getJson('/api/v1/classify/jobs')->assertJsonPath('data.0.company_id', $company);
        $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated()->assertJsonPath('company_id', $company);
        $this->postJson('/api/v1/classify/jobs', ['company_id' => (string) \Illuminate\Support\Str::uuid(), 'file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_submit_validates_the_file(): void
    {
        $this->postJson('/api/v1/classify/jobs')->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_unknown_job_returns_404(): void
    {
        $this->getJson('/api/v1/classify/jobs/does-not-exist')->assertStatus(404)->assertJsonPath('message', 'Classification job not found.');
    }

    public function test_cache_is_warmed_then_all_batches_go_to_the_rolling_pool(): void
    {
        config(['classification.batch_size' => 2, 'classification.concurrency' => 4]);
        $fake = $this->recording();

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(11))])   // rows 2..12 → 6 batches
            ->assertOk()->assertJsonPath('meta.total', 11)->assertJsonPath('meta.needs_review', 0);

        $this->assertSame(['type' => 'warm', 'rows' => []], $fake->calls[0]);
        $this->assertSame(['type' => 'rolling', 'rows' => [[2, 3], [4, 5], [6, 7], [8, 9], [10, 11], [12]], 'concurrency' => 4], $fake->calls[1]);
        $this->assertCount(6, array_filter($fake->calls, fn ($c) => $c['type'] === 'request'));
    }

    public function test_single_batch_is_not_warmed(): void
    {
        $fake = $this->recording();

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(3))])->assertOk();

        $this->assertSame([], array_filter($fake->calls, fn ($c) => $c['type'] === 'warm'));
    }

    public function test_failed_request_is_retried_and_finished_batches_are_kept(): void
    {
        config(['classification.batch_size' => 2]);
        $fake = $this->recording();
        $fake->failures = [6 => 2];   // batch R6–R7 fails twice, the 3rd attempt succeeds

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(7))])
            ->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.needs_review', 0)
            ->assertJsonPath('meta.warnings', []);

        $this->assertCount(3, array_filter($fake->calls, fn ($c) => $c === ['type' => 'request', 'rows' => [6, 7]]));
    }

    public function test_batch_that_keeps_failing_comes_back_unclassified_as_a_partial_result(): void
    {
        config(['classification.batch_size' => 2]);
        $fake = $this->recording();
        $fake->failures = [4 => 3];   // every attempt fails

        $response = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(5))])
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.needs_review', 2)
            ->assertJsonPath('meta.warnings', [])   // reported as a partial result, not among the normal warnings
            ->assertJsonPath('meta.partial', true)
            ->assertJsonPath('meta.unclassified', ['rows' => 2, 'ranges' => ['R4–R5'], 'failed_batches' => 2, 'missed_by_ai' => 0]);

        $this->assertSame([null, null], array_column(array_slice($response->json('data'), 2, 2), 'sub_class'));
        $this->assertSame('نقدية بالصندوق', $response->json('data.4.sub_class'));   // later batch still kept
    }

    public function test_batch_1_failing_every_attempt_is_a_normal_partial_result(): void
    {
        config(['classification.batch_size' => 2]);
        $fake = $this->recording();
        $fake->failures = [2 => 3];   // batch R2–R3, every attempt

        $response = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(5))])
            ->assertOk()
            ->assertJsonPath('meta.needs_review', 2)
            ->assertJsonPath('meta.partial', true)
            ->assertJsonPath('meta.unclassified.ranges', ['R2–R3']);

        $this->assertSame([null, null], array_column(array_slice($response->json('data'), 0, 2), 'sub_class'));
        $this->assertSame('نقدية بالصندوق', $response->json('data.2.sub_class'));
    }

    public function test_fully_classified_file_is_not_partial(): void
    {
        config(['classification.batch_size' => 2]);
        $this->recording();

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(5))])
            ->assertOk()
            ->assertJsonPath('meta.partial', false)
            ->assertJsonPath('meta.unclassified', ['rows' => 0, 'ranges' => [], 'failed_batches' => 0, 'missed_by_ai' => 0]);
    }

    public function test_failed_and_missed_rows_are_counted_by_cause_with_merged_ranges(): void
    {
        config(['classification.batch_size' => 3]);
        $fake = $this->recording();
        $fake->failures = [5 => 3];   // batch R5–R7 fails every attempt
        $fake->missing  = [9];        // the AI leaves out R9, in its first answer and in the retry

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(9))])   // R2..R10
            ->assertOk()
            ->assertJsonPath('meta.partial', true)
            ->assertJsonPath('meta.unclassified', ['rows' => 4, 'ranges' => ['R5–R7', 'R9'], 'failed_batches' => 3, 'missed_by_ai' => 1])
            ->assertJsonPath('data.7.note', 'لم يتم تصنيف الحساب');
    }

    public function test_every_batch_failing_aborts_with_the_provider_error(): void
    {
        config(['classification.batch_size' => 2]);
        $fake = $this->recording();
        $fake->failures = [2 => 3, 4 => 3, 6 => 3];

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(5))])
            ->assertStatus(502)
            ->assertJsonPath('message', 'AI provider error, please retry.');

        $fake->failures = [2 => 3, 4 => 3, 6 => 3];
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($this->accounts(5))])->json('id');
        $this->getJson("/api/v1/classify/jobs/{$id}")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'AI provider error, please retry.');
    }

    public function test_progress_is_recorded_per_batch(): void
    {
        config(['classification.batch_size' => 2]);
        $this->recording();

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($this->accounts(6))])->json('id');

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('progress', ['done' => 3, 'total' => 3])->assertJsonPath('stage', null);
    }

    public function test_real_http_pool_sends_the_warm_call_and_one_request_per_batch(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        config(['classification.batch_size' => 1]);
        Sleep::fake();
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية بالصندوق'), $this->account(4, 'نقدية بالصندوق'),
        ]);

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(3))])
            ->assertOk()->assertJsonPath('meta.needs_review', 0);

        Http::assertSentCount(5);   // structure + warm + 3 batches
    }

    /** Regression: running out of credit mid-run used to be retried, keep going, and end as "done". */
    public function test_no_credit_stops_the_run_immediately_with_a_clear_message(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        config(['classification.batch_size' => 1]);
        Sleep::fake();
        $calls = 0;
        Http::fake([$this->aiUrl() => function (\Illuminate\Http\Client\Request $r) use (&$calls) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', $this->standardStructure()));
            }
            $calls++;
            return $calls === 1
                ? Http::response($this->toolResponse('submit_rows', ['rows' => [$this->account(2, 'نقدية بالصندوق')]]))
                : Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
                    'message' => 'Your credit balance is too low to access the Anthropic API.']], 400);
        }]);

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($this->accounts(6))])->json('id');

        $this->getJson("/api/v1/classify/jobs/{$id}")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'Anthropic API credit balance is too low. Add credits, then retry.');

        $this->assertLessThanOrEqual(5, $calls);   // 1 ok + one pool of 4 — no retries, no further batches
    }

    public function test_invalid_key_is_not_retried(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        Sleep::fake();
        Http::fake([$this->aiUrl() => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(2))])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Anthropic API key is invalid. Check ANTHROPIC_API_KEY.');

        Http::assertSentCount(1);
    }

    public function test_server_errors_are_still_retried(): void
    {
        Sleep::fake();
        $calls = 0;
        Http::fake([$this->aiUrl() => function (\Illuminate\Http\Client\Request $r) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response(['error' => 'overloaded'], 529);
            }
            return $this->isStructureCall($r)
                ? Http::response($this->toolResponse('submit_structure', $this->standardStructure()))
                : Http::response($this->toolResponse('submit_rows', ['rows' => [$this->account(2, 'نقدية بالصندوق')]]));
        }]);

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($this->accounts(1))])->assertOk();
        $this->assertSame(3, $calls);
    }
}
