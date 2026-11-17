<?php

namespace App\Console\Commands;

use App\Repositories\Eloquents\AccountClassifierRepository;
use App\Repositories\Eloquents\TaxonomyMapperRepository;
use App\Repositories\Eloquents\TaxonomyRepository;
use App\Support\Llm\LlmCost;
use App\Support\Llm\Provider;
use App\Support\Llm\ProviderException;
use App\Support\Llm\UsageMeter;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live check that a provider accepts every tool schema we send: one real call of each kind (structure, a row batch with
 * the full sub-class enum, the matching call with integer enums, both import mappings with nullable fields and null
 * inside enums), through the real repositories and driver. Costs a few cents at most. Run after setting the key:
 *   php artisan classifier:probe --provider=gemini
 */
class ProbeClassifier extends Command
{
    protected $signature = 'classifier:probe {--provider= : anthropic | gemini (default: CLASSIFIER_PROVIDER)}';

    protected $description = 'Send one real call of every kind to the AI provider and report what it accepts';

    /** @var list<Response> responses of the current call */
    private array $responses = [];

    public function handle(UsageMeter $meter): int
    {
        if ($provider = $this->option('provider')) {
            config(['classification.provider' => $provider]);
        }
        Http::globalResponseMiddleware(function ($response) {
            $this->responses[] = new Response($response);
            return $response;
        });
        $meter->reset();
        $taxonomy = app(TaxonomyRepository::class);
        $classifier = app(AccountClassifierRepository::class);
        $mapper = app(TaxonomyMapperRepository::class);
        $this->info('Provider: '.Provider::name().', model: '.Provider::model());

        $structure = ['columns' => ['account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'G', 'closing_credit' => 'H', 'existing_classification' => 'I']];
        $headers = [1 => ['A' => 'رقم الحساب', 'B' => 'اسم الحساب', 'G' => 'مدين', 'H' => 'دائن', 'I' => 'بنود الميزانية']];
        $rows = [2 => ['A' => '110101', 'B' => 'الصندوق', 'G' => 100, 'I' => 'النقدية'], 3 => ['A' => '210101', 'B' => 'موردون - شركة النور', 'H' => 500, 'I' => 'الموردين'],
            4 => ['A' => '510101', 'B' => 'مصروف ايجار المكتب', 'G' => 1200, 'I' => 'مصروفات عمومية'], 5 => ['B' => 'إجمالي الأصول']];
        $fileContext = [
            'chart' => [['row' => 2, 'account_number' => '110101', 'account_name' => 'الصندوق', 'existing' => 'النقدية'],
                ['row' => 3, 'account_number' => '210101', 'account_name' => 'موردون - شركة النور', 'existing' => 'الموردين'],
                ['row' => 4, 'account_number' => '510101', 'account_name' => 'مصروف ايجار المكتب', 'existing' => 'مصروفات عمومية']],
            'adjustments' => [['id' => 1, 'sheet_name' => 'تسويات', 'row' => 3, 'account_name' => 'الصندوق', 'debit' => 50, 'credit' => 0]],
            'existing_values' => [['id' => 1, 'value' => 'النقدية'], ['id' => 2, 'value' => 'الموردين'], ['id' => 3, 'value' => 'مصروفات عمومية']],
        ];
        $subClasses = $taxonomy->subClasses();
        $taxonomyMap = $taxonomy->map();

        $calls = [
            'structure (integer enums)' => fn () => $classifier->detectStructure([1 => [
                1 => ['A' => 'رقم الحساب', 'B' => 'اسم الحساب', 'G' => 'رصيد ختامي مدين', 'H' => 'رصيد ختامي دائن'],
                2 => ['A' => '110101', 'B' => 'الصندوق', 'G' => 100, 'H' => 0],
                3 => ['A' => '210101', 'B' => 'موردون - شركة النور', 'G' => 0, 'H' => 500],
            ]], [], [1 => 'ميزان المراجعة'], ['A', 'B', 'G', 'H']),
            'row batch ('.count($subClasses).'-item enum, min/max)' => fn () => $this->rolling($classifier, [$structure, $headers, $rows, [], $fileContext, null]),
            'matching (integer enums)' => fn () => $this->rolling($classifier, [$structure, $headers, [], [], $fileContext, ['adjustments' => [1], 'existing' => [1, 2, 3]]]),
            'budget mappings (nullable type)' => fn () => $mapper->mapBudgetItems(['النقدية وما في حكمها', 'المصروفات العمومية والإدارية', 'شيء غير مفهوم'], $taxonomy->mainClasses()),
            'note mappings (null inside enums)' => fn () => $mapper->mapNotes([
                ['budget' => 'النقدية', 'note' => 'نقدية بالصندوق', 'names' => ['الصندوق الرئيسي'], 'hint_main' => 'النقد ومافي حكمه'],
                ['budget' => 'تكاليف المبيعات', 'note' => 'ايجار', 'names' => ['ايجار مستودع'], 'hint_main' => null, 'function_subs' => ['تكلفة البضاعة المباعة', 'تكلفة الخدمات المقدمة']],
            ], $taxonomyMap),
        ];
        $checks = [
            fn ($a) => is_int($a['sheet'] ?? null) && isset($a['columns']['account_name']),
            fn ($a) => count($a['rows']) === 4 && collect($a['rows'])->every(fn ($r) => ($r['row_type'] ?? null) !== 'account' || in_array($r['sub_class'] ?? null, $subClasses, true)),
            fn ($a) => count($a['adjustment_matches']) === 1 && count($a['existing_class_matches']) === 3,
            fn ($a) => count($a) === 3,
            fn ($a) => count($a) === 2 && collect($a)->every(fn ($m) => $m['sub_class'] === null || isset($taxonomyMap[$m['sub_class']])),
        ];

        $ok = true;
        foreach (array_values(array_keys($calls)) as $i => $name) {
            $this->responses = [];
            try {
                $answer = $calls[$name]();
                $error = $answer instanceof Throwable ? $answer : null;
            } catch (Throwable $e) {
                $answer = null;
                $error = $e;
            }
            $last = end($this->responses) ?: null;
            $accepted = $error === null && $answer !== [] && ($checks[$i])($answer);
            $ok = $ok && $accepted;
            $this->line(sprintf('%s %-40s HTTP %s  finish=%s  %s', $accepted ? '<info>✓</info>' : '<error>✗</error>', $name,
                $last?->status() ?? '-', $last?->json('candidates.0.finishReason') ?? $last?->json('stop_reason') ?? '-',
                $error ? $error->getMessage() : json_encode($answer, JSON_UNESCAPED_UNICODE)));
        }

        $totals = $meter->totals();
        $cost = LlmCost::usd($totals, Provider::model());
        $this->line("Tokens: input {$totals['input']}, cached {$totals['cached']}, output {$totals['output']}; cost ".($cost === null ? 'unknown (no price)' : '$'.number_format($cost, 4)));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** One rolling-pool request; the parsed answer or its final error. */
    private function rolling(AccountClassifierRepository $classifier, array $args): array|Throwable
    {
        $result = [];
        $classifier->classifyRowsRolling(['probe' => $args], 1, function ($key, $answer) use (&$result) {
            $result = $answer;
            return null;
        });

        return $result instanceof ProviderException ? throw $result : $result;
    }
}
