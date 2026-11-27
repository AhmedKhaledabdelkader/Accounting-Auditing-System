<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Company;
use App\Services\Engagement\Engagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared by the API controllers of a client's year (materiality, risks, the audit program, sampling, misstatements,
 * completion): the client from the URL, the year from the query or the body, and the engagement step's lock.
 */
trait ForClientYear
{
    protected function company(string $id): ?Company
    {
        return Str::isUuid($id) ? Company::find($id) : null;
    }

    protected function notFound(): JsonResponse
    {
        return response()->json(['message' => app()->getLocale() === 'en' ? 'Client not found.' : 'الشركة غير موجودة.'], 404);
    }

    /** The year asked for (query or body), else the client's financial year, like the pages. */
    protected function yearOf(Request $request, Company $company): int
    {
        $y = (int) $request->input('year', $request->query('year', 0));

        return $y >= 2000 && $y <= 2100 ? $y : ($company->periodYear() ?? now()->year - 1);
    }

    /**
     * The engagement step is locked (an earlier step not done): 423 with the hard blockers, as the page shows them.
     * Null when it is open.
     */
    protected function lockedStep(Company $company, int $year, string $step): ?JsonResponse
    {
        $e = app(Engagement::class);
        if (! $e->locked($company, $year, $step)) {
            return null;
        }
        $blockers = array_values(array_filter($e->blockersOf($company, $year, $step), fn ($b) => ! $b['soft']));

        return response()->json(['message' => __('engagement.sign_errors.locked'), 'locked' => true, 'blockers' => $blockers], 423);
    }
}
