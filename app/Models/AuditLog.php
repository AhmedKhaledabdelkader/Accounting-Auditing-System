<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change on an engagement: who, when, what, the value before and after. Written once, never changed. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['office_id', 'company_id', 'year', 'user_id', 'user_name', 'action', 'target_type', 'target_id', 'before', 'after'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'year' => 'integer'];

    public static function record(?Company $company, ?int $year, string $action, string $targetType, string|int|null $targetId, mixed $before = null, mixed $after = null): self
    {
        $user = auth()->user();

        return self::create(['office_id' => $company?->office_id ?? $user?->office_id, 'company_id' => $company?->id, 'year' => $year,
            'user_id' => $user?->id, 'user_name' => $user?->name, 'action' => $action, 'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'before' => $before === null ? null : (array) $before, 'after' => $after === null ? null : (array) $after]);
    }
}
