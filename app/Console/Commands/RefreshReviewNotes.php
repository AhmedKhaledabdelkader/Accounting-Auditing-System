<?php

namespace App\Console\Commands;

use App\Models\ClassificationRun;
use App\Services\ClassificationCache;
use App\Services\ClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Notes of rows the user corrected or confirmed before a confirmation re-derived them: a note still naming a doubt the
 * row's flags no longer have. Only those rows' `note` (and its phrase) change, to what their present flags give; their
 * class, balances, flags and review state never do, and a row whose review state its flags don't give is left alone
 * (listed). With --warnings-only, only the warnings of flags the row no longer has are taken out of its stored note; the
 * classification's reason (the AI's or a rule's), the file's note and present warnings stay. Nothing is written without
 * --apply; run again, it changes nothing.
 */
class RefreshReviewNotes extends Command
{
    protected $signature = 'classification:refresh-review-notes {runs* : run ids} {--warnings-only : drop only warnings no present flag gives; keep reasons and file notes}
        {--apply : write the changes (default: list them only)}';

    protected $description = 'List (or, with --apply, rewrite) stale review notes of manually corrected rows; nothing else changes';

    public function handle(ClassificationService $classifier): int
    {
        foreach ($this->argument('runs') as $id) {
            $done = DB::transaction(function () use ($id, $classifier) {
                $run = ClassificationRun::whereKey($id)->lockForUpdate()->first();
                if (! $run || $run->status !== 'done') {
                    $this->error("Run {$id}: not found or not done.");

                    return false;
                }
                $result = $run->result;
                $changed = 0;
                foreach ($result['data'] as $i => $r) {
                    if (($r['classification_source'] ?? null) !== ClassificationCache::MANUAL) {
                        continue;
                    }
                    $review = $classifier->reviewOf($r);
                    $new = $this->option('warnings-only') ? $classifier->withoutResolvedWarnings($r) : $review;
                    if ($new === null || $new['note'] === ($r['note'] ?? '')) {
                        continue;
                    }
                    if ($review['needs_review'] !== (bool) ($r['needs_review'] ?? false)) {
                        $this->warn("Run {$id} row {$r['row']}: left alone, its review state isn't what its flags give (confirm it on the page).");
                        continue;
                    }
                    $this->line("Run {$id} row {$r['row']} «{$r['account_name']}»: «".($r['note'] ?? '')."» → «{$new['note']}»");
                    foreach ($new['dropped'] ?? [] as $text) {
                        $this->line("  dropped: «{$text}»");
                    }
                    $result['data'][$i]['note'] = $new['note'];
                    $result['data'][$i]['note_phrase'] = $new['note_phrase'];
                    $changed++;
                }
                if ($changed && $this->option('apply')) {
                    $run->update(['result' => $result]);
                }
                $this->info("Run {$id}: {$changed} note(s) ".($this->option('apply') ? 'rewritten.' : 'to rewrite (dry run; --apply writes them).'));

                return true;
            });
            if (! $done) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
