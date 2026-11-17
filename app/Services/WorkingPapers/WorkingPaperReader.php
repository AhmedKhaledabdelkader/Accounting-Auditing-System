<?php

namespace App\Services\WorkingPapers;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads an audit working paper (the firm's blank forms in resources/working-papers, or the client's filled copy) the way
 * its layout is built, never by cell addresses alone, so any paper laid out this way is understood:
 *
 *   rows 1–10   title (B2), purpose (B3), the entity and period fields (labels in B, values in D; a second block in G),
 *   then        questions (a label and its answer, «نعم / لا / لم يُحدد»), the coverage figures, the test steps (a code in B
 *               such as «أ» or «أ1», its text in C), the sample table (its header row starts «رقم العينة» or «م»; one row per
 *               sample, ending at the total row or the conclusion), and the conclusion («الخلاصة»).
 *
 * `read()` gives the paper's structure (what to tell the client); `analyse()` says how far a returned copy is filled, what
 * its own formulas found (differences, coverage), and what is left open. No AI: the paper's formulas do the arithmetic.
 */
final class WorkingPaperReader
{
    public const PLACEHOLDER = 'لم يُحدد';

    /** @var array<string, array> */
    private static array $cache = [];

    /** @return array{title: string, purpose: string, entity: array<string, ?string>, questions: list<string>, steps: list<array{code: string, text: string}>, columns: list<array{column: string, label: string, input: bool}>, sample_rows: int, sheets: list<string>} */
    public static function read(string $path): array
    {
        $key = $path.'|'.filemtime($path);

        return self::$cache[$key] ??= self::build($path);
    }

    private static function build(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $table = self::table($sheet);

        return [
            'title' => self::text($sheet->getCell('B2')->getValue()),
            'purpose' => self::text($sheet->getCell('B3')->getValue()),
            'entity' => self::entity($sheet),
            'questions' => self::questions($sheet),
            'steps' => self::steps($sheet, $table['header'] ?? PHP_INT_MAX),
            'columns' => $table['columns'] ?? [],
            'sample_rows' => count($table['rows'] ?? []),
            'first_row' => ($table['rows'] ?? [null])[0],
            'entity_cells' => self::entityCells($sheet),
            'sheets' => array_map(fn (Worksheet $s) => $s->getTitle(), array_filter($book->getAllSheets(), fn (Worksheet $s) => $s->getSheetState() === Worksheet::SHEETSTATE_VISIBLE)),
        ];
    }

    /**
     * How far a filled copy is done: the entity fields, the questions, the sample rows (used, and with what still open), and
     * the figures the paper computed (differences, coverage). Cells the paper calculates are read as calculated.
     *
     * @return array{filled: bool, entity_missing: list<string>, questions_open: list<string>, rows_total: int, rows_used: int,
     *               rows_incomplete: list<int>, differences: list<array{label: string, total: float, rows: list<int>}>, coverage: ?float, open_markers: int, conclusion_written: bool}
     */
    public static function analyse(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $table = self::table($sheet);
        $calc = function (string $coord) use ($sheet) {
            try {
                return $sheet->getCell($coord)->getCalculatedValue();
            } catch (\Throwable) {
                return null;
            }
        };

        $entity = array_keys(array_filter(self::entity($sheet), fn ($v) => $v === null));
        $questions = array_values(array_filter(self::questions($sheet, true)));

        $inputs = array_values(array_filter($table['columns'] ?? [], fn ($c) => $c['input']));
        $used = 0;
        $incomplete = [];
        foreach ($table['rows'] ?? [] as $n => $row) {
            $filled = 0;
            $open = 0;
            foreach ($inputs as $c) {
                $v = $calc($c['column'].$row);
                $empty = $v === null || $v === '' || $v === self::PLACEHOLDER;
                $filled += $empty ? 0 : 1;
                $open += $v === self::PLACEHOLDER ? 1 : 0;
            }
            // A row is used when something other than a dropdown's untouched placeholder was typed in it.
            if ($filled > 0) {
                $used++;
                if ($open > 0 || $filled < count($inputs) / 2) {
                    $incomplete[] = $n + 1;
                }
            }
        }

        $differences = [];
        foreach ($table['columns'] ?? [] as $c) {
            if (! preg_match('/الفرق|فروق|فرق/u', $c['label']) || $c['input']) {
                continue;
            }
            $rows = [];
            $total = 0.0;
            foreach ($table['rows'] ?? [] as $n => $row) {
                $v = $calc($c['column'].$row);
                if (is_numeric($v) && abs((float) $v) >= 0.005) {
                    $rows[] = $n + 1;
                    $total += (float) $v;
                }
            }
            if ($rows !== []) {
                $differences[] = ['label' => $c['label'], 'total' => round($total, 2), 'rows' => $rows];
            }
        }

        $coverage = null;
        $markers = 0;
        $conclusion = false;
        foreach ($sheet->getRowIterator() as $r) {
            $label = self::text($sheet->getCell('B'.$r->getRowIndex())->getValue());
            if ($label === 'نسبة التغطية') {
                $v = $calc('F'.$r->getRowIndex());
                $coverage = is_numeric($v) ? round((float) $v, 4) : null;
            }
            if (str_starts_with($label, 'الخلاصة')) {
                $text = self::text($sheet->getCell('B'.($r->getRowIndex() + 1))->getValue());
                $conclusion = $text !== '' && ! str_starts_with($text, '[');
            }
        }
        // Formulas that print «[أدخل …]» / «[أضف …]» say what the sheet still wants.
        foreach ($table['rows'] ?? [] as $row) {
            foreach ($table['columns'] ?? [] as $c) {
                $v = $calc($c['column'].$row);
                if (is_string($v) && str_starts_with($v, '[')) {
                    $markers++;
                }
            }
        }

        return ['filled' => $used > 0, 'entity_missing' => $entity, 'questions_open' => $questions, 'rows_total' => count($table['rows'] ?? []), 'rows_used' => $used,
            'rows_incomplete' => $incomplete, 'differences' => $differences, 'coverage' => $coverage, 'open_markers' => $markers, 'conclusion_written' => $conclusion];
    }

    /** @return array<string, string> the entity labels (as entity() gives them) → the cell their value goes in */
    private static function entityCells(Worksheet $sheet): array
    {
        $out = [];
        foreach (range(5, 9) as $r) {
            foreach ([['B', 'D'], ['G', 'I']] as [$l, $v]) {
                $label = self::text($sheet->getCell($l.$r)->getValue());
                $label !== '' && $out[$label] = $v.$r;
            }
        }

        return array_diff_key($out, array_flip(['العملة', 'وحدة العرض', 'أعدّه', 'تاريخ الإعداد', 'راجعه', 'تاريخ المراجعة', 'مرجع ورقة العمل']));
    }

    /** The entity and period fields: label in B, value in D (and a second block, label in G, value in I). */
    private static function entity(Worksheet $sheet): array
    {
        $out = [];
        foreach (range(5, 9) as $r) {
            foreach ([['B', 'D'], ['G', 'I']] as [$l, $v]) {
                $label = self::text($sheet->getCell($l.$r)->getValue());
                if ($label !== '') {
                    $value = self::text($sheet->getCell($v.$r)->getValue());
                    $out[$label] = $value === '' ? null : $value;
                }
            }
        }
        // Currency and unit have their own defaults on the paper; only the entity and the period are the client's to give.
        return array_diff_key($out, array_flip(['العملة', 'وحدة العرض', 'أعدّه', 'تاريخ الإعداد', 'راجعه', 'تاريخ المراجعة', 'مرجع ورقة العمل']));
    }

    /** The paper's own questions: the labels next to an answer cell (a dropdown of «نعم, لا, لم يُحدد»). */
    private static function questions(Worksheet $sheet, bool $openOnly = false): array
    {
        $out = [];
        foreach ($sheet->getDataValidationCollection() as $cell => $validation) {
            $options = (string) $validation->getFormula1();
            if (! str_contains($options, self::PLACEHOLDER) || ! preg_match('/^([A-Z]+)(\d+)$/', $cell, $m) || (int) $m[2] > 20) {
                continue;
            }
            $label = self::text($sheet->getCell(Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($m[1]) - 4).$m[2])->getValue());
            $answer = self::text($sheet->getCell($cell)->getValue());
            if ($label !== '') {
                $out[] = $openOnly ? ($answer === '' || $answer === self::PLACEHOLDER ? $label : '') : $label;
            }
        }

        return $out;
    }

    /** @return list<array{code: string, text: string}> */
    private static function steps(Worksheet $sheet, int $before): array
    {
        $out = [];
        foreach ($sheet->getRowIterator(11, min($before - 1, $sheet->getHighestRow())) as $r) {
            $code = self::text($sheet->getCell('B'.$r->getRowIndex())->getValue());
            $text = self::text($sheet->getCell('C'.$r->getRowIndex())->getValue());
            if ($text !== '' && preg_match('/^[\x{0621}-\x{064A}]\d?$/u', $code)) {
                $out[] = ['code' => $code, 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * The sample table: the header row (the one whose B says «رقم العينة» or «م»), the row of letters under it, and the sample
     * rows, to the total row. A column is an input when its first sample row holds no formula.
     *
     * @return array{header?: int, columns?: list<array{column: string, label: string, input: bool}>, rows?: list<int>}
     */
    private static function table(Worksheet $sheet): array
    {
        $header = null;
        foreach ($sheet->getRowIterator(11) as $r) {
            if (in_array(self::text($sheet->getCell('B'.$r->getRowIndex())->getValue()), ['رقم العينة', 'م', 'رقم الجزء'], true)) {
                $header = $r->getRowIndex();
                break;
            }
        }
        if ($header === null) {
            return [];
        }
        $last = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $first = null;
        for ($r = $header + 1; $r <= $header + 4; $r++) {
            $b = $sheet->getCell('B'.$r)->getValue();
            if (is_numeric($b)) {
                $first = $r;
                break;
            }
        }
        if ($first === null) {
            return ['header' => $header, 'columns' => [], 'rows' => []];
        }
        $rows = [];
        for ($r = $first; $r <= $sheet->getHighestRow(); $r++) {
            $b = $sheet->getCell('B'.$r)->getValue();
            if (! (is_numeric($b) || self::text($b) === '….')) {
                break;
            }
            $rows[] = $r;
        }
        $columns = [];
        for ($i = 2; $i <= $last; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $label = self::text(self::calculated($sheet, $col.$header));
            if ($label === '' || in_array($label, ['رقم العينة', 'رقم الجزء'], true)) {
                continue;
            }
            $columns[] = ['column' => $col, 'label' => str_replace("\n", ' ', $label), 'input' => ! $sheet->getCell($col.$first)->isFormula() && $sheet->getCell($col.$first)->getValue() !== null];
        }
        // A column holding the placeholder or empty in its first row, with no formula, is an input; text columns with
        // neither (e.g. the name of the customer) are inputs too.
        foreach ($columns as &$c) {
            $cell = $sheet->getCell($c['column'].$first);
            $c['input'] = ! $cell->isFormula();
        }
        unset($c);

        return ['header' => $header, 'columns' => $columns, 'rows' => $rows];
    }

    private static function calculated(Worksheet $sheet, string $coord): mixed
    {
        try {
            return $sheet->getCell($coord)->getCalculatedValue();
        } catch (\Throwable) {
            return $sheet->getCell($coord)->getValue();
        }
    }

    private static function text(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }
}
