<?php

namespace App\Services\WorkingPapers;

use App\Models\ClientFile;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The firm's blank working papers (config/working_papers.php) and what a copy of one shows (WorkingPaperReader::analyse). The papers
 * are prepared by the AI (PaperMaker), not requested from the client; a copy found among the client's uploads is still read.
 */
final class Papers
{
    public static function path(string $key): ?string
    {
        $file = config("working_papers.papers.{$key}");
        $path = $file ? resource_path("working-papers/{$file}") : null;

        return $path && is_file($path) ? $path : null;
    }

    /** Reads a returned copy for the request and keeps what it shows; null when it isn't a workbook this layout reads. */
    public function analyse(DocumentRequest $request, string $storedPath): ?array
    {
        if ($request->paper === null || strtolower(pathinfo($storedPath, PATHINFO_EXTENSION)) !== 'xlsx') {
            return null;
        }
        try {
            $full = Storage::disk('local')->path($storedPath);
            $analysis = str_starts_with($request->paper, 'recalc-') ? (new Recalculations())->analyse(substr($request->paper, 7), $full) : WorkingPaperReader::analyse($full);
        } catch (\Throwable) {
            return null;
        }
        $request->update(['analysis' => $analysis + ['at' => now()->toIso8601String()]]);

        return $analysis;
    }

    /** The analysis in a few plain lines (the chat line and the accountant's view). */
    public static function summary(array $a): string
    {
        if (($a['kind'] ?? null) === 'recalc') {
            return implode(' · ', $a['lines']);
        }
        $parts = [];
        if ($a['entity_missing'] !== []) {
            $parts[] = __('portal.paper.sum_entity', ['fields' => implode('، ', $a['entity_missing'])]);
        }
        $parts[] = $a['rows_total'] > 0 ? __('portal.paper.sum_rows', ['used' => $a['rows_used'], 'total' => $a['rows_total']]) : __('portal.paper.sum_no_table');
        if ($a['rows_incomplete'] !== []) {
            $parts[] = __('portal.paper.sum_incomplete', ['rows' => implode('، ', array_slice($a['rows_incomplete'], 0, 10))]);
        }
        if ($a['questions_open'] !== []) {
            $parts[] = __('portal.paper.sum_questions', ['count' => count($a['questions_open'])]);
        }
        foreach ($a['differences'] as $d) {
            $parts[] = __('portal.paper.sum_difference', ['label' => $d['label'], 'total' => number_format($d['total'], 2), 'rows' => implode('، ', array_slice($d['rows'], 0, 10))]);
        }
        if ($a['coverage'] !== null) {
            $parts[] = __('portal.paper.sum_coverage', ['percent' => round($a['coverage'] * 100, 1)]);
        }
        $parts[] = __($a['conclusion_written'] ? 'portal.paper.sum_conclusion' : 'portal.paper.sum_no_conclusion');

        return implode(' · ', $parts);
    }
}
