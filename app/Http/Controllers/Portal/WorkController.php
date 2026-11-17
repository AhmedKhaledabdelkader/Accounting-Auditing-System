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
    public const ROUTES = ['trial_balance' => 'work.trial-balance', 'prior' => 'work.prior', 'statements' => 'work.statements', 'zakat' => 'work.zakat',
        'requirements' => 'work.requirements', 'papers' => 'work.papers', 'recalc' => 'work.recalc'];

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