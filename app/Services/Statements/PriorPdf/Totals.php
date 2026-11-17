<?php

namespace App\Services\Statements\PriorPdf;

/**
 * A printed total that differs from its columns' sum (Tables::totalColumn): which of the two is right is what the
 * statements say. The row's figure is set against the statement line its note explains, at the row's date: a net book
 * value row as it is; a balance of the cost or of the accumulated depreciation as the cost less the accumulated
 * depreciation at that date. The one that ties is used (`sum_ties`: the printed total is a misprint; `printed_ties`:
 * a column's figure is off); neither or both, or no line to tie to: both figures are shown and the accountant chooses
 * (`choose`), the sum used till then. The accountant may choose otherwise in every case.
 *
 * A movement table's balances at one year end are chosen together (a group): the cost, the accumulated depreciation and
 * the net book value printed there must agree (cost − accumulated depreciation = net book value), so only the
 * combinations of their printed totals and columns' sums that agree are offered; the one tying to the statement line is
 * used (`ties`), the only one when one alone agrees (`only`), else the accountant chooses (`choose`); no line to tie
 * to: the first that agrees, the accountant may choose another (`unlinked`); none agreeing is said plainly (`none`: each
 * figure then as above).
 */
final class Totals
{
    /** Below this, two figures are the same (whole riyals). */
    private const SAME = 0.5;

    /**
     * @return array{totals: list<array{id: string, table: int, row: int, page: int, title: string, printed: float, sum: float, statement: ?float, ties: string, group: ?string}>,
     *         groups: list<array{id: string, table: int, page: int, year: int, statement: ?float, status: string, options: list<array>, default: ?int, rows: list<int>}>}
     *         ties: sum_ties, printed_ties or choose
     */
    public static function resolve(array $doc): array
    {
        $out = [];
        $groups = [];
        foreach ($doc['tables'] as $t) {
            if (($t['total_differences'] ?? []) === []) {
                continue;
            }
            $line = self::line($doc, $t['id']);
            $parts = self::parts($t['rows']);
            foreach ($t['total_differences'] as $d) {
                $row = $t['rows'][$d['row']];
                $end = self::yearEnd($row['label']);
                $statement = $line === null || $end === null ? null : ($line[$end] ?? null);
                $nbv = fn (float $total) => match ($parts[$d['row']]) {
                    'nbv' => $total,
                    'cost' => ($other = self::balanceAt($t, $parts, 'accumulated', $end)) === null ? null : $total - $other,
                    'accumulated' => ($other = self::balanceAt($t, $parts, 'cost', $end)) === null ? null : $other - $total,
                };
                $ties = fn (float $total) => $statement !== null && ! self::isMovement($row['label'], $parts[$d['row']])
                    && ($v = $nbv($total)) !== null && abs(abs($v) - abs($statement)) < self::SAME;
                [$sum, $printed] = [$ties($d['sum']), $ties($d['printed'])];
                $out[] = ['id' => substr(sha1("{$t['page']}|{$t['id']}|{$d['row']}"), 0, 12), 'table' => $t['id'], 'row' => $d['row'], 'page' => $t['page'],
                    'title' => $row['label'], 'printed' => $d['printed'], 'sum' => $d['sum'], 'statement' => $statement,
                    'ties' => $sum && ! $printed ? 'sum_ties' : ($printed && ! $sum ? 'printed_ties' : 'choose'), 'group' => null];
            }
            foreach (self::groups($t, $parts, $line ?? []) as $g) {
                $groups[] = $g;
                foreach ($out as $k => $r) {
                    if ($r['table'] === $t['id'] && in_array($r['row'], $g['rows'], true)) {
                        $out[$k]['group'] = $g['id'];
                    }
                }
            }
        }

        return ['totals' => $out, 'groups' => $groups];
    }

    /**
     * The year ends of a movement table where a printed total differs from its columns' sum: the cost, accumulated
     * depreciation and net book value balances there, each its printed total or its columns' sum, in the combinations
     * that agree (cost − accumulated depreciation = net book value), the ones tying to the statement line first.
     */
    private static function groups(array $t, array $parts, array $line): array
    {
        $differ = array_column($t['total_differences'], null, 'row');
        $byEnd = [];
        foreach ($t['rows'] as $i => $r) {
            if (! Tables::isHeading($r) && ! self::isMovement($r['label'], $parts[$i]) && ($end = self::yearEnd($r['label'])) !== null) {
                $byEnd[$end][$parts[$i]][] = $i;
            }
        }
        $out = [];
        foreach ($byEnd as $end => $byPart) {
            $rows = array_merge(...array_values($byPart));
            if (! array_intersect($rows, array_keys($differ)) || ! (isset($byPart['nbv']) || isset($byPart['cost'], $byPart['accumulated']))) {
                continue;
            }
            // Each part's figures: its rows' printed totals, and their columns' sums where they differ.
            $figures = [];
            foreach ($byPart as $part => $is) {
                foreach ($is as $i) {
                    foreach ([$t['rows'][$i]['cells'][$t['total_column']]['amount'] ?? null, $differ[$i]['sum'] ?? null] as $v) {
                        $v === null || $figures[$part][(string) round($v)] = (float) $v;
                    }
                }
                $figures[$part] = array_values($figures[$part] ?? []);
            }
            $statement = $line[$end] ?? null;
            $options = [];
            foreach ($figures['cost'] ?? [null] as $cost) {
                foreach ($figures['accumulated'] ?? [null] as $acc) {
                    foreach ($figures['nbv'] ?? [null] as $nbv) {
                        $net = $nbv ?? ($cost !== null && $acc !== null ? $cost - $acc : null);
                        if ($cost !== null && $acc !== null && $nbv !== null && abs($cost - $acc - $nbv) >= self::SAME) {
                            continue;   // the table wouldn't agree with itself
                        }
                        $picks = [];
                        foreach ($byPart as $part => $is) {
                            $chosen = ['cost' => $cost, 'accumulated' => $acc, 'nbv' => $nbv][$part];
                            foreach ($is as $i) {
                                $printed = $t['rows'][$i]['cells'][$t['total_column']]['amount'] ?? null;
                                $picks[$i] = $printed !== null && abs($printed - $chosen) < self::SAME ? 'printed' : 'sum';
                            }
                        }
                        $options[] = ['cost' => $cost, 'accumulated' => $acc, 'nbv' => $net, 'ties' => $statement !== null && $net !== null && abs(abs($net) - abs($statement)) < self::SAME,
                            'sums' => ! in_array('printed', array_intersect_key($picks, $differ), true), 'picks' => $picks];
                    }
                }
            }
            usort($options, fn ($a, $b) => [$b['ties'], $b['sums']] <=> [$a['ties'], $a['sums']]);
            $tying = count(array_filter($options, fn ($o) => $o['ties']));
            $status = $options === [] ? 'none' : ($statement === null ? 'unlinked' : ($tying === 1 ? 'ties' : (count($options) === 1 ? 'only' : 'choose')));
            $out[] = ['id' => substr(sha1("{$t['page']}|{$t['id']}|y{$end}"), 0, 12), 'table' => $t['id'], 'page' => $t['page'], 'year' => $end, 'statement' => $statement,
                'status' => $status, 'options' => $options, 'default' => $options === [] ? null : 0, 'rows' => $rows];
        }

        return $out;
    }

    /**
     * The figure used for each total: the accountant's choice, else the one that ties, else the sum.
     *
     * @param array<string, string> $choices total id → `printed` or `sum`
     * @return array<int, array<int, float>> table id → row → amount
     */
    public static function used(array $doc, array $choices = []): array
    {
        $out = [];
        $groups = array_column($doc['total_groups'] ?? [], null, 'id');
        foreach ($doc['totals'] ?? [] as $r) {
            $out[$r['table']][$r['row']] = self::choice($r, $choices, $groups) === 'printed' ? $r['printed'] : $r['sum'];
        }

        return $out;
    }

    /**
     * `printed` or `sum`: in a group, its option's (the accountant's, else its default); else the accountant's, else the
     * one that ties, else the sum.
     *
     * @param array<string, string> $choices a total's id → printed|sum; a group's id → its option's index
     * @param array<string, array> $groups by id
     */
    public static function choice(array $total, array $choices = [], array $groups = []): string
    {
        $g = $groups[$total['group'] ?? ''] ?? null;
        if ($g !== null && ($option = self::option($g, $choices)) !== null) {
            return $g['options'][$option]['picks'][$total['row']] ?? 'sum';
        }

        return $choices[$total['id']] ?? ($total['ties'] === 'printed_ties' ? 'printed' : 'sum');
    }

    /** A group's option used: the accountant's, else its default (null: none agrees). */
    public static function option(array $group, array $choices = []): ?int
    {
        $chosen = $choices[$group['id']] ?? null;

        return $chosen !== null && isset($group['options'][(int) $chosen]) ? (int) $chosen : $group['default'];
    }

    /** The statement line the table's note explains, by year end: [year => amount]. */
    private static function line(array $doc, int $table): ?array
    {
        $section = collect($doc['sections'])->first(fn ($s) => in_array($table, $s['tables'], true));

        return $section === null ? null : Sections::lineOf($doc, $section['id']);
    }

    /** Each row's part, by the headings above it: cost (the default), accumulated depreciation, net book value. */
    public static function parts(array $rows): array
    {
        $part = 'cost';
        $out = [];
        foreach ($rows as $i => $r) {
            if (Tables::isHeading($r)) {
                $part = preg_match('/إهلاك|اهلاك|استهلاك|الاطفاء|إطفاء|مجمع/u', $r['label']) ? 'accumulated' : (preg_match(Tables::NBV, $r['label']) ? 'nbv' : 'cost');
            }
            $out[$i] = $part;
        }

        return $out;
    }

    /** The year whose end a row's date is: «31 ديسمبر 2023» and «1 يناير 2024» both 2023. Null without a year. */
    private static function yearEnd(string $label): ?int
    {
        $years = Tables::years($label);
        if ($years === []) {
            return null;
        }

        return preg_match('/(^|\s)1\s*(يناير|\/\s*1)|بداية|أول|اول/u', $label) ? $years[0] - 1 : $years[0];
    }

    /** A movement between balances (additions, the year's charge): it has no date to tie. */
    private static function isMovement(string $label, string $part): bool
    {
        return $part !== 'nbv' && ! Tables::isBalance($label);
    }

    /** The total printed for a part's balance at a year end. */
    private static function balanceAt(array $t, array $parts, string $part, ?int $end): ?float
    {
        foreach ($t['rows'] as $i => $r) {
            if ($parts[$i] === $part && ! self::isMovement($r['label'], $part) && self::yearEnd($r['label']) === $end && ! Tables::isHeading($r)) {
                return $r['cells'][$t['total_column']]['amount'] ?? null;
            }
        }

        return null;
    }
}
