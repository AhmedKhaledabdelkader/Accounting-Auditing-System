<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Runs a change to users or roles and undoes it when no active administrator would be left. */
class KeepAnAdmin
{
    /** @param  int|null  $officeId  the office whose administrators are counted (a user change); null = every office (a role change) */
    public static function run(callable $change, string $message, ?int $officeId = null): mixed
    {
        return DB::transaction(function () use ($change, $message, $officeId) {
            $result = $change();
            $admins = User::where('status', User::ACTIVE)->when($officeId !== null, fn ($q) => $q->where('office_id', $officeId))->whereHas('role', fn ($q) => $q->where('is_admin', true))->count();
            if ($admins === 0) {
                throw ValidationException::withMessages(['admin' => $message]);
            }

            return $result;
        });
    }
}
