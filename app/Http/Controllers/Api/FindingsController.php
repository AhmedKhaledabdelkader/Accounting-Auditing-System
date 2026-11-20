<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditFinding;
use App\Models\Company;
use App\Services\Audit\Findings;
use App\Services\Audit\FindingsExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The audit findings of a client's year over the API (Findings): the client's documents matched with the trial balance, the
 * recalculations against it with their adjusting entries, the working papers' differences, and the accountant's word on each.
 */
class FindingsController extends Controller
{
    public function __construct(private readonly Findings $findings) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'status' => ['nullable', Rule::in(AuditFinding::STATUSES)]]);
        $built = $this->findings->build($company, $this->year($company, $data['year'] ?? null));
        if (isset($data['status'])) {
            $built['findings'] = array_values(array_filter($built['findings'], fn ($f) => $f['status'] === $data['status']));
        }

        return response()->json($built);
    }

    /** The accountant's word on one finding: its status and a comment. Returns the year's findings. */
    public function decide(Request $request, string $id, string $key): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'status' => ['required', Rule::in(AuditFinding::STATUSES)],
            'comment' => ['nullable', 'string', 'max:2000']]);
        $built = $this->findings->build($company, (int) $data['year']);
        if (! in_array($key, array_column($built['findings'], 'key'), true)) {
            return response()->json(['message' => __('audit.not_found')], 404);
        }
        $this->findings->decide($company, (int) $data['year'], $key, $data['status'], $data['comment'] ?? null, $request->user()?->id);

        return response()->json($this->findings->build($company, (int) $data['year']));
    }

    public function export(Request $request, string $id)
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = $this->year($company, $data['year'] ?? null);

        return response(FindingsExport::xlsx($company, $this->findings->build($company, $year)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"findings-{$year}.xlsx\""]);
    }

    private function year(Company $company, mixed $year): int
    {
        return (int) ($year ?: ($company->periodYear() ?? now()->year - 1));
    }

    private function company(string $id): ?Company
    {
        return Str::isUuid($id) ? Company::find($id) : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'الشركة غير موجودة.'], 404);
    }
}
