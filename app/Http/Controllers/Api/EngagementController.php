<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\EngagementController as Board;
use App\Models\Company;
use App\Services\Engagement\Engagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The engagement file over the API: GET /engagements/{client}/status?year= (and /clients/{client}/engagement): the steps
 * (phase, status, sign-offs, locked), the blockers (code, the message in Arabic and English, the link), the current phase and
 * the next step. Decided by the server (App\Services\Engagement\Engagement); a client screen only shows it.
 */
class EngagementController extends Controller
{
    public function status(Request $request, string $id, Engagement $engagement): JsonResponse
    {
        $company = Str::isUuid($id) ? Company::find($id) : null;
        if ($company === null) {
            return response()->json(['message' => 'الشركة غير موجودة.'], 404);
        }
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);

        return response()->json(Board::present($engagement->status($company, Engagement::year($request, $company))));
    }
}
