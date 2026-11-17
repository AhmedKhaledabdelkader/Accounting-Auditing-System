<?php

namespace Tests\Feature;

use App\Jobs\ClassifyTrialBalance;
use App\Models\ClassificationRun;
use App\Repositories\Contracts\AccountClassifierRepositoryInterface;
use App\Repositories\Eloquents\AccountClassifierRepository;
use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\RecordingClassifier;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** A run belongs to the worker holding its lease: a second pick-up, a stale worker or a crash can't corrupt it. */
class JobLeaseTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function makeRun(string $status, ?\DateTimeInterface $heartbeat): ClassificationRun
    {
        Storage::disk('local')->put('classification/r.xlsx', 'data');

        return ClassificationRun::forceCreate(['id' => 'r1', 'status' => $status, 'original_name' => 'r.xlsx', 'file_path' => 'classification/r.xlsx',
            'lease' => $status === 'running' ? 'first-worker' : null, 'heartbeat_at' => $heartbeat, 'confirmation' => ['columns' => []]]);
    }

    private function secondPickUp(): void
    {
        (new ClassifyTrialBalance('r1'))->failed(new MaxAttemptsExceededException('attempted too many times'));
    }

    public function test_second_pick_up_leaves_a_live_run_alone(): void
    {
        $this->makeRun('running', now()->subMinutes(2));

        $this->secondPickUp();

        $this->assertSame(['running', 'first-worker', 'classification/r.xlsx'], [ClassificationRun::find('r1')->status, ClassificationRun::find('r1')->lease, ClassificationRun::find('r1')->file_path]);
        Storage::disk('local')->assertExists('classification/r.xlsx');
    }

    public function test_second_pick_up_fails_a_run_whose_worker_is_gone(): void
    {
        $this->makeRun('running', now()->subMinutes(11));

        $this->secondPickUp();

        $run = ClassificationRun::find('r1');
        $this->assertSame(['failed', null, null, null], [$run->status, $run->file_path, $run->lease, $run->confirmation]);
        Storage::disk('local')->assertMissing('classification/r.xlsx');
    }

    public function test_own_timeout_fails_the_run_even_with_a_fresh_heartbeat(): void
    {
        $this->makeRun('running', now());

        (new ClassifyTrialBalance('r1'))->failed(new TimeoutExceededException('timed out'));

        $this->assertSame('failed', ClassificationRun::find('r1')->status);
        Storage::disk('local')->assertMissing('classification/r.xlsx');
    }

    public function test_a_run_waiting_for_confirmation_is_never_failed(): void
    {
        $this->makeRun('needs_confirmation', now()->subDays(2));   // no worker, stale heartbeat: by design

        foreach ([new MaxAttemptsExceededException('x'), new TimeoutExceededException('x'), new RuntimeException('x')] as $e) {
            (new ClassifyTrialBalance('r1'))->failed($e);
        }

        $this->assertSame('needs_confirmation', ClassificationRun::find('r1')->status);
        Storage::disk('local')->assertExists('classification/r.xlsx');
    }

    public function test_a_worker_that_lost_the_lease_writes_and_deletes_nothing(): void
    {
        // The AI call is where another worker takes the run over (e.g. a second pick-up of a slow run).
        $fake = new class($this->standardStructure()) extends RecordingClassifier {
            public function detectStructure(array $preview, array $previousErrors = [], array $sheetNames = [], array $letters = []): array
            {
                ClassificationRun::query()->update(['lease' => 'second-worker']);
                return parent::detectStructure($preview, $previousErrors, $sheetNames, $letters);
            }
        };
        $this->app->instance(AccountClassifierRepositoryInterface::class, $fake);
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$warnings) {
            $e->level === 'warning' && $warnings[] = [$e->message, $e->context];
        });

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])->json('id');

        $run = ClassificationRun::find($id);
        $this->assertSame(['running', 'second-worker', null], [$run->status, $run->lease, $run->result]);   // not marked done by the old worker
        // A real loss is logged once, with where it was noticed.
        $this->assertSame([['classification_lease_lost', ['run_id' => $id, 'at' => 'progress:classifying', 'status' => 'running', 'stage' => 'structure', 'taken_by_another_worker' => true]]], $warnings);
        Storage::disk('local')->assertExists($run->file_path);                                            // the new owner still needs it
        $this->assertSame([], array_filter($fake->calls, fn ($c) => $c['type'] === 'rolling'));            // stopped before classifying
    }

    public function test_writes_that_change_nothing_keep_the_lease(): void
    {
        // Frozen clock: every write sets the same heartbeat_at second, and the first progress write repeats the stage the
        // claim set (reading). MySQL without FOUND_ROWS reports 0 affected rows for those; the lease must still hold.
        $this->freezeTime();
        $this->app->instance(AccountClassifierRepositoryInterface::class, new RecordingClassifier($this->standardStructure()));
        $warnings = 0;
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$warnings) {
            $e->level === 'warning' && $warnings++;
        });

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])->json('id');

        $run = ClassificationRun::find($id);
        $this->assertSame(['done', null, null], [$run->status, $run->lease, $run->stage]);
        $this->assertSame(0, $warnings);
    }

    public function test_a_normal_run_releases_its_lease(): void
    {
        $this->app->instance(AccountClassifierRepositoryInterface::class, new RecordingClassifier($this->standardStructure()));

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])->json('id');

        $run = ClassificationRun::find($id);
        $this->assertSame(['done', null, null], [$run->status, $run->lease, $run->file_path]);
        $this->assertNotNull($run->heartbeat_at);
    }

    public function test_prune_fails_stalled_runs_only(): void
    {
        $disk = Storage::disk('local');
        $make = function (string $id, string $status, $heartbeat, $updated = null) use ($disk) {
            $disk->put("classification/{$id}.xlsx", 'data');
            $run = ClassificationRun::forceCreate(['id' => $id, 'status' => $status, 'original_name' => 'x.xlsx', 'file_path' => "classification/{$id}.xlsx",
                'lease' => $status === 'running' ? 'w' : null, 'heartbeat_at' => $heartbeat, 'expires_at' => now()->addHours(5)]);
            if ($updated) {
                ClassificationRun::whereKey($id)->update(['updated_at' => $updated]);
            }
        };
        $make('stalled', 'running', now()->subMinutes(11));
        $make('legacy', 'running', null, now()->subHour());   // started before heartbeats existed
        $make('alive', 'running', now()->subMinutes(3));        // e.g. inside a long 429 wait
        $make('waiting', 'needs_confirmation', now()->subDays(1));

        $this->artisan('classification:prune')->expectsOutput('Expired 0 run(s), failed 2 stalled run(s), deleted 0 orphaned upload(s).')->assertSuccessful();

        $this->assertSame(['failed', 'failed', 'running', 'needs_confirmation'], ClassificationRun::orderByRaw("case id when 'stalled' then 1 when 'legacy' then 2 when 'alive' then 3 else 4 end")->pluck('status')->all());
        $this->assertSame('توقف التصنيف في منتصفه (توقف العامل الذي كان يعمل عليه). يرجى رفع الملف مرة أخرى.', ClassificationRun::find('stalled')->error);
        $this->assertSame(['classification/alive.xlsx', 'classification/waiting.xlsx'], $disk->allFiles('classification'));
    }

    public function test_stalled_option_fails_stalled_runs_and_nothing_else(): void
    {
        $disk = Storage::disk('local');
        foreach (['stalled', 'expired', 'orphan'] as $name) {
            $disk->put("classification/{$name}.xlsx", 'data');
        }
        touch($disk->path('classification/orphan.xlsx'), now()->subDays(2)->getTimestamp());
        ClassificationRun::forceCreate(['id' => 'stalled', 'status' => 'running', 'original_name' => 'x.xlsx', 'file_path' => 'classification/stalled.xlsx', 'lease' => 'w', 'heartbeat_at' => now()->subMinutes(11)]);
        ClassificationRun::forceCreate(['id' => 'expired', 'status' => 'needs_confirmation', 'original_name' => 'x.xlsx', 'file_path' => 'classification/expired.xlsx', 'expires_at' => now()->subHour()]);

        $this->artisan('classification:prune', ['--stalled' => true])->expectsOutput('Failed 1 stalled run(s).')->assertSuccessful();

        $this->assertSame(['failed', 'needs_confirmation'], [ClassificationRun::find('stalled')->status, ClassificationRun::find('expired')->status]);   // expiry waits for the hourly prune
        $this->assertSame(['classification/expired.xlsx', 'classification/orphan.xlsx'], $disk->allFiles('classification'));
    }

    public function test_every_http_attempt_and_response_beats_including_429_retries(): void
    {
        Sleep::fake();
        $beats = 0;
        $heartbeat = new Heartbeat(0);   // no throttling, to count every beat
        $heartbeat->start(function () use (&$beats) { $beats++; });
        $this->app->instance(Heartbeat::class, $heartbeat);
        $calls = 0;
        Http::fake([$this->aiUrl() => function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error']], 429, ['retry-after' => '30'])
                : Http::response($this->toolResponse('submit_structure', $this->standardStructure()));
        }]);

        app(AccountClassifierRepository::class)->detectStructure([1 => [1 => ['A' => 'x']]], [], [], ['A']);

        $this->assertSame(2, $calls);
        $this->assertSame(4, $beats);   // before and after each of the 2 attempts: the 429 wait is bracketed

        $beats = 0;
        app(AccountClassifierRepository::class)->classifyRowsRolling(
            [[['columns' => ['account_name' => 'A']], [], [2 => ['A' => 'x']], [], ['chart' => []], null]], 4, fn () => null);
        $this->assertSame(2, $beats);   // the pool's async requests beat too
    }
}
