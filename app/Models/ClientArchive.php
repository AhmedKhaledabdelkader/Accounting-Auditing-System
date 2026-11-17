<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A compressed file of documents sent in one go; the AI unpacks it and files each document in its request (ArchiveSorter). */
class ClientArchive extends Model
{
    use BelongsToOffice;

    public const QUEUED = 'queued';
    public const SORTING = 'sorting';
    public const DONE = 'done';
    public const FAILED = 'failed';

    protected $fillable = ['office_id', 'company_id', 'year', 'uploaded_by', 'from_client', 'original_name', 'path', 'size', 'status', 'summary', 'error'];

    protected $casts = ['from_client' => 'boolean', 'year' => 'integer', 'size' => 'integer', 'summary' => 'array'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}