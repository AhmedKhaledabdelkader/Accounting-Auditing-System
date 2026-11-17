<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\AmountParser;

/**
 * What PHP makes of a table the AI copied (brief §8, R10, R16): which column is which year, and which rows are totals.
 *
 * - Years (R16): the statements' years are the truth. In a table whose headers print years, the latest year printed is
 *   the current year and the next one the comparative, whatever the years printed (a note headed 2023/2022 in 2024's
 *   statements is 2024/2023, flagged with its page). Without years, the column nearest the labels is the current year.
 * - Totals (R10): a total is a row equal to the sum of the rows above it since the last total or heading (two or more),
 *   or of the totals above it (a grand total), or of the one line above it when it closes the table; never found by its
 *   words. A «-» row is a total only when the rows it adds aren't all «-» and it closes its part (a heading or the end
 *   follows): otherwise it is a line of nothing, hidden at zero anyway. Totals are kept for checks only; headings (rows
 *   without amounts) are never items. A movement table (balances and the movements between them) and a table of rates
 *   (a header with «%») have no totals of this kind.
 */
final class Tables
{
    public const TOLERANCE = 1.0;   // a printed total may differ from its rows' sum by rounding

    /** The 4-digit Gregorian years in a text (Arabic-Indic digits too). */
    public static function years(string $text): array
    {
        $text = AmountParser::asciiDigits($text);
        preg_match_all('/(?<!\d)(19[5-9]\d|20\d\d|2100)(?!\d)/', $text, $m);

        return array_map('intval', $m[1]);
    }

    /**
     * Each column's year role: `current`, `comparative` or null (a category column, or a year not of these). Ranked by
     * the year printed (one per header), else by position (nearest the labels first) when no header prints a year.
     *
     * @return array{roles: list<?string>, printed: list<int>, by: string} printed: the years printed, latest first; by:
     *         `years`, `position` or `none` (no two year columns: a category table)
     */
    public static function roles(array $columns, bool $positional = false): array
    {
        // A column dated the first of January stands for the year before's end (a third column of a restated balance
        // sheet, «1 يناير 2022» beside «31 ديسمبر 2022»): ranked as that year, after the year end it equals.
        $years = array_map(fn ($c) => self::yearStart($c['header']) ? array_map(fn ($y) => $y - 1, self::years($c['header'])) : self::years($c['header']), $columns);
        $dated = array_filter($years, fn ($y) => count(array_unique($y)) === 1);
        if (count($dated) >= 1 && count(array_unique(array_map(fn ($y) => $y[0], $dated))) === count($dated)) {
            $printed = array_map(fn ($y) => $y[0], $dated);
            rsort($printed);
            $roles = array_map(fn ($y) => count(array_unique($y)) === 1 ? ([0 => 'current', 1 => 'comparative'][array_search($y[0], $printed, true)] ?? null) : null, $years);

            return ['roles' => $roles, 'printed' => $printed, 'by' => 'years'];
        }
        if ($positional && count($columns) >= 1 && array_filter($years) === []) {
            return ['roles' => array_map(fn ($i) => [0 => 'current', 1 => 'comparative'][$i] ?? null, array_keys($columns)), 'printed' => [], 'by' => 'position'];
        }

        return ['roles' => array_fill(0, count($columns), null), 'printed' => [], 'by' => 'none'];
    }

    /**
     * A row naming a balance: «الرصيد …», or a date it stands at («كما في 31 ديسمبر 2023», «في 2023/12/31»): the
     * balances of a movement table (the PPE note, a provision, the changes in equity) between which the movements run.
     */
    /** A movement table's part of net book values («صافي القيمة الدفترية», «القيمة الدفترية»): balances, no movement. */
    public const NBV = '/صافي|القيمة\s+الدفترية/u';

    public static function isBalance(string $label): bool
    {
        $l = AmountParser::asciiDigits($label);

        return preg_match('/(^|\s)(ال)?رصيد/u', $l) === 1
            || preg_match('/^\s*(كما\s+)?(في|فى)\s+(\d{1,2}\s+\S+(\s+(19|20)\d\d)?|\d{1,4}\s*[\/\-.]\s*\d{1,2}\s*[\/\-.]\s*\d{1,4})\s*$/u', $l) === 1
            || preg_match('/^\s*(كما\s+)?(في|فى)\s+\d{1,2}\s+\S+\s+(19|20)\d\d/u', $l) === 1;
    }

    /** Whether a header dates its column the first of January (1/1/2022, ١ يناير ٢٠٢٢م, 2022-01-01). */
    public static function yearStart(string $header): bool
    {
        $h = AmountParser::asciiDigits($header);

        return preg_match('/(?<!\d)0?1\s*[\/\-.]\s*0?1\s*[\/\-.]\s*(19|20)\d\d|(19|20)\d\d\s*[\/\-.]\s*0?1\s*[\/\-.]\s*0?1(?!\d)|(?<!\d)0?1\s+(يناير|كانون\s+الثاني|January)/u', $h) === 1;
    }

    /** A row with no amount in any column (a heading, or a label line); text cells are no amounts. */
    public static function isHeading(array $row): bool
    {
        return array_filter($row['cells'], fn ($c) => $c['status'] !== 'empty' && $c['status'] !== 'text') === [];
    }

    /**
     * A movement table: balances (rows naming a balance or the date it stands at: isBalance) with the year's movements between them: the PPE note,
     * a provision's movement, the changes in equity.
     */
    public static function isMovement(array $rows): bool
    {
        // Two balances with the year's movements between them (balances side by side, nothing between, are no roll:
        // a related party's balances at two dates).
        $seen = false;
        $moves = 0;
        foreach ($rows as $r) {
            if (self::isBalance($r['label'])) {
                if ($seen && $moves > 0) {
                    return true;
                }
                [$seen, $moves] = [true, 0];
            } elseif ($seen && ! self::isHeading($r)) {
                $moves++;
            }
        }
        // An opening balance and the year's movements adding up to the last row (its closing printed without a label).
        $lines = array_values(array_filter($rows, fn ($r) => ! self::isHeading($r)));
        if (count($lines) < 3 || ! self::isBalance($lines[0]['label'])) {
            return false;
        }
        $last = array_pop($lines);
        $any = false;
        foreach ($last['cells'] as $c => $cell) {
            if (($cell['amount'] ?? null) === null || ! in_array($cell['status'], ['ok', 'dash'], true)) {
                continue;
            }
            if (abs(array_sum(array_map(fn ($r) => (float) ($r['cells'][$c]['amount'] ?? 0.0), $lines)) - $cell['amount']) > self::TOLERANCE) {
                return false;
            }
            $any = $any || abs($cell['amount']) > self::TOLERANCE;
        }

        return $any;
    }

    /**
     * The rows marked: `kind` heading, line or total, and a total's `sum_of` (row indexes). The rows of several tables of
     * one statement go in together, in order (a statement's other comprehensive income printed as a table of its own).
     *
     * @param list<array> $rows rows with `cells` read (PageExtractor::blocks)
     * @return list<array>
     */
    public static function totals(array $rows): array
    {
        $items = [];          // unconsumed rows above, [index, is_total, no heading since]
        $sinceHeading = 0;    // lines and totals since the last heading
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            if (self::isHeading($row)) {
                $rows[$i]['kind'] = 'heading';
                $sinceHeading = 0;
                $items = array_map(fn ($it) => [$it[0], $it[1], false], $items);
                continue;
            }
            $values = array_map(fn ($c) => $c['amount'], $row['cells']);
            $readable = array_filter($row['cells'], fn ($c) => in_array($c['status'], ['ok', 'dash'], true));
            $zero = $readable !== [] && array_filter($values, fn ($v) => $v !== null && abs($v) > self::TOLERANCE) === [];
            $closes = $i === $last || self::isHeading($rows[$i + 1]);
            $sumOf = null;
            $off = [];
            // The longest run of rows just above that adds up to it: two or more, one total under the same heading (a
            // roll-up), or the one line above when it closes its part.
            for ($n = count($items); $n >= 1 && $sumOf === null; $n--) {
                $run = array_slice($items, -$n);
                if ($n === 1 && ! ($run[0][1] && $run[0][2]) && ! ($closes && $sinceHeading === 1)) {
                    continue;
                }
                if (! self::adds($rows, array_column($run, 0), $row, $off)) {
                    continue;
                }
                // A «-» total: only of rows that aren't all «-», closing its part.
                if ($zero && (! $closes || ! self::anyAmount($rows, array_column($run, 0)))) {
                    continue;
                }
                $sumOf = array_column($run, 0);
            }
            if ($sumOf !== null) {
                $items = array_slice($items, 0, count($items) - count($sumOf));
                $rows[$i]['kind'] = 'total';
                $rows[$i]['sum_of'] = $sumOf;
                if ($off !== []) {
                    $rows[$i]['off'] = $off;   // column → its rows' sum, where the printed total isn't it
                }
                $items[] = [$i, true, true];
            } else {
                $rows[$i]['kind'] = 'line';
                $items[] = [$i, false, true];
            }
            $sinceHeading++;
        }

        return $rows;
    }

    /** A table of rates (a header with «%», or every cell with an amount a percentage or a range): never added up. */
    public static function isRates(array $columns, array $rows = []): bool
    {
        if (array_filter($columns, fn ($c) => str_contains($c['header'], '%') || str_contains($c['header'], '٪')) !== []) {
            return true;
        }
        $cells = array_filter(array_merge([], ...array_column($rows, 'cells')), fn ($c) => ! in_array($c['status'], ['empty', 'text'], true));

        return $cells !== [] && array_filter($cells, fn ($c) => ! in_array($c['status'], ['percent', 'range'], true)) === [];
    }

    /**
     * Headers that name nothing but the currency (or nothing): a note's amount columns printed without their years. Read
     * by position, the first the most recent (R16).
     */
    public static function plainHeaders(array $columns): bool
    {
        foreach ($columns as $c) {
            $rest = preg_replace('/ريال(ات)?|سعودي|بال|ر\.?\s*س|SAR|SR|[\s\/().\-]+/u', '', $c['header']);
            if ($rest !== '') {
                return false;
            }
        }

        return $columns !== [];
    }

    /**
     * The column that adds up the others (R10: by its arithmetic, never by its header): equal to the sum of the other
     * columns, within rounding, on two rows with amounts at least and on all but a third of them (a cell misread, a
     * misprint). With it, the rows where it differs from that sum at all: the sum is the figure used.
     *
     * @return ?array{column: int, differences: list<array{row: int, printed: float, sum: float}>}
     */
    public static function totalColumn(array $rows): ?array
    {
        $width = max(array_map(fn ($r) => count($r['cells']), $rows ?: [['cells' => []]]));
        if ($width < 3) {
            return null;   // a total of one column is that column
        }
        foreach (range($width - 1, 0) as $c) {   // the far side from the labels first: where totals usually are
            [$equal, $differences] = [0, []];
            foreach ($rows as $i => $r) {
                $cells = $r['cells'];
                if (($cells[$c]['amount'] ?? null) === null || in_array('unread', array_column($cells, 'status'), true)) {
                    continue;
                }
                $others = round(array_sum(array_map(fn ($x) => (float) ($x['amount'] ?? 0.0), array_diff_key($cells, [$c => 1]))), 2);
                if (abs($others) <= self::TOLERANCE && abs($cells[$c]['amount']) <= self::TOLERANCE) {
                    continue;   // a row of nothing proves nothing
                }
                $equal += (int) (abs($others - $cells[$c]['amount']) <= self::TOLERANCE);
                if (abs($others - $cells[$c]['amount']) >= 0.005) {
                    $differences[] = ['row' => $i, 'printed' => $cells[$c]['amount'], 'sum' => $others];
                }
            }
            $beyond = count(array_filter($differences, fn ($d) => abs($d['printed'] - $d['sum']) > self::TOLERANCE));
            if ($equal >= 2 && $beyond <= intdiv($equal, 3)) {
                return ['column' => $c, 'differences' => $differences];
            }
        }

        return null;
    }

    /**
     * A movement table printed the other way round (its categories as rows, the balances and movements as columns: a
     * PPE note on a portrait page) turned to the usual shape: the categories as columns, a row per printed column, a
     * heading row where its part changes (the cost, the accumulated depreciation, the net book value). The part is read
     * from the headers' words, else from the table's title, else from the balances' order (a third balance starts the
     * next part). Null when the table isn't one.
     */
    public static function transpose(array $table): ?array
    {
        $balance = fn (string $text) => self::isBalance($text);
        if (count(array_filter($table['columns'], fn ($c) => $balance($c['header']))) < 2 || self::isMovement($table['rows'])) {
            return null;
        }
        $body = array_values(array_filter($table['rows'], fn ($r) => ! self::isHeading($r) && trim($r['label']) !== ''));
        if (count($body) < 1) {
            return null;
        }
        $partOf = fn (string $text) => match (true) {
            preg_match(self::NBV, $text) === 1 => 'nbv',
            preg_match('/مجمع|متراكم|إهلاك|اهلاك|استهلاك|إطفاء|الاطفاء/u', $text) === 1 => 'accumulated',
            preg_match('/التكلفة|التكلفه/u', $text) === 1 => 'cost',
            default => null,
        };
        $titles = ['cost' => 'التكلفة', 'accumulated' => 'مجمع الإهلاك', 'nbv' => 'صافي القيمة الدفترية'];
        $part = $partOf((string) ($table['title'] ?? '')) ?? 'cost';
        $balances = 0;
        $rows = [];
        $shown = null;
        foreach ($table['columns'] as $j => $c) {
            $segments = array_values(array_filter(array_map('trim', explode(' / ', $c['header'])), fn ($s) => $s !== '' && ! self::plainHeaders([['header' => $s]])));
            $label = $segments === [] ? '' : end($segments);
            $isBalance = $balance($label);
            $named = null;
            foreach ($segments as $s) {
                $named ??= $partOf($s);
            }
            // A part named by the header; else a third balance starts the next part.
            $part = $named ?? ($isBalance && $balances >= 2 ? ($part === 'cost' ? 'accumulated' : 'nbv') : $part);
            if ($part !== $shown) {
                $rows[] = ['label' => count($segments) > 1 && $partOf($segments[0]) === $part ? $segments[0] : $titles[$part], 'note' => null,
                    'cells' => array_fill(0, count($body), ['raw' => null, 'amount' => null, 'status' => 'empty'])];
                [$shown, $balances] = [$part, 0];
            }
            $balances += (int) $isBalance;
            $rows[] = ['label' => $label, 'note' => null, 'cells' => array_map(fn ($r) => $r['cells'][$j] ?? ['raw' => null, 'amount' => null, 'status' => 'empty'], $body)];
        }

        return ['columns' => array_map(fn ($r) => ['header' => $r['label']], $body), 'rows' => $rows, 'transposed' => true] + $table;
    }

    private static function anyAmount(array $rows, array $parts): bool
    {
        foreach ($parts as $p) {
            foreach ($rows[$p]['cells'] as $c) {
                if ($c['amount'] !== null && abs($c['amount']) > self::TOLERANCE) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the rows add up to the row in every column the row has an amount in (and none of them is unread); with
     * three columns or more, in all but one ($off: that column, a digit misread or misprinted, flagged by the caller).
     */
    private static function adds(array $rows, array $parts, array $row, ?array &$off = null): bool
    {
        $off = [];
        $adding = 0;
        $amounts = 0;
        foreach ($row['cells'] as $c => $cell) {
            if ($cell['status'] === 'unread') {
                return false;
            }
            if (in_array($cell['status'], ['empty', 'text', 'percent', 'range'], true)) {
                continue;   // percentages and ranges are never money added up
            }
            $sum = 0.0;
            foreach ($parts as $p) {
                $part = $rows[$p]['cells'][$c] ?? ['status' => 'empty', 'amount' => null];
                if ($part['status'] === 'unread') {
                    return false;
                }
                $sum += $part['amount'] ?? 0.0;
            }
            if ($cell['status'] === 'dash' && abs($sum) > self::TOLERANCE) {
                continue;   // a column the total doesn't add (a share value under the partners' total)
            }
            if (abs($sum - $cell['amount']) > self::TOLERANCE) {
                $off[$c] = round($sum, 2);
                continue;
            }
            $adding++;
            $amounts += (int) (abs($sum) > self::TOLERANCE);   // a «-» under «-»s adds up, but says nothing
        }

        return $off === [] ? $adding > 0 : count($off) === 1 && $amounts >= 2;
    }
}
