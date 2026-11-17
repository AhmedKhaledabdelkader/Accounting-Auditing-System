<?php

namespace App\Jobs;

use App\Models\PreparedPaper;
use App\Services\WorkingPapers\PaperMaker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The AI prepares one working paper or recalculation (PaperMaker). A failure is kept on the paper and said on the page. */
class PreparePaper implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;   // the provider calls retry by themselves

    public function __construct(public int $paperId) {}

    public function handle(PaperMaker $maker): void
    {
        $paper = PreparedPaper::withoutGlobalScopes()->find($this->paperId);
        if ($paper === null) {
            return;
        }
        try {
            $maker->make($paper);
        } catch (Throwable $e) {
            Log::warning('paper preparation failed', ['paper' => $paper->id, 'error' => $e->getMessage()]);
            $paper->update(['status' => PreparedPaper::FAILED, 'error' => $e instanceof \App\Support\Llm\ProviderException ? $e->getMessage() : 'failed']);
        }
    }
}
