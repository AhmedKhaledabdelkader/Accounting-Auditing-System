<?php

namespace App\Jobs;

use App\Exceptions\LeaseLost;
use App\Exceptions\LocalizedException;
use App\Exceptions\StructureNotDetected;
use App\Models\ClassificationRun;
use App\Services\ClassificationService;
use App\Services\StructureChecks;
use App\Support\Heartbeat;
use App\Support\Llm\LlmCost;
use App\Support\Llm\Provider;
use App\Support\Llm\ProviderException;
use App\Support\Llm\UsageMeter;
use App\Support\ClassificationResponse;
use App\Support\Phrase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Classifies an uploaded trial balance in the background and records progress on its ClassificationRun. */
class ClassifyTrialBalance implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;   // large files: many batches (the queue's retry_after must stay above this)
    public int $tries   = 1;      // retries happen per batch inside the service; never re-run the whole file

    public function __construct(public string $runId) {}

    public function handle(ClassificationService $service, Heartbeat $heartbeat, UsageMeter $usage): void
    {
        $lease = (string) Str::uuid();
        // queued → running in one step, taking the lease: a run cancelled while queued, or already taken, is left alone.
        // The provider and model are the ones this worker runs with (a resumed run: the ones it resumes with).
        if (! ClassificationRun::whereKey($this->runId)->where('status', 'queued')
            ->update(['status' => 'running', 'stage' => 'reading', 'lease' => $lease, 'heartbeat_at' => now(),
                'provider' => Provider::name(), 'model' => Provider::model()])) {
            return;
        }
        // The first start fixes the date the run is priced at (a run resumed after its confirmation keeps it).
        ClassificationRun::whereKey($this->runId)->whereNull('started_at')->update(['started_at' => now()]);
        $run = ClassificationRun::findOrFail($this->runId);
        Log::shareContext(['run_id' => $run->id]);   // every classification log line carries the run

        // Every write needs this worker to still hold the lease of a running run, and refreshes the heartbeat. 0 affected
        // rows is no proof the lease is gone: MySQL without FOUND_ROWS counts only changed rows, and a write in the same
        // second with the same stage changes nothing. So the lease is checked before giving up, and a real loss is logged.
        $holds = fn () => ClassificationRun::whereKey($run->id)->where('lease', $lease)->where('status', 'running');
        $write = function (array $values, string $at) use ($holds, $run): bool {
            if ($holds()->update($values + ['heartbeat_at' => now()]) || $holds()->exists()) {
                return true;
            }
            $now = ClassificationRun::find($run->id, ['status', 'stage', 'lease']);
            Log::warning('classification_lease_lost', ['run_id' => $run->id, 'at' => $at, 'status' => $now?->status,
                'stage' => $now?->stage, 'taken_by_another_worker' => $now?->lease !== null]);

            return false;
        };
        $lost = false;
        $heartbeat->start(function () use ($write, &$lost) {
            $lost = $lost || ! $write([], 'heartbeat');   // never throws inside an HTTP call: noticed at the next stage
        });
        $progress = function (string $stage, int $done, int $total) use ($write, &$lost) {
            // Stages without batches report 0/0; keep the last batch counts instead of resetting them.
            if ($lost || ! $write(['stage' => $stage] + ($total > 0 ? ['progress_done' => $done, 'progress_total' => $total] : []), "progress:{$stage}")) {
                throw new LeaseLost;
            }
        };
        $finish = fn (array $values) => $write($values, 'finish:'.$values['status']) || throw new LeaseLost;
        $json   = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

        $file    = new UploadedFile(Storage::disk('local')->path($run->file_path), $run->original_name, null, null, true);
        $owned   = true;
        $waiting = false;

        try {
            if ($run->confirmed_at) {
                $usage->reset($run->usage ?? []);   // the detection before the pause counts toward this run
                // Checked and logged when confirmed; errors the user acknowledged then no longer block.
                $detection = $service->withStructure($file, $run->structure, log: false, acknowledged: $run->structure['acknowledged'] ?? []);
            } else {
                $detection = $service->detect($file, $progress);
                if (StructureChecks::pauses($detection['issues'])) {
                    // Nobody works on a waiting run: no lease, and only expiry (not a stale heartbeat) ends it.
                    $finish([
                        'status'           => 'needs_confirmation',
                        'stage'            => 'structure',
                        'lease'            => null,
                        'structure'        => $json($detection['structure']),
                        'structure_issues' => $json(self::shown($detection['issues'])),
                        'confirmation'     => $json($service->columnPreview($detection)),
                        'usage'            => $json($usage->snapshot()),   // attempts and kinds too, for the cost after the resume
                        'expires_at'       => now()->addHours((int) config('classification.confirmation_hours', 24)),
                    ]);
                    $waiting = true;   // the file and the preview stay until the user confirms or cancels, or the run expires
                    return;
                }
            }
            if ($problems = ClassificationService::problems($detection['issues'])) {
                throw new StructureNotDetected($problems, $detection['structure'], ClassificationService::problemPhrases($detection['issues']));
            }

            $result = $service->classifyDetected($file, $detection, $progress, $run->company_id);
            $finish(['status' => 'done', 'stage' => null, 'result' => $json(ClassificationResponse::make($result)), 'structure' => $json($detection['structure']),
                'rows_from_cache' => $result['cache']['rows_from_cache'], 'rows_sent_to_llm' => $result['cache']['rows_sent_to_llm'],
                'usage' => $json($usage->snapshot())]
                + LlmCost::forRun($usage, $run->model, $run->started_at, $result['cache']['rows_sent_to_llm'], $result['cache']['rows_from_cache']));
        } catch (LeaseLost) {
            $owned = false;   // someone else has the run now: touch nothing
        } catch (RuntimeException $e) {
            // The message as always, and the same message for any language (a provider's own message is English, as is).
            $owned = $write(['status' => 'failed', 'error' => $e->getMessage(), 'error_phrase' => $json(LocalizedException::phraseOf($e)->toArray())]
                + self::failedCost($usage, $run), 'fail');
        } catch (ProviderException $e) {
            report($e);
            $owned = $write(['status' => 'failed', 'error' => $e->getMessage()] + self::failedCost($usage, $run), 'fail');
        } finally {
            $heartbeat->stop();
            if ($owned && ! $waiting) {
                Storage::disk('local')->delete($run->file_path);
                ClassificationRun::whereKey($run->id)->where('lease', $lease)->update(['file_path' => null, 'lease' => null] + ClassificationRun::WAITING_DATA);
            }
        }
    }

    /** A failed run still paid for its calls; nothing was saved. */
    private static function failedCost(UsageMeter $usage, ClassificationRun $run): array
    {
        return ['estimated_savings_usd' => null, 'savings_basis' => null] + LlmCost::forRun($usage, $run->model, $run->started_at, 0, 0);
    }

    /** The issues as the user sees them (no English AI messages, no measurements). */
    public static function shown(array $issues): array
    {
        return array_map(fn ($i) => array_intersect_key($i, array_flip(['check', 'severity', 'field', 'column', 'user', 'user_phrase'])), $issues);
    }

    /**
     * Timeout, crash, or an error handle() didn't catch. Only a running run is touched (never one waiting for
     * confirmation). A second pick-up after retry_after (MaxAttemptsExceeded) doesn't mean the first worker is dead:
     * the run is failed only if its heartbeat went stale; a timeout or an uncaught error means this worker was on it.
     */
    public function failed(?Throwable $e): void
    {
        $running = fn () => ClassificationRun::whereKey($this->runId)->where('status', 'running')
            // (TimeoutExceededException extends MaxAttemptsExceededException, but is this worker's own timeout.)
            ->when($e instanceof MaxAttemptsExceededException && ! $e instanceof TimeoutExceededException, fn ($q) => $q->where(fn ($q) => $q
                ->where('heartbeat_at', '<', now()->subMinutes((int) config('classification.stale_minutes', 10)))
                ->orWhereNull('heartbeat_at')));

        $run = $running()->first();
        if (! $run || ! $running()->update(['status' => 'failed', 'error' => 'Unexpected error while classifying the file.',
            'error_phrase' => json_encode(Phrase::make('classification_messages.errors.unexpected')->toArray()), 'file_path' => null, 'lease' => null] + ClassificationRun::WAITING_DATA)) {
            return;
        }
        if ($run->file_path) {
            Storage::disk('local')->delete($run->file_path);
        }
    }
}
