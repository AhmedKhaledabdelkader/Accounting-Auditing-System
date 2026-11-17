<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use App\Services\Exports\NotesDocx;
use App\Services\Exports\StatementsPdf;
use App\Services\Exports\StatementsXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A version's files (plan §4.7, PRs 14–16), made on demand from its stored payload (Q16): the same version always gives
 * the same file.
 */
class ReportExportController extends Controller
{
    public function xlsx(string $id, int $version, StatementsXlsx $xlsx): Response|JsonResponse
    {
        [$report, $found] = $this->find($id, $version);
        if (! $found) {
            return $this->notFound($report);
        }

        $payload = $found->exportPayload();
        // Versions saved before their entries carried account names take them from the run, while it is there.
        $byRow = array_column($report->run?->result['data'] ?? [], null, 'row');
        $file  = $xlsx->build($payload, $found->created_at->toIso8601String(), $byRow);

        return $this->file($file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $payload, $found, 'xlsx');
    }

    /** The notes (PR 15). */
    public function docx(string $id, int $version, NotesDocx $docx): Response|JsonResponse
    {
        [$report, $found] = $this->find($id, $version);
        if (! $found) {
            return $this->notFound($report);
        }

        $payload = $found->exportPayload();
        $file    = $docx->build($payload, $found->created_at->toIso8601String());

        return $this->file($file, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $payload, $found, 'docx');
    }

    /** The statements and the notes (PR 16). */
    public function pdf(string $id, int $version, StatementsPdf $pdf): Response|JsonResponse
    {
        [$report, $found] = $this->find($id, $version);
        if (! $found) {
            return $this->notFound($report);
        }

        $payload = $found->exportPayload();
        $file    = $pdf->build($payload, $found->created_at->toIso8601String());

        return $this->file($file, 'application/pdf', $payload, $found, 'pdf');
    }

    /** @return array{0: ?FinancialReport, 1: ?FinancialReportVersion} */
    private function find(string $id, int $version): array
    {
        $report = FinancialReport::find($id);

        return [$report, $report ? FinancialReportVersion::where('report_id', $id)->where('version', $version)->first() : null];
    }

    private function notFound(?FinancialReport $report): JsonResponse
    {
        return response()->json(['message' => $report ? 'هذا الإصدار غير موجود.' : 'القوائم المالية هذه غير موجودة.'], 404);
    }

    private function file(string $file, string $type, array $payload, FinancialReportVersion $found, string $extension): Response
    {
        return response($file, 200, [
            'Content-Type'        => $type,
            'Content-Disposition' => self::disposition($payload, $found, $extension),
            'Content-Length'      => (string) strlen($file),
        ]);
    }

    /**
     * «القوائم المالية - <company> - <period end> - نسخة <n> (<kind>).xlsx» (the notes: «الإيضاحات المتممة للقوائم المالية - …
     * .docx»; both: «القوائم المالية والإيضاحات المتممة لها - ….pdf»), with an ASCII fallback.
     */
    private static function disposition(array $p, FinancialReportVersion $v, string $extension): string
    {
        $kind = __("exports.version_kinds.{$v->kind}", [], 'ar');
        $title = __(['docx' => 'exports.notes_filename', 'pdf' => 'exports.pdf_filename'][$extension] ?? 'exports.filename', [], 'ar');
        $name = implode(' - ', array_filter([$title, $p['company']['name_ar'] ?? null, $p['period_end'], "نسخة {$v->version} ({$kind})"]));
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name)).".{$extension}";
        $ascii = ($extension === 'docx' ? 'financial-statements-notes' : 'financial-statements')."-{$p['period_end']}-v{$v->version}-{$v->kind}.{$extension}";

        return "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($name);
    }
}
