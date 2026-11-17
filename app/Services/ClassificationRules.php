<?php

namespace App\Services;

use App\Models\ClassificationRule;
use App\Models\CompanyCodeGroup;
use App\Support\ClassificationKey;
use Illuminate\Support\Facades\Schema;

/**
 * The accountant's classification rules (docs/17-classification-rules.md), on balance-sheet accounts only (the AI or the
 * cache says which statement an account is on): the first enabled rule, in `sort` order, whose words are in the account's
 * name (whole words, ClassificationCache::nameHas), or in the heading above it (the row's `heading`), or whose code group the company marked it
 * with, gives the account its kind; its class follows the side of its balance (ClassificationCache::rowSide). A rule's
 * exclude words rule it out whatever matched. Applied to every run (ClassificationService::applyRules); `preview` reports what
 * they would do to a stored run.
 */
final class ClassificationRules
{
    public const BALANCE_SHEET = 'قائمة المركز المالي';
    /** Code-group proposals: accounts sharing a number less its last two digits, at least this many … */
    private const GROUP_MIN = 3;
    /** … with at least this share of them in one rule's AI classes. */
    private const GROUP_SHARE = 0.6;

    /**
     * @param list<ClassificationRule> $rules enabled, in order
     * @param array<string, ClassificationRule> $marks the company's code groups: prefix → rule
     */
    public function __construct(private array $rules, private array $marks = [])
    {
    }

    /** The enabled rules and the company's code groups; none when the tables don't exist yet (tests without a database). */
    public static function load(?string $companyId = null): self
    {
        if (! config('classification.rules_enabled', true) || ! Schema::hasTable('classification_rules')) {
            return new self([]);
        }
        $rules = ClassificationRule::where('enabled', true)->orderBy('sort')->orderBy('id')->get()->all();
        $marks = $companyId === null ? [] : CompanyCodeGroup::with('rule')->where('company_id', $companyId)->get()
            ->filter(fn ($g) => $g->rule?->enabled)->mapWithKeys(fn ($g) => [$g->prefix => $g->rule])->all();

        return new self($rules, $marks);
    }

    /**
     * The rule an account matches and how: `name`, `heading` or `mark` (the company's code group). Null: no rule, or not a
     * balance-sheet account.
     *
     * @return array{rule: ClassificationRule, by: string}|null
     */
    public function match(array $row, ?string $heading = null): ?array
    {
        $heading ??= $row['heading'] ?? null;   // stored with the run's rows
        if (($row['financial_statement'] ?? null) !== self::BALANCE_SHEET) {
            return null;
        }
        $name = (string) ($row['account_name'] ?? '');
        $mark = $this->markOf((string) ($row['account_number'] ?? ''));
        // The account's own words first, in rule order (a name says more than a code group: «جاري التأمينات» numbered among
        // the suppliers is still the insurance), then the code group.
        foreach ($this->rules as $rule) {
            if ($rule->exclude_words && ClassificationCache::nameHas($name, $rule->exclude_words)) {
                continue;
            }
            $by = match (true) {
                $rule->name_words && ClassificationCache::nameHas($name, $rule->name_words)
                    && (! $rule->also_words || ClassificationCache::nameHas($name, $rule->also_words)) => 'name',
                $heading !== null && $rule->heading_words && ClassificationCache::nameHas($heading, $rule->heading_words) => 'heading',
                default => null,
            };
            if ($by !== null) {
                return ['rule' => $rule, 'by' => $by];
            }
        }
        if ($mark !== null && ! ($mark->exclude_words && ClassificationCache::nameHas($name, $mark->exclude_words))) {
            return ['rule' => $mark, 'by' => 'mark'];
        }

        return null;
    }

    /**
     * What a rule makes of an account: its class for the side of its balance (null: the rule's class for that side isn't
     * decided, or the account is zero), and whether that side needs a review.
     *
     * @return array{side: ?string, class: ?string, review: bool}
     */
    public static function outcome(ClassificationRule $rule, array $row): array
    {
        $side = ClassificationCache::rowSide($row);

        return [
            'side'   => $side,
            'class'  => match ($side) { 'debit' => $rule->debit_class, 'credit' => $rule->credit_class, default => null },
            'review' => $side !== null && in_array($rule->review_side, [$side, 'both'], true),
        ];
    }

    /**
     * What the rules would do to a classified run's accounts, without changing them.
     *
     * @param list<array> $rows a run's result rows
     * @return list<array{row: int, account_number: string, account_name: string, side: ?string, current: ?string, rule: string, by: string, class: ?string, review: bool}>
     */
    public function preview(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($m = $this->match($row)) {
                $out[] = ['row' => $row['row'], 'account_number' => (string) $row['account_number'], 'account_name' => (string) $row['account_name'],
                    'current' => $row['sub_class'] ?? null, 'rule' => $m['rule']->name, 'by' => $m['by']] + self::outcome($m['rule'], $row);
            }
        }

        return $out;
    }

    /**
     * Code groups worth marking: at least GROUP_MIN balance-sheet accounts no rule catches whose numbers, less their last two
     * digits, are the same, not marked yet, with at least GROUP_SHARE of them in one rule's AI classes.
     *
     * @return list<array{prefix: string, rule: ClassificationRule, rows: list<int>, share: float}>
     */
    public function proposals(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $number = ClassificationKey::code((string) ($row['account_number'] ?? ''));
            if (preg_match('/^\d{6,}$/', $number) && ($row['financial_statement'] ?? null) === self::BALANCE_SHEET && $this->match($row) === null) {
                $groups[substr($number, 0, -2)][] = $row;
            }
        }
        $out = [];
        foreach ($groups as $prefix => $members) {
            if (count($members) < self::GROUP_MIN || $this->markOf((string) $prefix) !== null) {
                continue;
            }
            foreach ($this->rules as $rule) {
                $share = count(array_filter($members, fn ($r) => in_array($r['sub_class'] ?? null, $rule->ai_classes ?? [], true))) / count($members);
                if ($rule->ai_classes && $share >= self::GROUP_SHARE) {
                    $out[] = ['prefix' => (string) $prefix, 'rule' => $rule, 'rows' => array_column($members, 'row'), 'share' => round($share, 2)];
                    break;
                }
            }
        }

        return $out;
    }

    /** The company's code group an account number is in (the longest marked prefix), or null. */
    private function markOf(string $number): ?ClassificationRule
    {
        $number = ClassificationKey::code($number);
        $best = null;
        foreach ($this->marks as $prefix => $rule) {
            if ($number !== '' && str_starts_with($number, (string) $prefix) && ($best === null || strlen((string) $prefix) > strlen($best))) {
                $best = (string) $prefix;
            }
        }

        return $best === null ? null : $this->marks[$best];
    }
}
