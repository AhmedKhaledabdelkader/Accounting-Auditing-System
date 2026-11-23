<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A line of an adjusting entry: an account of the trial balance (its row), debit or credit. */
class AdjustingEntryLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['adjusting_entry_id', 'row', 'account_code', 'account_name', 'debit', 'credit'];

    protected $casts = ['row' => 'integer', 'debit' => 'float', 'credit' => 'float'];
}
