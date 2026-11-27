<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ForClientYear;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuditPlan;
use App\Models\AuditRisk;
use App\Models\Company;
use App\Services\Audit\Materiality;
use App\Services\Engagement\RiskRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The risk register of a client's year over the API (ISA 315 / 330): the same register as the page (Portal\RisksController),
 * from the same service (RiskRegister) and model (AuditRisk). The page and the API share the risks.
 *   GET    /clients/{id}/risks?year=                 the register, each risk's procedures, the areas, the counts
 *   POST   /clients/{id}/risks/suggest               the suggestions from the year's figures (once each)
 *   POST   /clients/{id}/risks                       a risk typed by hand (accepted)
 *   PUT    /clients/{id}/risks/{risk}                changes a risk (as the page's form)
 *   PATCH  /clients/{id}/risks/{risk}/status         accepts or dismisses a suggestion as it is
 *   DELETE /clients/{id}/risks/{risk}                a suggestion is dismissed (not made again); a risk typed by hand goes
 * Every answer is the whole register again, so the screen shows what is saved.
 */
class RisksController extends Controller
{
    use ForClientYear;

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $year = $this->yearOf($request, $company);

        return $this->lockedStep($company, $year, 'risks') ?? response()->json($this->present($request, $company, $year));
    }

    public function suggest(Request $request, string $id, Materiality $materiality): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        if ($locked = $this->lockedStep($company, $year, 'risks')) {
            return $locked;
        }
        $added = RiskRegister::suggest($company, $year, $materiality->build($company, $year), $request->user());

        return response()->json($this->present($request, $company, $year) + ['added' => $added, 'message' => __('engagement.risks.suggested', ['count' => $added])]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $this->validated($request);
        if ($locked = $this->lockedStep($company, (int) $data['year'], 'risks')) {
            return $locked;
        }
        $risk = AuditRisk::create($this->fields($company, $data) + ['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => (int) $data['year'],
            'status' => AuditRisk::ACCEPTED, 'created_by' => $request->user()->id]);
        AuditLog::record($company, (int) $data['year'], 'create', 'risk', $risk->id, null, $risk->only(['area', 'description', 'inherent_rating', 'is_significant']));

        return response()->json($this->present($request, $company, (int) $data['year']) + ['id' => $risk->id], 201);
    }

    public function update(Request $request, string $id, string $risk): JsonResponse
    {
        [$company, $r] = $this->risk($id, $risk);
        if ($r === null) {
            return $company === null ? $this->notFound() : $this->riskNotFound();
        }
        if ($locked = $this->lockedStep($company, $r->year, 'risks')) {
            return $locked;
        }
        $data = $this->validated($request);
        $before = $r->only(['area', 'description', 'likelihood', 'magnitude', 'is_significant', 'procedures', 'status']);
        $r->update($this->fields($company, $data) + ['status' => $data['status'] ?? $r->status]);
        AuditLog::record($company, $r->year, 'update', 'risk', $r->id, $before, $r->only(array_keys($before)));

        return response()->json($this->present($request, $company, $r->year));
    }

    public function status(Request $request, string $id, string $risk): JsonResponse
    {
        [$company, $r] = $this->risk($id, $risk);
        if ($r === null) {
            return $company === null ? $this->notFound() : $this->riskNotFound();
        }
        if ($locked = $this->lockedStep($company, $r->year, 'risks')) {
            return $locked;
        }
        $status = $request->validate(['status' => ['required', Rule::in([AuditRisk::ACCEPTED, AuditRisk::DISMISSED, AuditRisk::SUGGESTED])]])['status'];
        $before = $r->only(['status']);
        $r->update(['status' => $status]);
        AuditLog::record($company, $r->year, 'decide', 'risk', $r->id, $before, ['status' => $status]);

        return response()->json($this->present($request, $company, $r->year));
    }

    public function destroy(Request $request, string $id, string $risk): JsonResponse
    {
        [$company, $r] = $this->risk($id, $risk);
        if ($r === null) {
            return $company === null ? $this->notFound() : $this->riskNotFound();
        }
        if ($locked = $this->lockedStep($company, $r->year, 'risks')) {
            return $locked;
        }
        // As the page: a suggestion is dismissed (so it isn't made again); a risk typed by hand goes.
        if ($r->source !== null) {
            $r->update(['status' => AuditRisk::DISMISSED]);
        } else {
            $r->delete();
        }
        AuditLog::record($company, $r->year, 'delete', 'risk', $r->id, $r->only(['area', 'description']));

        return response()->json($this->present($request, $company, $r->year));
    }

    /** The client and its risk from the URL ([null, null] when the client isn't found, [company, null] when the risk isn't its). */
    private function risk(string $id, string $risk): array
    {
        $company = $this->company($id);
        if ($company === null) {
            return [null, null];
        }
        $r = ctype_digit($risk) ? AuditRisk::where('company_id', $company->id)->find((int) $risk) : null;

        return [$company, $r];
    }

    private function riskNotFound(): JsonResponse
    {
        return response()->json(['message' => app()->getLocale() === 'en' ? 'Risk not found.' : 'الخطر غير موجود.'], 404);
    }

    /** The page's rules (Portal\RisksController::validated). */
    private function validated(Request $request): array
    {
        return $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'area' => ['required', Rule::in(array_keys(RiskRegister::areas()))],
            'description' => ['required', 'string', 'max:2000'], 'assertions' => ['nullable', 'array'], 'assertions.*' => [Rule::in(config('audit_procedures.assertions'))],
            'likelihood' => ['required', 'integer', 'between:1,3'], 'magnitude' => ['required', 'integer', 'between:1,3'],
            'is_significant' => ['nullable', 'boolean'], 'is_fraud' => ['nullable', 'boolean'], 'control_reliance' => ['nullable', 'boolean'],
            'procedures' => ['nullable', 'array'], 'procedures.*' => ['string', 'max:10'], 'response' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([AuditRisk::ACCEPTED, AuditRisk::DISMISSED, AuditRisk::SUGGESTED])]]);
    }

    /** The page's fields (Portal\RisksController::fields): the rating from likelihood × magnitude; a fraud or high risk is significant. */
    private function fields(Company $company, array $d): array
    {
        $rating = AuditRisk::rating((int) $d['likelihood'], (int) $d['magnitude']);
        $codes = array_keys(RiskRegister::procedures($d['area'], AuditPlan::of($company, (int) $d['year'])));

        return ['area' => $d['area'], 'level' => $d['area'] === 'fs' ? 'fs' : 'assertion', 'description' => trim($d['description']),
            'assertions' => array_values($d['assertions'] ?? []), 'likelihood' => (int) $d['likelihood'], 'magnitude' => (int) $d['magnitude'],
            'inherent_rating' => $rating,
            'is_significant' => (bool) ($d['is_significant'] ?? false) || $rating === 'high' || (bool) ($d['is_fraud'] ?? false), 'is_fraud' => (bool) ($d['is_fraud'] ?? false),
            'control_reliance' => (bool) ($d['control_reliance'] ?? false),
            'procedures' => array_values(array_intersect($codes, array_map('strval', $d['procedures'] ?? []))), 'response' => $d['response'] ?? null];
    }

    /** The register as JSON: the risks (suggested first, then accepted, then dismissed, as the page), their procedures, the lists. */
    private function present(Request $request, Company $company, int $year): array
    {
        $plan = AuditPlan::of($company, $year);
        $risks = AuditRisk::where('company_id', $company->id)->where('year', $year)->orderByRaw("case status when 'suggested' then 0 when 'accepted' then 1 else 2 end")
            ->orderByDesc('is_significant')->orderBy('id')->get();
        $accepted = $risks->where('status', AuditRisk::ACCEPTED);
        $unanswered = array_map(fn ($r) => $r->id, RiskRegister::unanswered($accepted, $plan));
        $areas = RiskRegister::areas();
        $procedures = [];
        foreach (array_keys($areas) as $area) {
            $procedures[$area] = array_map(fn ($p) => ['text' => $p[0], 'type' => $p[1]], RiskRegister::procedures($area, $plan));
        }
        $assertions = [];
        foreach ((array) config('audit_procedures.assertions') as $a) {
            $assertions[$a] = __("audit_plan.procedures.assertions.{$a}");
        }

        return [
            'year' => $year,
            'risks' => $risks->map(fn (AuditRisk $r) => [
                'id' => $r->id, 'area' => $r->area, 'area_label' => $areas[$r->area] ?? $r->area, 'level' => $r->level, 'description' => $r->description,
                'assertions' => array_values((array) $r->assertions), 'likelihood' => $r->likelihood, 'magnitude' => $r->magnitude, 'inherent_rating' => $r->inherent_rating,
                'is_significant' => $r->is_significant, 'is_fraud' => $r->is_fraud, 'control_reliance' => $r->control_reliance, 'status' => $r->status,
                'source' => $r->source, 'procedures' => array_values(array_map('strval', (array) $r->procedures)), 'response' => $r->response,
                'unanswered' => in_array($r->id, $unanswered, true), 'updated_at' => $r->updated_at?->toIso8601String(),
            ])->values(),
            'counts' => ['total' => $risks->count(), 'suggested' => $risks->where('status', AuditRisk::SUGGESTED)->count(), 'accepted' => $accepted->count(),
                'significant' => $accepted->where('is_significant', true)->count(), 'unanswered' => count($unanswered)],
            'areas' => $areas,
            'assertions' => $assertions,
            'procedures' => $procedures,
            'can_manage' => $request->user()->can('clients.manage'),
        ];
    }
}
