<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A report's state frozen as a draft or final version (plan §4.1, Q19). Never changed or deleted once saved. */
class FinancialReportVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['report_id', 'version', 'kind', 'payload', 'inputs_hash', 'run_result_hash', 'content_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    /** The payload as stored: JSON kept as written, so `{}` stays an object. */
    public function payloadJson(): object
    {
        return json_decode($this->payload, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Line keys renamed since a version was saved: its files read the old key as the new one (the payload stays as saved). */
    public const RENAMED_LINES = ['is.depreciation_amortisation' => 'is.depreciation'];   // R5: depreciation and amortisation split

    /** The payload as the exporters read it: a version saved before a line was renamed shows its figure on the new line. */
    public function exportPayload(): array
    {
        $json = $this->payload;
        foreach (self::RENAMED_LINES as $old => $new) {
            $json = str_replace('"'.$old.'"', '"'.$new.'"', $json);
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A report version is immutable.'));
        static::deleting(fn () => throw new LogicException('A report version is immutable.'));
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialReport::class, 'report_id');
    }
}
