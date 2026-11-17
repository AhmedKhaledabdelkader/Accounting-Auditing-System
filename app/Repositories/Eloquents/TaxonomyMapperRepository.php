<?php

namespace App\Repositories\Eloquents;

use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolCall;
use App\Support\Llm\UsageMeter;

class TaxonomyMapperRepository implements TaxonomyMapperRepositoryInterface
{
    public const ROUTE = 'route';

    /** Sub classes the instructions give as examples (config names; the prompt uses the current ones). */
    public const EXAMPLE_SUBS = ['advances' => 'دفعات مقدمة للموردين', 'rent' => 'إيجارات', 'fuel' => 'وقود ومحروقات'];

    public function __construct(private ClassifierDriver $driver, private UsageMeter $meter, private TaxonomyRepository $taxonomy) {}

    public function mapBudgetItems(array $values, array $mainClasses): array
    {
        $system = 'You map hand-written Arabic balance-sheet / income-statement line items (الميزانية column of trial balances '
            .'classified by accountants) to a fixed list of main classes. Values have spelling mistakes and variants. '
            .'decision = map with main_class from the list when the value means that class. '
            .'decision = route when the value is a group by function that spans several of our classes and the account\'s '
            .'own note decides the class: general & administrative, selling & distribution, operating costs, cost of sales / revenue, '
            .'purchases, depreciation of several asset types, generic provisions, generic loans, finance costs. '
            .'decision = skip when the value fits no class or is ambiguous. confidence 0-100. reason: one short Arabic sentence.';
        $content = "Main classes:\n- ".implode("\n- ", $mainClasses)."\n\nValues (id: value):\n"
            .implode("\n", array_map(fn ($id, $v) => "{$id}: {$v}", array_keys($values), $values));

        $answer = $this->call([['text' => $system]], [
            'name'        => 'submit_budget_mappings',
            'description' => 'One mapping per value id',
            'schema'      => $this->listSchema([
                'decision'   => ['type' => 'string', 'enum' => ['map', self::ROUTE, 'skip']],
                'main_class' => ['type' => ['string', 'null'], 'description' => 'from the list; null unless decision = map'],
            ]),
        ], $content);

        $out = [];
        foreach ($answer as $a) {
            $id = $a['id'] ?? null;
            if (! isset($values[$id])) {
                continue;
            }
            $decision = in_array($a['decision'] ?? null, ['map', self::ROUTE, 'skip'], true) ? $a['decision'] : 'skip';
            $main     = $decision === 'map' && in_array($a['main_class'] ?? null, $mainClasses, true) ? $a['main_class'] : null;
            $out[$id] = [
                'decision'   => $decision === 'map' && $main === null ? 'skip' : $decision,
                'main_class' => $main,
                'confidence' => $this->confidence($a),
                'reason'     => trim((string) ($a['reason'] ?? '')),
            ];
        }

        return $out;
    }

    public function mapNotes(array $items, array $taxonomy): array
    {
        $tree = [];
        foreach ($taxonomy as $sub => [$statement, $main]) {
            $tree[$statement][$main][] = $sub;
        }
        $treeText = '';
        foreach ($tree as $statement => $mains) {
            $treeText .= "## {$statement}\n";
            foreach ($mains as $main => $subs) {
                $treeText .= "- {$main}: ".implode(' | ', $subs)."\n";
            }
        }

        $example = fn (string $key) => $this->taxonomy->renamedOrigins()['sub'][self::EXAMPLE_SUBS[$key]] ?? self::EXAMPLE_SUBS[$key];
        $system = 'You map accounts of trial balances classified by accountants to a sub-class of a fixed taxonomy (below: statement, main class: sub-classes). '
            .'Each item gives the accountant\'s line item (الميزانية), their note (الإيضاحات) and a few account names. '
            .'sub_class: the one sub-class the note and names mean, from the whole taxonomy: a name after the colon, never a main class name. When hint_main is given, that is the main class the '
            .'accountant\'s line item maps to: prefer its sub-classes, and pick one of another main class only when the account clearly belongs there '
            .'(e.g. advances to suppliers listed under receivables are '.$example('advances').'). null when nothing fits or it is ambiguous. '
            .'Items with function_subs are answered two ways: sub_class by the nature of the expense (rent → '.$example('rent').', fuel → '.$example('fuel').', '
            .'whatever line item it sits under), and function_sub_class = the best of function_subs (the same account seen as a cost of revenue: '
            .'goods vs services). Items without function_subs: function_sub_class null. '
            .'confidence 0-100 for sub_class. reason: one short Arabic sentence.'."\n\n# Taxonomy\n".$treeText;
        $content = '';
        foreach ($items as $id => $i) {
            $content .= "### {$id}\nالميزانية: {$i['budget']}\nالإيضاحات: {$i['note']}\nأسماء حسابات: ".implode('، ', $i['names'])
                .($i['hint_main'] ? "\nhint_main: {$i['hint_main']}" : '')
                .(! empty($i['function_subs']) ? "\nfunction_subs: ".implode(' | ', $i['function_subs']) : '')."\n";
        }

        $answer = $this->call([['text' => $system, 'cache' => true]], [
            'name'        => 'submit_note_mappings',
            'description' => 'One mapping per item id',
            // Enums: without them the model answers with the main class name (seen on the real file).
            'schema'      => $this->listSchema([
                'sub_class'          => ['type' => ['string', 'null'], 'enum' => [...array_keys($taxonomy), null]],
                'function_sub_class' => ['type' => ['string', 'null'], 'enum' => [...array_values(array_unique(array_merge(...array_map(fn ($i) => $i['function_subs'] ?? [], array_values($items)), ...[[]]))), null]],
            ]),
        ], $content);

        $out = [];
        foreach ($answer as $a) {
            $id = $a['id'] ?? null;
            if (! isset($items[$id])) {
                continue;
            }
            $sub      = isset($taxonomy[$a['sub_class'] ?? '']) ? $a['sub_class'] : null;
            $function = in_array($a['function_sub_class'] ?? null, $items[$id]['function_subs'] ?? [], true) ? $a['function_sub_class'] : null;
            $out[$id] = ['sub_class' => $sub, 'function_sub_class' => $function, 'confidence' => $sub === null ? 0 : $this->confidence($a),
                'reason' => trim((string) ($a['reason'] ?? ''))];
        }

        return $out;
    }

    private function listSchema(array $fields): array
    {
        return [
            'type'       => 'object',
            'properties' => ['mappings' => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer']] + $fields + [
                    'confidence' => ['type' => 'integer'],
                    'reason'     => ['type' => 'string'],
                ],
                'required' => array_merge(['id'], array_keys($fields), ['confidence', 'reason']),
            ]]],
            'required' => ['mappings'],
        ];
    }

    private function confidence(array $a): int
    {
        return max(0, min(100, (int) ($a['confidence'] ?? 0)));
    }

    /** @return list<array> the tool's mappings */
    private function call(array $system, array $tool, string $content): array
    {
        $answer = $this->driver->classify(new ToolCall($system, $tool, $content))->wait();
        if ($answer->usage !== null) {
            $this->meter->add($answer->usage, 'import');
        }

        $mappings = $answer->input['mappings'] ?? [];

        return is_array($mappings) ? array_values(array_filter($mappings, 'is_array')) : [];
    }
}
