<?php

namespace App\Console\Commands;

use App\Services\AccountExtractor;
use App\Services\SpreadsheetReader;
use App\Services\StructureChecks;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/** Runs the structure checks on a file and a known mapping, without the AI: for tuning the thresholds. */
class CheckStructure extends Command
{
    protected $signature = 'classification:check-structure
        {file : xlsx / xls / csv}
        {mapping : JSON file with the structure: {sheet, header_rows, first_data_row, columns, other_sheets}}
        {--json : print the issues as JSON}
        {--calibrate : also check the wrong mappings of the calibration set (DemoConfirmation::mutate): one line per mapping}';

    protected $description = 'Check a column mapping against a trial balance file and list every error and doubt';

    public function handle(SpreadsheetReader $reader, AccountExtractor $extractor, StructureChecks $checks): int
    {
        $sheets    = $reader->read(new UploadedFile($this->argument('file'), basename($this->argument('file')), null, null, true));
        $structure = $extractor->normalize(json_decode((string) file_get_contents($this->argument('mapping')), true, flags: JSON_THROW_ON_ERROR));
        $issues    = $checks->check($sheets, $structure);

        if ($this->option('calibrate')) {
            // The correct mapping must be clean; each wrong one must be caught. Compare the lines before and after a change.
            $rows = [];
            foreach (['none', 'swap-opening-closing', 'swap-closing-sides', 'notes-as-name', 'skip-rows'] as $mutation) {
                if ($wrong = DemoConfirmation::mutate($structure, $mutation)) {
                    $found  = $checks->check($sheets, $extractor->normalize($wrong));
                    $list   = fn (string $sev) => implode(', ', array_map(fn ($i) => $i['check'].($i['column'] ? ":{$i['column']}" : ''), array_filter($found, fn ($i) => $i['severity'] === $sev)));
                    $rows[] = [$mutation, $list('error'), $list('doubt')];
                }
            }
            $this->table(['mapping', 'errors', 'doubts'], $rows);

            return $rows[0][1] === '' ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif (! $issues) {
            $this->info('No issues.');
        } else {
            $this->table(['severity', 'check', 'field', 'column', 'value', 'threshold', 'message'],
                array_map(fn ($i) => [$i['severity'], $i['check'], $i['field'], $i['column'], $i['value'], $i['threshold'], $i['ai']], $issues));
        }

        return array_filter($issues, fn ($i) => $i['severity'] === 'error') ? self::FAILURE : self::SUCCESS;
    }
}
