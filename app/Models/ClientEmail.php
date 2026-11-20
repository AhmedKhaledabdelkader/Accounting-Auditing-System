<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An email between the office and a client about the missing documents: the one sent (out) and the reply that came (in). */
class ClientEmail extends Model
{
    use BelongsToOffice;

    protected $fillable = ['office_id', 'company_id', 'year', 'direction', 'ref', 'parent_id', 'message_id', 'address', 'subject', 'body', 'status', 'request_ids', 'analysis', 'reminders', 'last_sent_at', 'error', 'sent_by'];

    protected $casts = ['year' => 'integer', 'request_ids' => 'array', 'analysis' => 'array', 'reminders' => 'integer', 'last_sent_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isOut(): bool
    {
        return $this->direction === 'out';
    }
}
