<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The work steps of the main side menu (4 trial balance, 5 previous statements, 6 financial statements, 7 zakat and income tax, 8 the requirements, 9 the working papers, 10 the recalculations). Each is about one
 * client: the one in `?client=`, else the one opened last (remembered in the session by RememberClient). With none, the page asks which
 * client, and goes on to the same step for the one chosen. The step itself is the client's file page of it (ClientFileController).
 */
class WorkController extends Controller
{
    /** Step key → the name of its route. */
    public const ROUTES = ['engagement' => 'work.engagement', 'trial_balance' => 'work.trial-balance', 'prior' => 'work.prior', 'classification' => 'work.classification',
        'risks' => 'work.risks', 'materiality' => 'work.materiality', 'procedures' => 'work.procedures', 'requirements' => 'work.requirements',
        'papers' => 'work.papers', 'recalc' => 'work.recalc', 'sampling' => 'work.sampling', 'findings' => 'work.findings', 'adjustments' => 'work.adjustments',
        'zakat' => 'work.zakat', 'statements' => 'work.statements', 'completion' => 'work.completion', 'release' => 'work.release'];

    public function __construct(private readonly ClientFileController $files) {}

    public function trialBalance(Request $request): View
    {
        return $this->step($request, 'trial_balance', fn (Company $c) => $this->files->trialBalance($request, $c));
    }

    public function prior(Request $request): View
    {
        return $this->step($request, 'prior', fn (Company $c) => $this->files->prior($request, $c));
    }

    public function statements(Request $request): View
    {
        return $this->step($request, 'statements', fn (Company $c) => $this->files->statements($c));
    }

    public function zakat(Request $request): View
    {
        return $this->step($request, 'zakat', fn (Company $c) => $this->files->zakat($request, $c));
    }

    public function requirements(Request $request): View
    {
        return $this->step($request, 'requirements', fn (Company $c) => app(ClientController::class)->show($request, $c));
    }

    public function papers(Request $request): View
    {
        return $this->step($request, 'papers', fn (Company $c) => $this->files->papers($request, $c));
    }

    public function recalculation(Request $request): View
    {
        return $this->step($request, 'recalc', fn (Company $c) => $this->files->recalculation($request, $c));
    }

    public function findings(Request $request): View
    {
        return $this->step($request, 'findings', fn (Company $c) => app(FindingsController::class)->show($request, $c));
    }

    public function materiality(Request $request): View
    {
        return $this->step($request, 'materiality', fn (Company $c) => app(AuditPlanController::class)->materiality($request, $c));
    }

    public function sampling(Request $request): View
    {
        return $this->step($request, 'sampling', fn (Company $c) => app(AuditPlanController::class)->sampling($request, $c));
    }

    public function engagement(Request $request): View
    {
        return $this->step($request, 'engagement', fn (Company $c) => app(EngagementController::class)->show($request, $c));
    }

    public function classification(Request $request): View
    {
        return $this->step($request, 'classification', fn (Company $c) => app(EngagementController::class)->classification($request, $c));
    }

    public function risks(Request $request): View
    {
        return $this->step($request, 'risks', fn (Company $c) => app(RisksController::class)->show($request, $c));
    }

    public function adjustments(Request $request): View
    {
        return $this->step($request, 'adjustments', fn (Company $c) => app(AdjustmentsController::class)->show($request, $c));
    }

    public function completion(Request $request): View
    {
        return $this->step($request, 'completion', fn (Company $c) => app(CompletionController::class)->show($request, $c));
    }

    public function release(Request $request): View
    {
        return $this->step($request, 'release', fn (Company $c) => app(CompletionController::class)->release($request, $c));
    }

    public function procedures(Request $request): View
    {
        return $this->step($request, 'procedures', fn (Company $c) => app(ProceduresController::class)->show($request, $c));
    }

    private function step(Request $request, string $step, \Closure $page): View
    {
        if ($request->boolean('change')) {
            $request->session()->forget('current_client');
        }
        $id = $request->query('client') ?: $request->session()->get('current_client');
        $company = $id ? Company::find($id) : null;   // the signed-in office's own: another office's is not found
        if ($company === null) {
            $request->session()->forget('current_client');

            return view('portal.work.choose', ['step' => $step, 'clients' => Company::orderBy('name_ar')->get(), 'route' => self::ROUTES[$step]]);
        }
        $request->session()->put('current_client', $company->id);

        return $page($company);
    }
}