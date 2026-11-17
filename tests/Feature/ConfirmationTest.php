<?php

namespace Tests\Feature;

use App\Jobs\ClassifyTrialBalance;
use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Step C: a queued run waits for the user to confirm the column mapping when it can't be trusted. */
class ConfirmationTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function eightAccounts()
    {
        return $this->trialBalance(array_map(fn ($i) => ['110'.$i, "صندوق {$i}", 0, 0, 10, 0, 10, 0], range(1, 8)));
    }

    private function labels(): array
    {
        return array_map(fn ($r) => $this->account($r, 'نقدية بالصندوق'), range(2, 9));
    }

    /** The AI maps the name column as closing debit on both attempts: errors after the retry. */
    private function submitWithWrongMapping(): string
    {
        $this->fakeLlm($this->standardStructure(['columns' => ['closing_debit' => 'B']]), $this->labels());

        return $this->postJson('/api/v1/classify/jobs', ['file' => $this->eightAccounts()])->json('id');
    }

    private function correctMapping(): array
    {
        $s = $this->standardStructure();

        return ['header_rows' => $s['header_rows'], 'first_data_row' => $s['first_data_row'], 'columns' => $s['columns']];
    }

    public function test_errors_after_the_retry_pause_the_run_with_a_column_preview(): void
    {
        $id = $this->submitWithWrongMapping();

        $response = $this->getJson("/api/v1/classify/jobs/{$id}")
            ->assertJsonPath('status', 'needs_confirmation')
            ->assertJsonPath('result', null)
            ->assertJsonPath('cancel_url', "/api/v1/classify/jobs/{$id}/cancel")
            ->assertJsonPath('confirmation.confirm_url', "/api/v1/classify/jobs/{$id}/structure")
            ->assertJsonPath('confirmation.structure.columns.closing_debit', 'B')
            ->assertJsonPath('confirmation.issues.0.severity', 'error')
            ->assertJsonPath('confirmation.fields.closing_debit', 'مدين آخر المدة');

        $columnB = collect($response->json('confirmation.columns'))->firstWhere('letter', 'B');
        $this->assertSame(['letter' => 'B', 'header' => 'اسم الحساب', 'samples' => ['صندوق 1', 'صندوق 2', 'صندوق 3'], 'fields' => ['account_name', 'closing_debit']], $columnB);
        $this->assertSame(['row' => 1, 'cells' => array_combine(range('A', 'H'), self::FULL_HEADER)], $response->json('confirmation.rows.0'));
        $this->assertArrayNotHasKey('ai', $response->json('confirmation.issues.0'));   // no English AI messages, no measurements
        $this->assertCount(1, Storage::disk('local')->allFiles('classification'));   // the file waits with the run
        $this->assertEqualsWithDelta(now()->addHours(24)->getTimestamp(), ClassificationRun::find($id)->expires_at->getTimestamp(), 5);
    }

    public function test_a_pausing_doubt_pauses_and_a_non_pausing_one_stays_a_warning(): void
    {
        $this->fakeLlm($this->standardStructure(['first_data_row' => 4]), $this->labels());   // rows_skipped

        $paused = $this->postJson('/api/v1/classify/jobs', ['file' => $this->eightAccounts()])->json('id');
        $this->getJson("/api/v1/classify/jobs/{$paused}")->assertJsonPath('status', 'needs_confirmation')
            ->assertJsonPath('confirmation.issues.0.check', 'rows_skipped');

        config(['classification.structure_checks.pause_on' => []]);
        $warned = $this->postJson('/api/v1/classify/jobs', ['file' => $this->eightAccounts()])->json('id');
        $this->getJson("/api/v1/classify/jobs/{$warned}")->assertJsonPath('status', 'done')
            ->assertJsonPath('result.meta.warnings.0', 'راجع قراءة الأعمدة: الصفوف 2، 3 في الملف قبل أول صف بيانات تبدو حسابات ولن تُحتسب.');
    }

    public function test_confirming_a_corrected_mapping_classifies_with_it_and_clears_the_waiting_data(): void
    {
        $log = TestHandler::class;
        config(['classification.log_channel' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => $log]]);
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $id = $this->submitWithWrongMapping();

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $this->correctMapping())->assertStatus(202)->assertJsonPath('confirmation', null);

        $this->getJson("/api/v1/classify/jobs/{$id}")   // the queue is sync in tests: the job already ran
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.meta.total', 8)
            ->assertJsonPath('result.meta.warnings', []);
        $run = ClassificationRun::find($id);
        $this->assertSame(['H', null, null, null, null], [$run->structure['columns']['closing_credit'], $run->file_path, $run->confirmation, $run->structure_issues, $run->expires_at]);
        $this->assertNotNull($run->confirmed_at);
        $this->assertSame([], Storage::disk('local')->allFiles('classification'));

        $confirmed = collect($handler->getRecords())->firstWhere('message', 'structure_confirmed');
        $this->assertSame(['closing_debit' => ['ai' => 'B', 'user' => 'G']], $confirmed->context['changed']);
    }

    public function test_a_mapping_that_still_has_errors_is_rejected_and_the_run_keeps_waiting(): void
    {
        $id = $this->submitWithWrongMapping();

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", ['columns' => ['account_name' => 'B', 'closing_net' => 'B']] + $this->correctMapping())
            ->assertStatus(422)
            ->assertJsonPath('problems.0', 'قُرئ العمود B على أنه أكثر من حقل في الوقت نفسه: «اسم الحساب» و«رصيد آخر المدة».');

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'needs_confirmation');
        $this->assertCount(1, Storage::disk('local')->allFiles('classification'));
    }

    public function test_new_pausing_doubts_need_an_explicit_second_confirm(): void
    {
        $id      = $this->submitWithWrongMapping();   // shown: errors only
        $skipped = ['first_data_row' => 4] + $this->correctMapping();   // fixes the columns, but skips R2–R3 (rows_skipped)

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $skipped)
            ->assertStatus(422)
            ->assertJsonPath('needs_acknowledgement', true)
            ->assertJsonPath('doubts', ['الصفوف 2، 3 في الملف قبل أول صف بيانات تبدو حسابات ولن تُحتسب.']);
        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'needs_confirmation');

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $skipped + ['acknowledge_doubts' => true])->assertStatus(202);
        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')->assertJsonPath('result.meta.total', 6);
    }

    public function test_doubts_already_shown_need_no_second_confirm(): void
    {
        $this->fakeLlm($this->standardStructure(['first_data_row' => 4]), $this->labels());   // paused on rows_skipped
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->eightAccounts()])->json('id');

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", ['first_data_row' => 4] + $this->correctMapping())->assertStatus(202);
    }

    public function test_confirm_is_refused_when_the_run_is_not_waiting(): void
    {
        $id = $this->submitWithWrongMapping();
        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $this->correctMapping())->assertStatus(202);

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $this->correctMapping())->assertStatus(409);   // confirmed twice
        $this->postJson('/api/v1/classify/jobs/nope/structure', $this->correctMapping())->assertStatus(404);
    }

    public function test_confirm_validates_the_body(): void
    {
        $id = $this->submitWithWrongMapping();

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", ['header_rows' => [1], 'first_data_row' => 0, 'columns' => ['made_up' => 'A', 'account_name' => 'b1']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_data_row', 'columns', 'columns.account_name']);
    }

    public function test_cancelling_a_waiting_run_deletes_the_file_and_the_preview(): void
    {
        $id = $this->submitWithWrongMapping();

        $this->postJson("/api/v1/classify/jobs/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancel_url', null);

        $run = ClassificationRun::find($id);
        $this->assertSame([null, null, null], [$run->file_path, $run->confirmation, $run->structure_issues]);
        $this->assertSame([], Storage::disk('local')->allFiles('classification'));
        $this->postJson("/api/v1/classify/jobs/{$id}/cancel")->assertStatus(409);
    }

    public function test_a_queued_run_can_be_cancelled_and_its_job_then_does_nothing(): void
    {
        Storage::disk('local')->put('classification/q.xlsx', 'data');
        ClassificationRun::forceCreate(['id' => 'q1', 'status' => 'queued', 'original_name' => 'q.xlsx', 'file_path' => 'classification/q.xlsx']);

        $this->postJson('/api/v1/classify/jobs/q1/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        app()->call([new ClassifyTrialBalance('q1'), 'handle']);

        $this->assertSame('cancelled', ClassificationRun::find('q1')->status);
        Storage::disk('local')->assertMissing('classification/q.xlsx');
    }

    public function test_a_running_or_finished_run_cannot_be_cancelled(): void
    {
        foreach (['running', 'done', 'failed'] as $status) {
            ClassificationRun::forceCreate(['id' => $status, 'status' => $status, 'original_name' => 'x.xlsx']);
            $this->postJson("/api/v1/classify/jobs/{$status}/cancel")->assertStatus(409);
            $this->assertSame($status, ClassificationRun::find($status)->status);
        }
    }

    public function test_prune_expires_abandoned_runs_and_deletes_orphaned_uploads(): void
    {
        $disk = Storage::disk('local');
        foreach (['old', 'fresh', 'active', 'orphan', 'recent-orphan'] as $name) {
            $disk->put("classification/{$name}.xlsx", 'data');
        }
        $old = now()->subHours(30)->getTimestamp();
        foreach (['old', 'active', 'orphan'] as $name) {
            touch($disk->path("classification/{$name}.xlsx"), $old);
        }
        $waiting = fn (string $id, $expires) => ClassificationRun::forceCreate(['id' => $id, 'status' => 'needs_confirmation', 'original_name' => 'x.xlsx',
            'file_path' => "classification/{$id}.xlsx", 'confirmation' => ['columns' => []], 'structure_issues' => [], 'expires_at' => $expires]);
        $waiting('old', now()->subHour());
        $waiting('fresh', now()->addHours(5));
        ClassificationRun::forceCreate(['id' => 'active', 'status' => 'running', 'original_name' => 'x.xlsx', 'file_path' => 'classification/active.xlsx']);

        $this->artisan('classification:prune')->expectsOutput('Expired 1 run(s), failed 0 stalled run(s), deleted 1 orphaned upload(s).')->assertSuccessful();

        $expired = ClassificationRun::find('old');
        $this->assertSame(['expired', null, null, null], [$expired->status, $expired->file_path, $expired->confirmation, $expired->structure_issues]);
        $this->assertSame('needs_confirmation', ClassificationRun::find('fresh')->status);
        $this->assertSame(['classification/active.xlsx', 'classification/fresh.xlsx', 'classification/recent-orphan.xlsx'], $disk->allFiles('classification'));
    }

    public function test_prune_runs_hourly_and_the_stalled_check_every_five_minutes(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'classification:prune'))
            ->mapWithKeys(fn ($e) => [trim(explode('classification:prune', $e->command)[1]) => $e->expression]);

        $this->assertSame(['' => '0 * * * *', '--stalled' => '*/5 * * * *'], $events->all());
    }

    public function test_demo_command_creates_a_waiting_run_from_a_file_and_a_wrong_mapping_without_the_ai(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('classification:demo-confirmation', ['file' => base_path('tests/Fixtures/balance.xlsx'), 'mapping' => base_path('tests/Fixtures/mappings/balance.json')])
            ->assertSuccessful();

        $run = ClassificationRun::sole();
        $this->assertSame(['needs_confirmation', 'G'], [$run->status, $run->structure['columns']['opening_debit']]);   // opening ↔ closing swapped
        $this->assertContains('groups_swapped', array_column($run->structure_issues, 'check'));
        Storage::disk('local')->assertExists($run->file_path);
        Http::assertNothingSent();
    }

    public function test_demo_command_refuses_outside_local(): void
    {
        $this->artisan('classification:demo-confirmation', ['file' => base_path('tests/Fixtures/balance.xlsx'), 'mapping' => base_path('tests/Fixtures/mappings/balance.json')])
            ->expectsOutput('Local environment only.')
            ->assertFailed();
        $this->assertSame(0, ClassificationRun::count());
    }

    public function test_sync_endpoint_never_waits_and_its_422_carries_the_mapping(): void
    {
        $this->fakeLlm($this->standardStructure(['columns' => ['closing_debit' => 'B']]), $this->labels());

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->eightAccounts()])
            ->assertStatus(422)
            ->assertJsonPath('structure.columns.closing_debit', 'B');
        $this->assertSame(0, ClassificationRun::count());
    }
}
