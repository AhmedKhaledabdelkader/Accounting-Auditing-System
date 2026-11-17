<?php

namespace App\Console\Commands;

use App\Services\Statements\PriorPdf\FileFacts;
use App\Services\Statements\PriorPdf\Llm;
use App\Services\Statements\PriorPdf\PageClassifier;
use App\Services\Statements\PriorPdf\PriorPdfReader;
use App\Services\Statements\PriorPdf\Vocabulary;
use App\Services\Statements\PriorPdf\Wording;
use App\Support\Llm\LlmCost;
use App\Support\Llm\Provider;
use App\Support\Pdf\Qpdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads a PDF of issued statements as the app does (brief §8), outside any report: for a fixture of answers kept by
 * file (the reference client's regression tests, ReferencePdfFixtureTest), or to look at what the AI makes of a file. The AI's answers go to --out (kept by the
 * file's content: a second run pays only for what is new), with result.json and what the run cost.
 * Client files and their answers belong in docs/reference/ (gitignored), never in git.
 */
class ExtractPriorPdf extends Command
{
    protected $signature = 'prior-pdf:extract
        {file : the PDF}
        {--out= : where the answers and the result go (default: docs/reference/extraction/<file name>)}
        {--tb= : a trial balance file whose text cells are known words, to check the wording read}';

    protected $description = "Describe and read a PDF of last year's statements, the answers kept for tests";

    public function handle(Qpdf $qpdf, Llm $llm, PageClassifier $classifier, PriorPdfReader $reader): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No file: {$path}");

            return self::FAILURE;
        }
        $out = $this->option('out') ?: base_path('docs/reference/extraction/'.pathinfo($path, PATHINFO_FILENAME));
        File::ensureDirectoryExists($out);
        $llm->cacheIn("{$out}/answers");

        $pages = $qpdf->pages($path);
        $classified = $classifier->classify($path, (string) file_get_contents($path), $pages);
        $doc = $reader->read($path, $classified['pages']);
        $names = [];
        if ($tb = $this->option('tb')) {
            foreach (IOFactory::load($tb)->getAllSheets() as $sheet) {
                $cells = $sheet->toArray(null, false, false, false);
                array_walk_recursive($cells, function ($v) use (&$names) {
                    is_string($v) && $names[] = $v;
                });
            }
        }
        $version = config('financial_statements.template_version');
        // As the app checks it (PriorStatementsPdf): the general words never flagged, names not word by word.
        $wording = Wording::check($doc, Vocabulary::of($names, $version), Vocabulary::surfaces($names, $version), Vocabulary::general(),
            Wording::names(['document' => $doc, 'page_kinds' => $classified['pages']]));
        $doc['flags'] = [...$doc['flags'], ...$wording['flags']];
        $doc['corrections'] = $wording['corrections'];
        $doc['flags'] = [...$doc['flags'], ...FileFacts::flags(['document' => $doc, 'page_kinds' => $classified['pages']])];
        foreach ($doc['corrections'] as $c) {
            $this->line('  corrected: '.json_encode($c, JSON_UNESCAPED_UNICODE));
        }
        $usage = Llm::sum([$classified['usage']]);
        foreach (['input', 'cache_write', 'cached', 'output', 'requests'] as $k) {
            $usage[$k] += $doc['usage'][$k];
        }
        $result = ['file' => basename($path), 'sha256' => hash_file('sha256', $path), 'page_kinds' => $classified['pages'], 'document' => array_diff_key($doc, ['usage' => 1])];

        $cost = LlmCost::usd($usage, Provider::model());
        $result['usage'] = $usage + ['cost_usd' => $cost, 'provider' => Provider::name(), 'model' => Provider::model()];
        file_put_contents("{$out}/result.json", json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->table(['page', 'kind', 'printed', 'turn', 'notes'], array_map(fn ($p) => [$p['page'], $p['kind'], $p['printed_page'] ?? '', $p['rotation'],
            implode(' · ', array_map(fn ($n) => trim(($n['number'] ?? '').' '.$n['title']), $p['notes']))], $classified['pages']));
        $failing = array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok']));
        $this->line(sprintf('Checks: %d of %d pass; pages read again: %s', count($doc['checks']) - count($failing), count($doc['checks']),
            implode(', ', array_map(fn ($p) => "{$p['page']} (×{$p['attempt']})", array_filter($doc['pages'], fn ($p) => $p['attempt'] > 0))) ?: 'none'));
        foreach ($failing as $c) {
            $this->warn('  failing: '.json_encode($c, JSON_UNESCAPED_UNICODE));
        }
        $this->line(sprintf('Flags: %d', count($doc['flags'])));
        foreach ($doc['flags'] as $f) {
            $this->line('  '.json_encode($f, JSON_UNESCAPED_UNICODE));
        }
        $this->info(sprintf('%d request(s) this run, %s', $usage['requests'], $cost === null ? 'cost unknown' : '$'.number_format($cost, 4)));

        return self::SUCCESS;
    }
}
