<?php

namespace App\Support\Taxonomy;

use App\Repositories\Eloquents\AccountClassifierRepository;
use App\Repositories\Eloquents\TaxonomyMapperRepository;
use App\Repositories\Eloquents\TaxonomyRepository;

/**
 * Every rule that names a taxonomy category, and how a rename reaches it (docs/16-taxonomy.md).
 *
 * The config rules (config/financial_statements.php, config/classification.php) are written with the categories' config
 * names. apply() rewrites each place listed in PATHS from the config name to the current one (main or sub, as the place
 * says: a main class and a sub class may share a name), and puts every sub class added in the app on its chosen line
 * (`sub_classes` of each template that has the line). It runs when the app starts (pages, commands), before each queued
 * job, and after each change on the taxonomy page. The AI's hints name sub classes too: AccountClassifierRepository and
 * TaxonomyMapperRepository take the current names from the repository.
 */
final class TaxonomyRules
{
    /** [use, path (`*` = every key), what holds the names (keys | list | value), main | sub] */
    public const PATHS = [
        ['statement_rules', 'financial_statements.templates.*.main_classes', 'keys', 'main'],
        ['statement_rules', 'financial_statements.templates.*.sub_classes', 'keys', 'sub'],
        ['statement_rules', 'financial_statements.templates.*.side_rules.*.main_class', 'value', 'main'],
        ['schedules', 'financial_statements.templates.*.schedules.*.categories.*', 'list', 'sub'],
        ['schedules', 'financial_statements.templates.*.schedules.*.sub_classes', 'list', 'sub'],
        ['schedules', 'financial_statements.templates.*.schedules.*.charge.main_classes', 'list', 'main'],
        ['schedules', 'financial_statements.templates.*.schedules.*.charge.sub_classes', 'list', 'sub'],
        ['schedules', 'financial_statements.templates.*.schedules.*.reversal.sub_classes', 'list', 'sub'],
        ['schedules', 'financial_statements.templates.*.disposal_results', 'list', 'sub'],
        ['schedules', 'financial_statements.templates.*.other_assets_amortisation', 'list', 'sub'],
        ['periodic_cost', 'financial_statements.templates.*.periodic_cost.opening', 'list', 'sub'],
        ['periodic_cost', 'financial_statements.templates.*.periodic_cost.purchases', 'list', 'sub'],
        ['periodic_cost', 'financial_statements.templates.*.periodic_cost.purchase_costs', 'list', 'sub'],
        ['periodic_cost', 'financial_statements.templates.*.periodic_cost.closing', 'list', 'sub'],
        ['periodic_cost', 'financial_statements.templates.*.periodic_cost.cost_of_goods_sold', 'value', 'sub'],
        ['periodic_cost', 'financial_statements.templates.*.periodic_cost.inventory_family', 'value', 'main'],
        ['notes', 'financial_statements.templates.*.foreign_currency.sub_classes', 'list', 'sub'],
        ['notes', 'financial_statements.templates.*.related_parties.main_classes', 'list', 'main'],
        ['notes', 'financial_statements.templates.*.related_parties.sub_classes', 'list', 'sub'],
        ['side_pairs', 'classification.either_side_classes', 'list', 'sub'],
        ['side_pairs', 'classification.side_pairs.*.debit', 'list', 'sub'],
        ['side_pairs', 'classification.side_pairs.*.credit', 'list', 'sub'],
        ['side_pairs', 'classification.side_pairs.*.by_name.*', 'keys', 'sub'],
        ['import_rules', 'classification.import.budget_overrides.*.main', 'value', 'main'],
        ['import_rules', 'classification.import.budget_overrides.*.sub', 'value', 'sub'],
        ['import_rules', 'classification.import.side_rules.*.debit', 'value', 'sub'],
        ['import_rules', 'classification.import.side_rules.*.credit', 'value', 'sub'],
        ['import_rules', 'classification.import.policies.*.function_main', 'value', 'main'],
    ];

    /** The config values at every concrete place of PATHS before the first rewrite: [root => [place key => [segments, value]]]. */
    private ?array $original = null;

    /** What the config holds now: [renames, added lines]; empty = as in the files. */
    private array $applied = [['main' => [], 'sub' => []], []];

    public function __construct(private TaxonomyRepository $taxonomy)
    {
    }

    /** Brings the config rules in line with the taxonomy as it is now. A no-op while nothing was renamed or added. */
    public function apply(bool $reload = false): void
    {
        if ($reload) {
            $this->taxonomy->flush();
        }
        $wanted = [$this->taxonomy->renamedOrigins(), $this->taxonomy->addedSubLines()];
        if ($wanted === $this->applied) {
            return;
        }
        $this->original ??= $this->collect();

        foreach ($this->original as $root => $places) {
            $config = config($root);
            foreach ($places as [$segments, $value, $mode, $kind]) {
                $this->put($config, $segments, $this->rename($value, $mode, $wanted[0][$kind] ?? []));
            }
            if ($root === 'financial_statements') {
                foreach ($config['templates'] as $version => $template) {
                    foreach ($wanted[1] as $sub => $line) {
                        if (isset($template['lines'][$line])) {
                            $config['templates'][$version]['sub_classes'][$sub] = $line;
                        }
                    }
                }
            }
            config([$root => $config]);
        }
        $this->applied = $wanted;
    }

    /**
     * The rules that name a category now (for the delete check): the uses of PATHS (ids) whose place holds the name, plus
     * `ai_hints` for a sub class the AI's instructions name. A sub class added in the app is not used by its own line.
     *
     * @param 'main'|'sub' $kind
     * @return list<string>
     */
    public function usesOf(string $kind, string $name, ?string $originName): array
    {
        $this->apply();
        $added = $kind === 'sub' && $originName === null;
        $uses  = [];
        foreach (self::PATHS as [$use, $path, $mode, $pathKind]) {
            if ($pathKind !== $kind || ($added && $path === 'financial_statements.templates.*.sub_classes')) {
                continue;
            }
            [$root, $rest] = explode('.', $path, 2);
            foreach ($this->places(config($root), explode('.', $rest)) as [, $value]) {
                if (in_array($name, $this->names($value, $mode), true)) {
                    $uses[] = $use;
                    break;
                }
            }
        }
        if ($kind === 'sub' && $originName !== null
            && (str_contains(AccountClassifierRepository::CLASSIFY_RULES, '"'.$originName.'"') || in_array($originName, TaxonomyMapperRepository::EXAMPLE_SUBS, true))) {
            $uses[] = 'ai_hints';
        }

        return array_values(array_unique($uses));
    }

    /** Every concrete place of PATHS with its value as in the config files. */
    private function collect(): array
    {
        $out = [];
        foreach (self::PATHS as [, $path, $mode, $kind]) {
            [$root, $rest] = explode('.', $path, 2);
            foreach ($this->places(config($root) ?? [], explode('.', $rest)) as [$segments, $value]) {
                $out[$root][implode("\0", $segments)] = [$segments, $value, $mode, $kind];
            }
        }

        return $out;
    }

    /** @return list<array{0: list<string|int>, 1: mixed}> [segments, value] of every match of the pattern */
    private function places(mixed $node, array $pattern, array $at = []): array
    {
        if ($pattern === []) {
            return [[$at, $node]];
        }
        if (! is_array($node)) {
            return [];
        }
        $segment = array_shift($pattern);
        $keys    = $segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []);
        $out     = [];
        foreach ($keys as $key) {
            array_push($out, ...$this->places($node[$key], $pattern, [...$at, $key]));
        }

        return $out;
    }

    private function names(mixed $value, string $mode): array
    {
        return match ($mode) {
            'keys'  => is_array($value) ? array_map('strval', array_keys($value)) : [],
            'list'  => is_array($value) ? array_values(array_filter($value, 'is_string')) : [],
            default => is_string($value) ? [$value] : [],
        };
    }

    private function rename(mixed $value, string $mode, array $map): mixed
    {
        if ($map === []) {
            return $value;
        }

        return match ($mode) {
            'keys'  => is_array($value) ? array_combine(array_map(fn ($k) => is_string($k) ? $map[$k] ?? $k : $k, array_keys($value)), $value) : $value,
            'list'  => is_array($value) ? array_map(fn ($v) => is_string($v) ? $map[$v] ?? $v : $v, $value) : $value,
            default => is_string($value) ? $map[$value] ?? $value : $value,
        };
    }

    private function put(array &$config, array $segments, mixed $value): void
    {
        $node = &$config;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }
        $node = $value;
    }
}
