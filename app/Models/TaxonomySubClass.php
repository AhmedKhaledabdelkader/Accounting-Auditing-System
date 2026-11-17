<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sub class of the taxonomy: the name the AI answers with and the memory stores, its normal side, and — when added in
 * the app (`origin_name` null) — the statement line it goes to (`line_key`). Built-in ones follow the config rules.
 */
class TaxonomySubClass extends Model
{
    protected $fillable = ['main_class_id', 'name', 'normal_side', 'line_key', 'sort', 'origin_name'];

    public function mainClass(): BelongsTo
    {
        return $this->belongsTo(TaxonomyMainClass::class, 'main_class_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TaxonomyAlias::class, 'sub_class_id');
    }

    public function builtIn(): bool
    {
        return $this->origin_name !== null;
    }
}
