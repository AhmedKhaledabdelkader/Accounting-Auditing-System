<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A main class of the taxonomy (docs/16-taxonomy.md): its statement (Arabic, one of TaxonomyRepository::STATEMENTS), its
 * family (TaxonomyRepository::FAMILIES) and its sub classes. `origin_name`: the config name, null when added in the app.
 */
class TaxonomyMainClass extends Model
{
    protected $fillable = ['statement', 'name', 'family', 'sort', 'origin_name'];

    public function subClasses(): HasMany
    {
        return $this->hasMany(TaxonomySubClass::class, 'main_class_id')->orderBy('sort')->orderBy('id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TaxonomyAlias::class, 'main_class_id');
    }

    public function builtIn(): bool
    {
        return $this->origin_name !== null;
    }
}
