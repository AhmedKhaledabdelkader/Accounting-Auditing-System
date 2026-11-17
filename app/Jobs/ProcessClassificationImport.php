<?php

namespace App\Jobs;

use App\Models\ClassificationImport;
use App\Exceptions\LocalizedException;
use App\Services\CacheImport;
use App\Support\Phrase;
use App\Support\Llm\ProviderException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Reads and maps an uploaded accountant-classified file in the background (CacheImport::process), recording progress. */
class ProcessClassificationImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = ClassifyTrialBalance::TIMEOUT;   // the queue's retry_after is already checked against this
    public int $tries   = 1;                                // the LLM calls retry inside; never re-run the whole file

    public function __construct(public int $importId) {}

    public function handle(CacheImport $imports): void
    {
        // queued → running in one step: an import already taken (or deleted) is left alone.
        if (! ClassificationImport::whereKey($this->importId)->where('status', ClassificationImport::QUEUED)
            ->update(['status' => ClassificationImport::RUNNING, 'stage' => 'reading'])) {
            return;
        }
        $import = ClassificationImport::findOrFail($this->importId);

        try {
            $imports->process($import, Storage::disk('local')->path($import->file_path), function (string $stage, int $done, int $total) use ($import) {
                // Every write also refreshes updated_at: the page tells a live import from a dead worker by it.
                $import->update(['stage' => $stage, 'progress_done' => $done, 'progress_total' => $total]);
                $import->touch();
            });
        } catch (Throwable $e) {
            $this->fail($import, $e);
        } finally {
            Storage::disk('local')->delete($import->file_path);
            $import->update(['file_path' => null]);
        }
    }

    /** Called by the queue too (timeout, worker killed): the import must not stay «running». */
    public function failed(Throwable $e): void
    {
        if ($import = ClassificationImport::find($this->importId)) {
            in_array($import->status, [ClassificationImport::QUEUED, ClassificationImport::RUNNING], true) && $this->fail($import, $e);
        }
    }

    private function fail(ClassificationImport $import, Throwable $e): void
    {
        Log::error('classification_import_failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
        // The Arabic message (as always) and the same message for any language.
        $error = match (true) {
            $e instanceof ProviderException => Phrase::make('classification_messages.import.provider_error', ['message' => $e->getMessage()]),
            $e instanceof RuntimeException  => LocalizedException::phraseOf($e),   // CacheImport's own messages
            default                         => Phrase::make('classification_messages.import.unexpected'),
        };
        $import->update(['status' => ClassificationImport::FAILED, 'stage' => null, 'error' => $error->ar(), 'error_phrase' => $error->toArray()]);
    }
}
