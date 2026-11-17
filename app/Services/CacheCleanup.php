<?php

namespace App\Services;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Support\ClassificationKey;
use Illuminate\Support\Facades\DB;

/**
 * Brings entries stored before the current rules in line with them (run once by a migration; `classification:cache-cleanup`
 * repeats it, with --dry-run to only count):
 * - AI entries whose row was flagged in a finished run (ClassificationCache::NOT_STORED_FLAGS) are deleted: today they
 *   would never be stored, and the row goes back to the AI;
 * - side-pair sub-classes become their base class (ClassificationCache::baseClass), except manual corrections locked
 *   against the side;
 * - base_hash is filled where missing (the code + name fallback of imported entries).
 */
class CacheCleanup
{
    public function __construct(private TaxonomyRepositoryInterface $taxonomy) {}

    /** @return array{deleted_ai: int, converted_to_base: int, base_hash_filled: int} */
    public function run(bool $dryRun = false): array
    {
        $report = ['deleted_ai' => 0, 'converted_to_base' => 0, 'base_hash_filled' => 0];

        DB::transaction(function () use ($dryRun, &$report) {
            $bad = $this->flaggedKeys();
            foreach (array_chunk(array_keys($bad), 500) as $hashes) {
                $query = ClassificationCacheEntry::whereIn('key_hash', $hashes)->where('source', ClassificationCache::AI);
                $report['deleted_ai'] += $dryRun ? $query->count() : $query->delete();
            }

            ClassificationCacheEntry::query()->orderBy('id')->chunkById(500, function ($entries) use ($dryRun, &$report) {
                foreach ($entries as $e) {
                    $changes = [];
                    if ($e->base_hash === null && ($base = ClassificationKey::baseHash($e->normalized_key)) !== null) {
                        $changes['base_hash'] = $base;
                        $report['base_hash_filled']++;
                    }
                    $sub = $e->side_locked ? $e->sub_class : ClassificationCache::baseClass($e->sub_class, $e->original_name);
                    if ($sub !== $e->sub_class) {
                        $changes['sub_class']  = $sub;
                        $changes['main_class'] = $this->taxonomy->find($sub)[1] ?? $e->main_class;
                        $report['converted_to_base']++;
                    }
                    // updated_at stays: it is the last edit by a person or the AI, not by this cleanup.
                    $changes && ! $dryRun && ClassificationCacheEntry::whereKey($e->id)->toBase()->update($changes);
                }
            });
        });

        return $report;
    }

    /** @return array<string, true> key hashes of rows that carried a NOT_STORED_FLAGS flag in a finished run */
    private function flaggedKeys(): array
    {
        $out = [];
        // Every office's runs: the memory is shared, so what it must not keep is judged over all of them.
        foreach (ClassificationRun::withoutGlobalScopes()->where('status', 'done')->whereNotNull('result')->select(['id', 'result'])->lazyById(50) as $run) {
            foreach ($run->result['data'] ?? [] as $row) {
                $key = $row['cache_key'] ?? null;
                if ($key !== null && array_intersect(array_column($row['data_flags'] ?? [], 'type'), ClassificationCache::NOT_STORED_FLAGS)) {
                    $out[ClassificationKey::hash($key)] = true;
                }
            }
        }

        return $out;
    }
}
