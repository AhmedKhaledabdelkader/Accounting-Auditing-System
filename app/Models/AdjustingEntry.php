<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An adjusting (or reclassification) entry on a client's year: proposed, accepted, rejected (a misstatement) or posted. */
class AdjustingEntry extends Model
{
    use BelongsToOffice;

    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const POSTED = 'posted';
    public const STATUSES = [self::PROPOSED, self::ACCEPTED, self::REJECTED, self::POSTED];

    protected $fillable = ['office_id', 'company_id', 'year', 'classification_run_id', 'number', 'type', 'source_type', 'source_ref', 'source_file', 'source_page',
        'description', 'status', 'posted_at', 'posted_by', 'created_by'];

    protected $casts = ['year' => 'integer', 'number' => 'integer', 'posted_at' => 'datetime'];

    protected $attributes = ['status' => self::PROPOSED];

    public function lines(): HasMany
    {
        return $this->hasMany(AdjustingEntryLine::class);
    }

    public function total(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }
}
