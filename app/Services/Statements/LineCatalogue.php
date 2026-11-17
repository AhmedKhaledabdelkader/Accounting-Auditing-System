<?php

namespace App\Services\Statements;

use App\Support\ClassificationKey;
use App\Support\ImportNormalizer;
use InvalidArgumentException;

/** The statement lines and mapping rules of one template version (config/financial_statements.php). */
final class LineCatalogue
{
    public readonly string $version;
    private array $config;

    public function __construct(?string $version = null)
    {
        $version ??= config('financial_statements.template_version');
        $config = config("financial_statements.templates.{$version}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown template version {$version}");
        }
        $this->config  = $config;
        $this->version = $version;
    }

    /** @return array<string, array{label_ar: string, side: string}> */
    public function lines(): array
    {
        return $this->config['lines'];
    }

    /**
     * One statement's rows in template order: {type: heading, label_ar} | {type: line, key, label_ar, input} | {type: total, key, label_ar, sum}.
     * A line with `input: true` is entered by the user (OCI), not mapped from accounts; the cash flow's lines are computed.
     *
     * @param 'financial_position'|'comprehensive_income'|'cash_flows' $name
     */
    public function statement(string $name): array
    {
        return array_map(fn ($r) => match (true) {
            is_string($r)          => ['type' => 'line', 'key' => $r, 'label_ar' => $this->label($r), 'input' => false],
            isset($r['input'])     => ['type' => 'line', 'key' => $r['input'], 'label_ar' => $r['label_ar'], 'input' => true],
            isset($r['cf'])        => ['type' => 'line', 'key' => $r['cf'], 'label_ar' => $r['label_ar'], 'input' => false],
            isset($r['heading'])   => ['type' => 'heading', 'label_ar' => $r['heading']],
            default                => ['type' => 'total', 'key' => $r['total'], 'label_ar' => $r['label_ar'], 'sum' => $r['sum']],
        }, $this->config['statements'][$name] ?? throw new InvalidArgumentException("Unknown statement {$name}"));
    }

    public function has(string $line): bool
    {
        return isset($this->config['lines'][$line]);
    }

    public function side(string $line): string
    {
        return $this->config['lines'][$line]['side'] ?? throw new InvalidArgumentException("Unknown line {$line}");
    }

    public function label(string $line): string
    {
        return $this->config['lines'][$line]['label_ar'] ?? throw new InvalidArgumentException("Unknown line {$line}");
    }

    /** The rule for a classified account, normalised: {line?, function?, choose?, confirm?, reason?, partners_current?, source}. */
    public function rule(?string $subClass, ?string $mainClass): ?array
    {
        foreach (['sub_class' => [$subClass, 'sub_classes'], 'main_class' => [$mainClass, 'main_classes']] as $source => [$name, $table]) {
            $key  = $this->ruleName($name, array_keys($this->config[$table]));
            $rule = $key !== null ? $this->config[$table][$key] : null;
            if ($rule !== null) {
                return (is_string($rule) ? ['line' => $rule] : $rule) + ['source' => $source];
            }
        }
        return null;
    }

    /** The line a side rule moves this main class to when its balance sits on $side, or null. */
    public function sideRule(?string $mainClass, string $side): ?string
    {
        $name = $this->ruleName($mainClass, array_column($this->config['side_rules'], 'main_class'));
        foreach ($this->config['side_rules'] as $rule) {
            if ($rule['main_class'] === $name && $rule['side'] === $side) {
                return $rule['line'];
            }
        }
        return null;
    }

    /**
     * The name a rule knows a class by: its own, else the one rule name spelled the same but for spaces, punctuation,
     * diacritics and letter forms: a run classified where a class was renamed to fix its spelling («مستحقةو ارصدة» /
     * «مستحقة و ارصدة», «دائنه» / «دائنة»), read where it wasn't (another installation's run, the seeded taxonomy).
     * Former names are ReportSource's (the taxonomy's renames). Null when none, or several.
     *
     * @param list<string|int> $names the rules' names
     */
    private function ruleName(?string $name, array $names): ?string
    {
        if ($name === null || in_array($name, $names, true)) {
            return $name;
        }
        $spelled = fn (string $n) => preg_replace('/[\s\p{P}]+/u', '', ClassificationKey::name(mb_strtolower($n)));
        $same    = array_values(array_filter(array_map('strval', $names), fn ($n) => $spelled($n) === $spelled($name)));

        return count(array_unique($same)) === 1 ? $same[0] : null;
    }

    public function functionLine(string $function): string
    {
        return $this->config['functions'][$function] ?? throw new InvalidArgumentException("Unknown function {$function}");
    }

    /** The function a label from the file's own classification column names (Q34), or null. */
    public function functionOf(?string $label): ?string
    {
        $words = preg_split('/\s+/u', ImportNormalizer::text($label), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($this->config['function_labels'] as $function => $stems) {
            foreach ($stems as $stem) {
                $stem = ImportNormalizer::text($stem);
                foreach ($words as $word) {
                    if (str_starts_with($word, $stem)) {
                        return $function;
                    }
                }
            }
        }
        return null;
    }

    public function partnersCurrentLine(?string $choice): string
    {
        $cfg = $this->config['partners_current'];
        $choice ??= $cfg['default'];
        return $cfg[$choice] ?? throw new InvalidArgumentException("Unknown partners' current choice {$choice}");
    }

    /** Flags that make a row's mapping pending: "type" or "type:severity". */
    public function pendingFlags(): array
    {
        return $this->config['pending_flags'];
    }

    /** The statement of changes in equity: {columns: {key: {line, label_ar}}, rows: {key: label_ar}}. */
    public function changesInEquity(): array
    {
        return $this->config['changes_in_equity'];
    }

    /** Movement schedules (notes' roll-forwards), keyed by schedule. */
    public function schedules(): array
    {
        return $this->config['schedules'];
    }

    /** Account tags: tag → the line a tagged row moves to (null: the row keeps its line). */
    public function tags(): array
    {
        return $this->config['tags'];
    }

    /** The default category of an asset sub-class in a schedule, or null. */
    public function category(string $schedule, ?string $subClass): ?string
    {
        foreach ($this->config['schedules'][$schedule]['categories'] ?? [] as $category => $subClasses) {
            if (in_array($subClass, $subClasses, true)) {
                return $category;
            }
        }
        return null;
    }

    /** Sub-classes of gains and losses on disposal of assets. */
    public function disposalResults(): array
    {
        return $this->config['disposal_results'];
    }

    /** The accumulated amortisation of other assets (sub classes): its increase is the year's amortisation. */
    public function otherAssetsAmortisation(): array
    {
        return $this->config['other_assets_amortisation'] ?? [];
    }

    /** The notes in template order (PR 11): key → {block?, lines?, show?, breakdown?, breakdown_lines?, group?}. */
    public function notes(): array
    {
        return $this->config['notes'];
    }

    /** Policy blocks shown by their lines: block → [line key | 'schedule:category']. */
    public function policies(): array
    {
        return $this->config['policies'];
    }

    /** The periodic-inventory build-up of the cost of revenue: row → sub-classes, and the inventory family. */
    public function periodicCost(): array
    {
        return $this->config['periodic_cost'];
    }

    /** Check 26e: {main_classes, sub_classes} where a related party's or partner's accounts may sit. */
    public function relatedPartyClasses(): array
    {
        return $this->config['related_parties'] + ['main_classes' => [], 'sub_classes' => []];
    }

    /** Sub-classes that show foreign-currency transactions. */
    public function foreignCurrency(): array
    {
        return $this->config['foreign_currency']['sub_classes'];
    }

    /** The date from which this template's edition applies (Y-m-d). */
    public function editionEffective(): string
    {
        return $this->config['edition_effective'];
    }

    /** Every rule table, for the coverage checks. */
    public function rules(): array
    {
        return ['sub_classes' => $this->config['sub_classes'], 'main_classes' => $this->config['main_classes'], 'side_rules' => $this->config['side_rules'],
            'functions' => $this->config['functions'], 'partners_current' => $this->config['partners_current']];
    }
}
