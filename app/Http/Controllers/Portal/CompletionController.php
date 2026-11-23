<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuditPlan;
use App\Models\Company;
use App\Models\MaterialityVersion;
use App\Services\Audit\Materiality;
use App\Services\Engagement\Engagement;
use App\Services\Engagement\Misstatements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 13. The completion memo: subsequent events (ISA 560), going concern (ISA 570), the written representations (ISA 580).
 * 14. The partner's review and the release: the opinion (ISA 700/705/706) against the misstatements and the going concern,
 * the partner's approval, then the issue — kept as it was issued (the report, its fingerprint, who and when).
 */
class CompletionController extends Controller
{
    public const SUBSEQUENT = ['none', 'adjusting_recorded', 'non_adjusting_disclosed'];
    public const GOING_CONCERN = ['no_doubt', 'uncertainty_disclosed', 'uncertainty_not_disclosed', 'not_appropriate'];
    public const REPRESENTATIONS = ['received', 'refused'];

    public function __construct(private readonly Engagement $engagement) {}

    public function show(Request $request, Company $company): View
    {
        $year = Engagement::year($request, $company);
        if ($locked = EngagementController::lockedView($request, $company, $year, 'completion')) {
            return $locked;
        }

        return view('portal.file.completion', ['company' => $company, 'active' => 'completion', 'year' => $year,
            'c' => (array) (AuditPlan::of($company, $year)->completion ?? []), 'canManage' => $request->user()->can('clients.manage')]);
    }

    public function save(Request $request, Company $company): RedirectResponse
    {
        $d = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'],
            'subsequent_events' => ['nullable', Rule::in(self::SUBSEQUENT)], 'subsequent_note' => ['nullable', 'string', 'max:3000'], 'subsequent_to' => ['nullable', 'date'],
            'going_concern' => ['nullable', Rule::in(self::GOING_CONCERN)], 'going_concern_note' => ['nullable', 'string', 'max:3000'],
            'representations' => ['nullable', Rule::in(self::REPRESENTATIONS)], 'representations_date' => ['nullable', 'date'], 'representations_note' => ['nullable', 'string', 'max:3000']]);
        $plan = AuditPlan::of($company, (int) $d['year']);
        $before = $plan->completion;
        $plan->completion = array_filter(array_diff_key($d, ['year' => 1]), fn ($v) => $v !== null && $v !== '');
        $plan->updated_by = $request->user()->id;
        $plan->save();
        AuditLog::record($company, (int) $d['year'], 'update', 'completion', $plan->id, $before, $plan->completion);

        return redirect()->route('clients.file.completion', [$company, 'year' => $d['year']])->with('status', __('audit_plan.saved'));
    }

    public function release(Request $request, Company $company): View
    {
        $year = Engagement::year($request, $company);
        if ($locked = EngagementController::lockedView($request, $company, $year, 'release')) {
            return $locked;
        }
        $plan = AuditPlan::of($company, $year);
        $version = MaterialityVersion::current($company, $year);

        return view('portal.file.release', ['company' => $company, 'active' => 'release', 'year' => $year, 'e' => $this->engagement->status($company, $year),
            'r' => (array) ($plan->release ?? []), 'c' => (array) ($plan->completion ?? []), 'version' => $version,
            'totals' => Misstatements::totals($company, $year, $plan, $version), 'report' => Materiality::report($company, $year),
            'notes' => \App\Models\ReviewNote::where('company_id', $company->id)->where('year', $year)->where('status', '!=', 'closed')->orderBy('id')->get(),
            'canManage' => $request->user()->can('clients.manage')]);
    }

    public function saveRelease(Request $request, Company $company): RedirectResponse
    {
        $d = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'opinion' => ['required', Rule::in(config('engagement.opinions'))],
            'basis' => ['nullable', 'string', 'max:3000'], 'emphasis' => ['nullable', 'string', 'max:3000'], 'other_matter' => ['nullable', 'string', 'max:3000']]);
        $plan = AuditPlan::of($company, (int) $d['year']);
        if (! empty($plan->release['issued_at'])) {
            return back()->withErrors(['release' => __('engagement.release.already_issued')]);
        }
        if ($d['opinion'] !== 'unmodified' && trim((string) ($d['basis'] ?? '')) === '') {
            return back()->withInput()->withErrors(['release' => __('engagement.release.basis_required')]);
        }
        $before = $plan->release;
        $plan->release = array_filter(array_diff_key($d, ['year' => 1]), fn ($v) => $v !== null && $v !== '');
        $plan->updated_by = $request->user()->id;
        $plan->save();
        AuditLog::record($company, (int) $d['year'], 'update', 'release', $plan->id, $before, $plan->release);

        return redirect()->route('clients.file.release', [$company, 'year' => $d['year']])->with('status', __('audit_plan.saved'));
    }

    /** The final issue: the partner approved, nothing blocking; kept with the report's fingerprint as it was issued. */
    public function issue(Request $request, Company $company): RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        $s = $this->engagement->step($company, $year, 'release');
        $blocking = array_filter($this->engagement->blockersOf($company, $year, 'release'), fn ($b) => ! $b['soft']);
        if (! ($s['signoffs']['approved']['valid'] ?? false)) {
            return back()->withErrors(['release' => __('engagement.release.needs_partner')]);
        }
        if ($blocking !== []) {
            return back()->withErrors(['release' => __('engagement.release.blocked')]);
        }
        $plan = AuditPlan::of($company, $year);
        $report = Materiality::report($company, $year);
        $run = Materiality::run($company, $report);
        $plan->release = (array) $plan->release + ['issued_at' => now()->toIso8601String(), 'issued_by' => $request->user()->id, 'issued_name' => $request->user()->name,
            'snapshot' => ['report_id' => $report?->id, 'run_hash' => $run ? app(\App\Services\Statements\ReportSource::class)->hash($run) : null,
                'materiality_version' => MaterialityVersion::current($company, $year)?->version]];
        $plan->save();
        AuditLog::record($company, $year, 'issue', 'release', $plan->id, null, $plan->release['snapshot']);

        return redirect()->route('clients.file.release', [$company, 'year' => $year])->with('status', __('engagement.release.issued'));
    }
}
