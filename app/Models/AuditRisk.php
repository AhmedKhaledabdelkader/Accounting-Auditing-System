<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/** A risk of material misstatement of a client's year (ISA 315), and the procedures of its area that answer it (ISA 330). */
class AuditRisk extends Model
{
    use BelongsToOffice;

    public const SUGGESTED = 'suggested';
    public const ACCEPTED = 'accepted';
    public const DISMISSED = 'dismissed';

    protected $fillable = ['office_id', 'company_id', 'year', 'area', 'level', 'description', 'assertions', 'likelihood', 'magnitude', 'inherent_rating',
        'is_significant', 'is_fraud', 'control_reliance', 'status', 'source', 'procedures', 'response', 'created_by'];

    protected $casts = ['year' => 'integer', 'assertions' => 'array', 'procedures' => 'array', 'likelihood' => 'integer', 'magnitude' => 'integer',
        'is_significant' => 'boolean', 'is_fraud' => 'boolean', 'control_reliance' => 'boolean'];

    /** The inherent risk from its likelihood and magnitude (1 to 3 each): their product, 1–2 low, 3–4 medium, 6–9 high. */
    public static function rating(int $likelihood, int $magnitude): string
    {
        $p = $likelihood * $magnitude;

        return $p >= 6 ? 'high' : ($p >= 3 ? 'medium' : 'low');
    }
}
