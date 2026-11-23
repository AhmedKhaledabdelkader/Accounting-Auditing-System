<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuditPlan;
use App\Models\AuditRisk;
use App\Models\Company;
use App\Services\Audit\Materiality;
use App\Services\Engagement\Engagement;
use App\Services\Engagement\RiskRegister;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 5. Understanding the entity and the risks (ISA 315): the register, the suggestions from the figures, the procedures answering each. */
class RisksController extends Controller
{
    public function show(Request $request, Company $company): View
    {
        $year = Engagement::year($request, $company);
        if ($locked = EngagementController::lockedView($request, $company, $year, 'risks')) {
            return $locked;
        }
        $plan = AuditPlan::of($company, $year);
        $risks = AuditRisk::where('company_id', $company->id)->where('year', $year)->orderByRaw("case status when 'suggested' then 0 when 'accepted' then 1 else 2 end")
            ->orderByDesc('is_significant')->orderBy('id')->get();
        $unanswered = array_map(fn ($r) => $r->id, RiskRegister::unanswered($risks->where('status', AuditRisk::ACCEPTED), $plan));
        $procedures = [];
        foreach ($risks->pluck('area')->unique() as $area) {
            $procedures[$area] = RiskRegister::procedures($area, $plan);
        }

        return view('portal.file.risks', ['company' => $company, 'active' => 'risks', 'year' => $year, 'risks' => $risks, 'unanswered' => $unanswered,
            'procedures' => $procedures, 'areas' => RiskRegister::areas(), 'canManage' => $request->user()->can('clients.manage')]);
    }

    public function suggest(Request $request, Company $company, Materiality $materiality): RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        $added = RiskRegister::suggest($company, $year, $materiality->build($company, $year), $request->user());

        return $this->back($company, $year)->with('status', __('engagement.risks.suggested', ['count' => $added]));
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $this->validated($request);
        $risk = AuditRisk::create($this->fields($data) + ['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => (int) $data['year'],
            'status' => AuditRisk::ACCEPTED, 'created_by' => $request->user()->id]);
        AuditLog::record($company, (int) $data['year'], 'create', 'risk', $risk->id, null, $risk->only(['area', 'description', 'inherent_rating', 'is_significant']));

        return $this->back($company, (int) $data['year'])->with('status', __('audit_plan.saved'));
    }

    public function update(Request $request, Company $company, AuditRisk $risk): RedirectResponse
    {
        abort_unless($risk->company_id === $company->id, 404);
        if ($request->has('status') && ! $request->has('description')) {
            // Accept or dismiss a suggestion as it is.
            $status = $request->validate(['status' => ['required', Rule::in([AuditRisk::ACCEPTED, AuditRisk::DISMISSED, AuditRisk::SUGGESTED])]])['status'];
            $before = $risk->only(['status']);
            $risk->update(['status' => $status]);
            AuditLog::record($company, $risk->year, 'decide', 'risk', $risk->id, $before, ['status' => $status]);

            return $this->back($company, $risk->year)->with('status', __('audit_plan.saved'));
        }
        $data = $this->validated($request, $risk->area);
        $before = $risk->only(['area', 'description', 'likelihood', 'magnitude', 'is_significant', 'procedures', 'status']);
        $risk->update($this->fields($data) + ['status' => $data['status'] ?? $risk->status]);
        AuditLog::record($company, $risk->year, 'update', 'risk', $risk->id, $before, $risk->only(array_keys($before)));

        return $this->back($company, $risk->year)->with('status', __('audit_plan.saved'));
    }

    public function destroy(Request $request, Company $company, AuditRisk $risk): RedirectResponse
    {
        abort_unless($risk->company_id === $company->id, 404);
        // A suggestion is dismissed (so it isn't made again); a risk typed by hand goes.
        if ($risk->source !== null) {
            $risk->update(['status' => AuditRisk::DISMISSED]);
        } else {
            $risk->delete();
        }
        AuditLog::record($company, $risk->year, 'delete', 'risk', $risk->id, $risk->only(['area', 'description']));

        return $this->back($company, $risk->year)->with('status', __('audit_plan.saved'));
    }

    private function validated(Request $request, ?string $area = null): array
    {
        $areas = array_keys(RiskRegister::areas());

        return $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'area' => ['required', Rule::in($areas)],
            'description' => ['required', 'string', 'max:2000'], 'assertions' => ['nullable', 'array'], 'assertions.*' => [Rule::in(config('audit_procedures.assertions'))],
            'likelihood' => ['required', 'integer', 'between:1,3'], 'magnitude' => ['required', 'integer', 'between:1,3'],
            'is_significant' => ['nullable', 'boolean'], 'is_fraud' => ['nullable', 'boolean'], 'control_reliance' => ['nullable', 'boolean'],
            'procedures' => ['nullable', 'array'], 'procedures.*' => ['string', 'max:10'], 'response' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([AuditRisk::ACCEPTED, AuditRisk::DISMISSED, AuditRisk::SUGGESTED])]]);
    }

    private function fields(array $d): array
    {
        $rating = AuditRisk::rating((int) $d['likelihood'], (int) $d['magnitude']);
        $plan = AuditPlan::of(request()->route('company'), (int) $d['year']);
        $codes = array_keys(RiskRegister::procedures($d['area'], $plan));

        return ['area' => $d['area'], 'level' => $d['area'] === 'fs' ? 'fs' : 'assertion', 'description' => trim($d['description']),
            'assertions' => array_values($d['assertions'] ?? []), 'likelihood' => (int) $d['likelihood'], 'magnitude' => (int) $d['magnitude'],
            'inherent_rating' => $rating, // A fraud risk is significant (ISA 240.27), and so is one at the top of the scale.
            'is_significant' => (bool) ($d['is_significant'] ?? false) || $rating === 'high' || (bool) ($d['is_fraud'] ?? false), 'is_fraud' => (bool) ($d['is_fraud'] ?? false),
            'control_reliance' => (bool) ($d['control_reliance'] ?? false),
            'procedures' => array_values(array_intersect($codes, array_map('strval', $d['procedures'] ?? []))), 'response' => $d['response'] ?? null];
    }

    private function back(Company $company, int $year): RedirectResponse
    {
        return redirect()->route('clients.file.risks', [$company, 'year' => $year]);
    }
}
