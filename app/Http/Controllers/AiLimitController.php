<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Llm\AiBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** «رصيد الذكاء الاصطناعي»: the user's month (the limit, what was spent and on what, when it renews), and who can raise it. */
class AiLimitController extends Controller
{
    /** The tool the AI was asked to fill → what the user did, in their words (lang auth_pages.ai_limit.kinds). */
    private const KINDS = ['submit_rows' => 'classify', 'submit_structure' => 'classify', 'submit_matches' => 'classify', 'submit_mappings' => 'imports',
        'submit_note_mappings' => 'imports', 'submit_pages' => 'prior', 'submit_page' => 'prior', 'submit_columns' => 'prior', 'submit_review' => 'review',
        'submit_assignments' => 'review', 'submit_follow_up' => 'follow_up', 'submit_reply_reading' => 'follow_up', 'submit_paper_rows' => 'papers',
        'submit_registration' => 'registration', 'submit_partners' => 'registration'];

    public function show(Request $request): View
    {
        $user = $request->user();
        $budget = AiBudget::of($user->id);
        $spent = $budget['spent'] ?? AiBudget::spent($user->id);
        $byKind = [];
        foreach (DB::table('ai_usage')->where('user_id', $user->id)->where('created_at', '>=', now()->utc()->startOfMonth())
            ->groupBy('kind')->selectRaw('kind, sum(cost_usd) as usd, count(*) as calls')->get() as $row) {
            $k = self::KINDS[$row->kind] ?? 'other';
            $byKind[$k] = ['usd' => ($byKind[$k]['usd'] ?? 0) + (float) $row->usd, 'calls' => ($byKind[$k]['calls'] ?? 0) + (int) $row->calls];
        }
        uasort($byKind, fn ($a, $b) => $b['usd'] <=> $a['usd']);

        return view('ai-limit', [
            'user' => $user, 'limit' => $budget['limit'] ?? null, 'spent' => $spent, 'used_up' => $budget !== null && $spent >= $budget['limit'],
            'renews' => now()->utc()->startOfMonth()->addMonth(), 'byKind' => $byKind,
            'admins' => User::where('office_id', $user->office_id)->where('status', User::ACTIVE)->whereNull('company_id')
                ->whereHas('role', fn ($q) => $q->where('is_admin', true))->whereKeyNot($user->id)->orderBy('name')->limit(4)->get(['id', 'name', 'username']),
        ]);
    }
}
