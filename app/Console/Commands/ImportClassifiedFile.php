<?php

namespace App\Console\Commands;

use App\Models\ClassificationImport;
use App\Services\CacheImport;
use Illuminate\Console\Command;

/** POST /api/v1/classification-cache/import from the shell (large files: the mapping calls can take minutes). Never applies. */
class ImportClassifiedFile extends Command
{
    protected $signature = 'classification:import
        {file? : xlsx / xls / csv of accountant-classified trial balances}
        {--report= : write the import report (JSON) to this path}
        {--rebuild-name-layer : only rebuild the name-only layer from the applied imports (after changing its thresholds)}
        {--reapply= : apply an import again with the current approved mappings and policy modes (no LLM call)}';

    protected $description = 'Import an accountant-classified file for review (nothing reaches the cache before apply)';

    public function handle(CacheImport $imports): int
    {
        if ($this->option('rebuild-name-layer')) {
            $this->info($imports->rebuildNameLayer().' name-layer entries.');

            return self::SUCCESS;
        }
        if ($id = $this->option('reapply')) {
            $import = ClassificationImport::findOrFail($id);
            $this->info(json_encode($imports->apply($import), JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $import = $imports->import($file, basename($file));
        $report = $import->report;
        if ($path = $this->option('report')) {
            file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        $this->info("Import #{$import->id} ({$import->status}): {$report['rows']['total']} rows, {$report['rows']['approved']} approved, {$report['rows']['pending_review']} pending review.");

        return self::SUCCESS;
    }
}
