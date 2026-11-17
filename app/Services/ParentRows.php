<?php

namespace App\Services;

/**
 * Parent (group) rows a file shows as accounts, which would count their children twice. Two kinds of evidence, never a
 * client's codes or names:
 *
 * 1. Levels: when the file marks its hierarchy (a level column, the cell's indentation, leading spaces, or leading marks
 *    such as «. . .»), a row followed by a deeper row is a parent, whatever its code, when its net in every column group
 *    is the sum of its direct children's. The marker is found from the file: the first of those that gives at least two
 *    levels and marks at least half the account rows; it is trusted only when at least one parent with a balance adds up
 *    (trusted()), else the marks are decoration and every row has no known level. An account row the marker skips (no
 *    dots where the other accounts have some) has no known level: it is neither a parent nor the end of one.
 * 2. Amounts: where no level is known, a row whose net, in every real column group, equals the sum of the account rows
 *    right below it, and that explains why the file doesn't add up (counted twice, a parent unbalances the trial balance
 *    by its own net), is a probable parent. It is only proposed: the user decides.
 */
final class ParentRows
{
    /** Excluded from the totals and the statements: the file's levels say so. */
    public const LEVEL = 'parent_level';
    /** Proposed for exclusion: its balances are the sum of the rows below it. */
    public const AMOUNTS = 'probable_parent';

    /** Flags that leave a row out of the totals and the statements (`possible_parent`: its number and balance, ClassificationService). */
    public const EXCLUDED = ['possible_parent', self::LEVEL];

    public static function excluded(array $row): bool
    {
        return array_intersect(array_column($row['data_flags'] ?? [], 'type'), self::EXCLUDED) !== [];
    }

    /** Markers in order of preference: a level column says it outright; marks in the name are the weakest. */
    public const MARKERS = ['column', 'indent', 'spaces', 'marks'];

    private const MARK_CHARS = '.·•\-–_|>*';
    private const MIN_MARKED_SHARE = 0.5;     // a marker must mark at least half the account rows
    private const LOOKAHEAD = 300;

    /**
     * @param array<int, array{account: bool, name: string, raw: ?string, indent: ?int, level: ?int}> $rows row => what the
     *        file shows, in file order: whether the row is an account, its name as read, the raw name cell (spaces kept),
     *        the cell's indentation, the level column's value
     * @return array{marker: ?string, levels: array<int, ?int>} the marker used (null: none reliable), each row's level
     */
    public static function levels(array $rows): array
    {
        $accounts = array_filter($rows, fn ($r) => $r['account']);
        foreach (self::MARKERS as $marker) {
            $values = array_map(fn ($r) => self::value($marker, $r), $rows);
            $marked = array_filter(array_intersect_key($values, $accounts), fn ($v) => $v !== null && $v > 0);
            // Two levels among the rows it marks (no mark isn't a level, except in a level column).
            $distinct = array_unique(array_filter($values, fn ($v) => $v !== null && ($marker === 'column' || $v > 0)));
            if (! $accounts || count($distinct) < 2 || count($marked) / count($accounts) < self::MIN_MARKED_SHARE) {
                continue;
            }
            // Most accounts marked: an unmarked account is a gap in the marking, not the top of the tree. (A level
            // column's 0 is a level.)
            foreach ($values as $n => $v) {
                if ($v === 0 && $marker !== 'column' && $rows[$n]['account']) {
                    $values[$n] = null;
                }
            }

            return ['marker' => $marker, 'levels' => $values];
        }

        return ['marker' => null, 'levels' => array_fill_keys(array_keys($rows), null)];
    }

    /**
     * Account rows followed (past rows of no known level) by a deeper row, whose net in every group is the sum of their
     * direct children's (the rows under them not under another row under them).
     *
     * @param array<int, array{account: bool, amounts?: array<string, float>}> $rows amounts: group → net (debit − credit)
     * @param array<int, ?int> $levels
     * @return array<int, list<int>> parent row → the rows under it
     */
    public static function byLevel(array $rows, array $levels, float $tolerance = 0.01): array
    {
        $known = array_keys(array_filter($levels, fn ($v) => $v !== null));
        $out = [];
        foreach ($known as $i => $n) {
            if (! $rows[$n]['account']) {
                continue;
            }
            $children = [];
            $direct = [];
            for ($j = $i + 1; $j < count($known) && $levels[$known[$j]] > $levels[$n]; $j++) {
                $c = $known[$j];
                // Direct: no row before it under the parent is shallower.
                if (! array_filter($children, fn ($k) => $levels[$k] < $levels[$c])) {
                    $direct[] = $c;
                }
                $children[] = $c;
            }
            if ($children && self::addsUp($rows[$n], array_map(fn ($c) => $rows[$c], $direct), $tolerance)) {
                $out[$n] = $children;
            }
        }

        return $out;
    }

    /**
     * The levels when the file's marker shows a real tree: at least one parent with a balance adds up (byLevel). Otherwise
     * the marks are decoration: no row has a known level, and parents are only proposed by amounts.
     *
     * @param array<int, array{account: bool, amounts?: array<string, float>}> $rows
     * @param array<int, ?int> $levels
     * @return array<int, ?int>
     */
    public static function trusted(array $rows, array $levels, float $tolerance = 0.01): array
    {
        foreach (array_keys(self::byLevel($rows, $levels, $tolerance)) as $n) {
            if (array_filter($rows[$n]['amounts'] ?? [], fn ($v) => abs($v) > $tolerance)) {
                return $levels;
            }
        }

        return array_fill_keys(array_keys($levels), null);
    }

    /** Whether a row's net in every group is its children's total. */
    private static function addsUp(array $parent, array $children, float $tolerance): bool
    {
        foreach ($parent['amounts'] ?? [] as $g => $net) {
            if (abs(array_sum(array_map(fn ($c) => $c['amounts'][$g] ?? 0.0, $children)) - $net) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    /**
     * Account rows of no known level whose net in every given group equals the sum of the account rows right below them
     * (stopping at a row that isn't an account).
     *
     * @param array<int, array{account: bool, amounts: array<string, float>}> $rows amounts: group → net (debit − credit)
     * @param array<int, ?int> $levels
     * @param array<int, true> $skip rows already known to be parents
     * @return array<int, list<int>> probable parent row → the rows its balances add up
     */
    public static function byAmounts(array $rows, array $levels, array $groups, array $skip = [], float $tolerance = 0.01): array
    {
        $order = array_keys($rows);
        $out = [];
        foreach ($order as $i => $n) {
            $r = $rows[$n];
            if (! $r['account'] || isset($skip[$n]) || $levels[$n] !== null || ! array_filter($groups, fn ($g) => abs($r['amounts'][$g] ?? 0.0) > $tolerance)) {
                continue;
            }
            $sums = array_fill_keys($groups, 0.0);
            $children = [];
            for ($j = $i + 1; $j < count($order) && $j <= $i + self::LOOKAHEAD; $j++) {
                $c = $rows[$order[$j]];
                if (! $c['account'] || isset($skip[$order[$j]])) {
                    break;
                }
                $children[] = $order[$j];
                foreach ($groups as $g) {
                    $sums[$g] += $c['amounts'][$g] ?? 0.0;
                }
                if (array_filter($groups, fn ($g) => abs($sums[$g] - ($r['amounts'][$g] ?? 0.0)) > $tolerance) === []) {
                    $out[$n] = $children;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Which candidates explain the file not adding up: their nets, together or one alone, are the trial balance's
     * debit − credit in every group; or one's debit and credit are what the accounts exceed the file's total row by.
     *
     * @param array<int, array> $candidates row → the account row (its {group}_debit / {group}_credit)
     * @param array<string, array{0: float, 1: float}> $sums the counted accounts' totals, group → [debit, credit]
     * @param list<array> $totalRows the file's own total rows
     * @return list<int> rows
     */
    public static function explaining(array $candidates, array $sums, array $totalRows, array $groups, float $tolerance = 0.01): array
    {
        $equal = fn (float $a, float $b) => abs($a - $b) <= $tolerance;
        $net = fn (array $r, string $g) => (float) $r["{$g}_debit"] - (float) $r["{$g}_credit"];
        $imbalance = fn (string $g) => $sums[$g][0] - $sums[$g][1];
        if (array_filter($groups, fn ($g) => ! $equal($imbalance($g), 0.0)) !== []) {
            if (array_filter($groups, fn ($g) => ! $equal($imbalance($g), array_sum(array_map(fn ($r) => $net($r, $g), $candidates)))) === []) {
                return array_keys($candidates);
            }
            foreach ($candidates as $n => $r) {
                if (array_filter($groups, fn ($g) => ! $equal($imbalance($g), $net($r, $g))) === []) {
                    return [$n];
                }
            }
        }
        foreach ($totalRows as $total) {
            foreach ($candidates as $n => $r) {
                if (array_filter($groups, fn ($g) => ! $equal($sums[$g][0] - (float) $total["{$g}_debit"], (float) $r["{$g}_debit"])
                    || ! $equal($sums[$g][1] - (float) $total["{$g}_credit"], (float) $r["{$g}_credit"])) === []) {
                    return [$n];
                }
            }
        }

        return [];
    }

    /** A row's level under one marker; null when the marker says nothing about it. */
    private static function value(string $marker, array $r): ?int
    {
        return match ($marker) {
            'column' => $r['level'],
            'indent' => $r['indent'],
            'spaces' => $r['raw'] === null ? null : mb_strlen($r['raw']) - mb_strlen(preg_replace('/^[\s\x{00A0}]+/u', '', $r['raw'])),
            'marks'  => preg_match('/^[\s'.self::MARK_CHARS.']*/u', $r['name'], $m) ? preg_match_all('/['.self::MARK_CHARS.']/u', $m[0]) : 0,
        };
    }
}
