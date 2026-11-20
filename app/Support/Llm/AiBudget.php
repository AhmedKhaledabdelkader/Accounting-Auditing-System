<?php

namespace App\Support\Llm;

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Who an AI call is for, what each user spent this month, and their monthly limit (users.ai_limit_usd, in dollars; null = none).
 * The user is the signed-in one; a queued job carries them (Laravel Context, hidden, set by the `ai.payer` middleware); a scheduled
 * task sets them itself (ReplyFetcher: the mailbox's owner).
 */
final class AiBudget
{
    public const CONTEXT = 'ai_user';

    public static function payer(): ?int
    {
        $id = Context::getHidden(self::CONTEXT) ?? auth()->id();

        return $id === null ? null : (int) $id;
    }

    public static function forUser(?int $userId): void
    {
        $userId === null ? Context::forgetHidden(self::CONTEXT) : Context::addHidden(self::CONTEXT, $userId);
    }

    /** Dollars spent by the user in the current calendar month (UTC). */
    public static function spent(int $userId): float
    {
        return (float) DB::table('ai_usage')->where('user_id', $userId)->where('created_at', '>=', now()->utc()->startOfMonth())->sum('cost_usd');
    }

    /** @return array{limit: float, spent: float}|null null: no limit for this user */
    public static function of(int $userId): ?array
    {
        try {
            $limit = User::withoutGlobalScopes()->whereKey($userId)->value('ai_limit_usd');

            return $limit === null ? null : ['limit' => (float) $limit, 'spent' => self::spent($userId)];
        } catch (\Throwable) {
            return null;   // no database (a test without one): no limit to check
        }
    }

    /** Null when the user may still use the AI; else why not (their month's limit is used up). */
    public static function refusal(?int $userId): ?string
    {
        if ($userId === null || ($b = self::of($userId)) === null || $b['spent'] < $b['limit']) {
            return null;
        }

        return __('auth_pages.users.ai_limit_reached', ['limit' => number_format($b['limit'], 2), 'spent' => number_format($b['spent'], 2)]);
    }

    public static function record(?int $userId, string $kind, array $usage): void
    {
        try {
            $user = $userId ? User::withoutGlobalScopes()->find($userId, ['id', 'office_id']) : null;
            DB::table('ai_usage')->insert(['user_id' => $user?->id, 'office_id' => $user?->office_id, 'kind' => mb_substr($kind, 0, 60), 'provider' => Provider::name(),
                'model' => Provider::model(), 'input_tokens' => (int) ($usage['input'] ?? 0), 'cache_write_tokens' => (int) ($usage['cache_write'] ?? 0),
                'cached_tokens' => (int) ($usage['cached'] ?? 0), 'output_tokens' => (int) ($usage['output'] ?? 0),
                'cost_usd' => LlmCost::usd($usage, Provider::model()), 'created_at' => now()]);
        } catch (\Throwable) {
            // The ledger never stops the work (no table yet, a test without a database).
        }
    }
}
