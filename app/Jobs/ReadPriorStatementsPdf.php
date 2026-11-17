<?php

namespace App\Jobs;

use App\Models\FinancialReport;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPdf\PageClassifier;
use App\Services\Statements\PriorPdf\Vocabulary;
use App\Services\Statements\PriorStatementsPdf;
use App\Services\Statements\ReportStatements;
use App\Services\Statements\StatementSynonyms;
use App\Support\Llm\LlmCost;
use App\Support\Llm\Provider;
use App\Support\Llm\ProviderException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One step of reading a PDF of last year's statements (PriorStatementsPdf), on the report's waiting upload:
 * `locate` (status locating → pages: the statements' pages, for the accountant to confirm) or `read` (reading → ready:
 * the tables, for review in step 2). A failure leaves the upload `failed` with the step and a message; the pages stay,
 * so the accountant can correct them and read again. An upload replaced meanwhile is left alone.
 */
class ReadPriorStatementsPdf implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;   // the provider calls retry by themselves; a whole step is never run twice

    public function __construct(public string $reportId, public string $uploadId, public string $step) {}

    public function handle(PriorStatementsPdf $pdf): void
    {
        $upload = $this->upload();
        if ($upload === null || $upload['status'] !== ($this->step === 'locate' ? 'locating' : 'reading')) {
            return;
        }
        $path = Storage::disk('local')->path($upload['path']);
        try {
            if ($this->step === 'locate') {
                $found = $pdf->locate($path, $upload['pages_total']);
                // None found: the accountant gives the pages.
                $done = ['status' => 'pages', 'page_kinds' => $found['page_kinds'], 'pages' => $found['pages'], 'found' => $found['pages'], 'skipped' => $found['skipped']];
            } else {
                $report = FinancialReport::findOrFail($this->reportId);
                // What each page is; a file uploaded before page kinds: its statements' pages, the rest not read.
                $kinds = ($upload['page_kinds'] ?? []) ?: PageClassifier::fromRanges($upload['pages'], $upload['pages_total']);
                $names = Vocabulary::accountNames($report->run);
                $read = $pdf->read($path, $kinds, $upload['year'], new LineCatalogue($report->template_version), StatementSynonyms::map(),
                    Vocabulary::of($names, $report->template_version), $this->opening($report), Vocabulary::surfaces($names, $report->template_version));
                $found = $read;
                $done = ['status' => 'ready', 'blocks' => $read['blocks'], 'expenses_shown_positive' => $read['expenses_shown_positive'], 'document' => $read['document'],
                    'equity' => $read['equity']]
                    + ($read['blocks'] === [] ? ['status' => 'failed', 'error' => null, 'error_key' => 'pdf_no_tables'] : []);
            }
            $this->save($done + ['usage' => self::add($upload['usage'] ?? null, $found['usage'])]);
        } catch (Throwable $e) {
            Log::warning('prior statements PDF: '.$this->step.' failed', ['report' => $this->reportId, 'error' => $e->getMessage()]);
            $key = $e instanceof ProviderException ? 'pdf_provider' : 'pdf_failed';
            $this->save(['status' => 'failed', 'error' => $e->getMessage(), 'error_key' => $key]);
        }
    }

    /** This year's opening balance sheet (the trial balance's opening columns), null without them or when it can't be built. */
    private function opening(FinancialReport $report): ?array
    {
        try {
            return app(ReportStatements::class)->build($report)['opening'] ?? null;
        } catch (Throwable $e) {
            Log::info('prior statements PDF: no opening balances to compare', ['report' => $this->reportId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function failed(Throwable $e): void   // a timeout or a crashed worker
    {
        $this->save(['status' => 'failed', 'error' => $e->getMessage(), 'error_key' => 'pdf_failed']);
    }

    private function upload(): ?array
    {
        $upload = FinancialReport::find($this->reportId)?->prior_upload;

        return ($upload['kind'] ?? null) === 'pdf' && ($upload['id'] ?? null) === $this->uploadId ? $upload : null;
    }

    /** The step's result, written only on the same upload, still at this step. */
    private function save(array $values): void
    {
        DB::transaction(function () use ($values) {
            $report = FinancialReport::whereKey($this->reportId)->lockForUpdate()->first();
            $upload = $report?->prior_upload;
            if (($upload['kind'] ?? null) !== 'pdf' || ($upload['id'] ?? null) !== $this->uploadId
                || $upload['status'] !== ($this->step === 'locate' ? 'locating' : 'reading')) {
                return;
            }
            $values += ['provider' => Provider::name(), 'model' => Provider::model(), 'step' => $this->step];
            $values['cost_usd'] = isset($values['usage']) ? LlmCost::usd($values['usage'], Provider::model()) : ($upload['cost_usd'] ?? null);
            $report->update(['prior_upload' => array_merge(array_diff_key($upload, ['error' => 1, 'error_key' => 1]), $values)]);
        });
    }

    /** The tokens of both steps together. */
    private static function add(?array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + $v;
        }

        return $a;
    }
}
