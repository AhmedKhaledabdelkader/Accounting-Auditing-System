<?php

namespace App\Repositories\Contracts;

/** Maps hand-written classes of an imported file to the taxonomy (CacheImport): one call per list of distinct values. */
interface TaxonomyMapperRepositoryInterface
{
    /**
     * الميزانية values → a main class, «route» (a group by function, e.g. G&A: the sub-class comes from الإيضاحات) or skip.
     *
     * @param  array<int, string>  $values  id => value
     * @param  list<string>  $mainClasses
     * @return array<int, array{decision: string, main_class: ?string, confidence: int, reason: string}> by id
     */
    public function mapBudgetItems(array $values, array $mainClasses): array;

    /**
     * (الميزانية, الإيضاحات, sample account names) → any sub-class of the taxonomy (by nature), preferring hint_main's;
     * items with function_subs also get the best of those (by function: an open accounting decision answered both ways).
     *
     * @param  array<int, array{budget: string, note: string, names: list<string>, hint_main: ?string, function_subs?: list<string>}>  $items  id => item
     * @param  array<string, array>  $taxonomy  sub_class => [statement, main_class, side]
     * @return array<int, array{sub_class: ?string, function_sub_class: ?string, confidence: int, reason: string}> by id
     */
    public function mapNotes(array $items, array $taxonomy): array;
}
