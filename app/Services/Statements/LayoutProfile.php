<?php

namespace App\Services\Statements;

use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\ParentRows;

/**
 * What a classified run's trial balance can give the statements (plan §3 layouts, §4.2 step 1).
 *
 * Only the column groups the file really has count (meta.groups). The classifier copies values into the groups a file
 * lacks, so a row's opening or movement are ignored when their group is absent: they would be a copy of another group.
 * Layout: C = closing only, OC = opening + closing, OMC = opening + movement (+ adjustments) + closing.
 */
final class LayoutProfile
{
    public const GROUPS = ['opening', 'period', 'adjustment', 'closing'];
    private const PL = 'قائمة الدخل';
    private const RETAINED_EARNINGS = 'sofp.retained_earnings';
    private const FAMILIES = ['أصول', 'التزامات', 'حقوق الملكية', 'إيرادات', 'مصروفات'];
    private const ZERO = 0.005;

    private function __construct(
        /** @var list<string> the real groups, in GROUPS order */
        public readonly array $groups,
        public readonly string $layout,
        /** closing is opening + movement (+ adjustments): the file has no closing columns */
        public readonly bool $closingDerived,
        /** real groups whose amounts are all zero (check 26d, info) */
        public readonly array $emptyGroups,
        /** families present; fewer than 5 = a partial trial balance (check 2) */
        public readonly array $families,
        public readonly int $unclassified,
        public readonly int $needsReview,
        /** P&L accounts are all zero: the trial balance looks post-closing (check 6, Q22) */
        public readonly bool $postClosing,
        /**
         * Post-closing, but the year's P&L is the accounts' net movement: their closing balances are zero while their
         * movements aren't, and the closing entry shows on retained earnings (or the current-year profit account): a
         * profit is exactly their credit movement, a loss their debit movement; or, when the entry isn't in the movement
         * columns, the closing gap (closing − opening − movements) on the same side (Q22, decided 2026-10-14). A P&L
         * row's closing is then its net movement.
         */
        public readonly bool $plFromMovement = false,
        /** That net profit (credit − debit), when $plFromMovement */
        public readonly ?float $movementProfit = null,
        /** Where the closing entry was found: `movement` (retained earnings' movement columns) or `closing_gap` */
        public readonly ?string $closingEntry = null,
    ) {
    }

    /**
     * @param array{data: list<array>, meta: array} $result a run's stored result
     * @param ?LineCatalogue $catalogue which rows are retained earnings (the current template's when not given)
     * @param list<int> $excluded rows the user left out; they and the parents (ParentRows::excluded) count for nothing
     */
    public static function fromResult(array $result, TaxonomyRepositoryInterface $taxonomy, ?LineCatalogue $catalogue = null, array $excluded = []): self
    {
        // A parent's balance is its accounts' (counted already): the P&L from movements would count it twice.
        $rows   = array_values(array_filter($result['data'], fn ($r) => ! ParentRows::excluded($r) && ! in_array($r['row'], $excluded, true)));
        $groups = array_values(array_intersect(self::GROUPS, $result['meta']['groups'] ?? []));
        $has    = fn (string $g) => in_array($g, $groups, true);

        $layout = match (true) {
            $has('opening') && $has('period')                   => 'OMC',
            $has('opening') && $has('closing')                  => 'OC',
            default                                             => 'C',
        };

        $empty = array_values(array_filter($groups, fn ($g) => array_filter($rows, fn ($r) => abs((float) $r["{$g}_debit"]) > self::ZERO || abs((float) $r["{$g}_credit"]) > self::ZERO) === []));

        $families = [];
        foreach ($rows as $r) {
            $family = $r['main_class'] !== null ? $taxonomy->familyOf($r['main_class']) : null;
            if ($family !== null) {
                $families[$family === 'تكلفة' ? 'مصروفات' : $family] = true;
            }
        }

        $profile = new self($groups, $layout, ! $has('closing') && ($has('opening') || $has('period')), $empty,
            array_values(array_intersect(self::FAMILIES, array_keys($families))),
            count(array_filter($rows, fn ($r) => $r['sub_class'] === null)),
            count(array_filter($rows, fn ($r) => $r['needs_review'] ?? false)),
            false);

        $pl = array_filter($rows, fn ($r) => $r['financial_statement'] === self::PL);
        if ($pl !== [] && array_filter($pl, fn ($r) => abs((float) $profile->closing($r)) > self::ZERO) === []) {
            [$profit, $found] = $profile->movementProfit($pl, $rows, $catalogue ?? new LineCatalogue);

            return new self(...[...get_object_vars($profile), 'postClosing' => $profit === null, 'plFromMovement' => $profit !== null,
                'movementProfit' => $profit, 'closingEntry' => $found]);
        }

        return $profile;
    }

    /**
     * The year's net profit from the P&L accounts' movements (credit − debit), when the closing entry shows on retained
     * earnings (all their accounts together, or one alone: a current-year profit account), exactly:
     * - in their movement columns: a profit is their credit movement, a loss their debit movement (distributions sit on
     *   the other side, so they don't get in the way);
     * - else in their closing balance: the closing gap, closing − (opening + movements), is a credit equal to the profit,
     *   or a debit equal to the loss (the entry was posted after the movement columns).
     *
     * @return array{0: ?float, 1: ?string} the profit and where the entry was found (`movement` | `closing_gap`); nulls
     *         otherwise: no movement columns, no movement, or no exact match
     */
    private function movementProfit(array $pl, array $rows, LineCatalogue $catalogue): array
    {
        $groups = array_values(array_filter(['period', 'adjustment'], fn ($g) => $this->has($g)));
        if ($groups === []) {
            return [null, null];   // the P&L can't come from movements the file doesn't have
        }
        $side = fn (array $r, string $s) => array_sum(array_map(fn ($g) => (float) $r["{$g}_{$s}"], $groups));
        $profit = round(-array_sum(array_map(fn ($r) => $side($r, 'debit') - $side($r, 'credit'), $pl)), 2);
        $retained = array_filter($rows, fn ($r) => ($catalogue->rule($r['sub_class'], $r['main_class'])['line'] ?? null) === self::RETAINED_EARNINGS);
        if (abs($profit) <= self::ZERO || $retained === []) {
            return [null, null];
        }
        // The entry's amount on the side it posts to: credit for a profit, debit for a loss (positive when it's there).
        $sign = $profit > 0 ? -1.0 : 1.0;   // a credit lowers debit − credit
        $target = abs($profit);
        $matches = function (array $amounts) use ($target): bool {
            return abs(array_sum($amounts) - $target) <= self::ZERO || array_filter($amounts, fn ($a) => abs($a - $target) <= self::ZERO) !== [];
        };

        if ($matches(array_map(fn ($r) => round($side($r, $profit > 0 ? 'credit' : 'debit'), 2), $retained))) {
            return [$profit, 'movement'];
        }
        if ($this->has('closing')) {
            $gap = fn (array $r) => round($sign * ((float) $this->net($r, 'closing') - ((float) ($this->net($r, 'opening') ?? 0.0)
                + array_sum(array_map(fn ($g) => (float) $this->net($r, $g), $groups)))), 2);
            if ($matches(array_map($gap, $retained))) {
                return [$profit, 'closing_gap'];
            }
        }

        return [null, null];
    }

    public function has(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    public function isPartial(): bool
    {
        return count($this->families) < count(self::FAMILIES);
    }

    /** Debit − credit of a real group; null when the file doesn't have it (never the classifier's copy). */
    public function net(array $row, string $group): ?float
    {
        return $this->has($group) ? round((float) $row["{$group}_debit"] - (float) $row["{$group}_credit"], 2) : null;
    }

    /** Closing balance, debit − credit: the closing columns, else opening + movement (+ adjustments); null when unknown. */
    public function closing(array $row): ?float
    {
        if ($this->plFromMovement && ($row['financial_statement'] ?? null) === self::PL) {
            return round(($this->net($row, 'period') ?? 0.0) + ($this->net($row, 'adjustment') ?? 0.0), 2);
        }
        if ($this->has('closing')) {
            return $this->net($row, 'closing');
        }
        if (! $this->has('period')) {
            return null;
        }
        return round(($this->net($row, 'opening') ?? 0.0) + $this->net($row, 'period') + ($this->net($row, 'adjustment') ?? 0.0), 2);
    }

    /** Opening balance, debit − credit; null without opening columns. */
    public function opening(array $row): ?float
    {
        return $this->net($row, 'opening');
    }

    /** Debit and credit of a real movement group (period or adjustment); null when the file doesn't have it. */
    public function movement(array $row, string $group = 'period'): ?array
    {
        return $this->has($group) ? ['debit' => round((float) $row["{$group}_debit"], 2), 'credit' => round((float) $row["{$group}_credit"], 2)] : null;
    }

    public function toArray(): array
    {
        return ['groups' => $this->groups, 'layout' => $this->layout, 'closing_derived' => $this->closingDerived, 'empty_groups' => $this->emptyGroups,
            'families' => $this->families, 'partial_tb' => $this->isPartial(), 'unclassified' => $this->unclassified,
            'needs_review' => $this->needsReview, 'post_closing' => $this->postClosing, 'pl_from_movements' => $this->plFromMovement];
    }
}
