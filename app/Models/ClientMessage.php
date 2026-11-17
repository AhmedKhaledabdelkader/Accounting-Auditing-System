<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of the chat between the office and a client: in general (no request) or about one document request. */
class ClientMessage extends Model
{
    use BelongsToOffice;

    public const TEXT = 'text';
    public const EVENT = 'event';
    public const AI = 'ai';   // the AI reviewer's word on a file (DocumentReviewer): a line from the office's side

    protected $fillable = ['office_id', 'company_id', 'document_request_id', 'user_id', 'from_client', 'kind', 'body', 'read_at'];

    protected $casts = ['from_client' => 'boolean', 'read_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
