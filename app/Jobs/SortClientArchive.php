<?php

namespace App\Jobs;

use App\Models\ClientArchive;
use App\Services\Portal\ArchiveSorter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Unpacks a client's compressed file and files each document in its request (ArchiveSorter). */
class SortClientArchive implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 1;   // the provider calls retry by themselves; an unpacking is never run twice

    public function __construct(public int $archiveId) {}

    public function handle(ArchiveSorter $sorter): void
    {
        $archive = ClientArchive::withoutGlobalScopes()->find($this->archiveId);
        if ($archive !== null && $archive->status === ClientArchive::QUEUED) {
            $sorter->sort($archive);
        }
    }
}
