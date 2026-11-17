<?php

namespace App\Services\Exports;

/**
 * Which rows of a line statement (financial position, comprehensive income, cash flows) show (Q13): one rule for the
 * Excel file, the PDF and the wizard (public/js/financial-report/statements-view.js mirrors it).
 *
 * - A line or a total is hidden when it is zero in both years (this year only, in a first year). A missing figure isn't
 *   zero: a line whose prior year is missing shows, with «[ناقص]». Accounts under a zero line don't keep it.
 * - A heading shows when a line under it shows; its lines run to the total that closes it (the innermost open heading),
 *   or to the end of the statement.
 */
final class ShownRows
{
    /** A figure that shows 0 riyals ($shown: the state's settled whole riyals, WholeRiyals); a missing one isn't. */
    public static function isZero(?array $f, ?int $shown = null): bool
    {
        return $f !== null && ($f['amount'] ?? null) !== null && ($shown ?? round($f['amount'])) == 0;
    }

    /** A line or total zero in both years. */
    public static function zero(array $row, bool $comparative): bool
    {
        return self::isZero($row['current'] ?? null, $row['shown']['current'] ?? null)
            && (! $comparative || self::isZero($row['comparative'] ?? null, $row['shown']['comparative'] ?? null));
    }

    /**
     * The changes in equity (note 3 of the accountant's review): a movement row zero in its own year's table is left out
     * of that table, even where the other year has the movement; the rows in $always (opening, result, total, closing)
     * stay. A column is left out when it is zero in every row of both tables (the total column stays).
     *
     * @param list<array> $columns  the statement's columns ({key})
     * @param array<string, list<array>> $tables  scope (current | comparative) → rows ({key, cells, shown})
     * @param list<string> $always
     * @return array{rows: array<string, list<string>>, columns: list<string>} the row keys and the column keys that show
     */
    public static function equity(array $columns, array $tables, array $always): array
    {
        $rows = [];
        $used = [];
        foreach ($tables as $scope => $table) {
            foreach ($table as $r) {
                $zero = true;
                foreach ($columns as $c) {
                    if (! self::isZero($r['cells'][$c['key']] ?? null, $r['shown'][$c['key']] ?? null)) {
                        $zero = false;
                        $used[$c['key']] = true;
                    }
                }
                if (in_array($r['key'], $always, true) || ! $zero) {
                    $rows[$scope][] = $r['key'];
                }
            }
        }

        return ['rows' => $rows, 'columns' => array_values(array_filter(array_column($columns, 'key'), fn ($k) => $k === 'total' || isset($used[$k])))];
    }

    /**
     * @param list<array> $rows a statement's rows from the state (type heading | line | total)
     * @return list<bool> each row: shown
     */
    public static function of(array $rows, bool $comparative): array
    {
        $shown = [];
        $open = [];
        $scopes = [];
        foreach (array_values($rows) as $i => $r) {
            if ($r['type'] === 'heading') {
                $open[] = $i;
                $scopes[$i] = [];
                $shown[$i] = false;
                continue;
            }
            $shown[$i] = ! self::zero($r, $comparative);
            if ($r['type'] === 'line') {
                foreach ($open as $h) {
                    $scopes[$h][] = $i;
                }
            } elseif ($r['type'] === 'total' && $open) {
                array_pop($open);
            }
        }
        foreach ($scopes as $h => $lines) {
            $shown[$h] = array_filter($lines, fn ($i) => $shown[$i]) !== [];
        }

        return $shown;
    }
}
