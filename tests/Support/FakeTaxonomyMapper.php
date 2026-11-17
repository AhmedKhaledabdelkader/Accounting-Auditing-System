<?php

namespace Tests\Support;

use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;

/** Maps from fixed tables; records what it was asked. */
class FakeTaxonomyMapper implements TaxonomyMapperRepositoryInterface
{
    /** @var array<string, string> value => main class | route | skip */
    public array $budget = [];

    /** @var array<string, string|array> note => sub-class, or [by nature, by function] */
    public array $notes = [];

    public array $budgetCalls = [];

    public array $noteCalls = [];

    public function mapBudgetItems(array $values, array $mainClasses): array
    {
        $this->budgetCalls[] = array_values($values);

        return array_map(function ($v) {
            $t = $this->budget[$v] ?? 'skip';

            return in_array($t, ['route', 'skip'], true)
                ? ['decision' => $t, 'main_class' => null, 'confidence' => 95, 'reason' => 'اختبار']
                : ['decision' => 'map', 'main_class' => $t, 'confidence' => 95, 'reason' => 'اختبار'];
        }, $values);
    }

    public function mapNotes(array $items, array $taxonomy): array
    {
        $this->noteCalls[] = $items;

        return array_map(function ($i) use ($taxonomy) {
            [$sub, $function] = (array) ($this->notes[$i['note']] ?? null) + [null, null];

            return ['sub_class' => isset($taxonomy[$sub]) ? $sub : null,
                'function_sub_class' => in_array($function, $i['function_subs'] ?? [], true) ? $function : null, 'confidence' => 95, 'reason' => 'اختبار'];
        }, $items);
    }
}
