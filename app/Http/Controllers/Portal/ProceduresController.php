<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditPlan;
use App\Models\Company;
use App\Services\Audit\AuditProcedures;
use App\Services\Audit\ProceduresExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 14. The audit program: each area's procedures, answered, concluded and signed off; exported as a working paper. */
class ProceduresController extends Controller
{
    public function __construct(private readonly AuditProcedures $procedures) {}

    public function show(Request $request, Company $company): View
    {
        $year = FindingsController::year($request, $company);
        if ($locked = EngagementController::lockedView($request, $company, $year, 'procedures')) {
            return $locked;
        }

        return view('portal.file.procedures', ['company' => $company, 'active' => 'procedures', 'year' => $year,
            'p' => $this->procedures->build($company, $year), 'open' => $request->query('area'), 'canManage' => $request->user()->can('clients.manage')]);
    }

    public function save(Request $request, Company $company, string $area): RedirectResponse
    {
        $this->area($area);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'],
            'status' => ['nullable', 'array'], 'status.*' => ['nullable', Rule::in(config('audit_procedures.statuses'))],
            'ref' => ['nullable', 'array'], 'ref.*' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'array'], 'note.*' => ['nullable', 'string', 'max:1000'],
            'request' => ['nullable', 'array'], 'request.*' => ['nullable', 'integer'],
            'conclusion' => ['nullable', 'string', 'max:3000'], 'na' => ['nullable', 'boolean'], 'na_reason' => ['nullable', 'string', 'max:500']]);
        $plan = AuditPlan::of($company, (int) $data['year']);
        // A procedure's evidence: one of the requirements tied to the area.
        $ids = array_column(AuditProcedures::requirementsOf(AuditProcedures::catalogue()[$area], AuditProcedures::requests($company, (int) $data['year'])), 'id');
        $changed = AuditProcedures::saveArea($plan, $area, $data, $request->user(), $ids);
        $this->keep($plan, $request);

        return $this->back($company, $data['year'], $area, $changed ? 'audit_plan.saved' : 'audit_plan.procedures.unchanged');
    }

    public function add(Request $request, Company $company, string $area): RedirectResponse
    {
        $this->area($area);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'text' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(config('audit_procedures.types'))],
            'assertions' => ['nullable', 'array'], 'assertions.*' => [Rule::in(config('audit_procedures.assertions'))]]);
        $plan = AuditPlan::of($company, (int) $data['year']);
        AuditProcedures::addProcedure($plan, $area, $data['text'], $data['assertions'] ?? [], $data['type']);
        $this->keep($plan, $request);

        return $this->back($company, $data['year'], $area, 'audit_plan.procedures.added');
    }

    public function delete(Request $request, Company $company, string $area, string $procedure): RedirectResponse
    {
        $this->area($area);
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        $plan = AuditPlan::of($company, $year);
        abort_unless(AuditProcedures::deleteProcedure($plan, $area, $procedure), 404);
        $this->keep($plan, $request);

        return $this->back($company, $year, $area, 'audit_plan.procedures.deleted');
    }

    public function sign(Request $request, Company $company, string $area): RedirectResponse
    {
        $this->area($area);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'role' => ['required', Rule::in(['prepared', 'reviewed'])],
            'off' => ['nullable', 'boolean']]);
        $plan = AuditPlan::of($company, (int) $data['year']);
        $awaited = count(array_filter(AuditProcedures::requirementsOf(AuditProcedures::catalogue()[$area], AuditProcedures::requests($company, (int) $data['year'])), fn ($r) => $r['awaited']));
        $error = AuditProcedures::sign($plan, $area, $data['role'], $request->user(), (bool) ($data['off'] ?? false), $awaited);
        if ($error !== null) {
            return redirect()->route('clients.file.procedures', [$company, 'year' => $data['year'], 'area' => $area])
                ->withErrors(['sign' => __("audit_plan.procedures.errors.{$error}")]);
        }
        $this->keep($plan, $request);

        return $this->back($company, $data['year'], $area, ($data['off'] ?? false) ? 'audit_plan.procedures.unsigned' : 'audit_plan.procedures.signed');
    }

    public function export(Request $request, Company $company)
    {
        $year = FindingsController::year($request, $company);
        $only = $request->query('area');
        if ($only !== null) {
            $this->area($only);
        }
        $bytes = ProceduresExport::xlsx($company, $year, $this->procedures->build($company, $year), $only);
        $name = __('audit_plan.procedures.title')." - {$company->name_ar} - {$year}".($only ? ' - '.AuditProcedures::catalogue()[$only]['code'] : '').'.xlsx';

        return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"audit-program-{$year}.xlsx\"; filename*=UTF-8''".rawurlencode($name)]);
    }

    private function area(string $area): void
    {
        abort_unless(array_key_exists($area, AuditProcedures::catalogue()), 404);
    }

    private function keep(AuditPlan $plan, Request $request): void
    {
        $plan->updated_by = $request->user()->id;
        $plan->save();
    }

    private function back(Company $company, int|string $year, string $area, string $message): RedirectResponse
    {
        return redirect()->route('clients.file.procedures', [$company, 'year' => $year, 'area' => $area])->withFragment("area-{$area}")->with('status', __($message));
    }
}
