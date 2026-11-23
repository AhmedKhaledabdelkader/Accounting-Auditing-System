<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditPlan;
use App\Models\Company;
use App\Services\Audit\Materiality;
use App\Services\Audit\RevenueSampling;
use App\Services\Audit\SalesListReader;
use App\Services\Audit\SamplingExport;
use App\Support\ClientFileSource;
use App\Support\SpreadsheetFile;
use App\Support\Llm\AiBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** 12. The materiality of a client's year, and 13. its revenue sample (the sales list, the sample drawn, the working paper). */
class AuditPlanController extends Controller
{
    public function __construct(private readonly Materiality $materiality, private readonly RevenueSampling $sampling) {}

    public function materiality(Request $request, Company $company): View
    {
        $year = FindingsController::year($request, $company);
        if ($locked = EngagementController::lockedView($request, $company, $year, 'materiality')) {
            return $locked;
        }

        return view('portal.file.materiality', ['company' => $company, 'active' => 'materiality', 'year' => $year,
            'm' => $this->materiality->build($company, $year, $request->query('source')), 'canManage' => $request->user()->can('clients.manage'),
            'versions' => \App\Services\Engagement\MaterialityVersions::of($company, $year)]);
    }

    /** Approves the working paper as a new version of the materiality (the justification required; a change says why). */
    public function approveMateriality(Request $request, Company $company, \App\Services\Engagement\MaterialityVersions $versions): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'rationale' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'], 'stage' => ['nullable', Rule::in(['planning', 'final'])],
            'specific' => ['nullable', 'array', 'max:20'], 'specific.*.area' => ['nullable', 'string', 'max:40'], 'specific.*.amount' => ['nullable', 'numeric', 'min:0'],
            'specific.*.reason' => ['nullable', 'string', 'max:500']]);
        $made = $versions->approve($company, (int) $data['year'], $request->user(), $data['rationale'] ?? null, $data['change_reason'] ?? null,
            (array) ($data['specific'] ?? []), $data['stage'] ?? 'planning');
        if (is_string($made)) {
            return back()->withInput()->withErrors(['approve' => __("engagement.materiality.errors.{$made}")], 'approve');
        }

        return redirect()->route('clients.file.materiality', [$company, 'year' => $data['year']])
            ->with('status', __('engagement.materiality.approved', ['version' => $made->version]));
    }

    public function saveMateriality(Request $request, Company $company): RedirectResponse
    {
        $bases = array_keys(config('audit.materiality.bases'));
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            // Step 1: where the measures come from.
            'source' => ['nullable', Rule::in(Materiality::SOURCES)],
            // Step 2: the percentage on each basis, and the bases taken (counted in the highest and the average).
            'percents' => ['required', 'array'],
            'percents.*' => ['required', 'numeric', 'gt:0', 'max:50'],
            'bases' => ['required', 'array', 'min:1'],
            'bases.*' => [Rule::in($bases)],
            // Step 3: one basis, the highest or the average.
            'choice' => ['required', Rule::in([...$bases, ...Materiality::AGGREGATES])],
            // Step 4.
            'performance_percent' => ['required', 'numeric', 'between:10,100'],
            'trivial_percent' => ['required', 'numeric', 'between:1,20'],
            'round' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if (array_diff(array_keys($data['percents']), $bases) !== []) {
            throw ValidationException::withMessages(['percents' => __('audit_plan.materiality.unknown_basis')]);
        }
        if (! in_array($data['choice'], [...$data['bases'], ...Materiality::AGGREGATES], true)) {
            throw ValidationException::withMessages(['choice' => __('audit_plan.materiality.choice_not_taken')]);
        }
        $this->materiality->save($company, (int) $data['year'], [
            'source' => $data['source'] ?? 'statements', 'percents' => array_map('floatval', $data['percents']), 'bases' => array_values($data['bases']), 'choice' => $data['choice'],
            'performance_percent' => (float) $data['performance_percent'], 'trivial_percent' => (float) $data['trivial_percent'],
            'round' => (bool) ($data['round'] ?? false), 'reason' => $data['reason'] ?? null,
        ], $request->user()->id);

        return redirect()->route('clients.file.materiality', [$company, 'year' => $data['year']])->with('status', __('audit_plan.saved'));
    }

    public function sampling(Request $request, Company $company): View
    {
        $year = FindingsController::year($request, $company);
        $m = $this->materiality->build($company, $year);

        if ($locked = EngagementController::lockedView($request, $company, $year, 'sampling')) {
            return $locked;
        }
        $built = $this->sampling->build($company, $year, $m);
        // A sample drawn on another version of the materiality than the one in force: to be reassessed.
        $flag = fn (?array $x) => $x === null ? null : $x + ['reassess' => ($m['approved'] ?? null) !== null && ($x['materiality_version_id'] ?? null) !== $m['approved']['id']];
        $built['samples'] = array_map($flag, $built['samples']);
        $built['sample'] = $flag($built['sample']);

        return view('portal.file.sampling', ['company' => $company, 'active' => 'sampling', 'year' => $year, 'm' => $m,
            's' => $built, 'canManage' => $request->user()->can('clients.manage'),
            'defaultAssurance' => \App\Services\Engagement\RiskRegister::assurance($company, $year, 'revenue'),
            // Any account of the year's trial balance can be sampled (like the firm's working papers).
            'tbAccounts' => RevenueSampling::trialBalanceAccounts(Materiality::run($company, $m['report'])),
            'clientFiles' => ClientFileSource::forPurpose($company->id, 'sales', $year)]);
    }

    /** The client's sales list (Excel or PDF, uploaded or picked from the client's own uploads), read and kept; a new one drops the sample. */
    public function uploadSales(Request $request, Company $company, SalesListReader $reader): RedirectResponse
    {
        ClientFileSource::attach($request);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'],
            'file' => ['required', 'file', strtolower((string) $request->file('file')?->getClientOriginalExtension()) === 'pdf' ? 'mimes:pdf' : \App\Support\SpreadsheetFile::rule(), 'max:30720']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if ($ext === 'pdf' && ($refusal = AiBudget::refusal($request->user()->id)) !== null) {
            throw ValidationException::withMessages(['file' => $refusal]);
        }
        $path = $file->storeAs("audit-sales/{$company->id}", Str::uuid().".{$ext}", 'local');
        try {
            $read = $reader->read(\Illuminate\Support\Facades\Storage::disk('local')->path($path), $ext);
        } catch (Throwable $e) {
            Log::info('sales list not read', ['company' => $company->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['file' => __(match ($e->getMessage()) {
                'no_header' => 'audit_plan.sampling.errors.no_header', 'not_sales' => 'audit_plan.sampling.errors.not_sales',
                'no_sales_account' => 'audit_plan.sampling.errors.no_sales_account', default => 'audit_plan.sampling.errors.unreadable',
            })]);
        }
        if ($read['usage'] !== null) {
            AiBudget::record($request->user()->id, 'sales_list', $read['usage']);
        }
        if ($read['rows'] === []) {
            throw ValidationException::withMessages(['file' => __('audit_plan.sampling.errors.empty')]);
        }
        $plan = AuditPlan::of($company, (int) $data['year']);
        $plan->sales = ['file' => $path, 'name' => $file->getClientOriginalName(), 'kind' => $read['kind'], 'columns' => $read['columns'], 'accounts' => $read['accounts'] ?? [],
            'rows' => $read['rows'], 'read_at' => now()->toIso8601String()];
        // The samples keep their entries (drawn since 2026-11-22): they stay; an older one read its entries from the file replaced.
        $kept = array_values(array_filter(RevenueSampling::samples($plan->sampling), fn ($x) => array_filter($x['selected'], fn ($i) => ! isset($i['row'])) === []));
        $plan->sampling = $kept === [] ? null : ['samples' => $kept];
        $plan->updated_by = $request->user()->id;
        $plan->save();

        return redirect()->route('clients.file.sampling', [$company, 'year' => $data['year']])
            ->with('status', __('audit_plan.sampling.read', ['count' => count($read['rows'])]));
    }

    /**
     * A new sample (the working paper's «عينة جديدة»): on one account of the sales list (sales, other revenue, or the whole
     * list), by a method (monetary unit, or stratified by amount or by month) and an assurance; added to the year's samples.
     */
    public function draw(Request $request, Company $company, SalesListReader $reader): RedirectResponse
    {
        ClientFileSource::attach($request);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'],
            'assurance' => ['required', Rule::in(array_keys(config('audit.sampling.factors')))],
            'method' => ['required', Rule::in(RevenueSampling::METHODS)], 'strata' => ['nullable', 'integer', 'between:2,10'],
            // `tb:<row>`: an account of the year's trial balance (any); else a population of the sales list (a revenue account, `all`).
            'account' => ['required', 'string', 'max:40'],
            // That account's own statement, when the accounts' statements file doesn't hold it.
            'statement' => ['nullable', 'file', strtolower((string) $request->file('statement')?->getClientOriginalExtension()) === 'pdf' ? 'mimes:pdf' : SpreadsheetFile::rule(), 'max:30720'],
            // The brief's sample: the expected misstatement, the decision above the cap and its justification, a ledger of another year.
            'expected' => ['nullable', 'numeric', 'min:0'], 'cap_decision' => ['nullable', Rule::in(config('audit.sampling.cap_decisions'))],
            'cap_justification' => ['nullable', 'string', 'max:1000'], 'year_override' => ['nullable', 'string', 'max:500']]);
        if (in_array($data['cap_decision'] ?? null, ['lower_assurance', 'capped_with_approval'], true) && trim((string) ($data['cap_justification'] ?? '')) === '') {
            return back()->withInput()->withErrors(['cap_decision' => __('engagement.sampling.errors.justification')]);
        }
        $year = (int) $data['year'];
        $m = $this->materiality->build($company, $year);
        $plan = AuditPlan::of($company, $year);
        // No sample without an approved materiality: its performance materiality, and its version kept on the sample.
        $version = \App\Models\MaterialityVersion::current($company, $year);
        if ($version === null || $version->performance <= 0) {
            return back()->withErrors(['assurance' => __('engagement.sampling.needs_approved')]);
        }

        $tb = null;
        if (str_starts_with($data['account'], 'tb:')) {
            // An account of the trial balance: its entries on its natural side, from its own statement or the accounts' file.
            $tb = collect(RevenueSampling::trialBalanceAccounts(Materiality::run($company, Materiality::report($company, $year))))
                ->firstWhere('row', (int) substr($data['account'], 3));
            if ($tb === null) {
                return back()->withErrors(['account' => __('audit_plan.sampling.errors.no_population')]);
            }
            if ($request->hasFile('statement')) {
                $file = $request->file('statement');
                $ext = strtolower($file->getClientOriginalExtension());
                if ($ext === 'pdf' && ($refusal = AiBudget::refusal($request->user()->id)) !== null) {
                    throw ValidationException::withMessages(['statement' => $refusal]);
                }
                try {
                    $read = $reader->read((string) $file->getRealPath(), $ext);
                } catch (Throwable) {
                    throw ValidationException::withMessages(['statement' => __('audit_plan.sampling.errors.unreadable')]);
                }
                if (($read['usage'] ?? null) !== null) {
                    AiBudget::record($request->user()->id, 'sales_list', $read['usage']);
                }
                $rows = ($read['accounts'] ?? []) !== [] ? RevenueSampling::ledgerPopulation($read, $tb['number'], $tb['name'], $tb['side'])
                    : RevenueSampling::onSide($read['rows'], $tb['side']);
                $source = $file->getClientOriginalName();
            } else {
                if ($plan->sales === null) {
                    return back()->withErrors(['account' => __('audit_plan.sampling.errors.no_list_for_account')]);
                }
                $rows = RevenueSampling::ledgerPopulation($plan->sales, $tb['number'], $tb['name'], $tb['side']);
                $source = $plan->sales['name'] ?? null;
            }
            if ($rows === []) {
                return back()->withErrors(['account' => __('audit_plan.sampling.errors.account_not_in_file', ['account' => trim(($tb['number'] ?? '').' '.$tb['name'])])]);
            }
            $name = trim(($tb['number'] ? $tb['number'].' — ' : '').$tb['name']);
        } else {
            if ($plan->sales === null) {
                return back()->withErrors(['file' => __('audit_plan.sampling.errors.no_list')]);
            }
            $population = RevenueSampling::populations($plan->sales)[$data['account']] ?? null;
            if ($population === null || $population['rows'] === []) {
                return back()->withErrors(['account' => __('audit_plan.sampling.errors.no_population')]);
            }
            [$rows, $name, $source] = [$population['rows'], $population['name'], $plan->sales['name'] ?? null];
        }

        $samples = RevenueSampling::samples($plan->sampling);
        $id = 's'.(count($samples) === 0 ? 1 : max(array_map(fn ($x) => (int) substr($x['id'], 1), $samples)) + 1);
        // A ledger of another year: refused, unless the accountant says why.
        $period = \App\Services\Portal\Workspace::periodOf($company, $year);
        [$from, $to] = [$period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')];
        $dates = array_values(array_filter(array_map(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $d) ? substr((string) $d, 0, 10) : null, array_column($rows, 'date'))));
        $outside = count(array_filter($dates, fn ($d) => $d < $from || $d > $to));
        if ($dates !== [] && $outside * 2 > count($dates) && trim((string) ($data['year_override'] ?? '')) === '') {
            return back()->withInput()->withErrors(['year_override' => __('engagement.sampling.errors.other_year', ['count' => $outside, 'total' => count($dates),
                'from' => $from, 'to' => $to])]);
        }
        $raw = RevenueSampling::draw($rows, $version->performance, $data['assurance'], null, $data['method'], (int) ($data['strata'] ?? 3),
            (float) ($data['expected'] ?? 0), $data['cap_decision'] ?? null);
        if (! empty($raw['needs_decision'])) {
            return back()->withInput()->withErrors(['cap_decision' => __('engagement.sampling.errors.above_cap', ['n' => number_format($raw['calculated_size']), 'cap' => $raw['cap'],
                'cf' => $raw['achieved_cf_at_cap'] ?? '—'])]);
        }
        $drawn = RevenueSampling::keepRows($raw, $rows) + ['materiality_version_id' => $version->id, 'materiality_version' => $version->version,
            'cap_justification' => trim((string) ($data['cap_justification'] ?? '')) ?: null, 'decided_by' => $raw['cap_decision'] ? $request->user()->name : null,
            'year_override' => trim((string) ($data['year_override'] ?? '')) ?: null];
        $samples[] = ['id' => $id, 'account' => $data['account'], 'account_name' => $name, 'source' => $source, 'results' => [],
            // The account in the trial balance: its side, the year's movement on it and its balance, against the population.
            'tb' => $tb === null ? null : ['side' => $tb['side'], 'movement' => $tb['movement'], 'balance' => $tb['balance'], 'main_class' => $tb['main_class']]] + $drawn;
        $plan->sampling = ['samples' => $samples];
        $plan->updated_by = $request->user()->id;
        $plan->save();

        return redirect()->route('clients.file.sampling', [$company, 'year' => $year, 'sample' => $id])->with('status', __('audit_plan.sampling.drawn'));
    }

    /** The audited amount (and a note) of each entry of a sample: the misstatement and its projection follow. */
    public function results(Request $request, Company $company, string $sample): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'audited' => ['nullable', 'array'], 'audited.*' => ['nullable', 'numeric'],
            'note' => ['nullable', 'array'], 'note.*' => ['nullable', 'string', 'max:500']]);
        $plan = AuditPlan::of($company, (int) $data['year']);
        $samples = RevenueSampling::samples($plan->sampling);
        $at = array_search($sample, array_column($samples, 'id'), true);
        abort_if($at === false, 404);
        $indexes = array_map('strval', array_column($samples[$at]['selected'], 'index'));
        $results = [];
        foreach ($indexes as $i) {
            $audited = $data['audited'][$i] ?? null;
            $note = trim((string) ($data['note'][$i] ?? ''));
            if ($audited !== null || $note !== '') {
                $results[$i] = ['audited' => $audited === null ? null : (float) $audited, 'note' => $note === '' ? null : $note];
            }
        }
        $samples[$at]['results'] = $results;
        $plan->sampling = ['samples' => $samples];
        $plan->updated_by = $request->user()->id;
        $plan->save();

        return redirect()->route('clients.file.sampling', [$company, 'year' => $data['year'], 'sample' => $sample])->with('status', __('audit_plan.saved'));
    }

    public function deleteSample(Request $request, Company $company, string $sample): RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        $plan = AuditPlan::of($company, $year);
        $plan->sampling = ['samples' => array_values(array_filter(RevenueSampling::samples($plan->sampling), fn ($x) => $x['id'] !== $sample))];
        $plan->updated_by = $request->user()->id;
        $plan->save();

        return redirect()->route('clients.file.sampling', [$company, 'year' => $year])->with('status', __('audit_plan.sampling.deleted'));
    }

    public function export(Request $request, Company $company)
    {
        $year = FindingsController::year($request, $company);
        $m = $this->materiality->build($company, $year);
        $s = $this->sampling->build($company, $year, $m);
        $sample = collect($s['samples'])->firstWhere('id', $request->query('sample')) ?? $s['sample'];
        abort_if($sample === null, 404);
        $bytes = SamplingExport::xlsx($company, $year, $m, ['sample' => $sample] + $s);

        return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"revenue-sample-{$year}-{$sample['id']}.xlsx\"; filename*=UTF-8''".rawurlencode(__('audit_plan.sampling.title')." - {$company->name_ar} - {$year} - {$sample['id']}.xlsx")]);
    }
}
