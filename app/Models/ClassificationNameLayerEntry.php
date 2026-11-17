<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A name (+ closing balance side) most companies of the applied imports classify the same way (ClassificationCache). */
class ClassificationNameLayerEntry extends Model
{
    protected $table = 'classification_name_layer';

    protected $fillable = ['name_hash', 'side', 'normalized_name', 'original_name', 'sub_class', 'companies', 'share', 'hit_count', 'origin'];

    protected function casts(): array
    {
        return ['companies' => 'integer', 'share' => 'integer', 'hit_count' => 'integer'];
    }
}
