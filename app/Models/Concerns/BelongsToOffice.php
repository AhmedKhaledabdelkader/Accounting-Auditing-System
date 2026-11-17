<?php

namespace App\Models\Concerns;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The row belongs to an accounting office: a signed-in user sees and creates only their own office's rows. Without a
 * signed-in user (a queued job, a command) nothing is filtered: the job already holds the row it works on.
 */
trait BelongsToOffice
{
    protected static function bootBelongsToOffice(): void
    {
        static::addGlobalScope('office', function (Builder $query) {
            $user = auth()->user();
            if ($user instanceof User) {
                $query->where($query->getModel()->getTable().'.office_id', $user->office_id);
            }
        });
        static::creating(function ($model) {
            $user = auth()->user();
            if ($model->office_id === null && $user instanceof User) {
                $model->office_id = $user->office_id;
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
