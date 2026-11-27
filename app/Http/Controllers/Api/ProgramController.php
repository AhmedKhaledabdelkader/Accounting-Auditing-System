<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ForClientYear;
use App\Http\Controllers\Controller;
use App\Models\AuditPlan;
use App\Models\Company;
use App\Services\Audit\AuditProcedures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The audit program of a client's year over the API (ISA 330): the same program as the page (Portal\ProceduresController),
 * from the same service (AuditProcedures), kept on the same plan. Each area carries its letter code (config `ref`) and each
 * procedure its reference (A1, A2; the accountant's own follow the program's numbers).
 *   GET    /clients/{id}/program?year=                          the areas, their procedures and answers, balances, requirements, totals
 *   PUT    /clients/{id}/program/{area}                         the area's answers (as the page's form: status/ref/note/request by code)
 *   POST   /clients/{id}/program/{area}/procedures              a procedure of the accountant's own
 *   DELETE /clients/{id}/program/{area}/procedures/{procedure}  removes one of them (c1, c2…)
 *   POST   /clients/{id}/program/{area}/sign                    the preparer's or the reviewer's sign-off (off: takes it back)
 * Every answer is the whole program again.
 */
class ProgramController extends Controller
{
    use ForClientYear;

    public function __construct(private readonly AuditProcedures $procedures) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $year = $this->yearOf($request, $company);

        return $this->lockedStep($company, $year, 'procedures') ?? response()->json($this->present($request, $company, $year));
    }

    public function save(Request $request, string $id, string $area): JsonResponse
    {
        [$company, $error] = $this->open($id, $area);
        if ($error) {
            return $error;
        }
        // The page's rules (Portal\ProceduresController::save).
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'],
            'status' => ['nullable', 'array'], 'status.*' => ['nullable', Rule::in(config('audit_procedures.statuses'))],
            'ref' => ['nullable', 'array'], 'ref.*' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'array'], 'note.*' => ['nullable', 'string', 'max:1000'],
            'request' => ['nullable', 'array'], 'request.*' => ['nullable', 'integer'],
            'conclusion' => ['nullable', 'string', 'max:3000'], 'na' => ['nullable', 'boolean'], 'na_reason' => ['nullable', 'string', 'max:500']]);
        $year = (int) $data['year'];
        if ($locked = $this->lockedStep($company, $year, 'procedures')) {
            return $locked;
        }
        $plan = AuditPlan::of($company, $year);
        $ids = array_column(AuditProcedures::requirementsOf(AuditProcedures::catalogue()[$area], AuditProcedures::requests($company, $year)), 'id');
        $changed = AuditProcedures::saveArea($plan, $area, $data, $request->user(), $ids);
        $this->keep($plan, $request);

        return response()->json($this->present($request, $company, $year) + ['changed' => $changed,
            'message' => __($changed ? 'audit_plan.saved' : 'audit_plan.procedures.unchanged')]);
    }

    public function add(Request $request, string $id, string $area): JsonResponse
    {
        [$company, $error] = $this->open($id, $area);
        if ($error) {
            return $error;
        }
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'text' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(config('audit_procedures.types'))],
            'assertions' => ['nullable', 'array'], 'assertions.*' => [Rule::in(config('audit_procedures.assertions'))]]);
        $year = (int) $data['year'];
        if ($locked = $this->lockedStep($company, $year, 'procedures')) {
            return $locked;
        }
        $plan = AuditPlan::of($company, $year);
        $code = AuditProcedures::addProcedure($plan, $area, $data['text'], $data['assertions'] ?? [], $data['type']);
        $this->keep($plan, $request);

        return response()->json($this->present($request, $company, $year) + ['code' => $code, 'message' => __('audit_plan.procedures.added')], 201);
    }

    public function delete(Request $request, string $id, string $area, string $procedure): JsonResponse
    {
        [$company, $error] = $this->open($id, $area);
        if ($error) {
            return $error;
        }
        $year = $this->yearOf($request, $company);
        if ($locked = $this->lockedStep($company, $year, 'procedures')) {
            return $locked;
        }
        $plan = AuditPlan::of($company, $year);
        // Only the accountant's own (c1, c2…): the program's stay, marked not applicable instead.
        if (! AuditProcedures::deleteProcedure($plan, $area, $procedure)) {
            return response()->json(['message' => app()->getLocale() === 'en' ? 'Procedure not found.' : 'الإجراء غير موجود.'], 404);
        }
        $this->keep($plan, $request);

        return response()->json($this->present($request, $company, $year) + ['message' => __('audit_plan.procedures.deleted')]);
    }

    public function sign(Request $request, string $id, string $area): JsonResponse
    {
        [$company, $error] = $this->open($id, $area);
        if ($error) {
            return $error;
        }
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'role' => ['required', Rule::in(['prepared', 'reviewed'])],
            'off' => ['nullable', 'boolean']]);
        $year = (int) $data['year'];
        if ($locked = $this->lockedStep($company, $year, 'procedures')) {
            return $locked;
        }
        $plan = AuditPlan::of($company, $year);
        $awaited = count(array_filter(AuditProcedures::requirementsOf(AuditProcedures::catalogue()[$area], AuditProcedures::requests($company, $year)), fn ($r) => $r['awaited']));
        $off = (bool) ($data['off'] ?? false);
        $refused = AuditProcedures::sign($plan, $area, $data['role'], $request->user(), $off, $awaited);
        if ($refused !== null) {
            // The same reasons as the page (procedures left pending, requirements awaited, the same person, not prepared yet).
            $message = __("audit_plan.procedures.errors.{$refused}");

            return response()->json(['message' => $message, 'code' => $refused, 'errors' => ['sign' => [$message]]], 422);
        }
        $this->keep($plan, $request);

        return response()->json($this->present($request, $company, $year) + ['message' => __($off ? 'audit_plan.procedures.unsigned' : 'audit_plan.procedures.signed')]);
    }

    /** The client and the area from the URL: [company, null] when both are found, else [?company, a 404]. */
    private function open(string $id, string $area): array
    {
        $company = $this->company($id);
        if ($company === null) {
            return [null, $this->notFound()];
        }
        if (! array_key_exists($area, AuditProcedures::catalogue())) {
            return [$company, response()->json(['message' => app()->getLocale() === 'en' ? 'Area not found.' : 'القسم غير موجود.'], 404)];
        }

        return [$company, null];
    }

    private function keep(AuditPlan $plan, Request $request): void
    {
        $plan->updated_by = $request->user()->id;
        $plan->save();
    }

    /** The program as JSON (the working paper's figures behind it left out), with the letter codes and the lists' names. */
    private function present(Request $request, Company $company, int $year): array
    {
        $p = $this->procedures->build($company, $year);
        $catalogue = AuditProcedures::catalogue();
        $areas = array_map(function (array $a) use ($catalogue) {
            $ref = $catalogue[$a['key']]['ref'] ?? $a['code'];
            $n = count($catalogue[$a['key']]['procedures'] ?? []);
            $a['ref'] = $ref;
            $a['items'] = array_map(function (array $i) use ($ref, &$n) {
                // The program's procedures by their number (R3); the accountant's own after them (R9, R10…).
                $i['display'] = $ref.(ctype_digit($i['code']) ? (int) $i['code'] : ++$n);

                return $i;
            }, $a['items']);

            return $a;
        }, $p['areas']);
        $label = fn (string $group, array $keys) => array_combine($keys, array_map(fn ($k) => __("audit_plan.procedures.{$group}.{$k}"), $keys));

        return [
            'year' => $year,
            'areas' => $areas,
            'totals' => $p['totals'],
            'performance' => $p['m']['approved']['performance'] ?? $p['m']['performance'],
            'statuses' => $label('statuses', config('audit_procedures.statuses')),
            'types' => $label('types', config('audit_procedures.types')),
            'assertions' => $label('assertions', config('audit_procedures.assertions')),
            'can_manage' => $request->user()->can('clients.manage'),
        ];
    }
}
