<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** What the accountant decided about one finding of a client's year (App\Services\Audit\Findings), by the finding's key. */
class AuditFinding extends Model
{
    use BelongsToOffice;

    public const OPEN = 'open';
    public const ADJUSTED = 'adjusted';    // an adjusting entry was made
    public const ACCEPTED = 'accepted';    // explained, nothing to change
    public const DISMISSED = 'dismissed';  // not a finding (the AI misread)
    public const STATUSES = [self::OPEN, self::ADJUSTED, self::ACCEPTED, self::DISMISSED];

    protected $fillable = ['office_id', 'company_id', 'year', 'key', 'status', 'comment', 'updated_by'];

    protected $casts = ['year' => 'integer'];

    protected $attributes = ['status' => self::OPEN];
}
