<?php

namespace Tests\Unit;

use App\Support\SpreadsheetFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Any spreadsheet a trial balance comes in: an «XML Spreadsheet 2003» exported under an .xls name (FastReport) too. */
class SpreadsheetFileTest extends TestCase
{
    private function xmlTrialBalance(): string
    {
        // As FastReport writes it: XML under .xls, a creation date the library's reader can't parse, a merged title, a skipped column.
        $xml = <<<'XML'
<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<?fr-application created="FastReport"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Created>04/02/2026 12:00:00 AMT13:16:39.3835144Z</Created></DocumentProperties>
<Worksheet ss:Name="ميزان">
<Table>
<Row><Cell ss:MergeAcross="2"><Data ss:Type="String">ميزان مراجعة</Data></Cell></Row>
<Row><Cell><Data ss:Type="String">رقم الحساب</Data></Cell><Cell><Data ss:Type="String">اسم الحساب</Data></Cell><Cell><Data ss:Type="String">مدين</Data></Cell><Cell><Data ss:Type="String">دائن</Data></Cell></Row>
<Row><Cell><Data ss:Type="String">111110003</Data></Cell><Cell><Data ss:Type="String">سيارة كورولا 2024</Data></Cell><Cell><Data ss:Type="Number">85000.5</Data></Cell></Row>
<Row ss:Index="5"><Cell><Data ss:Type="String">211</Data></Cell><Cell><Data ss:Type="String">موردون</Data></Cell><Cell ss:Index="4"><Data ss:Type="Number">85000.5</Data></Cell></Row>
</Table>
</Worksheet>
</Workbook>
XML;
        $path = tempnam(sys_get_temp_dir(), 'tb').'.xls';
        file_put_contents($path, $xml);

        return $path;
    }

    public function test_an_xml_spreadsheet_named_xls_is_accepted_and_read(): void
    {
        $path = $this->xmlTrialBalance();
        $file = new UploadedFile($path, 'ميزان 2025.xls', null, null, true);

        $this->assertFalse(Validator::make(['file' => $file], ['file' => ['required', 'file', SpreadsheetFile::rule()]])->fails());
        $grid = SpreadsheetFile::load($path, true)->getActiveSheet()->toArray(null, false, false, true);
        $this->assertSame('ميزان مراجعة', $grid[1]['A']);
        $this->assertSame(['111110003', 'سيارة كورولا 2024', 85000.5], [$grid[3]['A'], $grid[3]['B'], $grid[3]['C']]);
        $this->assertSame(['211', 'موردون', null, 85000.5], [$grid[5]['A'], $grid[5]['B'], $grid[5]['C'], $grid[5]['D']]);   // ss:Index on the row and the cell
        $this->assertSame('ميزان', SpreadsheetFile::load($path)->getActiveSheet()->getTitle());
    }

    public function test_a_record_printed_over_rows_merged_down_comes_back_as_one_row(): void
    {
        // FastReport: the name and the opening on a first thin row, the closing debit on the second, the credit on the third,
        // each merged down to the same bottom row.
        $xml = <<<'XML'
<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Worksheet ss:Name="Page1"><Table>
<Row><Cell ss:Index="2"><Data ss:Type="String">مدين</Data></Cell><Cell><Data ss:Type="String">دائن</Data></Cell><Cell ss:Index="5"><Data ss:Type="String">اسم الحساب</Data></Cell></Row>
<Row ss:Height="0.75"><Cell ss:Index="4" ss:MergeDown="2"><Data ss:Type="Number">53927</Data></Cell><Cell ss:MergeDown="2"><Data ss:Type="String">يارس 2016</Data></Cell></Row>
<Row ss:Height="0.75"><Cell ss:Index="2" ss:MergeDown="1"><Data ss:Type="Number">53927</Data></Cell></Row>
<Row ss:Height="14"><Cell ss:Index="3"><Data ss:Type="Number">0</Data></Cell></Row>
</Table></Worksheet>
</Workbook>
XML;
        $path = tempnam(sys_get_temp_dir(), 'fr').'.xls';
        file_put_contents($path, $xml);
        $grid = SpreadsheetFile::load($path, true)->getActiveSheet()->toArray(null, false, false, true);

        $this->assertSame([53927.0, 0.0, 53927.0, 'يارس 2016'], [$grid[4]['B'], $grid[4]['C'], $grid[4]['D'], $grid[4]['E']]);
        $this->assertSame([null, null], [$grid[2]['E'], $grid[3]['B']]);
    }

    public function test_a_file_that_is_no_spreadsheet_is_refused_with_a_clear_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.pdf';
        file_put_contents($path, '%PDF-1.4');
        $v = Validator::make(['file' => new UploadedFile($path, 'x.pdf', null, null, true)], ['file' => ['required', 'file', SpreadsheetFile::rule()]]);

        $this->assertTrue($v->fails());
        $this->assertStringContainsString('xlsx', $v->errors()->first('file'));
    }
}
