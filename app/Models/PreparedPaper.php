<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A working paper or a recalculation the AI prepared for a client's year (PaperMaker). */
class PreparedPaper extends Model
{
    use BelongsToOffice;

    public const QUEUED = 'queued';
    public const WORKING = 'working';
    public const DONE = 'done';
    public const BLOCKED = 'blocked';   // nothing to prepare it from yet: the client's documents are missing
    public const FAILED = 'failed';

    protected $fillable = ['office_id', 'company_id', 'year', 'kind', 'key', 'status', 'progress_done', 'progress_total', 'path', 'analysis', 'sources', 'notes', 'error', 'prepared_at'];

    protected $casts = ['year' => 'integer', 'analysis' => 'array', 'sources' => 'array', 'notes' => 'array', 'prepared_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::WORKING], true);
    }
}
