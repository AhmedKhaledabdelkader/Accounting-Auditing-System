<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A former name of a main class (`kind` main) or a sub class (`kind` sub): old classification runs resolve through it. */
class TaxonomyAlias extends Model
{
    protected $fillable = ['kind', 'name', 'main_class_id', 'sub_class_id'];
}
