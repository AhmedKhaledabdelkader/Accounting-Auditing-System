<?php

namespace App\Repositories\Eloquents;

use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * The taxonomy (statement → main class → sub class → normal side), read from the taxonomy_* tables (edited on the
 * taxonomy page, docs/16-taxonomy.md) once per request or queued job (scoped binding; flush() after a change). Without
 * those tables (tests without a database) it is config('classification.taxonomy'), the list the tables were seeded from.
 */
class TaxonomyRepository implements TaxonomyRepositoryInterface
{
    /** The two statements, fixed: their families (codes, in order) and the prefix of their lines in the line catalogue. */
    public const STATEMENTS = [
        'قائمة المركز المالي' => ['families' => ['assets', 'liabilities', 'equity'], 'lines' => 'sofp.'],
        'قائمة الدخل'         => ['families' => ['revenue', 'cost', 'expenses'], 'lines' => 'is.'],
    ];

    /** Family code → the word familyOf() gives (data, used by the checks: ClassificationService::MAJOR_GROUPS). */
    public const FAMILIES = [
        'assets' => 'أصول', 'liabilities' => 'التزامات', 'equity' => 'حقوق الملكية',
        'revenue' => 'إيرادات', 'cost' => 'تكلفة', 'expenses' => 'مصروفات',
    ];

    /**
     * @var array{map: array, mains: array<string, array>, subs: array<string, array>, aliases: array{main: array, sub: array},
     *            origins: array{main: array, sub: array}, database: bool}|null
     */
    private ?array $data = null;

    /** Forget what was read: the next call reads the tables again (after a change on the taxonomy page). */
    public function flush(): void
    {
        $this->data = null;
    }

    /** sub_class => [financial_statement, main_class, normal_side] */
    public function map(): array
    {
        return $this->data()['map'];
    }

    /** The mapping table as flat entries. */
    public function entries(): array
    {
        return array_map(
            fn ($sub, $t) => ['sub_class' => $sub, 'main_class' => $t[1], 'statement' => $t[0], 'normal_side' => $t[2]],
            array_keys($this->map()), $this->map()
        );
    }

    public function subClasses(): array
    {
        return array_keys($this->map());
    }

    public function find(string $subClass): ?array
    {
        return $this->map()[$subClass] ?? null;
    }

    /** Main classes with at least one sub class (one without any can't be the answer for an account yet). */
    public function mainClasses(): array
    {
        return array_values(array_unique(array_column($this->map(), 1)));
    }

    public function normalSide(string $subClass): ?string
    {
        return $this->map()[$subClass][2] ?? null;
    }

    /** main_class (e.g. "الذمم المدينة التجارية") => its statement side (e.g. "أصول") */
    public function familyOf(string $mainClass): ?string
    {
        $code = $this->data()['mains'][$mainClass]['family'] ?? null;

        return $code !== null ? self::FAMILIES[$code] ?? null : null;
    }

    /** The current name of a sub class from its current or any former name; null when it is neither. */
    public function resolveSub(?string $name): ?string
    {
        return $this->resolve('sub', $name);
    }

    /** The current name of a main class from its current or any former name; null when it is neither. */
    public function resolveMain(?string $name): ?string
    {
        return $this->resolve('main', $name);
    }

    /** The statement line chosen for a sub class added in the app; null for the built-in ones (their rules decide). */
    public function lineKeyOf(string $subClass): ?string
    {
        return $this->data()['subs'][$subClass]['line_key'] ?? null;
    }

    /**
     * Built-in categories whose name changed: [main|sub => [config name => current name]]. The rules (config files, the
     * AI's hints) are written with the config names.
     */
    public function renamedOrigins(): array
    {
        return $this->data()['origins'];
    }

    /** Sub classes added in the app, with their line: [name => line_key]. */
    public function addedSubLines(): array
    {
        return array_filter(array_map(fn ($s) => $s['origin'] === null ? $s['line_key'] : null, $this->data()['subs']));
    }

    /** Whether the taxonomy comes from its tables (false: the config list, no database). */
    public function fromDatabase(): bool
    {
        return $this->data()['database'];
    }

    /** The family code of a config main class (seed and fallback): config taxonomy_families, else an expense. */
    public static function configFamily(string $statement, string $main): ?string
    {
        foreach (config('classification.taxonomy_families', []) as $code => $mains) {
            if (in_array($main, $mains, true)) {
                return $code;
            }
        }

        return isset(self::STATEMENTS[$statement]) && in_array('expenses', self::STATEMENTS[$statement]['families'], true) ? 'expenses' : null;
    }

    private function resolve(string $kind, ?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $current = $kind === 'sub' ? $this->data()['subs'] : $this->data()['mains'];

        return isset($current[$name]) ? $name : ($this->data()['aliases'][$kind][$name] ?? null);
    }

    private function data(): array
    {
        return $this->data ??= $this->readTables() ?? $this->readConfig();
    }

    private function readTables(): ?array
    {
        try {
            $mains = DB::table('taxonomy_main_classes')->orderBy('sort')->orderBy('id')->get();
            if ($mains->isEmpty()) {
                return null;
            }
            $subs    = DB::table('taxonomy_sub_classes')->orderBy('sort')->orderBy('id')->get()->groupBy('main_class_id');
            $aliases = DB::table('taxonomy_aliases')->get();
        } catch (QueryException|PDOException) {
            return null;   // the tables don't exist yet (before the migration, tests without a database)
        }

        $data      = ['map' => [], 'mains' => [], 'subs' => [], 'aliases' => ['main' => [], 'sub' => []], 'origins' => ['main' => [], 'sub' => []], 'database' => true];
        $mainNames = [];
        $subNames  = [];
        foreach (array_keys(self::STATEMENTS) as $statement) {
            foreach ($mains->where('statement', $statement) as $m) {
                $mainNames[$m->id] = $m->name;
                $data['mains'][$m->name] = ['id' => $m->id, 'statement' => $statement, 'family' => $m->family, 'origin' => $m->origin_name];
                foreach ($subs->get($m->id, collect()) as $s) {
                    $subNames[$s->id] = $s->name;
                    $data['map'][$s->name]  = [$statement, $m->name, $s->normal_side];
                    $data['subs'][$s->name] = ['id' => $s->id, 'main' => $m->name, 'line_key' => $s->line_key, 'origin' => $s->origin_name];
                    if ($s->origin_name !== null && $s->origin_name !== $s->name) {
                        $data['origins']['sub'][$s->origin_name] = $s->name;
                    }
                }
                if ($m->origin_name !== null && $m->origin_name !== $m->name) {
                    $data['origins']['main'][$m->origin_name] = $m->name;
                }
            }
        }
        foreach ($aliases as $a) {
            $current = $a->kind === 'sub' ? ($subNames[$a->sub_class_id] ?? null) : ($mainNames[$a->main_class_id] ?? null);
            if ($current !== null) {
                $data['aliases'][$a->kind][$a->name] = $current;
            }
        }

        return $data;
    }

    private function readConfig(): array
    {
        $data = ['map' => [], 'mains' => [], 'subs' => [], 'aliases' => ['main' => [], 'sub' => []], 'origins' => ['main' => [], 'sub' => []], 'database' => false];
        foreach (config('classification.taxonomy') as $statement => $mains) {
            foreach ($mains as $main => $subs) {
                $data['mains'][$main] = ['id' => null, 'statement' => $statement, 'family' => self::configFamily($statement, $main), 'origin' => $main];
                foreach ($subs as $sub => $side) {
                    $data['map'][$sub]  = [$statement, $main, $side];
                    $data['subs'][$sub] = ['id' => null, 'main' => $main, 'line_key' => null, 'origin' => $sub];
                }
            }
        }

        return $data;
    }
}
