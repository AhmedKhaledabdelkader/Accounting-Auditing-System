<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** A reviewer's note on a step of a client's year: open until the one who raised it closes it (answered in between). */
class ReviewNote extends Model
{
    use BelongsToOffice;

    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const CLOSED = 'closed';

    protected $fillable = ['office_id', 'company_id', 'year', 'step_key', 'text', 'status', 'raised_by', 'assigned_to', 'response', 'responded_by', 'closed_by', 'closed_at'];

    protected $casts = ['year' => 'integer', 'closed_at' => 'datetime'];

    protected $attributes = ['status' => self::OPEN];

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }
}
