<?php

namespace App\Services\Exports;

use App\Services\Statements\AccountNames;
use App\Services\Statements\LineCatalogue;
use App\Support\Amounts;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * The statements as an xlsx (PR 14, plan §4.7): the template's statements.xlsx filled from a version's payload, so the
 * same version always gives the same file (Q16). Every amount is a whole riyal (Q27), negatives in parentheses, zero «-»,
 * Western digits; a missing figure reads «[ناقص]», never 0. Totals keep the template's formulas (the sum of the rounded
 * lines, Q27) unless a part is missing. Lines, rows and columns zero in both years are hidden (Q13). A draft is marked
 * «مسودة» and lists what is missing and the checks it fails; every version carries the adjusting entries as journal
 * entries (Q39).
 */
final class StatementsXlsx
{
    private const FONT   = 'Sakkal Majalla';

    private string $missing;
    /** @var array<string, true> sheet!coord written as «[ناقص]» */
    private array $missingCells = [];
    /** @var array<string, true> sheet!coord of a comparative that doesn't apply (a first year) */
    private array $notApplicable = [];
    /** @var array<string, ?float> sheet!coord → the state's figure (null: missing) for every keyed amount cell */
    private array $known = [];

    /**
     * @param array      $payload  a version's payload (the report state, plus version and kind)
     * @param string     $savedAt  when the version was saved (ISO 8601): the file's dates
     * @param array<int, array> $byRow the run's rows, for accounts of older versions whose entries carry no names
     * @return string the xlsx file
     */
    public function build(array $payload, string $savedAt, array $byRow = []): string
    {
        $this->missing = __('exports.missing', [], 'ar');
        $this->missingCells = $this->notApplicable = $this->known = [];
        $config = config("financial_statements.templates.{$payload['template_version']}.xlsx")
            ?? throw new RuntimeException("No xlsx layout for {$payload['template_version']}");
        $book = IOFactory::load(resource_path("templates/{$payload['template_version']}/{$config['file']}"));
        $comparative = ($payload['comparative']['source'] ?? 'none') !== 'first_year';
        $draft = ($payload['kind'] ?? 'draft') !== 'final';
        $labels = new StateLabels($payload);

        $this->data($book, $config['data'], $payload);
        foreach ($config['statements'] as $statement => $layout) {
            $this->statement($book->getSheetByName($layout['sheet']), $layout, $payload['statements'][$statement] ?? [], $comparative, $payload);
        }
        $this->equity($book->getSheetByName($config['changes_in_equity']['sheet']), $config['changes_in_equity'], $payload['statements']['changes_in_equity'] ?? null, $comparative);
        $this->ppe($book->getSheetByName($config['ppe']['sheet']), $config['ppe'], $payload, $comparative);
        $this->settleFormulas($book);
        // The template's «[review]» comments are for whoever reviews the template, never in a file (template README).
        foreach ($book->getAllSheets() as $sheet) {
            $sheet->setComments([]);
        }

        $this->journal($book, $payload, $labels, $byRow);
        if ($draft) {
            $this->markDraft($book, $config['cover'], $payload, $savedAt, $labels);
        }

        $at = CarbonImmutable::parse($savedAt)->getTimestamp();
        $book->getProperties()->setCreator('')->setLastModifiedBy('')->setCreated($at)->setModified($at)
            ->setTitle(__('exports.filename', [], 'ar'))->setCompany('')->setManager('');
        $book->setActiveSheetIndex($book->getIndex($draft ? $book->getSheetByName(__('exports.draft.sheet', [], 'ar')) : $book->getSheetByName($config['cover']['sheet'])));

        return $this->write($book, $at);
    }

    /* ---------- data ---------- */

    private function data(Spreadsheet $book, array $layout, array $p): void
    {
        $sheet = $book->getSheetByName($layout['sheet']);
        $notes = array_filter($p['notes'] ?? [], fn ($n) => $n['visible']);
        $ppe = collect($notes)->firstWhere('key', 'ppe');
        // No comparative dates given: the full year before this one (as the report reads them).
        $cmpEnd = $p['comparative_end'] ?? CarbonImmutable::parse($p['period_start'])->subDay()->toDateString();
        $cmpStart = $p['comparative_start'] ?? CarbonImmutable::parse($cmpEnd)->subYear()->addDay()->toDateString();
        $first = ($p['comparative']['source'] ?? null) === 'first_year';
        $values = [
            'company_name'      => $p['company']['name_ar'] ?? $this->missing,
            'city'              => ($p['company']['city'] ?? null) ?: $this->missing,
            'period_start'      => $this->date($p['period_start']),
            'period_end'        => $this->date($p['period_end']),
            'comparative_start' => $first ? null : $this->date($cmpStart),
            'comparative_end'   => $first ? null : $this->date($cmpEnd),
            'has_auditor_report' => __('exports.no', [], 'ar'),   // Q28: out of scope
            'last_note_number'  => $notes ? max(array_column($notes, 'number')) : '',
            'ppe_note_number'   => $ppe['number'] ?? '',
            'overdraft_in_cash' => __(($p['inputs']['cash_flow.overdraft_in_cash'] ?? false) === true ? 'exports.yes' : 'exports.no', [], 'ar'),
        ];
        foreach ($this->keyedRows($sheet, $layout['key']) as $key => $row) {
            if (array_key_exists($key, $values)) {
                $sheet->setCellValue("{$layout['value']}{$row}", $values[$key]);
            }
        }
    }

    private function date(?string $iso): ?float
    {
        return $iso ? ExcelDate::PHPToExcel(CarbonImmutable::parse($iso)->startOfDay()) : null;
    }

    /* ---------- the three line statements ---------- */

    private function statement(Worksheet $sheet, array $layout, array $rows, bool $comparative, array $p): void
    {
        $byKey = array_column(array_filter($rows, fn ($r) => isset($r['key'])), null, 'key');
        $keyed = $this->keyedRows($sheet, $layout['key']);
        $hidden = [];
        foreach ($keyed as $key => $row) {
            $sheet->getStyle("{$layout['current']}{$row}:{$layout['current']}{$row}")->getNumberFormat()->setFormatCode(Amounts::xlsxFormat());
            $sheet->getStyle("{$layout['comparative']}{$row}:{$layout['comparative']}{$row}")->getNumberFormat()->setFormatCode(Amounts::xlsxFormat());
            $r = $byKey[$key] ?? null;
            if ($r === null) {
                continue;   // footer, checks: as the template has them
            }
            $this->amount($sheet, "{$layout['current']}{$row}", $r['current'] ?? null, $r['shown']['current'] ?? null);
            if ($comparative) {
                $this->amount($sheet, "{$layout['comparative']}{$row}", $r['comparative'] ?? null, $r['shown']['comparative'] ?? null);
            } else {
                $this->notApplicable($sheet, "{$layout['comparative']}{$row}");
            }
            $sheet->setCellValue("{$layout['note']}{$row}", ($r['note'] ?? null) ?: null);
            if (($r['label_by_sign'] ?? false) || ($r['label_set'] ?? false)) {
                $sheet->setCellValue("{$layout['label']}{$row}", $r['label_ar']);   // profit or loss by the figures' sign; a label of its own (unexplained)
            }
            $zero = ShownRows::zero($r, $comparative);
            $hidden[$row] = $zero;
            $sheet->getRowDimension($row)->setVisible(! $zero);
        }
        $this->headings($sheet, $layout, $keyed, $byKey, $hidden);

        foreach ($layout['comparative_columns'] as $c) {
            $sheet->getColumnDimension($c)->setVisible($comparative);
        }
        if ($comparative && ($p['comparative']['restated'] ?? false)) {
            $cell = $sheet->getCell($layout['comparative_header']);
            $cell->setValue($cell->getValue().'&"'.__('exports.restated', [], 'ar').'"');
        }
    }

    /**
     * A heading shows when a line under it shows (ShownRows' rule, on the template's rows). Its lines run to the total
     * that closes it (the innermost open heading), or to the end of the statement.
     */
    private function headings(Worksheet $sheet, array $layout, array $keyed, array $byKey, array $hidden): void
    {
        $first = 1;   // the titles above are formulas; the first heading sits above the first line
        $last = max(array_map(fn ($k, $r) => isset($byKey[$k]) ? $r : 0, array_keys($keyed), $keyed));
        $keyOf = array_flip($keyed);
        $open = [];
        $scopes = [];
        for ($row = $first; $row <= $last; $row++) {
            $key = $keyOf[$row] ?? null;
            if ($key === null) {
                $label = $sheet->getCell("{$layout['label']}{$row}")->getValue();
                if (is_string($label) && trim($label) !== '' && ! str_starts_with($label, '=')) {
                    $open[] = $row;
                    $scopes[$row] = [];
                }
                continue;
            }
            $type = $byKey[$key]['type'] ?? null;
            foreach ($open as $h) {
                if ($type === 'line') {
                    $scopes[$h][] = $row;
                }
            }
            if ($type === 'total' && $open) {
                array_pop($open);
            }
        }
        foreach ($scopes as $h => $lines) {
            $sheet->getRowDimension($h)->setVisible(array_filter($lines, fn ($r) => ! $hidden[$r]) !== []);
        }
    }

    /* ---------- changes in equity ---------- */

    private function equity(Worksheet $sheet, array $layout, ?array $soce, bool $comparative): void
    {
        if ($soce === null) {
            return;
        }
        $columns = $this->keyedColumns($sheet, $layout['columns_row']);
        $used = array_flip(array_column($soce['columns'], 'key'));
        $rowsByScope = ['current' => array_column($soce['current'] ?? [], null, 'key'), 'comparative' => array_column($soce['comparative'] ?? [], null, 'key')];
        $zeroRow = [];
        $zeroCol = array_fill_keys(array_keys($columns), true);
        foreach ($this->keyedRows($sheet, $layout['key']) as $key => $row) {
            if (! preg_match('/^soce\.(current|comparative)\.(\w+)$/', $key, $m)) {
                continue;
            }
            [, $scope, $rowKey] = $m;
            $applies = $scope === 'current' || $comparative;
            $cells = $rowsByScope[$scope][$rowKey]['cells'] ?? null;
            if ((($rowsByScope[$scope][$rowKey]['label_by_sign'] ?? false) || ($rowsByScope[$scope][$rowKey]['label_set'] ?? false)) && isset($layout['label'])) {
                $sheet->setCellValue("{$layout['label']}{$row}", $rowsByScope[$scope][$rowKey]['label_ar']);   // profit or loss by the year's sign; a label of its own
            }
            foreach ($columns as $colKey => $col) {
                if (! isset($used[$colKey])) {
                    continue;
                }
                if (! $applies) {
                    $this->notApplicable($sheet, "{$col}{$row}");
                    continue;
                }
                $f = $cells[$colKey] ?? null;
                $this->amount($sheet, "{$col}{$row}", $f, $rowsByScope[$scope][$rowKey]['shown'][$colKey] ?? null);
                if (! $this->isZero($f)) {
                    $zeroRow[$scope][$rowKey] = false;
                    if ($colKey !== 'total') {
                        $zeroCol[$colKey] = false;
                    }
                }
            }
            $zeroRow[$scope][$rowKey] ??= true;
            if (! $applies) {
                $sheet->getRowDimension($row)->setVisible(false);
            }
        }
        // A movement row zero in its own year's table is hidden there (the accountant's review, note 3: no empty line, even where
        // the other year has the movement); so is a column zero in both years (the total always shows).
        foreach ($this->keyedRows($sheet, $layout['key']) as $key => $row) {
            if (preg_match('/^soce\.(current|comparative)\.(\w+)$/', $key, $m) && ($m[1] === 'current' || $comparative)) {
                $sheet->getRowDimension($row)->setVisible(in_array($m[2], $layout['always'], true) || ! ($zeroRow[$m[1]][$m[2]] ?? true));
            }
        }
        foreach ($columns as $colKey => $col) {
            if ($colKey === 'total') {
                $sheet->getColumnDimension($col)->setVisible(true);
                continue;
            }
            $visible = isset($used[$colKey]) && ! $zeroCol[$colKey];
            $sheet->getColumnDimension($col)->setVisible($visible);
            if (isset($layout['spacers'][$col])) {
                $sheet->getColumnDimension($layout['spacers'][$col])->setVisible($visible);
            }
        }
    }

    /* ---------- the PPE note ---------- */

    private function ppe(Worksheet $sheet, array $layout, array $p, bool $comparative): void
    {
        $note = collect($p['notes'] ?? [])->firstWhere('key', $layout['note']);
        $schedule = collect($p['schedules'] ?? [])->firstWhere('key', $layout['schedule']);
        if (! ($note['visible'] ?? false) || $schedule === null) {
            $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

            return;
        }
        $sets = $p['narrative']['columns']['ppe_category'] ?? null;
        if ($sets !== null) {
            $this->ppeByCategory($sheet, $layout, $sets, $schedule, $comparative);

            return;
        }
        // A version saved before Phase 9: the template's own categories from the schedule.
        $columns = $this->keyedColumns($sheet, $layout['columns_row']);
        $used = array_flip(array_column($schedule['columns'], 'key'));
        $rows = array_column($schedule['rows'], null, 'key');
        $zeroCol = array_fill_keys(array_keys($columns), true);
        foreach ($this->keyedRows($sheet, $layout['key']) as $key => $row) {
            if (! str_starts_with($key, "{$layout['schedule']}.")) {
                continue;
            }
            $name = substr($key, strlen($layout['schedule']) + 1);
            $source = $rows[$layout['rows'][$name] ?? $name] ?? null;
            $applies = $comparative || ! in_array($name, $layout['comparative_rows'], true);
            $zero = true;
            foreach ($columns as $colKey => $col) {
                if (! isset($used[$colKey]) || $source === null) {
                    continue;
                }
                if (! $applies) {
                    $this->notApplicable($sheet, "{$col}{$row}");
                    continue;
                }
                $f = $source['cells'][$colKey] ?? null;
                $this->amount($sheet, "{$col}{$row}", $f);
                if (! $this->isZero($f)) {
                    $zero = false;
                    if ($colKey !== 'total') {
                        $zeroCol[$colKey] = false;
                    }
                }
            }
            $sheet->getRowDimension($row)->setVisible($applies && ($source !== null) && (! $zero || in_array($name, $layout['always'], true)));
        }
        foreach ($columns as $colKey => $col) {
            $sheet->getColumnDimension($col)->setVisible($colKey === 'total' || (isset($used[$colKey]) && ! $zeroCol[$colKey]));
        }
    }

    /**
     * The PPE sheet by the note's columns (NotesBuilder: last year's categories as printed, R13, or the note's own): one
     * column per category, the template's category columns taken in order and more inserted before its last when there
     * are more (the totals' ranges grow with them), the rest hidden; the total column the schedule's; a category or row
     * zero in both years hidden (D4).
     */
    private function ppeByCategory(Worksheet $sheet, array $layout, array $sets, array $schedule, bool $comparative): void
    {
        $columns = $this->keyedColumns($sheet, $layout['columns_row']);
        $total = $columns['total'];
        $slots = array_values(array_diff_key($columns, ['total' => 1]));
        $extra = count($sets) - count($slots);
        if ($extra > 0) {
            $last = end($slots);
            $sheet->insertNewColumnBefore($last, $extra);
            $first = Coordinate::columnIndexFromString($slots[0]);
            $slots = array_map(fn ($i) => Coordinate::stringFromColumnIndex($first + $i), range(0, count($sets) - 1));
            $total = Coordinate::stringFromColumnIndex($first + count($sets));
            // The inserted columns: the first category column's styles, width and per-column formulas.
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                // Read before another cell is fetched (PhpSpreadsheet detaches a cell object then).
                $style = $sheet->getStyle("{$slots[0]}{$row}");
                $formula = $sheet->getCell("{$slots[0]}{$row}")->isFormula() ? (string) $sheet->getCell("{$slots[0]}{$row}")->getValue() : null;
                foreach (array_slice($slots, count($slots) - $extra - 1, $extra) as $col) {
                    $sheet->duplicateStyle($style, "{$col}{$row}");
                    if ($formula !== null) {
                        $sheet->setCellValue("{$col}{$row}", preg_replace('/(?<![A-Z$])'.$slots[0].'(\d+)/', $col.'$1', $formula));
                    }
                }
            }
            foreach (array_slice($slots, count($slots) - $extra - 1, $extra) as $col) {
                $sheet->getColumnDimension($col)->setWidth($sheet->getColumnDimension($slots[0])->getWidth());
            }
            $area = $sheet->getPageSetup()->getPrintArea();
            $sheet->getPageSetup()->setPrintArea((string) preg_replace('/(:\$?)[A-Z]+(\$?\d+)$/', '${1}'.$total.'$2', $area))->setFitToWidth(1)->setFitToHeight(0);
        }
        $rows = array_column($schedule['rows'], null, 'key');
        $zeroCol = array_fill(0, count($slots), true);
        foreach ($sets as $i => $set) {
            $sheet->setCellValueExplicit("{$slots[$i]}{$layout['columns_row']}", 'c'.($i + 1), DataType::TYPE_STRING);
        }
        foreach ($this->keyedRows($sheet, $layout['key']) as $key => $row) {
            if (! str_starts_with($key, "{$layout['schedule']}.")) {
                continue;
            }
            $name = substr($key, strlen($layout['schedule']) + 1);
            $applies = $comparative || ! in_array($name, $layout['comparative_rows'], true);
            $zero = true;
            foreach ($slots as $i => $col) {
                if (! isset($sets[$i])) {
                    continue;
                }
                if (! $applies) {
                    $this->notApplicable($sheet, "{$col}{$row}");
                    continue;
                }
                $f = $sets[$i]["ppe_cy_{$name}_c"] ?? null;
                $this->amount($sheet, "{$col}{$row}", $f);
                if (! $this->isZero($f)) {
                    $zero = $zeroCol[$i] = false;
                }
            }
            $source = $rows[$layout['rows'][$name] ?? $name] ?? null;
            if ($applies) {
                $this->amount($sheet, "{$total}{$row}", $source['cells']['total'] ?? null);
            } else {
                $this->notApplicable($sheet, "{$total}{$row}");
            }
            $sheet->getRowDimension($row)->setVisible($applies && (! $zero || in_array($name, $layout['always'], true)));
        }
        foreach ($slots as $i => $col) {
            $sheet->getColumnDimension($col)->setVisible(isset($sets[$i]) && ! $zeroCol[$i]);
            if (isset($sets[$i])) {
                $sheet->setCellValueExplicit("{$col}{$layout['header_row']}", (string) $sets[$i]['ppe_category'], DataType::TYPE_STRING);
            }
        }
        $sheet->getColumnDimension($total)->setVisible(true);
    }

    /* ---------- cells ---------- */

    /**
     * A figure as a whole riyal (the state's settled one when given: WholeRiyals), or «[ناقص]»; the template's formula
     * stays when the figure is there (a total: the sum of the lines written, so the shown total).
     */
    private function amount(Worksheet $sheet, string $coord, ?array $f, ?int $shown = null): void
    {
        $cell = $sheet->getCell($coord);
        $id = $this->id($sheet, $coord);
        $amount = ($f['amount'] ?? null) === null ? null : ($shown ?? $f['amount']);
        $this->known[$id] = $amount === null ? null : (float) round($amount);
        $cell->getStyle()->getNumberFormat()->setFormatCode(Amounts::xlsxFormat());
        if ($amount === null) {
            $cell->setValueExplicit($this->missing, DataType::TYPE_STRING);
            $this->missingCells[$id] = true;

            return;
        }
        if (! $cell->isFormula()) {
            $cell->setValue((int) round($amount));
        }
    }

    private function notApplicable(Worksheet $sheet, string $coord): void
    {
        $sheet->getCell($coord)->setValue(null);
        $this->notApplicable[$this->id($sheet, $coord)] = true;
    }

    /**
     * A formula that reads a missing figure can't stand: a total of the state is written as its figure, or «[ناقص]» (and
     * so on for what reads it). One that reads a comparative that doesn't apply (a first year) is written as the figure.
     */
    private function settleFormulas(Spreadsheet $book): void
    {
        $formulas = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCoordinates() as $coord) {
                $cell = $sheet->getCell($coord);
                if ($cell->isFormula()) {
                    $formulas[$this->id($sheet, $coord)] = [$sheet, $coord, $this->references((string) $cell->getValue(), $sheet->getTitle())];
                }
            }
        }
        do {
            $changed = false;
            foreach ($formulas as $id => [$sheet, $coord, $refs]) {
                $readsMissing = array_intersect_key($refs, $this->missingCells) !== [];
                $readsNa = array_intersect_key($refs, $this->notApplicable) !== [];
                if (! $readsMissing && ! $readsNa) {
                    continue;
                }
                $cell = $sheet->getCell($coord);
                if (array_key_exists($id, $this->known) && $this->known[$id] !== null) {
                    $cell->setValue((int) $this->known[$id]);
                } elseif ($readsMissing) {
                    $cell->setValueExplicit($this->missing, DataType::TYPE_STRING);
                    $this->missingCells[$id] = true;
                } else {
                    continue;   // a check or a link outside the statements: the blank reads as nothing
                }
                unset($formulas[$id]);
                $changed = true;
            }
        } while ($changed);
    }

    /** @return array<string, true> sheet!coord of every cell a formula reads */
    private function references(string $formula, string $sheet): array
    {
        $formula = preg_replace('/"[^"]*"/u', '""', $formula);
        preg_match_all("/(?:(?:'((?:[^']|'')+)'|([A-Za-z_][A-Za-z0-9_]*))!)?\\$?([A-Z]{1,3})\\$?(\\d+)(?::\\$?([A-Z]{1,3})\\$?(\\d+))?(?![A-Za-z0-9_(])/u", $formula, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $ref) {
            $on = ($ref[1] ?? '') !== '' ? str_replace("''", "'", $ref[1]) : (($ref[2] ?? '') !== '' ? $ref[2] : $sheet);
            $range = isset($ref[5]) && $ref[5] !== '' ? "{$ref[3]}{$ref[4]}:{$ref[5]}{$ref[6]}" : "{$ref[3]}{$ref[4]}";
            foreach (Coordinate::extractAllCellReferencesInRange($range) as $c) {
                $out["{$on}!{$c}"] = true;
            }
        }

        return $out;
    }

    private function isZero(?array $f): bool
    {
        return ShownRows::isZero($f);
    }

    private function id(Worksheet $sheet, string $coord): string
    {
        return $sheet->getTitle().'!'.$coord;
    }

    /** @return array<string, int> key → row, from a sheet's key column */
    private function keyedRows(Worksheet $sheet, string $column): array
    {
        $out = [];
        foreach ($sheet->getColumnIterator($column, $column)->current()->getCellIterator() as $cell) {
            $v = $cell->getValue();
            if (is_string($v) && $v !== '' && $v !== 'key' && ! isset($out[$v])) {
                $out[$v] = $cell->getRow();
            }
        }

        return $out;
    }

    /** @return array<string, string> key → column, from a sheet's key row */
    private function keyedColumns(Worksheet $sheet, int $row): array
    {
        $out = [];
        foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
            $v = $cell->getValue();
            if (is_string($v) && $v !== '') {
                $out[$v] = $cell->getColumn();
            }
        }

        return $out;
    }

    /* ---------- journal entries (Q39) ---------- */

    private function journal(Spreadsheet $book, array $p, StateLabels $labels, array $byRow): void
    {
        $t = fn (string $k, array $r = []) => __("exports.journal.{$k}", $r, 'ar');
        $sheet = $this->newSheet($book, $t('sheet'));
        $sheet->setCellValue('A1', $p['company']['name_ar'] ?? $this->missing);
        $sheet->setCellValue('A2', $t('title', ['date' => $this->longDate($p['period_end'])]));
        $sheet->setCellValue('A3', $t('hint'));
        foreach (['A1', 'A2'] as $c) {
            $sheet->getStyle($c)->getFont()->setBold(true)->setSize(16);
        }
        $sheet->getStyle('A3')->getFont()->setItalic(true);
        $entries = $p['adjustments'] ?? [];
        if ($entries === []) {
            $sheet->setCellValue('A5', $t('none'));
            $sheet->getPageSetup()->setPrintArea('A1:E5');
            $this->approvedOpeningEntry($sheet, $p, 7, $labels);

            return;
        }
        $head = 5;
        foreach (['A' => 'col_entry', 'B' => 'col_account', 'C' => 'col_debit', 'D' => 'col_credit', 'E' => 'col_reason', 'F' => 'col_difference'] as $c => $k) {
            $sheet->setCellValue("{$c}{$head}", $t($k));
        }
        $this->headerStyle($sheet, "A{$head}:F{$head}");
        $row = $head + 1;
        $totals = [];
        foreach ($entries as $e) {
            $start = $row;
            foreach ($e['lines'] as $l) {
                $account = isset($l['row'])
                    ? AccountNames::one(isset($l['account_number']) || isset($l['account_name']) ? $l : ($byRow[$l['row']] ?? null))
                    : $t('line', ['line' => $labels->line($l['line_key'] ?? null) ?? __('financial_reports.ui.unknown', [], 'ar')]);
                $sheet->setCellValue("A{$row}", $e['number']);
                $sheet->setCellValueExplicit("B{$row}", $account, DataType::TYPE_STRING);
                $sheet->setCellValue("C{$row}", (float) $l['debit'] > 0 ? round((float) $l['debit'], 2) : null);
                $sheet->setCellValue("D{$row}", (float) $l['credit'] > 0 ? round((float) $l['credit'], 2) : null);
                $sheet->setCellValueExplicit("E{$row}", $e['reason'], DataType::TYPE_STRING);
                $row++;
            }
            $end = $row - 1;
            $sheet->setCellValue("B{$row}", $t('entry_total', ['n' => $e['number']]));
            $sheet->setCellValue("C{$row}", "=SUM(C{$start}:C{$end})");
            $sheet->setCellValue("D{$row}", "=SUM(D{$start}:D{$end})");
            $sheet->setCellValue("F{$row}", "=ROUND(C{$row}-D{$row},2)");
            $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:F{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $totals[] = $row;
            $row += 2;
        }
        $sheet->setCellValue("B{$row}", $t('grand_total'));
        $sheet->setCellValue("C{$row}", '='.implode('+', array_map(fn ($r) => "C{$r}", $totals)));
        $sheet->setCellValue("D{$row}", '='.implode('+', array_map(fn ($r) => "D{$r}", $totals)));
        $sheet->setCellValue("F{$row}", "=ROUND(C{$row}-D{$row},2)");
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:F{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
        $sheet->setCellValue('B'.($row + 2), $t('balanced'));
        $sheet->setCellValue('C'.($row + 2), '=IF(AND('.implode(',', array_map(fn ($r) => "F{$r}=0", $totals)).'),"'.__('exports.yes', [], 'ar').'","'.__('exports.no', [], 'ar').'")');
        $sheet->getStyle('B'.($row + 2).':C'.($row + 2))->getFont()->setBold(true);
        $sheet->getStyle("C".($head + 1).":D{$row}")->getNumberFormat()->setFormatCode(Amounts::xlsxFormat(2));
        $sheet->getStyle("F".($head + 1).":F{$row}")->getNumberFormat()->setFormatCode(Amounts::xlsxFormat(2));
        foreach (['A' => 12, 'B' => 48, 'C' => 18, 'D' => 18, 'E' => 60, 'F' => 22] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        $sheet->getStyle("E".($head + 1).":E{$row}")->getAlignment()->setWrapText(true);
        $sheet->getPageSetup()->setPrintArea("A1:E{$row}");
        $this->approvedOpeningEntry($sheet, $p, $row + 4, $labels);
    }

    /**
     * Step B, for information only: with this year's openings from last year's approved statements, the trial balance's
     * openings differ from them; the entry that would bring the books to them, line by line (already applied in the
     * report: not an adjusting entry, not in the totals above, outside the print area).
     */
    private function approvedOpeningEntry(Worksheet $sheet, array $p, int $row, StateLabels $labels): void
    {
        $a = $p['comparative']['approved_opening'] ?? null;
        $lines = array_values(array_filter($a['lines'] ?? [], fn ($l) => ($l['kind'] ?? 'equal') !== 'equal'));
        if (! ($a['applied'] ?? false) || $lines === []) {
            return;
        }
        $t = fn (string $k, array $r = []) => __("exports.journal.approved_opening.{$k}", $r, 'ar');
        $catalogue = new LineCatalogue($p['template_version'] ?? 'sa-ifrs-smes-3');
        $sheet->setCellValue("A{$row}", $t('title'));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A'.($row + 1), $t('hint'));
        $sheet->getStyle('A'.($row + 1))->getFont()->setItalic(true);
        $head = $row + 3;
        foreach (['B' => 'col_line', 'C' => 'col_debit', 'D' => 'col_credit', 'E' => 'col_reason'] as $c => $k) {
            $sheet->setCellValue("{$c}{$head}", $t($k));
        }
        $this->headerStyle($sheet, "A{$head}:F{$head}");
        $row = $head + 1;
        $first = $row;
        foreach ($lines as $l) {
            // The trial balance's opening − the approved closing, on the line's side: the books move it back by as much.
            $d = (float) $l['difference'];
            $debit = ($catalogue->side($l['line']) === 'debit') === ($d < 0);
            $sheet->setCellValueExplicit("B{$row}", $labels->line($l['line']) ?? $l['label'], DataType::TYPE_STRING);
            $sheet->setCellValue(($debit ? 'C' : 'D').$row, round(abs($d), 2));
            $sheet->setCellValueExplicit("E{$row}", $t('reason', ['tb' => number_format((float) $l['tb_opening'], 2), 'approved' => number_format((float) $l['approved'], 2)]), DataType::TYPE_STRING);
            $row++;
        }
        $last = $row - 1;
        $sheet->setCellValue("B{$row}", $t('total'));
        $sheet->setCellValue("C{$row}", "=SUM(C{$first}:C{$last})");
        $sheet->setCellValue("D{$row}", "=SUM(D{$first}:D{$last})");
        $sheet->setCellValue("F{$row}", "=ROUND(C{$row}-D{$row},2)");
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:F{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("C{$first}:D{$row}")->getNumberFormat()->setFormatCode(Amounts::xlsxFormat(2));
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(Amounts::xlsxFormat(2));
        $sheet->getStyle("E{$first}:E{$row}")->getAlignment()->setWrapText(true);
    }

    /* ---------- a draft ---------- */

    private function markDraft(Spreadsheet $book, array $cover, array $p, string $savedAt, StateLabels $labels): void
    {
        $t = fn (string $k, array $r = []) => __("exports.draft.{$k}", $r, 'ar');
        $coverSheet = $book->getSheetByName($cover['sheet']);
        [$from, $to] = explode(':', $cover['columns']);
        $at = "{$from}{$cover['draft_row']}";
        $coverSheet->mergeCells("{$at}:{$to}{$cover['draft_row']}");
        $coverSheet->setCellValue($at, $t('mark'));
        $coverSheet->getStyle($at)->getFont()->setBold(true)->setSize(24)->setName(self::FONT)->getColor()->setRGB('C00000');
        $coverSheet->getStyle($at)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // The draft's notes, right after the template's sheets (opened first): what blocks a final, what is missing, the
        // checks it fails.
        $sheet = $this->newSheet($book, $t('sheet'), $book->getIndex($book->getSheetByName(__('exports.journal.sheet', [], 'ar'))));
        $sheet->getTabColor()->setRGB('C00000');
        $sheet->setCellValue('A1', $t('title'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB('C00000');
        $sheet->setCellValue('A2', $t('version', ['n' => $p['version'] ?? '', 'date' => CarbonImmutable::parse($savedAt)->format('Y-m-d H:i')]));
        $row = 4;
        $blocking = $p['blocking'] ?? [];
        $sheet->setCellValue("A{$row}", $blocking ? $t('blocking') : $t('nothing_blocks'));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        foreach ($blocking as $b) {
            $sheet->setCellValueExplicit('A'.++$row, "• {$b['label_ar']} ({$b['count']})", DataType::TYPE_STRING);
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", $t('missing_title'));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $missing = $p['missing'] ?? [];
        if ($missing === []) {
            $sheet->setCellValue('A'.++$row, $t('missing_none'));
        } else {
            $row++;
            $sheet->setCellValue("A{$row}", $t('col_item'));
            $sheet->setCellValue("B{$row}", $t('col_reason'));
            $this->headerStyle($sheet, "A{$row}:B{$row}");
            foreach ($missing as $m) {
                [$what, $why] = $labels->missing($m);
                $row++;
                $sheet->setCellValueExplicit("A{$row}", $what, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$row}", $why, DataType::TYPE_STRING);
            }
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", $t('checks_title'));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $failing = array_values(array_filter($p['checks'] ?? [], fn ($c) => $c['severity'] === 'error' || ($c['severity'] === 'warning' && ! ($c['acknowledged'] ?? false))));
        usort($failing, fn ($a, $b) => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1));
        if ($failing === []) {
            $sheet->setCellValue('A'.++$row, $t('checks_none'));
        } else {
            $row++;
            $sheet->setCellValue("A{$row}", $t('col_check'));
            $sheet->setCellValue("B{$row}", $t('col_severity'));
            $sheet->setCellValue("C{$row}", $t('col_details'));
            $this->headerStyle($sheet, "A{$row}:C{$row}");
            foreach ($failing as $c) {
                $row++;
                $title = $c['title_ar'].(($c['scope'] ?? 'current') === 'comparative' ? ' ('.StateLabels::name('scopes.comparative').')' : '');
                $sheet->setCellValueExplicit("A{$row}", $title, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$row}", StateLabels::name("severities.{$c['severity']}") ?? '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$row}", $c['message_ar'], DataType::TYPE_STRING);
                $sheet->getStyle("B{$row}")->getFont()->getColor()->setRGB($c['severity'] === 'error' ? 'C00000' : 'B45F06');
            }
        }
        foreach (['A' => 60, 'B' => 40, 'C' => 90] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        $sheet->getStyle("A1:C{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('A1:A2')->getAlignment()->setWrapText(false);

        // Every printed page says so.
        foreach ($book->getAllSheets() as $each) {
            $each->getHeaderFooter()->setOddHeader('&C&"'.self::FONT.',Bold"&20&KC00000'.$t('header'));
            $each->getTabColor()->setRGB('C00000');
        }
    }

    /* ---------- helpers ---------- */

    private function newSheet(Spreadsheet $book, string $title, ?int $at = null): Worksheet
    {
        $sheet = new Worksheet($book, $title);
        $book->addSheet($sheet, $at);
        $sheet->setRightToLeft(true);
        $sheet->getStyle('A1:F400')->getFont()->setName(self::FONT)->setSize(14);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0);

        return $sheet;
    }

    private function headerStyle(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('002060');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function longDate(string $iso): string
    {
        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $d = CarbonImmutable::parse($iso);

        return $d->day.' '.$months[$d->month - 1].' '.$d->year.'م';
    }

    /** Written with fixed times inside the zip, so the same version always gives the same bytes (Q16). */
    private function write(Spreadsheet $book, int $at): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fs-xlsx-');
        try {
            (new Xlsx($book))->save($path);
            $zip = new ZipArchive;
            $zip->open($path);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zip->setMtimeIndex($i, $at);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
