<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A word the accountant marked as right when reading last year's statements (a name, a rare word, a word kept as
 * printed): part of the known words from then on (PriorPdf\Vocabulary), never flagged or corrected again.
 */
class KnownWord extends Model
{
    protected $fillable = ['word', 'label'];
}
