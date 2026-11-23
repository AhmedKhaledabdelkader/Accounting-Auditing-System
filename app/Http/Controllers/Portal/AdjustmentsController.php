<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AdjustingEntry;
use App\Models\AuditLog;
use App\Models\AuditPlan;
use App\Models\Company;
use App\Models\MaterialityVersion;
use App\Models\Misstatement;
use App\Services\Engagement\AdjustingEntries;
use App\Services\Engagement\Engagement;
use App\Services\Engagement\Misstatements;
use App\Support\SpreadsheetFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** 10. The adjusting entries (the adjusted trial balance) and the summary of misstatements (ISA 450). */
class AdjustmentsController extends Controller
{
    public function show(Request $request, Company $company): View
    {
        $year = Engagement::year($request, $company);
        if ($locked = EngagementController::lockedView($request, $company, $year, 'adjustments')) {
            return $locked;
        }
        $run = AdjustingEntries::run($company, $year);
        $version = MaterialityVersion::current($company, $year);
        $plan = AuditPlan::of($company, $year);

        return view('portal.file.adjustments', ['company' => $company, 'active' => 'adjustments', 'year' => $year, 'run' => $run,
            'accounts' => AdjustingEntries::accounts($run), 'version' => $version,
            'entries' => AdjustingEntry::with('lines')->where('company_id', $company->id)->where('year', $year)->orderBy('number')->get(),
            'misstatements' => Misstatements::rows($company, $year, $plan, $version), 'totals' => Misstatements::totals($company, $year, $plan, $version),
            'canManage' => $request->user()->can('clients.manage')]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'description' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['adjusting', 'reclassification'])], 'lines' => ['required', 'array', 'min:2', 'max:30'],
            'lines.*.row' => ['nullable', 'integer'], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0']]);
        $year = (int) $data['year'];
        $run = AdjustingEntries::run($company, $year);
        if ($run === null) {
            return back()->withInput()->withErrors(['entry' => __('engagement.entries.errors.no_trial_balance')]);
        }
        $check = AdjustingEntries::check(array_values(array_filter($data['lines'], fn ($l) => ($l['row'] ?? null) !== null)), AdjustingEntries::accounts($run));
        if ($check['error'] !== null) {
            return back()->withInput()->withErrors(['entry' => __("engagement.entries.errors.{$check['error']}", $check['params'])]);
        }
        $entry = AdjustingEntries::create($company, $year, $run, $check['lines'], trim($data['description']), $data['type'], $request->user());

        return $this->back($company, $year)->with('status', __('engagement.entries.saved', ['number' => $entry->number]));
    }

    public function import(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'file' => ['required', 'file', SpreadsheetFile::rule(), 'max:20480']]);
        $year = (int) $data['year'];
        $run = AdjustingEntries::run($company, $year);
        if ($run === null) {
            return back()->withErrors(['entry' => __('engagement.entries.errors.no_trial_balance')]);
        }
        try {
            $r = AdjustingEntries::import($company, $year, $run, (string) $request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $request->user());
        } catch (Throwable) {
            return back()->withErrors(['entry' => __('engagement.entries.errors.unreadable')]);
        }

        return $this->back($company, $year)->with('status', __('engagement.entries.imported_count', ['saved' => count($r['saved']), 'refused' => count($r['refused'])]))
            ->with('entries_refused', $r['refused']);
    }

    public function move(Request $request, Company $company, AdjustingEntry $entry): RedirectResponse
    {
        abort_unless($entry->company_id === $company->id, 404);
        $action = $request->validate(['action' => ['required', Rule::in(['accept', 'post', 'unpost', 'reject', 'reopen'])]])['action'];
        if (($error = AdjustingEntries::move($company, $entry, $action, $request->user())) !== null) {
            return back()->withErrors(['entry' => __("engagement.entries.errors.{$error}")]);
        }

        return $this->back($company, $entry->year)->with('status', __("engagement.entries.moved.{$action}", ['number' => $entry->number]));
    }

    public function storeMisstatement(Request $request, Company $company): RedirectResponse
    {
        $data = $this->misstatement($request);
        $m = Misstatement::create($data + ['office_id' => $company->office_id, 'company_id' => $company->id, 'source' => 'manual', 'created_by' => $request->user()->id]);
        AuditLog::record($company, $m->year, 'create', 'misstatement', $m->id, null, $m->only(['kind', 'description', 'amount']));

        return $this->back($company, $m->year)->with('status', __('audit_plan.saved'));
    }

    public function updateMisstatement(Request $request, Company $company, Misstatement $misstatement): RedirectResponse
    {
        abort_unless($misstatement->company_id === $company->id, 404);
        $before = $misstatement->only(['kind', 'amount', 'corrected', 'description']);
        if ($request->has('corrected') && ! $request->has('description')) {
            $misstatement->update(['corrected' => $request->boolean('corrected')]);
        } else {
            abort_unless($misstatement->source === 'manual', 403);
            $misstatement->update($this->misstatement($request));
        }
        AuditLog::record($company, $misstatement->year, 'update', 'misstatement', $misstatement->id, $before, $misstatement->only(array_keys($before)));

        return $this->back($company, $misstatement->year)->with('status', __('audit_plan.saved'));
    }

    public function destroyMisstatement(Request $request, Company $company, Misstatement $misstatement): RedirectResponse
    {
        abort_unless($misstatement->company_id === $company->id && $misstatement->source === 'manual', 404);
        AuditLog::record($company, $misstatement->year, 'delete', 'misstatement', $misstatement->id, $misstatement->only(['kind', 'description', 'amount']));
        $misstatement->delete();

        return $this->back($company, $misstatement->year)->with('status', __('audit_plan.saved'));
    }

    private function misstatement(Request $request): array
    {
        $d = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'kind' => ['required', Rule::in(Misstatement::KINDS)],
            'description' => ['required', 'string', 'max:1000'], 'amount' => ['required', 'numeric'], 'effect_assets' => ['nullable', 'numeric'],
            'effect_liabilities' => ['nullable', 'numeric'], 'effect_equity' => ['nullable', 'numeric'], 'effect_pl' => ['nullable', 'numeric'],
            'corrected' => ['nullable', 'boolean'], 'prior_period' => ['nullable', 'boolean']]);

        return ['year' => (int) $d['year'], 'kind' => $d['kind'], 'description' => trim($d['description']), 'amount' => round((float) $d['amount'], 2),
            'effect_assets' => (float) ($d['effect_assets'] ?? 0), 'effect_liabilities' => (float) ($d['effect_liabilities'] ?? 0),
            'effect_equity' => (float) ($d['effect_equity'] ?? 0), 'effect_pl' => (float) ($d['effect_pl'] ?? 0),
            'corrected' => (bool) ($d['corrected'] ?? false), 'prior_period' => (bool) ($d['prior_period'] ?? false)];
    }

    private function back(Company $company, int $year): RedirectResponse
    {
        return redirect()->route('clients.file.adjustments', [$company, 'year' => $year]);
    }
}
