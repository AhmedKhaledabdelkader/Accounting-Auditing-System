<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** A sign-off of a step of a client's year (prepared, reviewed, approved, eqr), on the step's content as it was then (its hash). */
class EngagementSignoff extends Model
{
    use BelongsToOffice;

    public const ROLES = ['prepared', 'reviewed', 'approved', 'eqr'];

    protected $fillable = ['office_id', 'company_id', 'year', 'step_key', 'role', 'user_id', 'user_name', 'content_hash', 'signed_at'];

    protected $casts = ['year' => 'integer', 'signed_at' => 'datetime'];
}
