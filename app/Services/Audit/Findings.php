<?php

namespace App\Services\Audit;

use App\Models\AuditFinding;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\PreparedPaper;
use App\Services\Portal\Workspace;
use App\Services\WorkingPapers\PaperMaker;
use Illuminate\Support\Carbon;

/**
 * The audit findings of a client's year (ملاحظات المراجعة), from what the AI read and the system matched — never typed by hand:
 *  - each document's balances against the trial balance (DocumentMatch, kept on the file's review): a difference, an account
 *    that couldn't be told, a balance at another date;
 *  - a document with a problem the client hasn't fixed yet;
 *  - each recalculation against the trial balance (Recalculations: `computed_vs_tb`), with the adjusting entry it calls for, its
 *    rows that differ and what it couldn't recalculate;
 *  - each working paper's unexplained differences and unanswered rows.
 * The accountant's word on each (adjusted, accepted, dismissed, a comment) is kept by the finding's key (AuditFinding); the
 * finding itself is recomputed every time, so a document read again or a paper prepared again shows at once.
 */
final class Findings
{
    /**
     * @return array{year: int, period_end: string, trial_balance: ?array{run_id: string, latest: bool}, findings: list<array>, counts: array<string, int>}
     */
    public function build(Company $company, int $year): array
    {
        $end = Carbon::parse(Workspace::periodOf($company, $year)['end'])->toDateString();
        $book = TrialBalanceBook::for($company, $year);
        $out = [...$this->documents($company, $year), ...$this->papers($company, $year)];

        $kept = AuditFinding::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->get()->keyBy('key');
        foreach ($out as &$f) {
            $k = $kept->get($f['key']);
            $f['status'] = $k?->status ?? AuditFinding::OPEN;
            $f['comment'] = $k?->comment;
        }
        unset($f);
        $rank = ['difference' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => [$a['status'] !== AuditFinding::OPEN, $rank[$a['severity']], -abs($a['amounts']['difference'] ?? 0)]
            <=> [$b['status'] !== AuditFinding::OPEN, $rank[$b['severity']], -abs($b['amounts']['difference'] ?? 0)]);

        $counts = ['total' => count($out), 'open' => 0, 'differences' => 0];
        foreach ($out as $f) {
            $f['status'] === AuditFinding::OPEN && $counts['open']++;
            $f['severity'] === 'difference' && $f['status'] === AuditFinding::OPEN && $counts['differences']++;
        }

        return ['year' => $year, 'period_end' => $end, 'trial_balance' => $book ? ['run_id' => $book->runId, 'latest' => $book->latest] : null,
            'findings' => $out, 'counts' => $counts];
    }

    /** Keeps the accountant's word on a finding. */
    public function decide(Company $company, int $year, string $key, string $status, ?string $comment, ?int $by): AuditFinding
    {
        return AuditFinding::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id, 'year' => $year, 'key' => $key],
            ['office_id' => $company->office_id, 'status' => $status, 'comment' => $comment, 'updated_by' => $by]);
    }

    /** The documents: their balances against the books, and their problems still open. */
    private function documents(Company $company, int $year): array
    {
        $files = ClientFile::withoutGlobalScopes()->where('company_id', $company->id)->where('from_client', true)
            ->whereHas('documentRequest', fn ($q) => $q->withoutGlobalScopes()->where('year', $year))->with(['documentRequest' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get();
        $out = [];
        foreach ($files as $file) {
            $review = (array) $file->review;
            $source = ['type' => 'document', 'id' => $file->id, 'name' => $file->original_name, 'request' => $file->documentRequest?->title];
            foreach ((array) ($review['tb'] ?? []) as $i => $m) {
                if ($m['status'] === 'matched') {
                    continue;
                }
                $kind = $m['kind'];
                $account = $m['account'] ? trim(($m['account']['number'] ? $m['account']['number'].' - ' : '').$m['account']['name']) : null;
                $f = ['key' => "doc-{$file->id}-{$i}", 'source' => $source, 'account' => $account, 'entry' => null,
                    'amounts' => ['document' => $m['document'], 'book' => $m['book'], 'difference' => $m['difference']]];
                $label = $m['label'].($m['counterparty'] ? " ({$m['counterparty']})" : '');
                $out[] = $f + match ($m['status']) {
                    'differs' => ['type' => 'balance_differs', 'severity' => 'difference',
                        'title' => __('audit.finding.balance_differs', ['label' => $label]),
                        'detail' => __('audit.detail.balance_differs', ['document' => self::money($m['document']), 'book' => self::money($m['book']), 'account' => $account, 'difference' => self::money($m['difference'])]),
                        'recommendation' => __("audit.recommend.{$kind}")],
                    'other_date' => ['type' => 'other_date', 'severity' => 'warning',
                        'title' => __('audit.finding.other_date', ['label' => $label]),
                        'detail' => __('audit.detail.other_date', ['date' => $m['as_of'], 'document' => self::money($m['document']), 'book' => self::money($m['book']), 'account' => $account]),
                        'recommendation' => __('audit.recommend.other_date')],
                    'not_found' => ['type' => 'account_not_found', 'severity' => 'warning',
                        'title' => __('audit.finding.not_found', ['label' => $label]),
                        'detail' => __('audit.detail.not_found', ['document' => self::money($m['document']), 'reference' => $m['reference'] ?? '—']),
                        'recommendation' => __('audit.recommend.not_found')],
                    default => ['type' => 'no_trial_balance', 'severity' => 'info',
                        'title' => __('audit.finding.no_tb', ['label' => $label]),
                        'detail' => __('audit.detail.no_tb', ['document' => self::money($m['document'])]),
                        'recommendation' => __('audit.recommend.no_tb')],
                };
            }
            // A document the client still has to fix (the request not accepted since).
            $status = $review['status'] ?? null;
            if ($status !== null && ! in_array($status, ['ok', 'unavailable', 'other_request'], true) && $file->documentRequest?->status !== 'accepted') {
                $issues = array_map(fn ($i) => \App\Services\Portal\DocumentReviewer::issueText($i), (array) ($review['issues'] ?? []));
                $out[] = ['key' => "docissue-{$file->id}", 'source' => $source, 'account' => null, 'entry' => null, 'amounts' => ['document' => null, 'book' => null, 'difference' => null],
                    'type' => 'document_issue', 'severity' => 'warning', 'title' => __('audit.finding.document_issue', ['name' => $file->original_name]),
                    'detail' => implode(' ', $issues), 'recommendation' => __('audit.recommend.document_issue')];
            }
        }

        return $out;
    }

    /** The recalculations and working papers the AI prepared. */
    private function papers(Company $company, int $year): array
    {
        $catalogue = PaperMaker::catalogue();
        $tolerance = (float) config('tb_matching.tolerance', 2) - 0.005;
        $entries = (array) config('tb_matching.papers');
        $out = [];
        $papers = PreparedPaper::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->where('status', PreparedPaper::DONE)->orderBy('kind')->orderBy('id')->get();
        foreach ($papers as $p) {
            $a = (array) $p->analysis;
            $title = $catalogue["{$p->kind}.{$p->key}"]['title'] ?? $p->key;
            $source = ['type' => $p->kind === 'recalc' ? 'recalculation' : 'working_paper', 'id' => $p->id, 'name' => $title, 'request' => null];
            $none = ['document' => null, 'book' => null, 'difference' => null];
            if ($p->kind === 'recalc') {
                // Against the trial balance as it is now (it may have been corrected since the paper was prepared), else as the paper had it.
                $tb = $p->key === 'payroll' ? null : (PaperMaker::booksTotal($company, $year, "recalc.{$p->key}") ?? $a['trial_balance'] ?? null);
                $a['trial_balance'] = $tb;
                $vs = $tb === null || ! isset($a['computed']) ? null : round($a['computed'] - $tb, 2);
                if ($vs !== null && abs($vs) > $tolerance) {
                    $spec = $entries["recalc.{$p->key}"] ?? null;
                    $pair = $spec ? ($vs > 0 ? $spec['more'] : $spec['less']) : null;
                    $rows = array_map(fn ($d) => __('audit.detail.row', ['name' => $d['name'], 'computed' => self::money($d['computed']), 'book' => self::money($d['book'])]), array_slice((array) ($a['differing'] ?? []), 0, 5));
                    $out[] = ['key' => "recalc-{$p->key}", 'source' => $source, 'account' => null, 'type' => 'recalc_differs', 'severity' => 'difference',
                        'amounts' => ['document' => $a['computed'], 'book' => $a['trial_balance'], 'difference' => $vs],
                        'entry' => $pair ? ['debit' => $pair[0], 'credit' => $pair[1], 'amount' => round(abs($vs), 2)] : null,
                        'title' => __('audit.finding.recalc_differs', ['title' => $title]),
                        'detail' => __('audit.detail.recalc_differs', ['computed' => self::money($a['computed']), 'tb' => self::money($a['trial_balance']), 'difference' => self::money($vs)])
                            .($rows ? ' '.__('audit.detail.rows', ['rows' => implode('؛ ', $rows)]) : ''),
                        'recommendation' => $pair ? __('audit.recommend.entry', ['debit' => $pair[0], 'credit' => $pair[1], 'amount' => self::money(abs($vs))]) : __('audit.recommend.recalc')];
                } elseif ($vs === null && $p->key !== 'payroll' && ($a['rows_used'] ?? 0) > 0) {
                    $out[] = ['key' => "recalc-{$p->key}-tb", 'source' => $source, 'account' => null, 'type' => 'no_trial_balance', 'severity' => 'info', 'amounts' => ['document' => $a['computed'] ?? null, 'book' => null, 'difference' => null],
                        'entry' => null, 'title' => __('audit.finding.recalc_no_tb', ['title' => $title]), 'detail' => __('audit.detail.recalc_no_tb', ['computed' => self::money($a['computed'] ?? 0)]),
                        'recommendation' => __('audit.recommend.no_tb')];
                }
                if ($p->key === 'payroll' && ($a['differing'] ?? []) !== []) {
                    $rows = array_map(fn ($d) => __('audit.detail.row', ['name' => $d['name'], 'computed' => self::money($d['computed']), 'book' => self::money($d['book'])]), array_slice($a['differing'], 0, 8));
                    $out[] = ['key' => 'recalc-payroll', 'source' => $source, 'account' => null, 'type' => 'recalc_differs', 'severity' => 'difference', 'entry' => null,
                        'amounts' => ['document' => null, 'book' => null, 'difference' => round(array_sum(array_column($a['differing'], 'difference')), 2)],
                        'title' => __('audit.finding.payroll', ['count' => count($a['differing'])]), 'detail' => implode('؛ ', $rows), 'recommendation' => __('audit.recommend.payroll')];
                }
                if (($a['missing'] ?? []) !== []) {
                    $out[] = ['key' => "recalc-{$p->key}-missing", 'source' => $source, 'account' => null, 'type' => 'missing_data', 'severity' => 'warning', 'amounts' => $none, 'entry' => null,
                        'title' => __('audit.finding.missing', ['title' => $title]), 'detail' => implode('؛ ', array_slice($a['missing'], 0, 8)), 'recommendation' => __('audit.recommend.missing')];
                }
            } else {
                foreach ((array) ($a['differences'] ?? []) as $i => $d) {
                    $out[] = ['key' => "paper-{$p->key}-{$i}", 'source' => $source, 'account' => null, 'type' => 'paper_difference', 'severity' => 'difference', 'entry' => null,
                        'amounts' => ['document' => null, 'book' => null, 'difference' => $d['total']],
                        'title' => __('audit.finding.paper_difference', ['title' => $title, 'label' => $d['label']]),
                        'detail' => __('audit.detail.paper_difference', ['total' => self::money($d['total']), 'rows' => implode('، ', array_slice($d['rows'], 0, 10))]),
                        'recommendation' => __('audit.recommend.paper_difference')];
                }
                $rows = (array) ($a['rows_incomplete'] ?? []);
                $questions = (array) ($a['questions_open'] ?? []);
                if ($rows !== [] || $questions !== []) {
                    $detail = array_filter([$rows ? __('audit.detail.paper_rows', ['rows' => implode('، ', array_slice($rows, 0, 15))]) : null,
                        $questions ? __('audit.detail.paper_questions', ['questions' => implode('؛ ', array_slice($questions, 0, 5))]) : null]);
                    $out[] = ['key' => "paper-{$p->key}-open", 'source' => $source, 'account' => null, 'type' => 'missing_data', 'severity' => 'warning', 'amounts' => $none, 'entry' => null,
                        'title' => __('audit.finding.paper_open', ['title' => $title]), 'detail' => implode(' ', $detail), 'recommendation' => __('audit.recommend.missing')];
                }
            }
        }

        return $out;
    }

    public static function money(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }
}
