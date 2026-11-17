<?php

namespace App\Services\Statements;

use App\Support\ImportNormalizer;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Last year's issued statements, from their Excel file (PR 17, Q7): PHP reads every number; nothing is asked of an AI.
 *
 * - **Statements**: a row titled «قائمة …» names the statement below it (financial position, comprehensive income, cash
 *   flows; changes in equity only ends the one before it). A sheet without such a title isn't read.
 * - **Year column**: the header above the first amounts holds a year («2024», «31 ديسمبر 2024م», a date); the column of
 *   the comparative year is taken. Without a header naming that year, the column is proposed and waits for the user.
 * - **Amounts**: a number, or text such as «(1,234)», «1,234», «-» (zero), with Western or Arabic-Indic digits. Anything
 *   else, a formula error included, is `unread`: it becomes a missing figure, never 0. Nothing is rounded or guessed.
 * - **Lines**: each row's label is matched to the statement's lines and totals by their wording (normalised Arabic). A
 *   label equal to a line's is `exact`; equal to a synonym the user keeps (StatementSynonyms) `synonym`; close to a single
 *   line `probable`; the rest `none`. Exact and synonym matches are placed, and only once the user confirms the file; they
 *   can still change them. Probable and unmatched rows with an amount wait for the user's choice (a line, a total to check
 *   against, or ignore).
 * - **Expenses**: the income statement shows expenses negative. A file that shows every expense positive is flagged
 *   (`expenses_shown_positive`): the user confirms before they are turned negative.
 */
final class PriorStatementsFile
{
    public const STATEMENTS = ['financial_position', 'comprehensive_income', 'cash_flows'];

    /** Title words, normalised (ImportNormalizer), after «قائمة». Changes in equity is recognised to end a block only. */
    private const TITLES = [
        'changes_in_equity'    => ['تغيرات في حقوق ملكيه', 'تغير في حقوق ملكيه', 'تغيرات حقوق ملكيه'],
        'cash_flows'           => ['تدفقات نقديه'],
        'comprehensive_income' => ['دخل شامل', 'دخل', 'ارباح خسائر', 'ارباح او خسائر', 'ربح خساره'],
        'financial_position'   => ['مركز مالي', 'ميزانيه'],
    ];

    private const HEADER_ROWS = 12;   // how far below a title its year header may sit
    private const PROBABLE = 0.5;     // word overlap for a probable match
    private const MARGIN = 0.15;      // and its lead over the next line

    /**
     * @param string $path the uploaded file
     * @param int    $year the comparative year (the year last year's statements are for)
     * @return array{blocks: list<array>, expenses_shown_positive: ?bool}
     *         block: {statement, sheet, sheet_name, title_row, columns: list<{column, header, year}>, column: ?string, column_certain: bool,
     *                 rows: list<{id, row, label, values: array<string, {amount: ?float, raw: string, status: ok|unread|empty}>, match: ?string, certainty: exact|probable|none}>}
     */
    public function read(string $path, int $year, LineCatalogue $catalogue, array $synonyms = []): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);
        $sheets = [];
        $auditor = false;
        try {
            foreach ($book->getAllSheets() as $index => $sheet) {
                $grid = [];
                $auditor = $auditor || self::namesAuditorReport($sheet->getTitle());
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $value = self::value($cell);
                        if ($value !== null && $value !== '') {
                            $grid[$row->getRowIndex()][$cell->getColumn()] = $value;
                            $auditor = $auditor || (is_string($value) && self::namesAuditorReport($value));
                        }
                    }
                }
                $sheets[] = ['sheet' => $index, 'sheet_name' => $sheet->getTitle(), 'grid' => $grid];
            }
        } finally {
            $book->disconnectWorksheets();
        }

        // Last year's approved statements come with the independent auditor's report (the accountant's rule): a sheet or a
        // title naming it. The file is refused without one (FinancialReportController::uploadPriorStatements).
        return ['auditor_report' => $auditor] + $this->fromSheets($sheets, $year, $catalogue, $synonyms);
    }

    /** Whether a title or a line of text names the independent auditor's report («تقرير المراجع المستقل», «تقرير مراجع الحسابات», …). */
    public static function namesAuditorReport(string $text): bool
    {
        return preg_match('/تقرير\s+(ال)?(مراجع|مراقب)|independent\s+auditor/iu', $text) === 1;
    }

    /**
     * Statements from sheets already read into cells (row → column letter → value): an Excel file's, or the tables the AI
     * copied from a PDF (PriorStatementsPdf). The rest is the same: titles, the year column, amounts, lines.
     *
     * @param list<array{sheet: int, sheet_name: string, grid: array<int, array<string, mixed>>}> $sheets
     */
    public function fromSheets(array $sheets, int $year, LineCatalogue $catalogue, array $synonyms = []): array
    {
        $blocks = [];
        foreach ($sheets as $sheet) {
            foreach ($this->blocks($sheet['grid'], $year) as $b) {
                $blocks[] = ['sheet' => $sheet['sheet'], 'sheet_name' => $sheet['sheet_name']] + $b;
            }
        }
        $blocks = array_values(array_filter($blocks, fn ($b) => in_array($b['statement'], self::STATEMENTS, true)));

        return self::rematch(['blocks' => $blocks], $catalogue, $synonyms);
    }

    /**
     * The file's rows matched again, with the synonyms as they are now: a file still waiting for review follows the list
     * the user edits; one in use keeps the matches it was confirmed with.
     *
     * @param array<string, array<string, string>> $synonyms statement → wording → line key (StatementSynonyms::map)
     */
    public static function rematch(array $file, LineCatalogue $catalogue, array $synonyms): array
    {
        $file['blocks'] ??= [];   // a PDF still being read has none yet
        foreach ($file['blocks'] as $i => $b) {
            $file['blocks'][$i]['rows'] = self::match($b['rows'], $b['statement'], $catalogue, $synonyms[$b['statement']] ?? []);
        }
        $file['expenses_shown_positive'] = self::expensesPositive($file['blocks'], $catalogue);

        return $file;
    }

    /** A cell's value as shown: a number, a date as a year header (text), text; a formula's last value, or an error. */
    private static function value(Cell $cell): mixed
    {
        try {
            $v = $cell->isFormula() ? ($cell->getOldCalculatedValue() ?? $cell->getCalculatedValue()) : $cell->getValue();
        } catch (Throwable) {
            return '#ERROR';
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if (is_numeric($v) && ! is_string($v) && ExcelDate::isDateTime($cell) && $v > 20000) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }

        return is_string($v) ? trim($v) : $v;
    }

    /** @return list<array{statement: string, title_row: int, columns: list<array>, column: ?string, column_certain: bool, rows: list<array>}> */
    private function blocks(array $grid, int $year): array
    {
        $titles = [];
        foreach ($grid as $r => $cells) {
            $statement = self::title($cells);
            if ($statement !== null) {
                $titles[$r] = $statement;
            }
        }
        $starts = array_keys($titles);
        $out = [];
        foreach ($starts as $i => $start) {
            $end = $starts[$i + 1] ?? (max(array_keys($grid)) + 1);
            $rows = array_filter($grid, fn ($r) => $r > $start && $r < $end, ARRAY_FILTER_USE_KEY);
            $out[] = ['statement' => $titles[$start], 'title_row' => $start] + $this->columns($rows, $year) + ['raw' => $rows];
        }

        return array_map(function ($b) {
            $rows = [];
            foreach ($b['raw'] as $r => $cells) {
                if ($r <= $b['header_row']) {
                    continue;
                }
                $label = self::label($cells, array_column($b['columns'], 'column'));
                if ($label === null) {
                    continue;
                }
                $values = [];
                foreach ($b['columns'] as $c) {
                    $values[$c['column']] = self::amount($cells[$c['column']] ?? null);
                }
                $rows[] = ['id' => "{$r}", 'row' => $r, 'label' => $label, 'values' => $values];
            }
            unset($b['raw'], $b['header_row']);

            return $b + ['rows' => $rows];
        }, $out);
    }

    /** The statement a title row names: text only, «قائمة» and a statement's words (or «الميزانية»). */
    private static function title(array $cells): ?string
    {
        $texts = array_filter($cells, fn ($v) => is_string($v) && ImportNormalizer::isArabic($v));
        if (! $texts || count($texts) !== count($cells) || count($texts) > 2) {
            return null;
        }
        $text = ' '.ImportNormalizer::text(implode(' ', $texts)).' ';
        if (! str_contains($text, ' قائمه ') && ! str_contains($text, ' ميزانيه ')) {
            return null;
        }
        foreach (self::TITLES as $statement => $words) {
            foreach ($words as $w) {
                if (str_contains($text, " {$w} ")) {
                    return $statement;
                }
            }
        }

        return null;
    }

    /**
     * The amount columns: those headed by a year in the rows under the title (else every column holding numbers in two
     * rows or more, the note column aside); the comparative year's is taken when one header names it.
     *
     * @return array{columns: list<array{column: string, header: string, year: ?int}>, column: ?string, column_certain: bool, header_row: int}
     */
    private function columns(array $rows, int $year): array
    {
        $first = array_key_first($rows) ?? 0;
        // Columns holding an amount below a row (a subtitle «كما في 31 ديسمبر 2024م» heads no column of amounts).
        $amountsBelow = function (int $r, string $col) use ($rows) {
            foreach ($rows as $n => $cells) {
                if ($n > $r && isset($cells[$col]) && self::yearOf($cells[$col]) === null && self::amount($cells[$col])['status'] === 'ok') {
                    return true;
                }
            }
            return false;
        };
        foreach (array_slice($rows, 0, self::HEADER_ROWS, true) as $r => $cells) {
            // A year typed as a number (2024) heads a column only in a row whose numbers all look like years.
            $numbers = array_filter($cells, fn ($v) => is_int($v) || is_float($v));
            $yearRow = $numbers !== [] && array_filter($numbers, fn ($v) => ! (floor($v) == $v && $v >= 1990 && $v <= 2100)) === [];
            $years = [];
            foreach ($cells as $col => $v) {
                $y = $yearRow && (is_int($v) || is_float($v)) ? (int) $v : self::yearOf($v);
                if ($y !== null && $amountsBelow($r, (string) $col)) {
                    $years[$col] = ['column' => (string) $col, 'header' => (string) $v, 'year' => $y];
                }
            }
            if ($years) {
                $columns = array_values($years);
                $hit = array_values(array_filter($columns, fn ($c) => $c['year'] === $year));

                return ['columns' => $columns, 'column' => $hit[0]['column'] ?? $columns[0]['column'], 'column_certain' => count($hit) === 1, 'header_row' => $r];
            }
        }
        // No header: the columns holding numbers in two rows or more, except a note column (only small whole numbers).
        $numbers = [];
        foreach ($rows as $cells) {
            foreach ($cells as $col => $v) {
                $a = self::amount($v);
                if ($a['status'] === 'ok' && $a['amount'] !== null && ! (is_string($v) && trim($v) === '-')) {
                    $numbers[$col][] = $a['amount'];
                }
            }
        }
        $counts = [];
        foreach ($numbers as $col => $list) {
            $note = array_filter($list, fn ($n) => ! (floor($n) == $n && $n > 0 && $n <= 60)) === [];
            if (! $note) {
                $counts[$col] = count($list);
            }
        }
        $columns = array_map(fn ($c) => ['column' => $c, 'header' => '', 'year' => null], array_keys(array_filter($counts, fn ($n) => $n >= 2)));
        usort($columns, fn ($a, $b) => strlen($a['column']) <=> strlen($b['column']) ?: strcmp($a['column'], $b['column']));

        return ['columns' => $columns, 'column' => $columns[0]['column'] ?? null, 'column_certain' => false, 'header_row' => $first - 1];
    }

    private static function yearOf(mixed $v): ?int
    {
        if (! is_string($v)) {
            return null;
        }
        $v = self::westernDigits($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return (int) substr($v, 0, 4);
        }
        // A header names one year and nothing else numeric (a label like «2024م» or «31 ديسمبر 2024»).
        if (preg_match_all('/(?<!\d)(19\d{2}|20\d{2})(?!\d)/', $v, $m) === 1 && ! preg_match('/[\d,]{6,}/', str_replace($m[1][0], '', $v))) {
            return (int) $m[1][0];
        }

        return null;
    }

    /** The row's label: its first text with Arabic letters outside the amount columns. */
    private static function label(array $cells, array $amountColumns): ?string
    {
        foreach ($cells as $col => $v) {
            if (! in_array($col, $amountColumns, true) && is_string($v) && ImportNormalizer::isArabic($v)) {
                return preg_replace('/\s+/u', ' ', trim($v));
            }
        }

        return null;
    }

    /** @return array{amount: ?float, raw: string, status: string} */
    public static function amount(mixed $v): array
    {
        if ($v === null || $v === '') {
            return ['amount' => null, 'raw' => '', 'status' => 'empty'];
        }
        if (is_int($v) || is_float($v)) {
            return ['amount' => round((float) $v, 2), 'raw' => (string) $v, 'status' => 'ok'];
        }
        $raw = (string) $v;
        $s = trim(str_replace(["\u{00A0}", ' ', '٬', '،'], ['', '', ',', ','], self::westernDigits($raw)));
        $s = str_replace('٫', '.', $s);
        if (in_array($s, ['-', '–', '—', '_'], true)) {
            return ['amount' => 0.0, 'raw' => $raw, 'status' => 'ok'];
        }
        if (preg_match('/^(\()?(-)?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?(\))?$/', $s, $m) && (($m[1] ?? '') === '') === (($m[6] ?? '') === '')) {
            $n = (float) str_replace(',', '', $m[3].($m[5] ?? ''));

            return ['amount' => round(($m[1] === '(' || $m[2] === '-') ? -$n : $n, 2) + 0.0, 'raw' => $raw, 'status' => 'ok'];
        }

        return ['amount' => null, 'raw' => $raw, 'status' => 'unread'];
    }

    private static function westernDigits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /** Each row's proposed line or total of the statement, and how sure the wording makes it. */
    private static function match(array $rows, string $statement, LineCatalogue $catalogue, array $synonyms): array
    {
        $targets = array_map(fn ($w) => explode(' ', $w), StatementSynonyms::own($catalogue, $statement));

        return array_map(function ($r) use ($targets, $synonyms) {
            $words = explode(' ', StatementSynonyms::wording($r['label']));
            $r = array_diff_key($r, ['match' => 1, 'certainty' => 1]);
            $scores = [];
            // Equal wording: exact, unless two lines share it (the partners' current accounts in equity and in liabilities).
            $equal = array_keys(array_filter($targets, fn ($t) => $t === $words));
            if ($equal) {
                return $r + ['match' => $equal[0], 'certainty' => count($equal) === 1 ? 'exact' : 'probable'];
            }
            $synonym = $synonyms[implode(' ', $words)] ?? null;
            if ($synonym !== null && isset($targets[$synonym])) {
                return $r + ['match' => $synonym, 'certainty' => 'synonym'];
            }
            foreach ($targets as $key => $t) {
                $union = count(array_unique([...$words, ...$t]));
                $scores[$key] = $union ? count(array_intersect($words, $t)) / $union : 0.0;
            }
            arsort($scores);
            [$best, $second] = [array_slice($scores, 0, 1, true), array_values(array_slice($scores, 1, 1))[0] ?? 0.0];
            $key = array_key_first($best);
            if ($key !== null && $best[$key] >= self::PROBABLE && $best[$key] - $second >= self::MARGIN) {
                return $r + ['match' => $key, 'certainty' => 'probable'];
            }

            return $r + ['match' => null, 'certainty' => 'none'];
        }, $rows);
    }

    /**
     * Whether the file shows the income statement's expenses positive: every expense line matched (exactly or by a synonym), with an
     * amount in the proposed column, positive (null: no such line, or signs mixed).
     */
    private static function expensesPositive(array $blocks, LineCatalogue $catalogue): ?bool
    {
        $signs = [];
        foreach ($blocks as $b) {
            if ($b['statement'] !== 'comprehensive_income' || $b['column'] === null) {
                continue;
            }
            foreach ($b['rows'] as $r) {
                $a = $r['values'][$b['column']]['amount'] ?? null;
                if (in_array($r['certainty'], ['exact', 'synonym'], true) && str_starts_with($r['match'], 'is.') && $a !== null && abs($a) > 0.005
                    && self::isLine($catalogue, $r['match']) && $catalogue->side($r['match']) === 'debit') {   // a total has no side
                    $signs[$a > 0 ? 'positive' : 'negative'] = true;
                }
            }
        }

        return count($signs) === 1 ? isset($signs['positive']) : null;
    }

    public static function isLine(LineCatalogue $catalogue, string $key): bool
    {
        foreach ($catalogue->statement(explode('.', $key)[0] === 'sofp' ? 'financial_position' : (str_starts_with($key, 'cf.') ? 'cash_flows' : 'comprehensive_income')) as $i) {
            if (($i['key'] ?? null) === $key) {
                return $i['type'] === 'line';
            }
        }

        return false;
    }
}
