<?php

namespace Tests\Unit;

use App\Services\SpreadsheetReader;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    use TrialBalanceFiles;

    public function test_keeps_excel_row_numbers_and_column_letters(): void
    {
        $sheets = (new SpreadsheetReader)->read($this->xlsxUpload([
            ['Title'],
            [],
            [null, 'x', null, 5],
        ]));

        $this->assertSame([1 => [1 => ['A' => 'Title'], 3 => ['B' => 'x', 'D' => 5]]], $sheets);
    }

    public function test_reads_every_non_empty_sheet(): void
    {
        $sheets = (new SpreadsheetReader)->read($this->workbook([
            'First' => [['a']],
            'Empty' => [],
            'Third' => [['c']],
        ]));

        $this->assertSame([1, 3], array_keys($sheets));
        $this->assertSame('c', $sheets[3][1]['A']);
    }

    public function test_trims_strings_and_calculates_formulas(): void
    {
        $sheets = (new SpreadsheetReader)->read($this->xlsxUpload([['  padded  ', '=2*21']]));

        $this->assertSame('padded', $sheets[1][1]['A']);
        $this->assertEquals(42, $sheets[1][1]['B']);
    }

    public function test_reads_csv(): void
    {
        $sheets = (new SpreadsheetReader)->read($this->csvUpload("a,b\n1,2\n"));

        $this->assertSame(['A' => 'a', 'B' => 'b'], $sheets[1][1]);
    }

    public function test_format_row(): void
    {
        $this->assertSame(
            'R12 | A: 110101 | B: الصندوق / فرع | C: 5000 | D: 12.5 | E: line one line two',
            SpreadsheetReader::formatRow(12, ['A' => '110101', 'B' => 'الصندوق | فرع', 'C' => 5000.0, 'D' => 12.5, 'E' => "line one\nline two"])
        );
    }

    public function test_sheet_names(): void
    {
        $reader = new SpreadsheetReader;

        $this->assertSame([1 => 'Report', 2 => 'قيود التسويات'],
            $reader->sheetNames($this->workbook(['Report' => [['a']], 'قيود التسويات' => [['b']]]), [1, 2]));
        $this->assertSame([1 => 'Sheet 1'], $reader->sheetNames($this->csvUpload("a,b\n"), [1]));
    }

    /** Regression: recalculating real workbooks (thousands of SUBTOTAL formulas) exhausted memory. */
    public function test_formulas_use_the_value_saved_in_the_file_without_recalculating(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->getCell('A1')->setValue('=1+1');
        $path = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);   // saves =1+1 with value 2

        // Pretend Excel saved 999: recalculating would give 2.
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<v>2</v>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<v>2</v>', '<v>999</v>', $xml));
        $zip->close();

        $sheets = (new SpreadsheetReader)->read(new \Illuminate\Http\UploadedFile($path, 'f.xlsx', null, null, true));

        $this->assertEquals(999, $sheets[1][1]['A']);
    }

    public function test_reads_upload_without_file_extension(): void
    {
        $source = $this->xlsxUpload([['x', 5]]);
        $path   = tempnam(sys_get_temp_dir(), 'php');   // real uploads have no extension
        copy($source->getRealPath(), $path);

        $sheets = (new SpreadsheetReader)->read(new \Illuminate\Http\UploadedFile($path, 'f.xlsx', null, null, true));

        $this->assertSame(['A' => 'x', 'B' => 5], $sheets[1][1]);
    }
}
