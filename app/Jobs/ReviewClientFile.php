<?php

namespace App\Jobs;

use App\Models\ClientFile;
use App\Services\Portal\DocumentReviewer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The AI reviews a file a client uploaded (DocumentReviewer). A failure leaves it to the accountant and says so in the chat. */
class ReviewClientFile implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;   // the provider calls retry by themselves

    public function __construct(public int $fileId) {}

    public function handle(DocumentReviewer $reviewer): void
    {
        $file = ClientFile::withoutGlobalScopes()->find($this->fileId);
        if ($file === null) {
            return;
        }
        try {
            $reviewer->review($file);
        } catch (Throwable $e) {
            Log::warning('client file review failed', ['file' => $this->fileId, 'error' => $e->getMessage()]);
            $reviewer->unavailable($file->fresh());
        }
    }
}
