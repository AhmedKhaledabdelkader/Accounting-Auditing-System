<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One distinct hand-written value of an import and what it maps to (kind budget: الميزانية; kind note: الإيضاحات). */
class ClassificationImportMapping extends Model
{
    public const BUDGET = 'budget';
    public const NOTE   = 'note';

    protected $fillable = [
        'import_id', 'kind', 'value', 'normalized', 'parent', 'decision', 'main_class', 'sub_class', 'function_sub_class', 'policy', 'method', 'confidence',
        'reason', 'flag', 'reviewed', 'rows', 'reason_phrase', 'flag_phrase',
    ];

    protected function casts(): array
    {
        return ['reason_phrase' => 'array', 'flag_phrase' => 'array', 'confidence' => 'integer', 'reviewed' => 'boolean', 'rows' => 'integer'];
    }
}
