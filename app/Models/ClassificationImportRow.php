<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One data row of an imported file, with the sub-class its mappings resolve to (or why it is skipped). */
class ClassificationImportRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_id', 'row', 'company', 'account_code', 'account_name', 'budget_value', 'note_value', 'side',
        'budget_mapping_id', 'note_mapping_id', 'sub_class', 'confidence', 'skip_reason', 'approved', 'applied', 'opening_side', 'movement_side',
    ];

    protected function casts(): array
    {
        return ['approved' => 'boolean', 'applied' => 'boolean'];
    }
}
