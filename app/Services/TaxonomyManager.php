<?php

namespace App\Services;

use App\Models\TaxonomyAlias;
use App\Models\TaxonomyMainClass;
use App\Models\TaxonomySubClass;
use App\Repositories\Eloquents\TaxonomyRepository;
use App\Support\Taxonomy\TaxonomyRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to the taxonomy from its page (docs/16-taxonomy.md), each in one transaction:
 * - a rename reaches everything that stores the name for reuse (the classification memory, the name layer, the
 *   accountant-import mappings and rows) and records the old name as an alias, so old runs still build statements; the
 *   config rules follow through TaxonomyRules;
 * - a delete is refused while anything uses the category (uses()), a main class while it has sub classes;
 * - a sub class added here goes to the statement line chosen with it.
 */
final class TaxonomyManager
{
    public function __construct(private TaxonomyRepository $taxonomy, private TaxonomyRules $rules)
    {
    }

    /** @param array{statement: string, name: string, family: string} $data */
    public function createMain(array $data): TaxonomyMainClass
    {
        return $this->change(fn () => TaxonomyMainClass::create($data + ['sort' => $this->nextSort(), 'origin_name' => null]));
    }

    /** @param array{name: string, family?: string} $data family: only for a main class added in the app */
    public function updateMain(TaxonomyMainClass $main, array $data): TaxonomyMainClass
    {
        return $this->change(function () use ($main, $data) {
            $main = TaxonomyMainClass::lockForUpdate()->findOrFail($main->id);
            $old  = $main->name;
            $main->name = $data['name'];
            if (! $main->builtIn() && isset($data['family'])) {
                $main->family = $data['family'];
            }
            $main->save();
            if ($old !== $main->name) {
                $this->alias('main', $old, $main->name, ['main_class_id' => $main->id]);
                DB::table('classification_cache')->where('main_class', $old)->update(['main_class' => $main->name]);
                DB::table('classification_import_mappings')->where('main_class', $old)->update(['main_class' => $main->name]);
                DB::table('classification_import_mappings')->where('parent', $old)->update(['parent' => $main->name]);
            }

            return $main;
        });
    }

    public function deleteMain(TaxonomyMainClass $main): void
    {
        $this->change(function () use ($main) {
            $main = TaxonomyMainClass::lockForUpdate()->findOrFail($main->id);
            $this->refuseIfUsed('main', $main->name, $this->uses($main));
            $main->delete();
        });
    }

    /** @param array{name: string, normal_side: string, line_key: string} $data */
    public function createSub(TaxonomyMainClass $main, array $data): TaxonomySubClass
    {
        return $this->change(fn () => TaxonomySubClass::create($data + ['main_class_id' => $main->id, 'sort' => $this->nextSort(), 'origin_name' => null]));
    }

    /** @param array{name: string, normal_side: string, line_key?: ?string} $data line_key: only for a sub class added in the app */
    public function updateSub(TaxonomySubClass $sub, array $data): TaxonomySubClass
    {
        return $this->change(function () use ($sub, $data) {
            $sub = TaxonomySubClass::lockForUpdate()->findOrFail($sub->id);
            $old = $sub->name;
            $sub->fill(['name' => $data['name'], 'normal_side' => $data['normal_side']]);
            if (! $sub->builtIn() && isset($data['line_key'])) {
                $sub->line_key = $data['line_key'];
            }
            $sub->save();
            if ($old !== $sub->name) {
                $this->alias('sub', $old, $sub->name, ['sub_class_id' => $sub->id]);
                $new = $sub->name;
                DB::table('classification_cache')->where('sub_class', $old)->update(['sub_class' => $new]);
                DB::table('classification_name_layer')->where('sub_class', $old)->update(['sub_class' => $new]);
                DB::table('classification_import_mappings')->where('sub_class', $old)->update(['sub_class' => $new]);
                DB::table('classification_import_mappings')->where('function_sub_class', $old)->update(['function_sub_class' => $new]);
                DB::table('classification_import_rows')->where('sub_class', $old)->update(['sub_class' => $new]);
                DB::table('classification_rules')->where('debit_class', $old)->update(['debit_class' => $new]);
                DB::table('classification_rules')->where('credit_class', $old)->update(['credit_class' => $new]);
                foreach (DB::table('classification_rules')->get(['id', 'ai_classes']) as $r) {
                    $classes = json_decode($r->ai_classes, true) ?: [];
                    if (in_array($old, $classes, true)) {
                        DB::table('classification_rules')->where('id', $r->id)->update(['ai_classes' => json_encode(
                            array_map(fn ($c) => $c === $old ? $new : $c, $classes), JSON_UNESCAPED_UNICODE)]);
                    }
                }
            }

            return $sub;
        });
    }

    public function deleteSub(TaxonomySubClass $sub): void
    {
        $this->change(function () use ($sub) {
            $sub = TaxonomySubClass::lockForUpdate()->findOrFail($sub->id);
            $this->refuseIfUsed('sub', $sub->name, $this->uses($sub));
            $sub->delete();
        });
    }

    /**
     * Where a category is used, so it can't be deleted: [use => count] (count null for a rule). Uses: sub_classes (a main
     * class's own), memory, name_layer, imports (accountant-import mappings and rows), rules (TaxonomyRules uses, prefixed
     * `rules.`), runs (classification runs whose result names it, by its current or a former name).
     *
     * @return array<string, ?int>
     */
    public function uses(TaxonomyMainClass|TaxonomySubClass $category): array
    {
        $kind  = $category instanceof TaxonomySubClass ? 'sub' : 'main';
        $name  = $category->name;
        $names = [$name, ...$category->aliases()->pluck('name')->all()];
        $uses  = [];
        if ($kind === 'main') {
            $uses['sub_classes'] = $category->subClasses()->count();
            $uses['memory']      = DB::table('classification_cache')->where('main_class', $name)->count();
            $uses['imports']     = DB::table('classification_import_mappings')->where(fn ($q) => $q->where('main_class', $name)->orWhere('parent', $name))->count();
        } else {
            $uses['memory']     = DB::table('classification_cache')->where('sub_class', $name)->count();
            $uses['name_layer'] = DB::table('classification_name_layer')->where('sub_class', $name)->count();
            $uses['imports']    = DB::table('classification_import_mappings')->where(fn ($q) => $q->where('sub_class', $name)->orWhere('function_sub_class', $name))->count()
                + DB::table('classification_import_rows')->where('sub_class', $name)->count();
            $uses['classification_rules'] = DB::table('classification_rules')->get(['debit_class', 'credit_class', 'ai_classes'])
                ->filter(fn ($r) => in_array($name, [$r->debit_class, $r->credit_class, ...(json_decode($r->ai_classes, true) ?: [])], true))->count();
        }
        foreach ($this->rules->usesOf($kind, $name, $category->origin_name) as $use) {
            $uses["rules.{$use}"] = null;
        }
        $uses['runs'] = $this->runsNaming($kind === 'sub' ? 'sub_class' : 'main_class', $names);

        return array_filter($uses, fn ($n) => $n === null || $n > 0);
    }

    /** Classification runs whose stored result has a row with this class under one of these names. */
    private function runsNaming(string $field, array $names): int
    {
        $patterns = [];
        foreach (array_unique($names) as $name) {
            // The result is stored as JSON by the model's cast (Arabic escaped); unescaped too, for any other writer.
            foreach ([json_encode($name), json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] as $value) {
                foreach (['"'.$field.'":'.$value, '"'.$field.'": '.$value] as $needle) {
                    $patterns[] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
                }
            }
        }

        return DB::table('classification_runs')->where(function ($q) use ($patterns) {
            foreach (array_unique($patterns) as $p) {
                $q->orWhereRaw("result like ? escape '!'", [$p]);
            }
        })->count();
    }

    /** Records the old name of a renamed category; its own former name used again is no longer an alias. */
    private function alias(string $kind, string $old, string $new, array $owner): void
    {
        TaxonomyAlias::where('kind', $kind)->where('name', $new)->where($owner)->delete();
        TaxonomyAlias::create(['kind' => $kind, 'name' => $old] + $owner);
    }

    private function refuseIfUsed(string $kind, string $name, array $uses): void
    {
        if ($uses === []) {
            return;
        }
        $where = array_map(fn ($use, $count) => __("taxonomy_page.uses.{$use}", ['count' => $count ?? 0]), array_keys($uses), $uses);
        throw ValidationException::withMessages(['delete' => __("taxonomy_page.in_use_{$kind}", ['name' => $name, 'uses' => implode(__('taxonomy_page.list_separator'), $where)])]);
    }

    private function nextSort(): int
    {
        return max((int) TaxonomyMainClass::max('sort'), (int) TaxonomySubClass::max('sort')) + 1;
    }

    /** Runs a change in a transaction, then has the app read the taxonomy again (repository, config rules). */
    private function change(callable $fn): mixed
    {
        try {
            $out = DB::transaction($fn);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('taxonomy_page.name_taken')]);   // the same name saved at the same moment
        }
        $this->rules->apply(reload: true);

        return $out;
    }
}
