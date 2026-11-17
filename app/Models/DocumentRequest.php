<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One document the accountant asks a client for, for a financial year (a line of the audit checklist, or their own). */
class DocumentRequest extends Model
{
    use BelongsToOffice;

    public const PENDING = 'pending';
    public const UPLOADED = 'uploaded';
    public const RETURNED = 'returned';
    public const ACCEPTED = 'accepted';
    public const NOT_APPLICABLE = 'not_applicable';
    public const STATUSES = [self::PENDING, self::UPLOADED, self::RETURNED, self::ACCEPTED, self::NOT_APPLICABLE];

    /** What the client still has to do something about. */
    public const OPEN = [self::PENDING, self::RETURNED];

    protected $fillable = ['office_id', 'company_id', 'year', 'period_start', 'period_end', 'section', 'number', 'title', 'format', 'paper', 'instructions', 'optional', 'status', 'note', 'analysis', 'position', 'created_by'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'analysis' => 'array', 'optional' => 'boolean', 'year' => 'integer', 'number' => 'integer', 'position' => 'integer'];

    protected $attributes = ['status' => self::PENDING];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ClientFile::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ClientMessage::class)->orderBy('id');
    }
}
