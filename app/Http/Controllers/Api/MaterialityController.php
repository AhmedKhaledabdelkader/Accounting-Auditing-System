<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ForClientYear;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Audit\Materiality;
use App\Services\Engagement\MaterialityVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The materiality of a client's year over the API (ISA 320): the same working paper as the page (Portal\AuditPlanController),
 * from the same services (Materiality, MaterialityVersions). The page and the API share the plan and the versions.
 *   GET  /clients/{id}/materiality?year=&source=     the measures, each basis, the choice, the levels, the versions
 *   PUT  /clients/{id}/materiality                   saves the choice (as the page's form)
 *   POST /clients/{id}/materiality/approve           approves it as a new version (justification required; a change says why)
 */
class MaterialityController extends Controller
{
    use ForClientYear;

    public function __construct(private readonly Materiality $materiality) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $year = $this->yearOf($request, $company);
        if ($locked = $this->lockedStep($company, $year, 'materiality')) {
            return $locked;
        }
        $source = $request->query('source');

        return response()->json($this->present($request, $company, $year, is_string($source) ? $source : null));
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $bases = array_keys(config('audit.materiality.bases'));
        // The page's rules (Portal\AuditPlanController::saveMateriality).
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'source' => ['nullable', Rule::in(Materiality::SOURCES)],
            'percents' => ['required', 'array'],
            'percents.*' => ['required', 'numeric', 'gt:0', 'max:50'],
            'bases' => ['required', 'array', 'min:1'],
            'bases.*' => [Rule::in($bases)],
            'choice' => ['required', Rule::in([...$bases, ...Materiality::AGGREGATES])],
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
        $year = (int) $data['year'];
        if ($locked = $this->lockedStep($company, $year, 'materiality')) {
            return $locked;
        }
        $this->materiality->save($company, $year, [
            'source' => $data['source'] ?? 'statements', 'percents' => array_map('floatval', $data['percents']), 'bases' => array_values($data['bases']), 'choice' => $data['choice'],
            'performance_percent' => (float) $data['performance_percent'], 'trivial_percent' => (float) $data['trivial_percent'],
            'round' => (bool) ($data['round'] ?? false), 'reason' => $data['reason'] ?? null,
        ], $request->user()->id);

        return response()->json($this->present($request, $company, $year, null));
    }

    public function approve(Request $request, string $id, MaterialityVersions $versions): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'rationale' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'], 'stage' => ['nullable', Rule::in(['planning', 'final'])],
            'specific' => ['nullable', 'array', 'max:20'], 'specific.*.area' => ['nullable', 'string', 'max:40'], 'specific.*.amount' => ['nullable', 'numeric', 'min:0'],
            'specific.*.reason' => ['nullable', 'string', 'max:500']]);
        $year = (int) $data['year'];
        if ($locked = $this->lockedStep($company, $year, 'materiality')) {
            return $locked;
        }
        $made = $versions->approve($company, $year, $request->user(), $data['rationale'] ?? null, $data['change_reason'] ?? null,
            (array) ($data['specific'] ?? []), $data['stage'] ?? 'planning');
        if (is_string($made)) {
            // The same reasons as the page (not saved, an aggregate chosen, no amount, no justification, unchanged, no reason for the change).
            return response()->json(['message' => __("engagement.materiality.errors.{$made}"), 'code' => $made, 'errors' => ['approve' => [__("engagement.materiality.errors.{$made}")]]], 422);
        }

        return response()->json($this->present($request, $company, $year, null) + ['message' => __('engagement.materiality.approved', ['version' => $made->version])]);
    }

    /** The working paper as JSON: without the report and the trial balance themselves, with each basis's name. */
    private function present(Request $request, Company $company, int $year, ?string $source): array
    {
        $m = $this->materiality->build($company, $year, $source);
        $cfg = (array) config('audit.materiality');
        $benchmarks = array_map(fn ($b) => $b + ['label' => __("audit_plan.benchmarks.{$b['key']}"), 'range' => [$cfg['bases'][$b['key']]['low'] ?? null, $cfg['bases'][$b['key']]['high'] ?? null]], $m['benchmarks']);

        return [
            'year' => $year,
            'has_report' => $m['report'] !== null,
            'source' => $m['source'],
            'benchmarks' => $benchmarks,
            'aggregates' => $m['aggregates'],
            'proposed' => $m['proposed'],
            'saved' => $m['saved'],
            'chosen' => $m['chosen'],
            'overall' => $m['overall'],
            'overall_rounded' => $m['overall_rounded'],
            'performance' => $m['performance'],
            'trivial' => $m['trivial'],
            'prior_overall' => $m['prior_overall'],
            'significant' => $m['significant'],
            'approved' => $m['approved'],
            'versions' => MaterialityVersions::of($company, $year)->map(fn ($v) => [
                'version' => $v->version, 'stage' => $v->stage, 'benchmark' => $v->benchmark, 'overall' => (float) $v->overall, 'performance' => (float) $v->performance,
                'trivial' => (float) $v->trivial, 'rationale' => $v->rationale, 'change_reason' => $v->change_reason, 'status' => $v->status,
                'approved_name' => $v->approved_name, 'approved_at' => $v->approved_at?->toDateString(),
            ])->values(),
            'defaults' => ['performance' => $cfg['performance'] ?? null, 'performance_default' => $cfg['performance_default'] ?? null, 'trivial_default' => $cfg['trivial_default'] ?? null],
            'can_manage' => $request->user()->can('clients.manage'),
        ];
    }
}
