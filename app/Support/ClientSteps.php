<?php

namespace App\Support;

use App\Models\ClassificationRun;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\FinancialReport;
use App\Models\PreparedPaper;

/**
 * The steps of a client, in order, and whether each is done. 1 to 3 are the client's own file (its side menu), 4 to 10 the work
 * on the client (the main side menu, which asks for a client when none is open):
 * 1 the client's details and period, 2 the commercial registration, 3 the articles of association, 4 the trial balance (current and
 * previous if any), 5 last year's financial statements (the opening balances), 6 the financial statements, 7 zakat and income tax, 8 the
 * requirements (what the client is asked for), 9 the working papers and 10 the recalculations (both prepared by the AI).
 */
final class ClientSteps
{
    /** The client's own file, in the side menu of the client: step key => its route. */
    public const ORDER = ['details' => 'clients.edit', 'registration' => 'clients.file.registration', 'articles' => 'clients.file.articles'];

    /** The work steps (4 to 7), in the main side menu: step key => the client's page of it. */
    public const WORK = ['trial_balance' => 'clients.file.trial-balance', 'prior' => 'clients.file.prior', 'statements' => 'clients.file.statements', 'zakat' => 'clients.file.zakat',
        'requirements' => 'clients.show', 'papers' => 'clients.file.papers', 'recalc' => 'clients.file.recalculation'];

    /**
     * @return array<string, array{done: bool, count: ?int}>
     */
    public static function of(Company $company): array
    {
        $reports = FinancialReport::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'prior_figures', 'zakat_tax']);
        $runs = ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'done')->count();
        $priorFiles = ClientFile::withoutGlobalScopes()->where('company_id', $company->id)->where('from_client', true)
            ->whereHas('documentRequest', fn ($q) => $q->where('title', 'like', '%القوائم المالية المعتمدة والمصدّرة%'))->count();
        $withPrior = $reports->filter(fn ($r) => in_array($r->prior_figures['source'] ?? 'none', ['statements', 'report', 'run', 'manual'], true))->count();
        $withZakat = $reports->filter(fn ($r) => ! empty($r->zakat_tax['applied']) || ! empty($r->zakat_tax['inputs']))->count();

        $requests = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('paper')->get(['status']);
        $closed = $requests->whereIn('status', [DocumentRequest::ACCEPTED, DocumentRequest::NOT_APPLICABLE])->count();
        $prepared = PreparedPaper::withoutGlobalScopes()->where('company_id', $company->id)->where('status', PreparedPaper::DONE)->get(['kind']);

        return [
            'details' => ['done' => $company->name_ar && $company->name_en && $company->client_name && $company->client_email && $company->period_start && $company->period_end, 'count' => null],
            'registration' => ['done' => ! empty($company->profile['registration']['file']), 'count' => null],
            // A sole proprietorship has no articles of association: nothing to do there.
            'articles' => ['done' => ! empty($company->profile['articles']['partners']) || $company->articlesRequired() === false, 'count' => null],
            'trial_balance' => ['done' => $runs > 0, 'count' => $runs ?: null],
            'prior' => ['done' => $priorFiles > 0 || $withPrior > 0, 'count' => $priorFiles ?: null],
            'statements' => ['done' => $reports->isNotEmpty(), 'count' => $reports->count() ?: null],
            'zakat' => ['done' => $withZakat > 0, 'count' => null],
            'requirements' => ['done' => $requests->isNotEmpty() && $closed === $requests->count(), 'count' => $requests->count() ?: null],
            'papers' => ['done' => $prepared->where('kind', 'paper')->isNotEmpty(), 'count' => $prepared->where('kind', 'paper')->count() ?: null],
            'recalc' => ['done' => $prepared->where('kind', 'recalc')->isNotEmpty(), 'count' => $prepared->where('kind', 'recalc')->count() ?: null],
        ];
    }
}
