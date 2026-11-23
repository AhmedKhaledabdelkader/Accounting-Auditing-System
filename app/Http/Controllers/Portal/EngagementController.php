<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ReviewNote;
use App\Services\Engagement\Engagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The engagement file of a client's year: the board of its six phases and their steps (status, sign-offs, blockers, the next
 * step), the status as JSON (the same as the API's), the sign-offs of a step, and the reviewer's notes. A step whose
 * requirements aren't done shows its blockers instead of its page (lockedView).
 */
class EngagementController extends Controller
{
    public function __construct(private readonly Engagement $engagement) {}

    /** The page of a locked step: what it waits for, with the links; null when the step is open. */
    public static function lockedView(Request $request, Company $company, int $year, string $step): ?View
    {
        $e = app(Engagement::class);
        if (! $e->locked($company, $year, $step)) {
            return null;
        }

        return view('portal.file.locked', ['company' => $company, 'active' => $step, 'year' => $year,
            'blockers' => array_values(array_filter($e->blockersOf($company, $year, $step), fn ($b) => ! $b['soft']))]);
    }

    public function show(Request $request, Company $company): View
    {
        $year = Engagement::year($request, $company);

        return view('portal.file.engagement', ['company' => $company, 'active' => 'engagement', 'year' => $year, 'e' => $this->engagement->status($company, $year),
            'canManage' => $request->user()->can('clients.manage'),
            'log' => AuditLog::where('company_id', $company->id)->where('year', $year)->latest('id')->limit(30)->get()]);
    }

    public function status(Request $request, Company $company): JsonResponse
    {
        return response()->json(self::present($this->engagement->status($company, Engagement::year($request, $company))));
    }

    /** The status as the brief's shape (without the content hashes). */
    public static function present(array $s): array
    {
        // The sign-offs by role: an object even when there are none.
        $s['steps'] = array_map(fn ($x) => ['signoffs' => (object) $x['signoffs']] + array_diff_key($x, array_flip(['hash'])), $s['steps']);

        return $s;
    }

    /** Classification (step 4): the trial balance's page, on its classification. */
    public function classification(Request $request, Company $company): View
    {
        return app(ClientFileController::class)->trialBalance($request, $company)->with('active', 'classification');
    }

    public function sign(Request $request, Company $company, string $step): RedirectResponse
    {
        abort_unless(array_key_exists($step, Engagement::steps()), 404);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'role' => ['required', Rule::in(\App\Models\EngagementSignoff::ROLES)],
            'off' => ['nullable', 'boolean']]);
        $error = $this->engagement->sign($company, (int) $data['year'], $step, $data['role'], $request->user(), (bool) ($data['off'] ?? false));
        if ($error !== null) {
            return back()->withErrors(['sign' => __("engagement.sign_errors.{$error}")]);
        }

        return back()->with('status', __(! empty($data['off']) ? 'engagement.unsigned' : 'engagement.signed'));
    }

    /** A reviewer's note on a step. */
    public function addNote(Request $request, Company $company, string $step): RedirectResponse
    {
        abort_unless(array_key_exists($step, Engagement::steps()), 404);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'text' => ['required', 'string', 'max:3000']]);
        $note = ReviewNote::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => (int) $data['year'], 'step_key' => $step,
            'text' => trim($data['text']), 'raised_by' => $request->user()->id]);
        AuditLog::record($company, (int) $data['year'], 'raise', 'review_note', $note->id, null, ['step' => $step, 'text' => $note->text]);

        return back()->with('status', __('engagement.notes.raised'));
    }

    /** Answer (the preparer), close or reopen (the one who raised it, or anyone who may manage, when they are gone). */
    public function note(Request $request, Company $company, ReviewNote $note, string $action): RedirectResponse
    {
        abort_unless($note->company_id === $company->id && in_array($action, ['answer', 'close', 'reopen'], true), 404);
        $data = $request->validate(['response' => [$action === 'answer' ? 'required' : 'nullable', 'string', 'max:3000']]);
        $before = $note->only(['status', 'response']);
        if ($action === 'answer') {
            $note->fill(['response' => trim($data['response']), 'responded_by' => $request->user()->id, 'status' => ReviewNote::ANSWERED]);
        } elseif ($action === 'close') {
            if ($note->raised_by !== null && $note->raised_by !== $request->user()->id && $note->raiser !== null) {
                return back()->withErrors(['sign' => __('engagement.notes.close_own')]);
            }
            $note->fill(['status' => ReviewNote::CLOSED, 'closed_by' => $request->user()->id, 'closed_at' => now()]);
        } else {
            $note->fill(['status' => ReviewNote::OPEN, 'closed_by' => null, 'closed_at' => null]);
        }
        $note->save();
        AuditLog::record($company, $note->year, $action, 'review_note', $note->id, $before, $note->only(['status', 'response']));

        return back()->with('status', __('engagement.notes.'.['answer' => 'answered', 'close' => 'closed', 'reopen' => 'reopened'][$action]));
    }
}
