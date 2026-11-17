<?php

namespace App\Console\Commands;

use App\Services\CacheCleanup;
use Illuminate\Console\Command;

/** Re-runs the one-time cache cleanup (CacheCleanup); a migration already ran it once on deploy. */
class CleanupClassificationCache extends Command
{
    protected $signature = 'classification:cache-cleanup {--dry-run : only count what would change}';

    protected $description = 'Delete cached AI answers of flagged rows, store side-pair classes as their base class, fill base_hash';

    public function handle(CacheCleanup $cleanup): int
    {
        $report = $cleanup->run((bool) $this->option('dry-run'));
        $this->table(['deleted_ai', 'converted_to_base', 'base_hash_filled'], [$report]);
        $this->option('dry-run') && $this->comment('Dry run: nothing changed.');

        return self::SUCCESS;
    }
}
