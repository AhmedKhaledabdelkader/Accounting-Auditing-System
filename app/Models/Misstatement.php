<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** A misstatement of a client's year (ISA 450): factual, judgmental or projected; corrected or not. */
class Misstatement extends Model
{
    use BelongsToOffice;

    public const KINDS = ['factual', 'judgmental', 'projected'];

    protected $fillable = ['office_id', 'company_id', 'year', 'source', 'source_id', 'kind', 'description', 'amount', 'effect_assets', 'effect_liabilities',
        'effect_equity', 'effect_pl', 'corrected', 'prior_period', 'created_by'];

    protected $casts = ['year' => 'integer', 'amount' => 'float', 'effect_assets' => 'float', 'effect_liabilities' => 'float', 'effect_equity' => 'float',
        'effect_pl' => 'float', 'corrected' => 'boolean', 'prior_period' => 'boolean'];
}
