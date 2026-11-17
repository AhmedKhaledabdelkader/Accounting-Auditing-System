<?php

namespace Tests\Feature;

use App\Jobs\ClassifyTrialBalance;
use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use RuntimeException;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Structure checks in the pipeline: doubts as warnings, retry with errors only, logging, upload cleanup. */
class StructureDetectionTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    private const SKIPPED = 'راجع قراءة الأعمدة: الصفوف 2، 3 في الملف قبل أول صف بيانات تبدو حسابات ولن تُحتسب.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function eightAccounts()
    {
        return $this->trialBalance(array_map(fn ($i) => ['110'.$i, "صندوق {$i}", 0, 0, 10, 0, 10, 0], range(1, 8)));
    }

    private function labels(): array
    {
        return array_map(fn ($r) => $this->account($r, 'نقدية بالصندوق'), range(2, 9));
    }

    private function captureLog(): TestHandler
    {
        config(['classification.log_channel' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

        return Log::channel('capture')->getLogger()->getHandlers()[0];
    }

    public function test_doubt_is_a_warning_and_the_run_continues_without_a_retry(): void
    {
        $this->fakeLlm($this->standardStructure(['first_data_row' => 4]), $this->labels());

        $this->postJson(self::URL, ['file' => $this->eightAccounts()])
            ->assertOk()
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('meta.warnings', [self::SKIPPED]);

        $this->assertCount(1, Http::recorded(fn (Request $r) => $this->isStructureCall($r)));
    }

    public function test_retry_is_told_the_errors_only(): void
    {
        $this->fakeLlm(
            fn (int $attempt) => $attempt === 1
                ? $this->standardStructure(['first_data_row' => 4, 'columns' => ['account_name' => 'Z']])   // an error and a doubt
                : $this->standardStructure(),
            $this->labels()
        );

        $this->postJson(self::URL, ['file' => $this->eightAccounts()])->assertOk()->assertJsonPath('meta.warnings', []);

        $retry = $this->userText(Http::recorded(fn (Request $r) => $this->isStructureCall($r))->last()[0]);
        $this->assertStringContainsString('account_name: column Z has no values in sheet 1.', $retry);
        $this->assertStringNotContainsString('look like accounts', $retry);
    }

    public function test_every_issue_is_logged_with_its_check_and_measurement(): void
    {
        $log = $this->captureLog();
        Storage::fake('local');
        $this->fakeLlm($this->standardStructure(['first_data_row' => 4]), $this->labels());

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->eightAccounts()])->json('id');

        $issue = collect($log->getRecords())->first(fn ($r) => $r->message === 'structure_issue');
        $this->assertSame(['run_id' => $id, 'stage' => 'structure', 'attempt' => 1, 'check' => 'rows_skipped', 'severity' => 'doubt',
            'field' => null, 'column' => null, 'value' => 2.0, 'threshold' => null], $issue->context);   // no cell values, no messages

        $summaries = collect($log->getRecords())->filter(fn ($r) => $r->message === 'structure_summary')->map->context->values();
        $this->assertSame(['structure', 1, 0, 1, ['rows_skipped']], [$summaries[0]['stage'], $summaries[0]['attempt'], $summaries[0]['errors'], $summaries[0]['doubts'], $summaries[0]['checks']]);
        $this->assertCount(1, $summaries);   // rows_skipped pauses the run: nothing classified yet
        $this->assertSame('needs_confirmation', ClassificationRun::find($id)->status);
    }

    public function test_failed_job_deletes_the_upload(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('classification/x.xlsx', 'data');
        $run = ClassificationRun::create(['id' => 'r1', 'status' => 'running', 'original_name' => 'x.xlsx', 'file_path' => 'classification/x.xlsx']);

        (new ClassifyTrialBalance($run->id))->failed(new RuntimeException('worker killed'));

        Storage::disk('local')->assertMissing('classification/x.xlsx');
        $this->assertSame(['failed', null], [$run->fresh()->status, $run->fresh()->file_path]);
    }

    public function test_classification_log_is_daily_and_kept_30_days(): void
    {
        $this->assertSame(['daily', 30], [config('logging.channels.classification.driver'), (int) config('logging.channels.classification.days')]);
    }
}
