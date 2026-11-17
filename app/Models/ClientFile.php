<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file uploaded to a document request, kept on the private disk and served only to the office and that client. */
class ClientFile extends Model
{
    use BelongsToOffice;

    protected $fillable = ['office_id', 'company_id', 'document_request_id', 'uploaded_by', 'from_client', 'original_name', 'path', 'size', 'review'];

    protected $casts = ['from_client' => 'boolean', 'size' => 'integer', 'review' => 'array'];

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
