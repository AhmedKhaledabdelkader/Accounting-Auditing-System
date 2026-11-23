<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** A client's year of audit planning: the materiality chosen, the sales list read, the samples drawn and the audit program's answers. */
class AuditPlan extends Model
{
    use BelongsToOffice;

    protected $fillable = ['office_id', 'company_id', 'year', 'materiality', 'sales', 'sampling', 'procedures', 'completion', 'release', 'updated_by'];

    protected $casts = ['year' => 'integer', 'materiality' => 'array', 'sales' => 'array', 'sampling' => 'array', 'procedures' => 'array', 'completion' => 'array', 'release' => 'array'];

    /** The client's plan of the year, a new one (not saved) when there is none yet. */
    public static function of(Company $company, int $year): self
    {
        return self::withoutGlobalScopes()->firstOrNew(['company_id' => $company->id, 'year' => $year], ['office_id' => $company->office_id]);
    }
}
