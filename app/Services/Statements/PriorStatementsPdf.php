<?php

namespace App\Services\Statements;

use App\Services\Statements\PriorPdf\Llm;
use App\Services\Statements\PriorPdf\PageClassifier;
use App\Services\Statements\PriorPdf\PriorPdfReader;

/**
 * Last year's issued statements from a PDF (scanned or not), in two steps (brief §8):
 *
 * 1. **Pages** (locate): the whole file goes once; the AI says what each page is (PageClassifier). The accountant sees
 *    one row per page and corrects it before anything is read.
 * 2. **Reading** (read): each statement and notes page is cut out alone, turned upright and copied by the AI; PHP then
 *    decides the years, the totals, the notes and the links, and checks the arithmetic, reading again a page that fails
 *    (PriorPdfReader). The statements' tables become sheets for PriorStatementsFile, which reads the year column and
 *    matches the lines exactly as for an Excel file. The whole reading (notes included) is kept for the later steps.
 */
class PriorStatementsPdf
{
    /** The statements whose pages are found; changes in equity is found but not mapped here (as for an Excel file). */
    public const FOUND = PageClassifier::STATEMENTS;

    /** Pages the AI names so they are left out, never read (R9): last year's auditor's report. */
    public const SKIPPED = ['auditor_report'];

    /**
     * Whether a file can stand as last year's approved statements (the accountant's rule): a PDF needs a page of the
     * independent auditor's report among its pages' kinds, as the AI said or the accountant corrected (never read, R9;
     * found by what the page is, not where). An Excel file needs a sheet or a title naming it (PriorStatementsFile): one
     * read before that rule, with no mark, stands.
     */
    public static function hasAuditorReport(array $file): bool
    {
        return ($file['kind'] ?? null) !== 'pdf'
            ? ($file['auditor_report'] ?? true) !== false
            : in_array('auditor_report', array_column($file['page_kinds'] ?? [], 'kind'), true);
    }

    /** A sheet's title for each statement mapped, in the words PriorStatementsFile recognises. */
    private const TITLES = [
        'financial_position'   => 'قائمة المركز المالي',
        'comprehensive_income' => 'قائمة الدخل الشامل',
        'cash_flows'           => 'قائمة التدفقات النقدية',
    ];

    public function __construct(private PriorStatementsFile $file, private PageClassifier $classifier, private PriorPdfReader $reader) {}

    /**
     * What each page is (PageClassifier), and from it each statement's pages and the auditor's report's.
     *
     * @return array{page_kinds: list<array>, pages: array<string, array{from: int, to: int}>, skipped: array<string, array{from: int, to: int}>, usage: array}
     */
    public function locate(string $path, int $pageCount): array
    {
        $classified = $this->classifier->classify($path, (string) file_get_contents($path), $pageCount);

        return ['page_kinds' => $classified['pages'], 'pages' => PageClassifier::ranges($classified['pages']),
            'skipped' => PageClassifier::skipped($classified['pages']), 'usage' => Llm::sum([$classified['usage']])];
    }

    /**
     * Each page that is read, read (PriorPdfReader), and the statements' tables as PriorStatementsFile's blocks.
     *
     * @param list<array{page: int, kind: string, rotation: int, printed_page: ?string}> $kinds every page, as confirmed
     * @param array<string, true> $vocabulary the words known outside the PDF (Vocabulary), to check the wording read
     * @param ?array<string, Figure> $opening this year's opening balance sheet (the trial balance's opening columns): a
     *        page with a line differing from it by more than rounding is read once more, and the reading with fewer
     *        differences kept (OpeningCheck); what still differs is shown in step 2, a reading error or a real difference
     * @param array<string, string> $surfaces the known words' printed forms (Vocabulary::surfaces), to correct a misprint to
     * @return array{blocks: list<array>, expenses_shown_positive: ?bool, document: array, usage: array}  PriorStatementsFile's
     *         result; each row also has `page` (in the whole file), `note` (its note reference as printed) and, on a row
     *         the file's words make a total but whose rows above don't add up to it, `check`: list<{column, sum, printed}>
     */
    public function read(string $path, array $kinds, int $year, LineCatalogue $catalogue, array $synonyms = [], array $vocabulary = [], ?array $opening = null, array $surfaces = []): array
    {
        $doc = $this->reader->read($path, $kinds);
        $read = $this->mapped($doc, $year, $catalogue, $synonyms);
        // Pages read once more: a line differing from this year's opening, or a statement whose placed lines don't hold
        // (shape()); the reading with fewer of both kept.
        $once = array_map(fn ($labels) => 'the amounts of «'.implode('», «', $labels).'» may be misread.', self::differing(OpeningCheck::lines($catalogue, $opening, $read)));
        foreach (array_filter(self::shape($read, $catalogue), fn ($c) => ! $c['ok']) as $c) {
            foreach ($c['pages'] as $p) {
                $once[$p] = trim(($once[$p] ?? '').' '.$c['hint']);
            }
        }
        $bad = fn (array $r) => count(self::differing(OpeningCheck::lines($catalogue, $opening, $r), true)) + count(array_filter(self::shape($r, $catalogue), fn ($c) => ! $c['ok']));
        if ($once !== []) {
            $again = $this->reader->read($path, $kinds, $once);
            $reread = $this->mapped($again, $year, $catalogue, $synonyms);
            $usage = $doc['usage'];
            foreach ($again['usage'] as $k => $v) {
                $usage[$k] += $v;   // the second reading pays only for the pages read once more
            }
            if ($bad($reread) < $bad($read)) {
                [$doc, $read] = [$again, $reread];
            }
            $doc['usage'] = $usage;
        }
        // What still doesn't hold is flagged with its pages.
        $doc['checks'] = [...$doc['checks'], ...array_map(fn ($c) => array_diff_key($c, ['hint' => 1]), self::shape($read, $catalogue))];
        // The wording checked before R11 copies it: slips and misprints corrected (the accountant may keep the
        // original), the rest flagged, general words never.
        // Names (the partners', the related parties': people and companies) aren't checked word by word: proper names.
        $names = PriorPdf\Wording::names(['document' => $doc, 'blocks' => $read['blocks']]);
        $wording = PriorPdf\Wording::check($doc, $vocabulary, $surfaces, $vocabulary === [] ? [] : PriorPdf\Vocabulary::general(), $names);
        $doc['flags'] = [...$doc['flags'], ...$wording['flags']];
        $doc['corrections'] = $wording['corrections'];
        // What the file says that the accountant should know first: its framework, row labels' years, a transition note,
        // partners who left (FileFacts).
        $doc['flags'] = [...$doc['flags'], ...PriorPdf\FileFacts::flags(['document' => $doc, 'blocks' => $read['blocks']])];
        // Last year's changes in equity, for step 2's grid; what can't be placed listed with its page.
        $equity = PriorPdf\EquityBlock::of($doc, $catalogue);
        foreach ($equity['unplaced_rows'] ?? [] as $r) {
            $doc['flags'][] = ['code' => 'equity_row_unplaced', 'page' => $r['page'], 'title' => $r['label']];
        }
        foreach ($equity['unplaced_columns'] ?? [] as $header) {
            $doc['flags'][] = ['code' => 'equity_column_unplaced', 'page' => $equity['pages'][0], 'title' => $header];
        }
        $doc['checks'] = [...$doc['checks'], ...self::across($read, $equity, $catalogue, $doc)];

        // The company as last year's notes describe it, for step 2 to offer to the company's profile.
        $company = PriorPdf\CompanyFacts::of($doc, $doc['corrections']);

        return $read + ['equity' => $equity, 'company' => $company, 'document' => array_diff_key($doc, ['usage' => 1]), 'usage' => $doc['usage']];
    }

    /**
     * The statements as placed on lines, by their own shape: the cash flow's net change = its closing cash − its opening
     * cash (`cf_net_change`); the lines the shape requires (`line_missing`): gross profit where revenue and the cost of
     * revenue are printed, a cash flow's closing cash and its net change (the balance sheet's totals: Checks' balance) (a labelled row
     * with its amount, placed or not, is there; a zero only placed). Each with the pages to read again and what to say to the AI.
     */
    private static function shape(array $read, LineCatalogue $catalogue): array
    {
        [$line, $pages] = self::placed($read, $catalogue);
        $statementPages = [];
        foreach ($read['blocks'] as $block) {
            foreach ($block['rows'] as $r) {
                $statementPages[$block['statement']][] = $r['page'] ?? null;
            }
        }
        $of = fn (string $st) => array_values(array_unique(array_filter($statementPages[$st] ?? [])));
        // A row of the statement with this amount in the column used and a label, on this line or on none yet: an unplaced
        // row with an amount waits for the accountant in step 2. Never a row of nothing (step 2 doesn't ask for it), one
        // without a label (its label lost), nor another line's row (no revenue: the gross loss is the cost's amount).
        $row = function (string $st, float $amount, string $key) use ($read) {
            if (abs($amount) <= PriorPdf\Tables::TOLERANCE) {
                return false;
            }
            foreach ($read['blocks'] as $block) {
                foreach ($block['statement'] === $st ? $block['rows'] : [] as $r) {
                    $v = $r['values'][$block['column']]['amount'] ?? null;
                    $on = in_array($r['certainty'] ?? null, ['exact', 'synonym'], true) ? $r['match'] : null;
                    if ($v !== null && trim((string) $r['label']) !== '' && ($on === null || $on === $key) && abs(abs((float) $v) - abs($amount)) <= PriorPdf\Tables::TOLERANCE) {
                        return true;
                    }
                }
            }

            return false;
        };
        $label = fn (string $key) => collect(array_merge(...array_map(fn ($st) => $catalogue->statement($st), ['financial_position', 'comprehensive_income', 'cash_flows'])))->firstWhere('key', $key)['label_ar'] ?? $key;
        $missing = fn (string $key, string $st) => ['code' => 'line_missing', 'ok' => false, 'pages' => $of($st), 'statement' => $st, 'label' => $label($key),
            'hint' => "the line «{$label($key)}» was not found: read every row with its label, a total too."];
        $checks = [];
        if (($revenue = $line('is.revenue')) !== null && ($cost = $line('is.cost_of_revenue')) !== null && $line('is.gross_profit') === null
            && ! $row('comprehensive_income', abs($revenue) - abs($cost), 'is.gross_profit')) {
            $checks[] = $missing('is.gross_profit', 'comprehensive_income');
        }
        // A cash flow ends on its cash: its closing cash placed, else its last labelled row with an amount (a reading a row
        // off its lines leaves the end without one).
        foreach ($of('cash_flows') === [] ? [] : array_filter($read['blocks'], fn ($b) => $b['statement'] === 'cash_flows') as $block) {
            $last = collect($block['rows'])->last(fn ($r) => trim((string) $r['label']) !== '');
            if ($line('cf.closing_cash') === null && ($last === null || ($last['values'][$block['column']]['amount'] ?? null) === null)) {
                $checks[] = $missing('cf.closing_cash', 'cash_flows');
            }
        }
        if (($open = $line('cf.opening_cash')) !== null && ($close = $line('cf.closing_cash')) !== null) {
            $net = $line('cf.net_change') ?? ($row('cash_flows', $close - $open, 'cf.net_change') ? round($close - $open, 2) : null);
            $checks[] = $net === null ? $missing('cf.net_change', 'cash_flows') : ['code' => 'cf_net_change', 'ok' => abs($net - ($close - $open)) <= PriorPdf\Tables::TOLERANCE,
                'pages' => array_values(array_unique(array_filter([...($pages['cf.net_change'] ?? []), ...($pages['cf.closing_cash'] ?? [])]))), 'statement' => 'cash_flows',
                'printed' => $net, 'expected' => round($close - $open, 2), 'hint' => 'the net change in cash does not equal the cash at the end less the cash at the start.'];
        }

        return $checks;
    }

    /**
     * The lines a file places, with their amounts (a line placed exactly, else a total of the file's) and pages.
     *
     * @return array{0: callable(string): ?float, 1: array<string, list<?int>>}
     */
    private static function placed(array $read, LineCatalogue $catalogue): array
    {
        $file = PriorYearResolver::fromFile($catalogue, ['file' => $read]);
        $placed = [];
        $pages = [];
        foreach ($read['blocks'] as $b => $block) {
            foreach ($block['rows'] as $r) {
                if (($key = PriorYearResolver::rowLine(['rows' => []], $b, $r)) !== null) {
                    $placed[$key] = true;
                    $pages[$key][] = $r['page'] ?? null;
                }
            }
        }
        $totals = array_column(array_filter($file['file_totals'], fn ($t) => $t['amount'] !== null), 'amount', 'key');
        $line = function (string $key) use ($placed, $file, $totals) {
            $f = $file['figures'][$key] ?? $file['cash_flows'][$key] ?? null;

            return isset($placed[$key]) && $f !== null && ! $f->isMissing() ? $f->amount : ($totals[$key] ?? null);
        };

        return [$line, $pages];
    }

    /**
     * The statements against each other once their lines are placed (Phase 4.5): the changes in equity's closing = the
     * balance sheet's equity lines, its profit = the income statement's, the cash flow's closing cash = the balance
     * sheet's cash. Only what both sides give (a line the file places exactly) is compared.
     */
    private static function across(array $read, ?array $equity, LineCatalogue $catalogue, array $doc): array
    {
        [$line, $pages] = self::placed($read, $catalogue);
        $at = fn (array $keys, array $more = []) => array_values(array_unique(array_filter([...array_merge(...array_map(fn ($k) => $pages[$k] ?? [], $keys)), ...$more])));
        $check = fn (string $code, float $a, float $b, array $p) => ['code' => $code, 'ok' => abs($a - $b) <= PriorPdf\Tables::TOLERANCE, 'pages' => $p, 'printed' => $a, 'expected' => $b];

        $checks = [];
        if ($equity !== null) {
            foreach ($equity['closing'] as $col => $amount) {
                $key = $catalogue->changesInEquity()['columns'][$col]['line'] ?? null;
                if ($key !== null && ($v = $line($key)) !== null) {
                    $checks[] = $check('cross_equity_sofp', $amount, $v, $at([$key], $equity['pages'])) + ['label' => $catalogue->changesInEquity()['columns'][$col]['label_ar']];
                }
            }
            if (isset($equity['rows']['net_profit']) && ($v = $line('is.net_profit')) !== null) {
                $checks[] = $check('cross_profit_equity', array_sum($equity['rows']['net_profit']), $v, $at(['is.net_profit'], $equity['pages']));
            }
        }
        if (($cash = $line('cf.closing_cash')) !== null && ($v = $line('sofp.cash')) !== null) {
            $checks[] = $check('cross_cash_sofp', $cash, $v, $at(['cf.closing_cash', 'sofp.cash']));
        }

        return $checks;
    }

    /**
     * The lines differing from this year's opening by more than rounding, by the PDF page they are on.
     *
     * @return array<int, list<string>> page → labels; with $lines: the lines themselves
     */
    private static function differing(array $check, bool $lines = false): array
    {
        $out = [];
        foreach ($check as $l) {
            if ($l['kind'] === 'difference') {
                foreach ($lines ? [0] : $l['pages'] as $p) {
                    $out[$lines ? $l['line'] : $p][] = $l['label'];
                }
            }
        }

        return $out;
    }

    /** The statements' tables as PriorStatementsFile's blocks, each row with its page, note and check. */
    private function mapped(array $doc, int $year, LineCatalogue $catalogue, array $synonyms): array
    {
        $sheets = [];
        $extra = [];   // sheet → grid row → statement row
        foreach (array_keys(self::TITLES) as $statement) {
            if (! isset($doc['statements'][$statement])) {
                continue;
            }
            $i = count($sheets);
            [$grid, $extra[$i]] = self::grid($statement, $doc['statements'][$statement], $doc['tables']);
            $sheets[] = ['sheet' => $i, 'sheet_name' => self::TITLES[$statement], 'grid' => $grid];
        }
        $read = $this->file->fromSheets($sheets, $year, $catalogue, $synonyms);
        foreach ($read['blocks'] as $b => $block) {
            $read['blocks'][$b] = self::rows($block, $extra[$block['sheet']] ?? [], $catalogue);
        }

        return $read;
    }

    /**
     * A statement's rows as a sheet: the title on row 1, its columns' headers on row 2, then a row for each row printed
     * (label in A, amounts from B on, as printed). Also each sheet row's statement row.
     *
     * @return array{0: array<int, array<string, string>>, 1: array<int, array>}
     */
    private static function grid(string $statement, array $s, array $tables): array
    {
        $letters = fn (int $n) => chr(ord('B') + $n);   // amount columns: a statement has a handful
        $grid = [1 => ['A' => self::TITLES[$statement]]];
        foreach (array_slice($tables[$s['tables'][0]]['columns'], 0, 20) as $c => $column) {
            $grid[2][$letters($c)] = $column['header'];
        }
        $grid[2] = array_filter($grid[2] ?? [], fn ($h) => $h !== '') ?: ['B' => ''];
        $extra = [];
        foreach ($s['rows'] as $i => $row) {
            $r = $i + 3;
            $cells = ['A' => $row['label']];
            foreach (array_slice($row['cells'], 0, 20) as $c => $cell) {
                if ($cell['raw'] !== null) {
                    $cells[$letters($c)] = $cell['raw'];
                }
            }
            $grid[$r] = array_filter($cells, fn ($v) => $v !== '');
            $extra[$r] = $row + ['index' => $i];
        }

        return [array_filter($grid), $extra];
    }

    /**
     * Each row's page and note; a row the matcher puts on a total of the statement that the arithmetic didn't find to be
     * one gets its check: the sum of the rows the matcher puts on the total's parts (a part not in the file: its own
     * parts'), against what it prints.
     */
    private static function rows(array $block, array $extra, LineCatalogue $catalogue): array
    {
        $sums = array_column(array_filter($catalogue->statement($block['statement']), fn ($i) => $i['type'] === 'total'), 'sum', 'key');
        $byKey = [];
        foreach ($block['rows'] as $row) {
            if ($row['match'] !== null) {
                $byKey[$row['match']] ??= $row;
            }
        }
        foreach ($block['rows'] as $k => $row) {
            $info = $extra[$row['row']] ?? null;
            $block['rows'][$k]['page'] = $info['page'] ?? null;
            $block['rows'][$k]['note'] = $info['note'] ?? null;
            // A total by its arithmetic (R10): checked, never an item; it needs no line of its own.
            $block['rows'][$k]['kind'] = $info['kind'] ?? null;
            $block['rows'][$k]['statement_row'] = $info['index'] ?? null;   // the document's row (its link to a note)
            if ($info === null || ! isset($sums[$row['match']]) || $info['kind'] === 'total') {
                continue;
            }
            $checks = [];
            foreach ($block['columns'] as $column) {
                $printed = $row['values'][$column['column']] ?? null;
                if (($printed['status'] ?? null) !== 'ok') {
                    continue;
                }
                $sum = self::sumOf($row['match'], $column['column'], $sums, $byKey, true);
                if ($sum !== null && abs($sum - $printed['amount']) > PriorPdf\Tables::TOLERANCE) {
                    $checks[] = ['column' => $column['column'], 'sum' => round($sum, 2), 'printed' => $printed['amount']];
                }
            }
            if ($checks) {
                $block['rows'][$k]['check'] = $checks;
            }
        }

        return $block;
    }

    /** A key's amount in a column: its row's, else (a total) its parts' sum; null when a part is unread or nothing is there. */
    private static function sumOf(string $key, string $column, array $sums, array $byKey, bool $parts = false): ?float
    {
        if (! $parts && isset($byKey[$key])) {
            $v = $byKey[$key]['values'][$column] ?? ['status' => 'empty'];

            return $v['status'] === 'unread' ? null : (float) ($v['amount'] ?? 0.0);
        }
        if (! isset($sums[$key])) {
            return 0.0;   // a line not in the file
        }
        $total = 0.0;
        foreach ($sums[$key] as $part) {
            $v = self::sumOf($part, $column, $sums, $byKey);
            if ($v === null) {
                return null;
            }
            $total += $v;
        }

        return $total;
    }
}
