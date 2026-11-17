<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\Statements\StatementSynonyms;

/**
 * Reads the pages of a PDF of last year's statements (brief §8): each page extracted alone (PageExtractor), then PHP
 * decides the years (R16), the totals (R10), the notes and their parts, the links from the statements to the notes,
 * and checks the arithmetic. A statement whose own checks fail has its pages' amount columns read again as lists and
 * placed on the rows by its arithmetic (Alignment); a page whose checks still fail is read again, alone (up to twice);
 * what still fails is flagged with its page for the accountant. Nothing is guessed silently (P3).
 */
final class PriorPdfReader
{
    public const RETRIES = 2;

    public function __construct(private PageExtractor $extractor) {}

    /**
     * @param list<array{page: int, kind: string, rotation: int, printed_page: ?string}> $kinds every page (PageClassifier)
     * @param array<int, string> $once pages read once more at the end, page → why (a line differing from this year's
     *        opening balance: PriorStatementsPdf), whatever their checks; the caller keeps the reading it trusts more
     * @return array the document (process()) and `usage`: what the AI calls cost
     */
    public function read(string $path, array $kinds, array $once = []): array
    {
        $read = array_values(array_filter($kinds, fn ($p) => in_array($p['kind'], PageClassifier::READ, true)));
        $pages = $this->extractor->extract($path, $read);
        $usages = array_column($pages, 'usage');
        $doc = self::process($pages);
        [$pages, $doc] = $this->align($path, $read, $pages, $doc, $usages);
        for ($attempt = 1; $attempt <= self::RETRIES && ($failing = self::failingPages($doc)) !== []; $attempt++) {
            $again = $this->extractor->extract($path, array_map(fn ($p) => ['attempt' => $attempt, 'hint' => Checks::hint($doc, $p['page'])] + $p,
                array_values(array_filter($read, fn ($p) => in_array($p['page'], $failing, true)))));
            $usages = [...$usages, ...array_column($again, 'usage')];
            // A page read again is kept when its checks fail less than before.
            foreach ($again as $n => $page) {
                $try = self::process(array_replace($pages, [$n => $page]));
                if (self::badness($try, $n) < self::badness($doc, $n)) {
                    $pages[$n] = $page;
                    $doc = $try;
                }
            }
        }
        [$pages, $doc] = $this->align($path, $read, $pages, $doc, $usages);
        if ($once !== []) {
            $more = $this->extractor->extract($path, array_map(fn ($p) => ['attempt' => self::RETRIES + 1, 'hint' => $once[$p['page']]] + $p,
                array_values(array_filter($read, fn ($p) => isset($once[$p['page']])))));
            $usages = [...$usages, ...array_column($more, 'usage')];
            [, $doc] = $this->align($path, $read, array_replace($pages, $more), self::process(array_replace($pages, $more)), $usages);
        }

        return $doc + ['usage' => Llm::sum($usages)];
    }

    /**
     * Each statement whose own checks fail (it doesn't end on its total, the cash doesn't roll, it doesn't balance): its
     * pages' amount columns read as lists (PageExtractor::columns, kept by file) and placed on the rows by the
     * statement's arithmetic, table by table. One alignment: the page's rows replaced (`aligned`); several or none: the
     * page notes it (`unaligned`), flagged if the statement still fails.
     *
     * @return array{0: array<int, array>, 1: array} the pages and the document
     */
    private function align(string $path, array $read, array $pages, array $doc, array &$usages): array
    {
        $failing = array_values(array_unique(array_column(array_filter($doc['checks'],
            fn ($c) => ! $c['ok'] && in_array($c['code'], ['statement_total', 'cash_roll', 'balance'], true)), 'statement')));
        $on = [];   // page → statement
        foreach ($failing as $st) {
            foreach ($doc['statements'][$st]['tables'] ?? [] as $id) {
                $on[$doc['tables'][$id]['page']] = $st;
            }
        }
        if ($on === []) {
            return [$pages, $doc];
        }
        $lists = $this->extractor->columns($path, array_values(array_filter($read, fn ($p) => isset($on[$p['page']]))));
        $usages = [...$usages, ...array_column($lists, 'usage')];
        foreach ($on as $n => $st) {
            unset($pages[$n]['unaligned']);
            $t = -1;
            foreach ($pages[$n]['blocks'] as $k => $b) {
                if ($b['type'] !== 'table' || ! isset($lists[$n]['tables'][++$t])) {
                    continue;
                }
                $found = Alignment::align($b['rows'], $lists[$n]['tables'][$t], function (array $rows) use ($pages, $n, $k, $st) {
                    $pages[$n]['blocks'][$k]['rows'] = $rows;

                    return array_filter(Checks::statement($st, Tables::totals(self::statementRows($pages, $st))), fn ($c) => ! $c['ok']) === [];
                });
                if ($found['result'] === 'one') {
                    $pages[$n]['blocks'][$k]['rows'] = $found['rows'];
                    $pages[$n]['aligned'][] = ['statement' => $st, 'table' => $t];
                } else {
                    $pages[$n]['unaligned'][] = ['statement' => $st, 'table' => $t, 'result' => $found['result'], 'count' => $found['count']];
                }
            }
        }

        return [$pages, self::process($pages)];
    }

    /** A statement's rows across its pages, in order, each with its page (before Tables::totals). */
    private static function statementRows(array $pages, string $st): array
    {
        $rows = [];
        foreach ($pages as $n => $page) {
            if ($page['kind'] !== $st) {
                continue;
            }
            foreach ($page['blocks'] as $b) {
                if ($b['type'] === 'table') {
                    foreach ($b['rows'] as $r) {
                        $rows[] = $r + ['page' => $n];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * What the pages say: the statements' years, every table (years, totals), the notes, the links, checks and flags.
     *
     * @param array<int, array> $pages PageExtractor::extract() by page
     * @return array{years: array, tables: array<int, array>, statements: array<string, array>, sections: list<array>, links: list<array>, checks: list<array>, flags: list<array>, pages: array<int, array>}
     */
    public static function process(array $pages): array
    {
        ksort($pages);
        $tables = [];
        $statements = [];
        foreach ($pages as $n => $page) {
            foreach ($page['blocks'] as $k => $b) {
                if ($b['type'] !== 'table') {
                    continue;
                }
                $id = count($tables);
                $statement = in_array($page['kind'], PageClassifier::STATEMENTS, true) ? $page['kind'] : null;
                $tables[$id] = ['id' => $id, 'page' => $n, 'statement' => $statement, 'title' => $b['text'], 'columns' => $b['columns'], 'rows' => $b['rows']];
                $pages[$n]['blocks'][$k] = ['type' => 'table', 'table' => $id, 'number' => null, 'text' => $b['text']];
                if ($statement !== null) {
                    $statements[$statement]['tables'][] = $id;
                }
            }
        }

        // Statements: their tables' rows together, in order (totals across them); the years from their headers. A table
        // after the first has its cells in the first's column order (a statement printed as two tables, each its own way).
        foreach ($statements as $st => $s) {
            $first = $tables[$s['tables'][0]];
            // The equity statement's columns are its components, never years.
            $roles = Tables::roles($first['columns'], $st !== 'changes_in_equity');
            $rows = [];
            foreach ($s['tables'] as $id) {
                $order = $id === $first['id'] ? null : self::conform($first['columns'], $tables[$id]['columns'], $st === 'changes_in_equity');
                foreach ($tables[$id]['rows'] as $r => $row) {
                    if ($order !== null) {
                        $row['cells'] = array_map(fn ($j) => $j === null ? ['raw' => null, 'amount' => null, 'status' => 'empty'] : $row['cells'][$j], $order);
                    }
                    $rows[] = $row + ['table' => $id, 'row' => $r, 'page' => $tables[$id]['page']];
                }
            }
            $statements[$st] = ['statement' => $st, 'tables' => $s['tables'], 'roles' => $roles,
                'rows' => $st === 'changes_in_equity' ? array_map(fn ($r) => $r + ['kind' => Tables::isHeading($r) ? 'heading' : 'line'], $rows) : Tables::totals($rows)];
        }
        $years = self::truth($statements, $pages);

        // Notes' tables: years and totals of each. A movement table printed with its categories as rows is turned; a
        // table's amounts without years in their headers are the years by position (R16: the first the most recent).
        $flags = [];
        foreach ($tables as $id => $t) {
            if ($t['statement'] !== null) {
                continue;
            }
            $t = Tables::transpose($t) ?? $t;
            $movement = Tables::isMovement($t['rows']);
            $rates = Tables::isRates($t['columns'], $t['rows']);
            $roles = Tables::roles($t['columns'], ! $movement && ! $rates && count($t['columns']) <= 2 && Tables::plainHeaders($t['columns']));
            $tables[$id] = ['columns' => $t['columns'], 'rows' => $t['rows'], 'roles' => $roles, 'movement' => $movement] + $t;
            $tables[$id]['rows'] = $movement || $rates
                ? array_map(fn ($r) => $r + ['kind' => Tables::isHeading($r) ? 'heading' : 'line'], $t['rows'])
                : Tables::totals($t['rows']);
            // A column adding up the others (by its arithmetic, R10); where it differs from its columns' sum, the
            // statements say which is right (Totals, below). The columns' own arithmetic (Checks: movement) finds a cell
            // misread.
            if ($roles['by'] === 'none' && ($total = Tables::totalColumn($t['rows'])) !== null) {
                $tables[$id]['total_column'] = $total['column'];
                $tables[$id]['total_differences'] = $total['differences'];
            }
        }
        foreach ($statements as $st => $s) {
            foreach ($s['tables'] as $id) {
                $tables[$id] += ['roles' => $s['roles'], 'movement' => $st === 'changes_in_equity'];
            }
        }

        $notes = array_filter($pages, fn ($p) => $p['kind'] === 'notes');
        $built = Sections::build($notes);
        $sections = $built['sections'];
        $flags = [...$flags, ...$built['flags'], ...self::yearFlags($tables, $sections, $years), ...self::textYearFlags($sections, $years)];

        $lines = [];
        foreach (['financial_position', 'comprehensive_income'] as $st) {
            foreach ($statements[$st]['rows'] ?? [] as $i => $r) {
                if ($r['kind'] === 'line') {
                    $lines[] = ['statement' => $st, 'row' => $i, 'label' => $r['label'], 'note' => $r['note'],
                        'amounts' => array_values(array_filter(array_map(fn ($c) => $c['amount'], $r['cells']), fn ($a) => $a !== null && abs($a) > Tables::TOLERANCE))];
                }
            }
        }
        $links = Sections::link($lines, $sections, $tables);

        $doc = ['years' => $years, 'tables' => $tables, 'statements' => $statements, 'sections' => $sections, 'links' => $links, 'flags' => $flags,
            'pages' => array_map(fn ($p) => array_intersect_key($p, array_flip(['page', 'kind', 'rotation', 'printed_page', 'attempt', 'aligned'])), $pages)];
        $doc['checks'] = Checks::run($doc);
        // A printed total differing from its columns' sum: the one that ties to the statement line is used, else the
        // accountant chooses; each said with its page and both figures; a year end's balances together, agreeing.
        ['totals' => $doc['totals'], 'groups' => $doc['total_groups']] = Totals::resolve($doc);
        foreach ($doc['total_groups'] as $g) {
            $doc['flags'][] = ['code' => "total_group_{$g['status']}", 'page' => $g['page'], 'table' => $g['table'], 'year' => $g['year'], 'total_group' => $g['id']];
        }
        foreach ($doc['totals'] as $r) {
            // A year end's balances are flagged once, together (a group); a row of its own alone.
            if ($r['group'] === null || ($doc['total_groups'][array_search($r['group'], array_column($doc['total_groups'], 'id'), true)]['status'] ?? null) === 'none') {
                $doc['flags'][] = ['code' => ['sum_ties' => 'total_misprint', 'printed_ties' => 'total_columns_differ', 'choose' => 'total_choice'][$r['ties']],
                    'page' => $r['page'], 'table' => $r['table'], 'title' => $r['title'], 'total' => $r['id']];
            }
        }

        // A statement's amounts placed by its arithmetic; or not placeable while it still fails (several fit, or none).
        $failing = array_column(array_filter($doc['checks'], fn ($c) => ! $c['ok'] && isset($c['statement'])), 'statement', 'statement');
        foreach ($pages as $n => $p) {
            foreach ($p['aligned'] ?? [] as $a) {
                $doc['flags'][] = ['code' => 'aligned_by_arithmetic', 'page' => $n, 'statement' => $a['statement']];
            }
            foreach ($p['unaligned'] ?? [] as $a) {
                if (isset($failing[$a['statement']])) {
                    $doc['flags'][] = ['code' => "alignment_{$a['result']}", 'page' => $n, 'statement' => $a['statement'], 'count' => $a['count']];
                }
            }
        }

        return $doc;
    }

    /**
     * A later table's column for each of the first table's: by the year printed (a statement), by the header's words
     * (the changes in equity's components), else by position.
     *
     * @return ?list<?int> null: the same order
     */
    private static function conform(array $first, array $other, bool $byWords): ?array
    {
        $key = $byWords
            ? fn (array $c) => StatementSynonyms::wording((string) preg_replace('/\d+|ريال|سعودي/u', '', explode(' / ', $c['header'])[0]))
            : fn (array $c) => ($y = array_unique(Tables::years($c['header']))) && count($y) === 1 ? (string) reset($y) : '';
        $want = array_map($key, $first);
        $have = array_map($key, $other);
        if (in_array('', $want, true) || in_array('', $have, true) || count(array_unique($have)) !== count($have) || array_diff($want, $have) !== []) {
            return null;   // not every column named, or not the same ones: by position
        }
        $order = array_map(fn ($k) => array_search($k, $have, true), $want);

        return $order === array_keys($first) ? null : $order;
    }

    /** The pages a failing check points at. */
    public static function failingPages(array $doc): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($c) => $c['pages'], array_filter($doc['checks'], fn ($c) => ! $c['ok'])) ?: [[]])));
    }

    /** How bad a page's reading is: its failing checks, an unreadable table counted by its unreadable rows. */
    private static function badness(array $doc, int $page): int
    {
        return array_sum(array_map(fn ($c) => $c['code'] === 'unread' ? max(1, count($c['rows'] ?? [])) : 1, self::failuresOn($doc, $page)));
    }

    private static function failuresOn(array $doc, int $page): array
    {
        return array_filter($doc['checks'], fn ($c) => ! $c['ok'] && in_array($page, $c['pages'], true));
    }

    /**
     * The statements' years (R16: the truth): the latest year their headers print and the one before it; else the years
     * of the statements' titles.
     *
     * @return array{current: ?int, comparative: ?int, from: string}
     */
    private static function truth(array $statements, array $pages): array
    {
        foreach (['financial_position', 'comprehensive_income', 'cash_flows'] as $st) {
            $printed = $statements[$st]['roles']['printed'] ?? [];
            if (count($printed) >= 2) {
                return ['current' => $printed[0], 'comparative' => $printed[1], 'from' => $st];
            }
        }
        $titles = [];
        foreach ($pages as $p) {
            if (in_array($p['kind'], PageClassifier::STATEMENTS, true)) {
                foreach ($p['blocks'] as $b) {
                    if ($b['type'] === 'heading') {
                        $titles = [...$titles, ...Tables::years((string) $b['text'])];
                    }
                }
            }
        }
        $latest = $titles ? max($titles) : null;

        return ['current' => $latest, 'comparative' => $latest === null ? null : $latest - 1, 'from' => $latest === null ? 'none' : 'titles'];
    }

    /** Note tables headed with other years than the statements' (R16): read as the statements' years, flagged once per note. */
    private static function yearFlags(array $tables, array $sections, array $years): array
    {
        if ($years['current'] === null) {
            return [];
        }
        $flags = [];
        $in = [];
        foreach ($sections as $s) {
            foreach ($s['tables'] as $id) {
                $in[$id] = $s['parent'] !== null ? ($sections[$s['parent']]['printed'] ?? null) : $s['printed'];
            }
        }
        // One flag per note and years printed: its tables (and pages) inside it.
        foreach ($tables as $t) {
            // The two years in use; a third column (a restated balance sheet's start) is not a typo.
            $printed = array_slice($t['roles']['printed'] ?? [], 0, 2);
            $used = array_slice([$years['current'], $years['comparative']], 0, count($printed));
            if ($t['statement'] === null && ($t['roles']['by'] ?? null) === 'years' && $printed !== $used) {
                $key = ($in[$t['id']] ?? 'p'.$t['page']).'|'.implode('/', $printed);
                if (isset($flags[$key])) {
                    $flags[$key]['tables'][] = $t['id'];
                    $flags[$key]['pages'] = array_values(array_unique([...$flags[$key]['pages'], $t['page']]));
                    continue;
                }
                $flags[$key] = ['code' => 'year_typo', 'page' => $t['page'], 'pages' => [$t['page']], 'table' => $t['id'], 'tables' => [$t['id']],
                    'note' => $in[$t['id']] ?? null, 'printed' => $printed, 'used' => $used];
            }
        }

        return array_values($flags);
    }

    /** A year in a note's text before the comparative year or well after the current one (R16): flagged to check. */
    private static function textYearFlags(array $sections, array $years): array
    {
        if ($years['current'] === null) {
            return [];
        }
        $flags = [];
        foreach ($sections as $s) {
            foreach ($s['paragraphs'] as $text) {
                foreach (array_unique(Tables::years($text)) as $y) {
                    if ($y < $years['comparative'] || $y > $years['current'] + 2) {
                        $flags[] = ['code' => 'year_in_text', 'page' => $s['page'], 'note' => $s['parent'] !== null ? ($sections[$s['parent']]['printed'] ?? null) : $s['printed'],
                            'year' => $y, 'text' => mb_substr($text, 0, 160)];
                    }
                }
            }
        }

        return $flags;
    }
}
