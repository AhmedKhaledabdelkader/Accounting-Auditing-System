<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;
use ZipArchive;

/**
 * Reads every sheet into raw cells without interpreting the layout.
 * Structure is decided later by the AI; this class only keeps what's in the file.
 */
class SpreadsheetReader
{
    /** @var array<int, array<int, true>> sheet => row => true, for rows holding SUBTOTAL/AGGREGATE formulas (set by read()). */
    private array $subtotalRows = [];

    /**
     * @return array<int, array<int, array<string, int|float|string>>>
     *         sheet number (1-based) => row number (1-based, as in Excel) => column letter => value.
     *         Empty cells, rows and sheets are dropped.
     */
    public function read(UploadedFile $file): array
    {
        $path   = $file->getRealPath();
        $reader = IOFactory::createReader(IOFactory::identify($path));
        $reader->setReadDataOnly(true);   // values only: no styles, far less memory
        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);   // Arabic CSVs are often Windows-1256
        }

        $sheets             = [];
        $this->subtotalRows = [];
        foreach ($reader->load($path)->getWorksheetIterator() as $index => $worksheet) {
            $sheet = [];
            foreach ($worksheet->getRowIterator() as $row) {
                $cells    = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(true);
                foreach ($iterator as $cell) {
                    if ($cell->isFormula() && preg_match('/\b(SUBTOTAL|AGGREGATE)\s*\(/i', (string) $cell->getValue())) {
                        $this->subtotalRows[$index + 1][$row->getRowIndex()] = true;
                    }
                    $value = $this->cellValue($cell);
                    if (is_string($value)) {
                        $value = trim($value);
                    }
                    if ($value === null || $value === '' || is_bool($value)) {
                        continue;
                    }
                    $cells[$cell->getColumn()] = $value;
                }
                if ($cells) {
                    $sheet[$row->getRowIndex()] = $cells;
                }
            }
            if ($sheet) {
                $sheets[$index + 1] = $sheet;
            }
        }

        return $sheets;
    }

    /**
     * Formulas use the value Excel saved in the file. Recalculating is avoided on purpose:
     * PhpSpreadsheet's engine can exhaust memory on real workbooks (e.g. many SUBTOTAL ranges).
     * Only a formula without a saved value (files written by some libraries) is calculated.
     */
    private function cellValue(Cell $cell): mixed
    {
        $value = $cell->getValue();

        if ($cell->isFormula()) {
            $value = $cell->getOldCalculatedValue();
            if ($value === null) {
                try {
                    $value = $cell->getCalculatedValue();
                } catch (Throwable) {
                    $value = null;
                }
            }
        }

        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    /** Rows of the last read() whose cells contain SUBTOTAL/AGGREGATE formulas: always totals, never accounts. */
    public function subtotalRows(int $sheet): array
    {
        return $this->subtotalRows[$sheet] ?? [];
    }

    /**
     * True when an .xlsx was saved with manual calculation, so the saved formula values may be stale.
     * (Other formats don't store the setting.)
     */
    public function hasManualCalculation(UploadedFile $file): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        return (bool) preg_match('/<(?:\w+:)?calcPr\b[^>]*\bcalcMode="manual"/i', $workbook);
    }

    /**
     * How one column of one sheet shows its hierarchy (parent rows, ParentRows): each cell's raw text, leading spaces
     * kept (read() trims them), and its indentation. Reads only that column, with its styles.
     *
     * @return array<int, array{raw: string, indent: int}> row => hints; empty when the file can't say
     */
    public function levelHints(UploadedFile $file, int $sheet, string $column): array
    {
        try {
            $path   = $file->getRealPath();
            $reader = IOFactory::createReader(IOFactory::identify($path));
            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
            }
            $reader->setReadFilter(new class($column) implements IReadFilter {
                public function __construct(private string $column) {}

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $columnAddress === $this->column;
                }
            });
            $book = $reader->load($path);
            $worksheet = $book->getSheet($sheet - 1);
            $out = [];
            foreach ($worksheet->getColumnIterator($column, $column)->current()->getCellIterator() as $cell) {
                $value = $this->cellValue($cell);
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $out[$cell->getRow()] = ['raw' => $value, 'indent' => (int) $cell->getStyle()->getAlignment()->getIndent()];
            }
            $book->disconnectWorksheets();

            return $out;
        } catch (Throwable) {
            return [];   // hints only: the levels then come from the names as read
        }
    }

    /**
     * Sheet titles by sheet number (1-based). Formats without titles (CSV) return "Sheet N" names.
     *
     * @return array<int, string>
     */
    public function sheetNames(UploadedFile $file, array $sheetNumbers): array
    {
        $titles = [];
        try {
            $reader = IOFactory::createReader(IOFactory::identify($file->getRealPath()));
            if (method_exists($reader, 'listWorksheetNames')) {
                $titles = $reader->listWorksheetNames($file->getRealPath());
            }
        } catch (Throwable) {
            // Names are cosmetic; fall back to numbers.
        }

        $names = [];
        foreach ($sheetNumbers as $n) {
            $title     = trim((string) ($titles[$n - 1] ?? ''));
            $names[$n] = $title !== '' ? $title : "Sheet {$n}";
        }
        return $names;
    }

    /** One row as prompt text: "R12 | A: 110101 | B: الصندوق | C: 5000" */
    public static function formatRow(int $rowNumber, array $cells): string
    {
        $parts = ["R{$rowNumber}"];
        foreach ($cells as $letter => $value) {
            $text = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
            $text = mb_substr(str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $text), 0, 200);
            $parts[] = "{$letter}: {$text}";
        }
        return implode(' | ', $parts);
    }
}
