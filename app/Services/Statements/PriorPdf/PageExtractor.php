<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\AmountParser;
use App\Services\Statements\PriorStatementsFile;
use App\Support\Llm\ToolCall;
use App\Support\Pdf\Qpdf;

/**
 * Step 2 of reading last year's statements from a PDF (brief §8.2): each page that is read (a statement or notes) is cut
 * out alone, turned upright, and copied by the AI into structured blocks in reading order: headings (a note's number and
 * title), paragraphs, and tables (each column's header as printed, each row's label, its note reference and its cells
 * character for character). The AI is a copyist: it never computes, rounds or corrects. PHP reads the amounts here
 * (PriorStatementsFile::amount); totals, years, links and checks are PriorPdfReader's.
 */
final class PageExtractor
{
    public function __construct(private Llm $llm, private Qpdf $qpdf) {}

    /**
     * The pages, all sent at once (or from the cache). $attempt > 0 asks a page again, bypassing an earlier answer (a
     * check failed on it).
     *
     * @param list<array{page: int, kind: string, rotation: int, printed_page: ?string, attempt?: int, hint?: string}> $pages hint: why
     *        the page is read again (a check that failed on it)
     * @return array<int, array{page: int, kind: string, rotation: int, printed_page: ?string, attempt: int, blocks: list<array>, usage: ?array}> by page
     */
    public function extract(string $path, array $pages): array
    {
        $calls = array_map(fn ($p) => ['extract', $p['page'], $p['rotation'] + 1000 * ($p['attempt'] ?? 0), fn () => new ToolCall([['text' => self::RULES]], self::TOOL,
            self::content($p), 16000, false, $this->qpdf->extract($path, $p['page'], $p['page'], $p['rotation']))], $pages);
        $answers = $this->llm->askMany($path, $calls);
        $out = [];
        foreach ($pages as $i => $p) {
            $out[$p['page']] = ['page' => $p['page'], 'kind' => $p['kind'], 'rotation' => $p['rotation'], 'printed_page' => $p['printed_page'] ?? null,
                'attempt' => $p['attempt'] ?? 0, 'blocks' => self::blocks($answers[$i]->input), 'usage' => $answers[$i]->usage];
        }
        ksort($out);

        return $out;
    }

    /**
     * A statement page's amount columns read again as lists (brief §8.3: amounts printed off their labels' lines): each
     * column's values top to bottom, every «-» included, without pairing them with the labels; PriorPdfReader then
     * places them on the rows by the statement's arithmetic (Alignment).
     *
     * @param list<array{page: int, kind: string, rotation: int, printed_page: ?string}> $pages
     * @return array<int, array{tables: list<list<list<array>>>, usage: ?array}> by page: each table's columns' cells, in order
     */
    public function columns(string $path, array $pages): array
    {
        $calls = array_map(fn ($p) => ['columns', $p['page'], $p['rotation'], fn () => new ToolCall([['text' => self::COLUMN_RULES]], self::COLUMNS_TOOL,
            "This is page {$p['page']} of the file. List each amount column's values.", 8000, false, $this->qpdf->extract($path, $p['page'], $p['page'], $p['rotation']))], $pages);
        $answers = $this->llm->askMany($path, $calls);
        $out = [];
        foreach ($pages as $i => $p) {
            $out[$p['page']] = ['tables' => array_map(fn ($t) => array_map(fn ($c) => array_map(fn ($v) => self::cell(is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null),
                array_values(array_filter((array) ($c['values'] ?? []), fn ($v) => is_scalar($v) && trim((string) $v) !== ''))), (array) ($t['columns'] ?? [])),
                (array) ($answers[$i]->input['tables'] ?? [])), 'usage' => $answers[$i]->usage];
        }

        return $out;
    }

    private static function content(array $p): string
    {
        $what = ['financial_position' => 'the statement of financial position', 'comprehensive_income' => 'the statement of comprehensive income',
            'changes_in_equity' => 'the statement of changes in equity', 'cash_flows' => 'the statement of cash flows', 'notes' => 'notes to the financial statements'][$p['kind']] ?? 'a page';

        return "This is page {$p['page']} of the file".($p['printed_page'] ? " (printed number {$p['printed_page']})" : '').": {$what}. Copy everything on it."
            .(isset($p['hint']) ? "\n\nAn earlier copy of this page failed a check: {$p['hint']} Copy the page again from the image, carefully. In scanned statements the amounts are often printed a little above or below their label's line: pair each amount with the label line nearest to it. Count the amounts printed in each column, dashes «-» included (each dash is one row's zero), and make sure every one of them is on its own row: none left out, none shifted to another row. Never change an amount to make the check pass." : '');
    }

    /**
     * The AI's blocks with each table's cells read as amounts: {raw, amount, status} (ok, empty, dash = 0, text, unread).
     *
     * @return list<array{type: string, number: ?string, text: ?string, columns: list<array{header: string}>, rows: list<array{label: string, note: ?string, cells: list<array>}>}>
     */
    public static function blocks(array $input): array
    {
        $text = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $out = [];
        foreach ($input['blocks'] ?? [] as $b) {
            $type = in_array($b['type'] ?? null, ['heading', 'paragraph', 'table'], true) ? $b['type'] : 'paragraph';
            $columns = array_values(array_map(fn ($c) => ['header' => (string) $text($c['header'] ?? null)], (array) ($b['columns'] ?? [])));
            $rows = $type !== 'table' ? [] : array_values(array_map(fn ($r) => [
                'label' => (string) $text($r['label'] ?? null),
                'note'  => $text($r['note'] ?? null),
                'cells' => array_map(fn ($c) => self::cell($text($c)), array_pad(array_slice((array) ($r['cells'] ?? []), 0, count($columns)), count($columns), null)),
            ], (array) ($b['rows'] ?? [])));
            if ($type === 'table' && $rows === []) {
                continue;
            }
            if ($type !== 'table' && $text($b['text'] ?? null) === null) {
                continue;
            }
            $out[] = ['type' => $type, 'number' => $text($b['number'] ?? null), 'text' => $text($b['text'] ?? null)]
                + ($type === 'table' ? ['columns' => $columns, 'rows' => $rows] : []);
        }

        return $out;
    }

    /**
     * A cell as printed, and as an amount: «-» is a zero shown as a dash; words without digits are text; a percentage
     * («٪25», «2,5%») is a `percent` (its number, never added up as money); two numbers apart by a dash or «إلى» («٪20 -
     * ٪10») a `range` (no amount); words with digits that aren't an amount («(حصة 1,200)», «المعيار (17)») text; «?» or
     * anything else unreadable is unread.
     */
    public static function cell(?string $raw): array
    {
        if ($raw === null) {
            return ['raw' => null, 'amount' => null, 'status' => 'empty'];
        }
        if (preg_match('/^[-–—ـ]+$/u', $raw)) {
            return ['raw' => $raw, 'amount' => 0.0, 'status' => 'dash'];
        }
        // Words, no digit: a text column (a related party's relationship, the nature of its transactions), not an amount.
        if (preg_match('/\p{Arabic}{2,}/u', $raw) && ! preg_match('/[0-9٠-٩۰-۹?]/u', $raw)) {
            return ['raw' => $raw, 'amount' => null, 'status' => 'text'];
        }
        $digits = AmountParser::asciiDigits($raw);
        if (preg_match_all('/\d+(?:[.,]\d+)?/', $digits, $n) === 2 && preg_match('/\d\s*[%٪]?\s*(?:[-–—]|إلى|الى|حتى)\s*[%٪]?\s*\d/u', $digits)) {
            return ['raw' => $raw, 'amount' => null, 'status' => 'range'];
        }
        if (preg_match('/[%٪]/u', $raw) && count($n[0]) === 1) {
            // A comma before one or two digits is the decimal point here («2,5%»).
            $number = preg_match('/^\d+,\d{1,2}$/', $n[0][0]) ? str_replace(',', '.', $n[0][0]) : str_replace(',', '', $n[0][0]);

            return ['raw' => $raw, 'amount' => (float) $number * (preg_match('/^\s*[-–]|[-–]\s*$/u', $digits) ? -1 : 1), 'status' => 'percent'];
        }
        $read = PriorStatementsFile::amount($raw);
        if ($read['status'] !== 'ok' && preg_match('/[\x{0621}-\x{064A}]{3,}/u', $raw) && ! str_contains($raw, '?')) {
            return ['raw' => $raw, 'amount' => null, 'status' => 'text'];
        }

        return ['raw' => $raw, 'amount' => $read['amount'], 'status' => $read['status'] === 'ok' ? 'ok' : 'unread'];
    }

    private const TOOL = [
        'name' => 'submit_page', 'description' => 'Everything on the page, in reading order.', 'strict' => true,
        'schema' => ['type' => 'object', 'required' => ['blocks'], 'additionalProperties' => false, 'properties' => [
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['type', 'number', 'text', 'columns', 'rows'], 'properties' => [
                    'type'    => ['type' => 'string', 'enum' => ['heading', 'paragraph', 'table']],
                    'number'  => ['type' => ['string', 'null']],
                    'text'    => ['type' => ['string', 'null']],
                    'columns' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['header'],
                        'properties' => ['header' => ['type' => 'string']]]],
                    'rows'    => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'note', 'cells'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'note'  => ['type' => ['string', 'null']],
                            'cells' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
                        ]]],
                ]]],
        ]],
    ];

    private const COLUMNS_TOOL = [
        'name' => 'submit_columns', 'description' => "Each table's amount columns, as lists of their values.", 'strict' => true,
        'schema' => ['type' => 'object', 'required' => ['tables'], 'additionalProperties' => false, 'properties' => [
            'tables' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['columns'], 'properties' => [
                'columns' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['values'], 'properties' => [
                    'values' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]]],
            ]]],
        ]],
    ];

    private const COLUMN_RULES = <<<'TXT'
        You are given one page of a company's issued financial statements, usually in Arabic, often scanned. For each
        table of amounts on the page, in order, list its amount columns, starting from the column nearest the labels (the
        note reference column, إيضاح, is not an amount column). For each column, copy every value printed in it, from top
        to bottom, as printed: parentheses, commas, Arabic or Western digits; each dash «-» is a value of its own. Leave out
        the column's header lines (years, dates, currency) and the labels. Do not pair the values with labels or rows: read
        the column alone, so that no value is left out, repeated or moved. When a digit can't be read with certainty, copy
        what you see with «?» in its place. You never compute, round or correct.
        TXT;

    private const RULES = <<<'TXT'
        You are given one page of a company's issued financial statements, usually in Arabic, often scanned. Copy
        everything on it into blocks, in reading order (top to bottom; Arabic right to left). You are a copyist: you never
        compute, round, correct, translate or fill in anything, and you never leave anything out.
        - heading: a title: the page's titles (company, statement, period), a note's or sub-note's title, a table's
          title. number: the note's number as printed without its brackets, dashes or colon («5», «4.1», «4.1.2»,
          «1/16», «15/أ»), null when it has none. text: the title as printed.
        - paragraph: one paragraph or list item of text, as printed (its lines joined with a space). number: null.
        - table: every table of amounts. columns: the table's amount columns, in order starting from the column nearest
          the labels; each column's header is all its header lines as printed, top to bottom, joined with « / » (such as
          «31 ديسمبر 2024م / ريال سعودي» or «مباني وانشاءات / ريال سعودي»); an empty string when it has none. The note
          reference column (إيضاح) is not an amount column. rows: every row of the table, in order: headings, lines,
          subtotals and totals alike. label: the row's text as printed (an empty string when it has none). note: the
          note number printed on the row in the إيضاح column, null when none. cells: one per amount column, in the same
          order, each copied character for character as printed: parentheses, commas, dashes, Arabic or Western digits;
          null when the cell is empty. When a digit can't be read with certainty, copy what you see with «?» in its
          place. A table that continues past a heading line is still one table; two tables side by side or one under the
          other with their own headers are two tables.
        For a heading or paragraph, columns and rows are empty; for a table, text is its title when printed right above
        it inside the table area, else null, and number is null.
        Years, dates and numbers are copied exactly as printed, even when they look wrong.
        TXT;
}
