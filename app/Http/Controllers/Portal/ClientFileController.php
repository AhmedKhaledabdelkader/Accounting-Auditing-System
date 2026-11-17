<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FinancialReportPagesController;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\FinancialReport;
use App\Models\PreparedPaper;
use App\Services\WorkingPapers\PaperMaker;
use App\Services\Portal\ClientDocuments;
use App\Support\ClientFileSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The pages of a client's file, in the order of its side menu (ClientSteps): the registration, the articles of association, the
 * trial balance, last year's statements, the financial statements and zakat. The first step (the client's details) is the edit page
 * of ClientController; the requests and the chat stay on the client's own page. Every company here is the signed-in office's own:
 * another office's company is a 404.
 */
class ClientFileController extends Controller
{
    public function __construct(private readonly ClientDocuments $documents) {}

    /** 2. The commercial registration: what the AI read from it, and a new one to replace it. */
    public function registration(Company $company): View
    {
        return view('portal.file.registration', ['company' => $company, 'registration' => $company->profile['registration'] ?? null, 'active' => 'registration']);
    }

    public function updateRegistration(Request $request, Company $company): RedirectResponse
    {
        $request->validate(['registration_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $read = $this->documents->readRegistration($request->file('registration_file'), $company->id);
        $this->documents->keepRegistration($company, $request->file('registration_file'), $read);

        return back()->with('status', __('client_file.registration.updated'))->with('warnings', array_filter([$this->documents->nameWarning($company->fresh(), $read)]));
    }

    /** 3. The articles of association: the partners the AI read, their ownership and the zakat share, and a new contract to replace it. */
    public function articles(Company $company): View
    {
        return view('portal.file.articles', ['company' => $company, 'articles' => CompanyController::articles($company), 'file' => $company->profile['articles'] ?? null, 'active' => 'articles']);
    }

    public function updateArticles(Request $request, Company $company): RedirectResponse
    {
        $request->validate(['articles_file' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('financial_statements.prior_pdf.max_kb', 20480)]]);
        $warning = $this->documents->keepArticles($company, $request->file('articles_file'));

        return back()->with($warning ? 'warnings' : 'status', $warning ? [$warning] : __('client_file.articles.updated'));
    }

    /** 4. The trial balance, current and previous if any: what the client uploaded, and the trial balances already classified. */
    public function trialBalance(Request $request, Company $company): View
    {
        $year = (int) $request->query('year', $company->periodYear() ?? now()->year - 1);
        $files = fn (int $y) => collect(ClientFileSource::forPurpose($company->id, 'trial_balance', $y)['files'])->filter(fn ($f) => ($f['request']['year'] ?? null) === $y)->values();

        return view('portal.file.trial-balance', [
            'company' => $company, 'active' => 'trial_balance', 'year' => $year,
            'periods' => [['year' => $year, 'text' => \App\Services\Portal\Workspace::periodText($company, $year), 'files' => $files($year), 'current' => true],
                ['year' => $year - 1, 'text' => \App\Services\Portal\Workspace::periodText($company, $year - 1), 'files' => $files($year - 1), 'current' => false]],
            'runs' => ClassificationRun::where('company_id', $company->id)->orderByDesc('created_at')->limit(12)->get(['id', 'status', 'original_name', 'created_at']),
            'canManage' => $request->user()->can('clients.manage'),
        ]);
    }

    /**
     * 5. Last year's financial statements: what the client uploaded, and the comparison of the opening balances (the wizard's step,
     * here in the client's file) for the chosen statements.
     */
    public function prior(Request $request, Company $company): View
    {
        $year = (int) $request->query('year', $company->periodYear() ?? now()->year - 1);
        [$reports, $selected] = $this->chosenStatements($request, $company);

        return view('portal.file.prior', [
            'company' => $company, 'active' => 'prior', 'year' => $year, 'reports' => $reports, 'selected' => $selected, 'route' => 'clients.file.prior',
            'files' => collect(ClientFileSource::forPurpose($company->id, 'prior_statements', $year - 1)['files']),
            'config' => $selected ? app(FinancialReportPagesController::class)->embeddedConfig($selected, 'opening') : null,
        ]);
    }

    /** The company's statements, newest period first, and the one the page is about (?report=, else the latest). */
    private function chosenStatements(Request $request, Company $company): array
    {
        $reports = FinancialReport::where('company_id', $company->id)->orderByDesc('period_end')->orderByDesc('created_at')->get();
        $selected = $reports->firstWhere('id', $request->query('report')) ?? $reports->first();

        return [$reports, $selected];
    }
    /** 6. The financial statements of the client, and the trial balances they can be made from. */
    public function statements(Company $company): View
    {
        return view('portal.file.statements', [
            'company' => $company, 'active' => 'statements',
            'reports' => FinancialReport::where('company_id', $company->id)->orderByDesc('period_end')->orderByDesc('created_at')->get(['id', 'period_start', 'period_end', 'status', 'updated_at', 'finalized_at']),
            'runs' => ClassificationRun::where('company_id', $company->id)->where('status', 'done')->orderByDesc('created_at')->limit(8)->get(['id', 'original_name', 'created_at']),
        ]);
    }

    /** 7. Zakat: the owners' share from the articles, and the zakat step (the wizard's, here in the client's file) of the chosen statements. */
    public function zakat(Request $request, Company $company): View
    {
        [$reports, $selected] = $this->chosenStatements($request, $company);

        return view('portal.file.zakat', [
            'company' => $company, 'active' => 'zakat', 'articles' => CompanyController::articles($company), 'reports' => $reports, 'selected' => $selected, 'route' => 'clients.file.zakat',
            'config' => $selected ? app(FinancialReportPagesController::class)->embeddedConfig($selected, 'zakat') : null,
        ]);
    }

    /** 9. The working papers, prepared by the AI from the client's documents and the trial balance. */
    public function papers(Request $request, Company $company): View
    {
        return $this->prepared($request, $company, 'paper', 'papers');
    }

    /** 10. The recalculations (service benefits, depreciation, prepaid, receivables allowance, payroll), prepared by the AI. */
    public function recalculation(Request $request, Company $company): View
    {
        return $this->prepared($request, $company, 'recalc', 'recalc');
    }

    private function prepared(Request $request, Company $company, string $kind, string $active): View
    {
        $year = (int) $request->query('year', $company->periodYear() ?? now()->year - 1);
        $maker = app(PaperMaker::class);
        $done = PreparedPaper::where('company_id', $company->id)->where('year', $year)->where('kind', $kind)->get()->keyBy('key');
        $items = collect(PaperMaker::catalogue())->where('kind', $kind)->map(fn ($p) => $p + [
            'paper' => $done->get($p['key']), 'documents' => count($maker->sources($company, $year, "{$kind}.{$p['key']}")),
        ])->values();

        return view('portal.file.prepared', ['company' => $company, 'active' => $active, 'kind' => $kind, 'year' => $year, 'items' => $items,
            'canManage' => $request->user()->can('clients.manage'), 'route' => $kind === 'paper' ? 'clients.file.papers' : 'clients.file.recalculation']);
    }

    /** Starts the AI on one paper (kind.key) or on all of a kind. */
    public function prepare(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'kind' => ['required', 'in:paper,recalc'], 'key' => ['required', 'string', 'max:80']]);
        $catalogue = collect(PaperMaker::catalogue())->where('kind', $data['kind']);
        $chosen = $data['key'] === 'all' ? $catalogue : $catalogue->where('key', $data['key']);
        abort_if($chosen->isEmpty(), 404);
        foreach ($chosen as $paper) {
            PaperMaker::queue($company, (int) $data['year'], $paper['kind'], $paper['key']);
        }

        return back()->with('status', __('client_file.prepared.started', ['count' => $chosen->count()]));
    }

    /** Where the AI is on each paper: the page looks again by itself while any is being prepared. */
    public function preparedStatus(Request $request, Company $company)
    {
        $papers = PreparedPaper::where('company_id', $company->id)->where('year', (int) $request->query('year'))->get();

        return response()->json(['active' => $papers->filter->isActive()->count(), 'papers' => $papers->map(fn ($p) => [
            'id' => $p->id, 'kind' => $p->kind, 'key' => $p->key, 'status' => $p->status, 'done' => $p->progress_done, 'total' => $p->progress_total])->values()]);
    }

    public function downloadPaper(Company $company, int $paper)
    {
        $p = PreparedPaper::where('company_id', $company->id)->findOrFail($paper);
        abort_unless($p->path && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->path), 404);
        $title = PaperMaker::catalogue()["{$p->kind}.{$p->key}"]['title'] ?? $p->key;

        return \Illuminate\Support\Facades\Storage::disk('local')->download($p->path, $title.' - '.$p->year.'.xlsx');
    }
}
