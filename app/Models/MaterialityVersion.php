<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** The materiality of a client's year as approved (ISA 320): never changed; a change after it is a new version with its reason. */
class MaterialityVersion extends Model
{
    use BelongsToOffice;

    protected $fillable = ['office_id', 'company_id', 'year', 'version', 'stage', 'benchmark', 'benchmark_amount', 'pct', 'overall', 'performance_pct', 'performance',
        'trivial_pct', 'trivial', 'rationale', 'change_reason', 'specific', 'choice', 'status', 'approved_by', 'approved_name', 'approved_at'];

    protected $casts = ['year' => 'integer', 'version' => 'integer', 'benchmark_amount' => 'float', 'pct' => 'float', 'overall' => 'float', 'performance_pct' => 'float',
        'performance' => 'float', 'trivial_pct' => 'float', 'trivial' => 'float', 'specific' => 'array', 'choice' => 'array', 'approved_at' => 'datetime'];

    /** The year's version in force (the last approved), null before any. */
    public static function current(Company $company, int $year): ?self
    {
        return self::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->where('status', 'approved')->orderByDesc('version')->first();
    }
}
