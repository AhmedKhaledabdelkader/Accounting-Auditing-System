<?php

namespace App\Console\Commands;

use App\Services\ClassificationService;
use App\Support\Llm\LlmCost;
use App\Support\Llm\Provider;
use App\Support\Llm\UsageMeter;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Classifies one file with two providers, the classification cache off (every row from the AI, nothing stored), and
 * compares their sub-classes row by row: the agreement rate, and the classes they disagree on most. Costs real money.
 *   php artisan classifier:compare tests/Fixtures/balance.xlsx
 */
class CompareClassifiers extends Command
{
    protected $signature = 'classifier:compare
        {file : xlsx / xls / csv trial balance}
        {--providers=anthropic,gemini : the two providers, the first as reference}
        {--report= : JSON report path (default storage/logs/classifier-compare-<time>.json)}';

    protected $description = 'Classify a file with two AI providers and report how often they agree';

    public function handle(UsageMeter $meter): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");
            return self::FAILURE;
        }
        [$reference, $other] = array_map('trim', explode(',', $this->option('providers'))) + [1 => null];
        config(['classification.cache.enabled' => false]);   // every row from the AI, nothing written to the cache

        $runs = [];
        foreach ([$reference, $other] as $provider) {
            config(['classification.provider' => $provider]);
            $this->info("Classifying with {$provider} (".Provider::model().')…');
            $started = microtime(true);
            $result = app(ClassificationService::class)->classifyFile(new UploadedFile($path, basename($path), null, null, true));
            $runs[$provider] = [
                'model'   => Provider::model(),
                'seconds' => round(microtime(true) - $started, 1),
                'usage'   => $meter->totals(),
                'cost'    => LlmCost::usd($meter->totals(), Provider::model()),
                'rows'    => collect($result['rows'])->filter(fn ($r) => $r['sub_class'] !== null)->keyBy('row')->all(),
            ];
        }

        $a = $runs[$reference]['rows'];
        $b = $runs[$other]['rows'];
        $both = array_intersect_key($a, $b);
        $differ = array_filter($both, fn ($r) => $r['sub_class'] !== $b[$r['row']]['sub_class']);
        $byClass = [];
        foreach ($differ as $row => $r) {
            $byClass[$r['sub_class']]['rows'] = ($byClass[$r['sub_class']]['rows'] ?? 0) + 1;
            $byClass[$r['sub_class']]['became'][$b[$row]['sub_class']] = ($byClass[$r['sub_class']]['became'][$b[$row]['sub_class']] ?? 0) + 1;
        }
        uasort($byClass, fn ($x, $y) => $y['rows'] <=> $x['rows']);
        $mainAgree = count(array_filter($both, fn ($r) => $r['main_class'] === $b[$r['row']]['main_class']));

        $report = [
            'file'      => basename($path),
            'providers' => array_map(fn ($r) => array_diff_key($r, ['rows' => 0]) + ['classified_rows' => count($r['rows'])], $runs),
            'compared_rows'        => count($both),
            'only_classified_by'   => [$reference => count(array_diff_key($a, $b)), $other => count(array_diff_key($b, $a))],
            'sub_class_agreement'  => count($both) ? round(100 * (count($both) - count($differ)) / count($both), 1) : null,
            'main_class_agreement' => count($both) ? round(100 * $mainAgree / count($both), 1) : null,
            'disagreements_by_reference_class' => $byClass,
            'disagreements' => array_values(array_map(fn ($r) => [
                'row' => $r['row'], 'account' => trim($r['account_number'].' '.$r['account_name']),
                $reference => $r['sub_class'], $other => $b[$r['row']]['sub_class'],
                "{$reference}_confidence" => $r['classification_confidence'], "{$other}_confidence" => $b[$r['row']]['classification_confidence'],
            ], $differ)),
        ];
        $file = $this->option('report') ?: storage_path('logs/classifier-compare-'.now()->format('Ymd-His').'.json');
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($runs as $provider => $r) {
            $this->line(sprintf('%-9s %-18s %4d rows  %6.1fs  %s', $provider, $r['model'], count($r['rows']), $r['seconds'],
                $r['cost'] === null ? 'cost unknown' : '$'.number_format($r['cost'], 4)));
        }
        $this->info("Sub-class agreement: {$report['sub_class_agreement']}% of {$report['compared_rows']} rows (main class: {$report['main_class_agreement']}%).");
        $this->table([$reference.' class', 'rows', $other.' chose'], array_map(
            fn ($class, $d) => [$class, $d['rows'], collect($d['became'])->sortDesc()->map(fn ($n, $c) => "{$c} ({$n})")->implode('، ')],
            array_keys(array_slice($byClass, 0, 10, true)), array_slice($byClass, 0, 10, true)));
        $this->line("Report: {$file}");

        return self::SUCCESS;
    }
}
