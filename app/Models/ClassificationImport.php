<?php

namespace App\Models;

use App\Services\CacheImport;
use App\Support\Phrase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One imported file of accountant-labelled trial balances (CacheImport): pending_review until applied to the cache. */
class ClassificationImport extends Model
{
    public const QUEUED  = 'queued';
    public const RUNNING = 'running';
    public const PENDING = 'pending_review';
    public const APPLIED = 'applied';
    public const FAILED  = 'failed';

    public const FILE   = 'file';
    public const EXPORT = 'export';   // mappings brought by a JSON export (no rows): reused by later uploads

    /** A running import whose last progress write is older than this has no live worker. */
    public const STALE_MINUTES = 15;

    protected $fillable = [
        'file_name', 'status', 'report', 'applied_at', 'kind', 'stage', 'progress_done', 'progress_total', 'error', 'error_phrase', 'file_path', 'file_hash', 'provider', 'model',
    ];

    protected function casts(): array
    {
        return ['report' => 'array', 'error_phrase' => 'array', 'applied_at' => 'datetime', 'progress_done' => 'integer', 'progress_total' => 'integer'];
    }

    /** The status to show: a running import without progress for STALE_MINUTES failed (its worker died). */
    public function liveStatus(): string
    {
        return in_array($this->status, [self::RUNNING, self::QUEUED], true) && $this->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES))
            ? self::FAILED : $this->status;
    }

    /**
     * The report as the API returns it: maps keyed by name stay JSON objects when empty (PHP would encode [] as a list),
     * so a client always gets the type the spec documents.
     */
    public function reportForApi(): ?array
    {
        $report = $this->report;
        if (! is_array($report)) {
            return $report;
        }
        // Labels and flags stay Arabic as stored; `*_text` beside them in the request's language.
        if (array_key_exists('skipped', $report) && is_array($report['skipped'])) {
            foreach ($report['skipped'] as $code => $item) {
                $key = "classification_messages.import.skip_reasons.{$code}";
                is_array($item) && $report['skipped'][$code]['label_text'] = \Illuminate\Support\Facades\Lang::has($key) ? __($key) : ($item['label'] ?? $code);
            }
            $report['skipped'] = (object) $report['skipped'];
        }
        $flagText = function (array $item): array {
            $item['flag_text'] = Phrase::localize($item['flag'] ?? null, $item['flag_phrase'] ?? null);
            unset($item['flag_phrase']);

            return $item;
        };
        if (isset($report['budget_mapping']['values']) && is_array($report['budget_mapping']['values'])) {
            $report['budget_mapping']['values'] = array_map($flagText, $report['budget_mapping']['values']);
        }
        if (isset($report['review']['flagged_top']) && is_array($report['review']['flagged_top'])) {
            $report['review']['flagged_top'] = array_map($flagText, $report['review']['flagged_top']);
        }
        foreach ($report['open_decisions'] ?? [] as $i => $decision) {
            if (isset($decision['policy'], $decision['mode'])) {
                $report['open_decisions'][$i]['switch_text'] = CacheImport::switchText($decision['policy'], $decision['mode'] === 'function' ? 'nature' : 'function')->current();
            }
            foreach (['by_function', 'by_nature'] as $map) {
                if (isset($decision[$map]) && is_array($decision[$map])) {
                    $report['open_decisions'][$i][$map] = (object) $decision[$map];
                }
            }
        }

        return $report;
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ClassificationImportMapping::class, 'import_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ClassificationImportRow::class, 'import_id');
    }
}
