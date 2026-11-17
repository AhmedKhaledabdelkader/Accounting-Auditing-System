<?php

namespace App\Services\Statements\PriorPdf;

/**
 * A statement table whose amounts the AI paired with the wrong labels (a scan printing amounts a little above or below
 * their lines; brief §8.3), placed again by its arithmetic. The labels as read (in order) and each amount column read
 * alone as a list (in order, every «-» included) are two ordered lists; an alignment puts the list's values, in order, on
 * rows of the table. The rows the per-row reading gave an amount in a column are taken first: a column with fewer values
 * leaves some of them empty, one with more puts the extra values on rows read without one (a heading). Every alignment
 * the statement's checks hold for is kept; alignments giving the same amounts (an empty cell and «-» are both nothing)
 * are one. Exactly one: it is used, flagged «aligned by arithmetic»; several or none: nothing changes, flagged.
 */
final class Alignment
{
    /** More alignments than this to try: not settled. */
    public const LIMIT = 20000;

    /**
     * @param list<array> $rows the table's rows as read (cells read: PageExtractor::blocks)
     * @param list<list<array>> $columns each amount column's values, in order (cells read: PageExtractor::cell)
     * @param callable(list<array>): bool $holds whether the statement's checks hold with the table's rows so
     * @return array{result: string, count: int, rows?: list<array>} result: `one` (rows: the table aligned), `several`
     *         (count: how many fit, or more than LIMIT to try) or `none`
     */
    public static function align(array $rows, array $columns, callable $holds): array
    {
        $width = count($rows[0]['cells'] ?? []);
        if ($rows === [] || count($columns) !== $width) {
            return ['result' => 'none', 'count' => 0];
        }
        $options = [];
        $tries = 1;
        foreach ($columns as $c => $values) {
            $options[$c] = self::placements($rows, $c, count($values));
            if ($options[$c] === null) {
                return ['result' => 'several', 'count' => self::LIMIT + 1];
            }
            $tries *= count($options[$c]);
            if ($options[$c] === [] || $tries > self::LIMIT) {
                return ['result' => $options[$c] === [] ? 'none' : 'several', 'count' => $options[$c] === [] ? 0 : $tries];
            }
        }

        $fit = [];
        $seen = [];
        foreach (self::product($options) as $placement) {
            $candidate = $rows;
            foreach ($candidate as $r => $row) {
                foreach ($columns as $c => $values) {
                    $candidate[$r]['cells'][$c] = ['raw' => null, 'amount' => null, 'status' => 'empty'];
                }
            }
            foreach ($placement as $c => $at) {
                foreach ($at as $j => $r) {
                    $candidate[$r]['cells'][$c] = $columns[$c][$j];
                }
            }
            $key = json_encode(array_map(fn ($row) => array_map(fn ($cell) => $cell['status'] === 'unread' ? $cell['raw'] : round($cell['amount'] ?? 0.0, 2), $row['cells']), $candidate));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($holds($candidate)) {
                $fit[] = $candidate;
            }
        }

        return count($fit) === 1 ? ['result' => 'one', 'count' => 1, 'rows' => $fit[0]] : ['result' => count($fit) > 1 ? 'several' : 'none', 'count' => count($fit)];
    }

    /**
     * The rows a column's $k values may go on, in order: the rows read with an amount in it, fewer of them or more.
     *
     * @return ?list<list<int>> null: more than LIMIT
     */
    private static function placements(array $rows, int $c, int $k): ?array
    {
        $with = array_keys(array_filter($rows, fn ($r) => ($r['cells'][$c]['status'] ?? 'empty') !== 'empty'));
        $without = array_values(array_diff(array_keys($rows), $with));
        if ($k === count($with)) {
            return [$with];
        }
        if ($k < count($with)) {
            return self::ways(count($with), $k) > self::LIMIT ? null : self::choose($with, $k);
        }
        if ($k - count($with) > count($without)) {
            return [];
        }
        if (self::ways(count($without), $k - count($with)) > self::LIMIT) {
            return null;
        }

        return array_map(function ($extra) use ($with) {
            $at = [...$with, ...$extra];
            sort($at);

            return $at;
        }, self::choose($without, $k - count($with)));
    }

    /** How many ways to pick $k of $n. */
    private static function ways(int $n, int $k): float
    {
        $ways = 1.0;
        for ($i = 1; $i <= min($k, $n - $k); $i++) {
            $ways = $ways * ($n - $i + 1) / $i;
        }

        return $ways;
    }

    /** @return list<list<int>> the ways to pick $k of $items, in order */
    private static function choose(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        for ($i = 0; $i <= count($items) - $k; $i++) {
            foreach (self::choose(array_slice($items, $i + 1), $k - 1) as $rest) {
                $out[] = [$items[$i], ...$rest];
            }
        }

        return $out;
    }

    /** Every combination of one option per column. */
    private static function product(array $options): \Generator
    {
        $columns = array_keys($options);
        $pick = function (int $i, array $chosen) use (&$pick, $columns, $options) {
            if ($i === count($columns)) {
                yield $chosen;

                return;
            }
            foreach ($options[$columns[$i]] as $option) {
                yield from $pick($i + 1, $chosen + [$columns[$i] => $option]);
            }
        };

        yield from $pick(0, []);
    }
}
