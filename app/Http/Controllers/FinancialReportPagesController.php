<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\CompanyController;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\FinancialReport;
use App\Services\Statements\ReportSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The financial-report pages (plan §4.6, PR 13): the list, a new report, and the wizard. Server-rendered shells; their
 * scripts (public/js/financial-report) read and write through the API. Every word shown comes from lang/{ar,en} in the
 * page's language (`financial_reports` for the pages, `statements` for the codes' names), passed in `PageConfig`. URLs are relative.
 */
class FinancialReportPagesController extends Controller
{
    /** Steps of the wizard, in order (§4.6). */
    /** Steps that live in the client's file (step => its route), when the statements have a company. */
    public const CLIENT_STEPS = ['opening' => 'clients.file.prior', 'zakat' => 'clients.file.zakat'];

    public const STEPS = ['company', 'prior_year', 'accounts', 'opening', 'movements', 'zakat', 'notes', 'review', 'versions'];

    public function __construct(private ReportSource $source)
    {
    }

    public function index(): Response
    {
        return response()->view('financial-reports.index', ['config' => $this->config('list')]);
    }

    /** The synonyms of the statements' lines (PR 17): see, add, edit and delete them. */
    public function synonyms(): Response
    {
        return response()->view('statement-synonyms', ['config' => $this->config('synonyms')]);
    }

    /** ?run= a finished run to build the report from; without it, the page lists the finished runs to pick from. */
    public function create(Request $request): Response
    {
        $run = $request->query('run') ? ClassificationRun::find($request->query('run')) : null;
        if ($request->query('run') && ! $run) {
            return $this->error(__('financial_reports.errors.run_not_found'), 404);
        }

        return response()->view('financial-reports.create', ['config' => $this->config('new', [
            'run' => $run ? ['id' => $run->id, 'original_name' => $run->original_name, 'status' => $run->status, 'unusable' => $this->source->unusable($run),
                'unclassified' => $run->status === 'done' ? count(array_filter($run->result['data'] ?? [], fn ($r) => ($r['sub_class'] ?? null) === null)) : 0,
                // The company chosen on the classifier page, carried to the new report (still changeable).
                'company' => $run->company_id && ($company = Company::find($run->company_id)) ? CompanyController::present($company) : null] : null,
        ])]);
    }

    public function show(string $report): Response
    {
        $model = FinancialReport::find($report);
        if (! $model) {
            return $this->error(__('financial_reports.errors.report_not_found'), 404);
        }
        if ($reason = $this->source->unusable($model->run)) {
            return $this->error($reason, 409);
        }

        // With a company, the opening balances and zakat are steps of the client's file (their own pages there), not of this wizard.
        $steps = self::STEPS;
        $urls = [];
        if ($model->company_id) {
            $steps = array_values(array_diff(self::STEPS, array_keys(self::CLIENT_STEPS)));
            $urls = self::clientStepUrls($model);
        }

        return response()->view('financial-reports.show', ['config' => $this->config('wizard', [
            'report_id' => $model->id, 'run_id' => $model->classification_run_id, 'steps' => $steps, 'step_urls' => (object) $urls,
        ])]);
    }

    /** Where the steps that are not in the wizard are: the client's file pages for the opening balances and zakat. */
    public static function clientStepUrls(FinancialReport $model): array
    {
        $urls = [];
        foreach (self::CLIENT_STEPS as $step => $route) {
            $urls[$step] = route($route, $model->company_id, false).'?report='.$model->id;
        }

        return $urls;
    }

    /**
     * The wizard with ONE step, for a page of the client's file (the opening balances, zakat): the same state and the same step, no
     * header or step bar; every other step is a link back to the wizard (or to the client's page that holds it).
     */
    public function embeddedConfig(FinancialReport $model, string $step): array
    {
        $urls = [];
        foreach (self::STEPS as $other) {
            $urls[$other] = '/financial-reports/'.rawurlencode($model->id).'#'.$other;
        }

        return $this->config('wizard', ['report_id' => $model->id, 'run_id' => $model->classification_run_id, 'steps' => [$step], 'embedded' => true,
            'step_urls' => (object) array_diff_key(self::clientStepUrls($model) + $urls, [$step => 1])]);
    }

    /**
     * The old preview of a run (PR 6), now the review step of its latest report; a run with no report yet starts one. A
     * redirect, not a 404, so old links keep working (PR 13c).
     */
    public function runPreview(string $run): RedirectResponse
    {
        $report = FinancialReport::where('classification_run_id', $run)->orderByDesc('updated_at')->orderByDesc('created_at')->first();

        return redirect($report ? '/financial-reports/'.rawurlencode($report->id).'#review' : '/financial-reports/new?run='.rawurlencode($run));
    }

    private function config(string $page, array $extra = []): array
    {
        return ['page' => $page, 'api' => '/api/v1', 'urls' => [
            'list' => '/financial-reports', 'new' => '/financial-reports/new', 'report' => '/financial-reports/:id',
            'classifier' => '/classifier', 'synonyms' => '/statement-synonyms',
        ], 'ui' => self::lang('financial_reports.ui'), 'labels' => self::lang('statements')] + $extra;
    }

    /** A lang group in the page's language, with the Arabic names for what has no translation (statement and note content). */
    private static function lang(string $key): array
    {
        return array_replace_recursive(__($key, [], 'ar'), app()->getLocale() === 'ar' ? [] : __($key));
    }

    private function error(string $message, int $status): Response
    {
        return response()->view('financial-reports.error', ['message' => $message], $status);
    }
}
