<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditFinding;
use App\Models\Company;
use App\Services\Audit\Findings;
use App\Services\Audit\FindingsExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 11. The audit findings of a client's year (Findings): the page, the accountant's word on each, and the workbook. */
class FindingsController extends Controller
{
    public function __construct(private readonly Findings $findings) {}

    public function show(Request $request, Company $company): View
    {
        $year = self::year($request, $company);
        $built = $this->findings->build($company, $year);

        return view('portal.file.findings', ['company' => $company, 'active' => 'findings', 'year' => $year, 'built' => $built,
            'openOnly' => $request->boolean('open'), 'canManage' => $request->user()->can('clients.manage')]);
    }

    public function decide(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'key' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::in(AuditFinding::STATUSES)], 'comment' => ['nullable', 'string', 'max:2000']]);
        $this->findings->decide($company, (int) $data['year'], $data['key'], $data['status'], $data['comment'] ?? null, $request->user()->id);

        return back()->with('status', __('audit.saved'));
    }

    public function export(Request $request, Company $company)
    {
        $year = self::year($request, $company);
        $bytes = FindingsExport::xlsx($company, $this->findings->build($company, $year));

        return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"findings-{$year}.xlsx\"; filename*=UTF-8''".rawurlencode(__('audit.title')." - {$company->name_ar} - {$year}.xlsx")]);
    }

    public static function year(Request $request, Company $company): int
    {
        $y = (int) $request->query('year', 0);

        return $y >= 2000 && $y <= 2100 ? $y : ($company->periodYear() ?? now()->year - 1);
    }
}
