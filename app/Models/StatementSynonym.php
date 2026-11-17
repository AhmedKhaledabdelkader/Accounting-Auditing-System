<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Another wording of a statement line or total (PR 17), kept and edited by the user: a row of an uploaded file of issued
 * statements whose label has this wording (normalised, `wording`) is placed on `line_key`. One wording per statement.
 * `seeded`: from the starting list and never changed by a user (a cleanup of the starting list may remove only these).
 */
class StatementSynonym extends Model
{
    protected $fillable = ['statement', 'line_key', 'label', 'wording'];

    protected $casts = ['seeded' => 'boolean'];
}
