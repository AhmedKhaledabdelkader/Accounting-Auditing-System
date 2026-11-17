<?php

namespace App\Services\Statements\PriorPdf;

/**
 * The arithmetic that proves a PDF was read right (brief §8.3). Each check names the pages it points at: a failing one
 * makes PriorPdfReader read those pages again; what still fails is flagged with its pages.
 *
 * - unread: no cell copied with «?»;
 * - statement_total: a statement's last row adds up (the balance sheet's, the income statement's, the cash flow's);
 * - balance: total assets = total equity and liabilities (a total of the balance sheet equal to its last row);
 * - movement: in a movement table each balance = the balance before it + the movements between (disposals taken off),
 *   in every column; the changes in equity too, and its total column = the sum of the others;
 * - note_tie: a note linked to a statement line has a row (its total, or its only line) equal to the line in both years;
 * - ppe_tie: the PPE note, its tables as one (PriorPpe::combined), cost − accumulated depreciation at the year's end =
 *   the balance sheet's line (a misread its own arithmetic can't see, or a part lost);
 * - company_cr_date: the commercial registration's date read after its number is a Hijri date read.
 */
final class Checks
{
    public static function run(array $doc): array
    {
        $checks = [];
        foreach ($doc['tables'] as $t) {
            $unread = array_filter($t['rows'], fn ($r) => array_filter($r['cells'], fn ($c) => $c['status'] === 'unread') !== []);
            if ($unread) {
                $checks[] = self::check('unread', false, [$t['page']], ['table' => $t['id'], 'rows' => array_values(array_map(fn ($r) => $r['label'], $unread))]);
            }
        }

        foreach (self::STATEMENTS as $st) {
            $checks = [...$checks, ...self::statement($st, $doc['statements'][$st]['rows'] ?? [])];
        }

        foreach ($doc['tables'] as $t) {
            if ($t['movement'] ?? false) {
                foreach (self::movementGaps($t) as $gap) {
                    $checks[] = self::check('movement', false, [$t['page']], ['table' => $t['id']] + $gap);
                }
                if (self::movementGaps($t) === [] && count(array_filter($t['rows'], fn ($r) => ! Tables::isHeading($r))) > 1) {
                    $checks[] = self::check('movement', true, [$t['page']], ['table' => $t['id']]);
                }
            }
        }
        // The changes in equity's total column: the one adding up the others (by its arithmetic, wherever printed), else
        // the one its header calls the total; a table with neither has no total column to check.
        foreach ($doc['statements']['changes_in_equity']['tables'] ?? [] as $id) {
            $t = $doc['tables'][$id];
            $total = Tables::totalColumn($t['rows'])['column']
                ?? array_key_first(array_filter($t['columns'], fn ($c) => preg_match(EquityBlock::TOTAL, \App\Support\ImportNormalizer::text(explode(' / ', $c['header'])[0])) === 1));
            if ($total === null) {
                continue;
            }
            foreach ($t['rows'] as $r) {
                $cells = array_map(fn ($c) => $c['amount'], $r['cells']);
                if (count($cells) >= 3 && ! in_array(null, $cells, true) && abs(array_sum($cells) - 2 * $cells[$total]) > Tables::TOLERANCE) {
                    $checks[] = self::check('equity_columns', false, [$t['page']], ['table' => $id, 'label' => $r['label']]);
                }
            }
        }

        foreach ($doc['links'] as $link) {
            if ($link['section'] === null) {
                continue;
            }
            $line = $doc['statements'][$link['statement']]['rows'][$link['row']];
            $tables = Sections::tablesOf($link['section'], $doc['sections'], $doc['tables']);
            $ok = self::ties($line, $doc['statements'][$link['statement']]['roles']['roles'], $tables);
            $pages = array_values(array_unique([...array_column($tables, 'page'), $doc['sections'][$link['section']]['page']]));
            $checks[] = self::check('note_tie', $ok, $pages, ['statement' => $link['statement'], 'label' => $line['label'], 'note' => $doc['sections'][$link['section']]['printed']]);
            // The PPE note against its line.
            $at = array_search('current', $doc['statements'][$link['statement']]['roles']['roles'] ?? [], true);
            if ($link['statement'] === 'financial_position' && preg_match(self::PPE, $line['label']) && $at !== false && ($want = $line['cells'][$at]['amount'] ?? null) !== null
                && array_filter($tables, fn ($t) => $t['movement'] ?? false) !== []) {
                $nbv = \App\Services\Statements\PriorPpe::netBookValue($tables, $doc['years']['current'] ?? null);
                $checks[] = self::check('ppe_tie', $nbv !== null && abs($nbv - abs($want)) <= Tables::TOLERANCE, array_values(array_unique([$line['page'], ...$pages])),
                    ['statement' => $link['statement'], 'label' => $line['label'], 'note' => $doc['sections'][$link['section']]['printed'], 'printed' => $nbv, 'expected' => abs($want)]);
            }
        }

        // The registration's date: a date after its number that isn't read as a Hijri date (a letter lost).
        $facts = CompanyFacts::of($doc);
        if (isset($facts['cr_number'])) {
            foreach ($doc['sections'] as $sec) {
                $text = \App\Services\AmountParser::asciiDigits(implode(' ', $sec['paragraphs']));
                if (str_contains($text, $facts['cr_number']['value']) && preg_match('/السجل\s+التجاري.{0,160}?بتاريخ/u', $text)) {
                    $checks[] = self::check('company_cr_date', isset($facts['cr_date_hijri']), [$sec['page']], []);
                    break;
                }
            }
        }

        return $checks;
    }

    /** A balance-sheet line of property, plant and equipment. */
    private const PPE = '/ممتلكات|الأصول\s+الثابتة|الاصول\s+الثابتة|الموجودات\s+الثابتة|العقارات\s+والآلات/u';

    /** The statements whose rows are checked as a whole. */
    public const STATEMENTS = ['financial_position', 'comprehensive_income', 'cash_flows'];

    /** The checks of a statement's rows (Tables::totals marked): it ends on its total; the cash roll; the balance. */
    public static function statement(string $st, array $all): array
    {
        $rows = array_values(array_filter($all, fn ($r) => $r['kind'] !== 'heading'));
        if ($rows === []) {
            return [];
        }
        $checks = [];
        // A cash flow ends on the cash at the year's end; rows printed after it (supplementary information, non-cash
        // transactions) are not its arithmetic.
        if ($st === 'cash_flows' && ($at = self::cashEnd($rows)) !== null && ($cut = array_search($rows[$at], $all, true)) !== false
            && ($all[$cut + 1]['kind'] ?? null) === 'heading') {
            // Only under a heading of their own: a row adding up by chance (a statement printed off its lines) ends nothing.
            [$rows, $all] = [array_slice($rows, 0, $at + 1), array_slice($all, 0, $cut + 1)];
        }
        $last = end($rows);
        // A statement ends on its total: a last row without amounts is a row whose amounts were lost.
        $end = end($all);
        $checks[] = self::check('statement_total', $last['kind'] === 'total' && $end['kind'] !== 'heading', [$end['page']], ['statement' => $st, 'label' => $end['label']]);
        // The cash flow ends: the year's net change, the cash at its start, the cash at its end = the two before.
        if ($st === 'cash_flows' && count($rows) >= 3) {
            [$change, $opening] = array_slice($rows, -3, 2);
            $ok = true;
            foreach ($last['cells'] as $i => $c) {
                if ($c['amount'] !== null && abs(($change['cells'][$i]['amount'] ?? 0.0) + ($opening['cells'][$i]['amount'] ?? 0.0) - $c['amount']) > Tables::TOLERANCE) {
                    $ok = false;
                }
            }
            $checks[] = self::check('cash_roll', $ok, array_values(array_unique([$change['page'], $opening['page'], $last['page']])), ['statement' => $st]);
        }
        if ($st === 'financial_position') {
            $equal = array_filter($rows, fn ($r) => $r !== $last && $r['kind'] === 'total' && self::same($r['cells'], $last['cells']));
            $checks[] = self::check('balance', $equal !== [], array_values(array_unique(array_column($rows, 'page'))), ['statement' => $st]);
        }

        return $checks;
    }

    /**
     * The last row of a cash flow at which the cash rolls: it equals the two rows above it (the year's net change and the
     * cash at its start) in every column with an amount. Null when none does.
     */
    public static function cashEnd(array $rows): ?int
    {
        for ($i = count($rows) - 1; $i >= 2; $i--) {
            $ok = false;
            foreach ($rows[$i]['cells'] as $c => $cell) {
                if (! in_array($cell['status'] ?? 'ok', ['ok', 'dash'], true) || $cell['amount'] === null) {
                    continue;
                }
                if (abs(($rows[$i - 1]['cells'][$c]['amount'] ?? 0.0) + ($rows[$i - 2]['cells'][$c]['amount'] ?? 0.0) - $cell['amount']) > Tables::TOLERANCE) {
                    $ok = false;
                    break;
                }
                $ok = $ok || abs($cell['amount']) > Tables::TOLERANCE;
            }
            if ($ok) {
                return $i;
            }
        }

        return null;
    }

    /** What failed on a page, in words for the AI reading it again. */
    public static function hint(array $doc, int $page): string
    {
        $words = [
            'unread'          => 'some digits were not readable («?»).',
            'statement_total' => 'the statement\'s last row does not add up to the rows above it, or has no amounts.',
            'balance'         => 'total assets do not equal total equity and liabilities.',
            'cash_roll'       => 'the cash at the end of the year does not equal the cash at its start plus the year\'s net change.',
            'movement'        => 'a balance does not equal the balance before it plus the movements between them.',
            'equity_columns'  => 'a row\'s total column does not equal the sum of its other columns.',
            'note_tie'        => 'a note\'s amounts do not match the statement line it explains.',
            'ppe_tie'         => 'the property, plant and equipment note\'s cost less accumulated depreciation does not equal the balance sheet\'s line: read every part (cost, accumulated depreciation, net book value) with its heading.',
            'company_cr_date' => 'the commercial registration\'s date was not read as a Hijri date: copy it exactly, with «هـ».',
        ];

        return implode(' ', array_unique(array_map(fn ($c) => $words[$c['code']] ?? '', array_filter($doc['checks'], fn ($c) => ! $c['ok'] && in_array($page, $c['pages'], true)))));
    }

    private static function check(string $code, bool $ok, array $pages, array $about): array
    {
        return ['code' => $code, 'ok' => $ok, 'pages' => $pages] + $about;
    }

    private static function same(array $a, array $b): bool
    {
        foreach ($a as $i => $cell) {
            if ($cell['amount'] === null || ($b[$i]['amount'] ?? null) === null || abs($cell['amount'] - $b[$i]['amount']) > Tables::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * The statement line's amount in each year equals a row of the note in the same year (a note's years are its own
     * roles; a note table without years, such as a movement by category, is matched on any column; a year the note
     * doesn't print is not tied).
     */
    private static function ties(array $line, array $roles, array $tables): bool
    {
        $want = [];
        foreach ($line['cells'] as $i => $c) {
            if (($roles[$i] ?? null) !== null && $c['amount'] !== null) {
                $want[$roles[$i]] = abs($c['amount']);
            }
        }
        if ($want === [] || array_filter($want, fn ($v) => $v > Tables::TOLERANCE) === []) {
            return true;   // a line of nothing: nothing to tie
        }
        // A year the note doesn't print (a note with this year's column only) has nothing to tie.
        $printed = array_merge(...array_map(fn ($t) => array_map(fn ($r) => $r ?? 'any', $t['roles']['roles'] ?? []), $tables ?: [[]]));
        foreach ($want as $role => $amount) {
            if ($amount <= Tables::TOLERANCE || ! array_intersect([$role, 'any'], $printed)) {
                continue;
            }
            $found = false;
            foreach ($tables as $t) {
                foreach ($t['rows'] as $r) {
                    foreach ($r['cells'] as $i => $c) {
                        $colRole = $t['roles']['roles'][$i] ?? null;
                        if ($c['amount'] !== null && ($colRole === $role || $colRole === null) && abs(abs($c['amount']) - $amount) <= Tables::TOLERANCE) {
                            $found = true;
                        }
                    }
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each balance row (a row naming a balance) after the first: the balance before it + the rows between, disposals
     * (استبعاد) taken off, in every column. A balance right after another (the next year's opening) equals it.
     *
     * @return list<array{label: string, column: int, expected: float, printed: float}>
     */
    public static function movementGaps(array $t): array
    {
        $gaps = [];
        $balance = null;
        $moves = [];
        $nbv = false;
        foreach ($t['rows'] as $r) {
            if (Tables::isHeading($r)) {
                [$balance, $moves] = [null, []];   // a new part (the cost, then the accumulated depreciation)
                $nbv = preg_match(Tables::NBV, $r['label']) === 1;   // net book values: balances side by side, nothing rolls
                continue;
            }
            if ($nbv) {
                continue;
            }
            $isBalance = Tables::isBalance($r['label']);
            if (! $isBalance) {
                $moves[] = $r;
                continue;
            }
            if ($balance !== null) {
                foreach ($r['cells'] as $i => $c) {
                    if ($c['amount'] === null || ($balance['cells'][$i]['amount'] ?? null) === null) {
                        continue;
                    }
                    $expected = $balance['cells'][$i]['amount'];
                    foreach ($moves as $m) {
                        $v = abs($m['cells'][$i]['amount'] ?? 0.0);
                        $expected += preg_match('/استبعاد|المسدد|المستخدم|مدفوع/u', $m['label']) ? -$v : ($m['cells'][$i]['amount'] ?? 0.0);
                    }
                    if (abs($expected - $c['amount']) > Tables::TOLERANCE) {
                        $gaps[] = ['label' => $r['label'], 'column' => $i, 'expected' => round($expected, 2), 'printed' => $c['amount']];
                    }
                }
            }
            $balance = $r;
            $moves = [];
        }

        return $gaps;
    }
}
