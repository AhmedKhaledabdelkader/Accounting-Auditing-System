<?php

namespace Tests\Unit;

use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** The cleaned templates (resources/templates/sa-ifrs-smes-3): clean packages, working formulas, well-formed placeholders. */
class StatementTemplatesTest extends TestCase
{
    private const DIR = __DIR__.'/../../resources/templates/sa-ifrs-smes-3/';
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const SHEETS = ['data', 'الغلاف', 'الفهرس', 'المركز المالى', 'قائمة الدخل الشامل', 'قائمة التغيرات', 'قائمة التدفقات النقدية', 'ايضاح الاصول والممتلكات'];

    /* ---------- packages ---------- */

    public static function files(): array
    {
        return ['xlsx' => ['statements.xlsx'], 'docx' => ['notes.docx']];
    }

    #[DataProvider('files')]
    public function test_package_has_no_personal_data(string $file): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(self::DIR.$file));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertSame([], preg_grep('#printerSettings|people\.xml|commentsExtended|commentsIds|commentsExtensible|docProps/custom\.xml#', $names));
        $core = $zip->getFromName('docProps/core.xml');
        foreach (['dc:creator', 'cp:lastModifiedBy', 'dc:title'] as $tag) {
            $this->assertDoesNotMatchRegularExpression("#<{$tag}>[^<]+</{$tag}>#", $core, $tag);
        }
        $this->assertDoesNotMatchRegularExpression('#<(Company|Manager)>[^<]+<#', $zip->getFromName('docProps/app.xml'));

        // Comments are [review] marks only, by the neutral author.
        foreach (preg_grep('#comments\d*\.xml$#', $names) as $part) {
            $xml = $zip->getFromName($part);
            $this->assertSame([], array_diff(array_unique(array_merge(
                preg_match_all('#w:author="([^"]*)"#', $xml, $m) ? $m[1] : [],
                preg_match_all('#<author>([^<]*)</author>#', $xml, $m) ? $m[1] : [],
            )), ['template']), $part);
            $texts = preg_match_all('#<(?:w:)?t(?:\s[^>]*)?>([^<]*)</(?:w:)?t>#u', $xml, $m) ? $m[1] : [];
            $this->assertNotEmpty($texts, $part);
            foreach ($texts as $t) {
                $this->assertStringStartsWith('[review]', $t, $part);
            }
        }
    }

    /* ---------- statements.xlsx ---------- */

    private function book(): Spreadsheet
    {
        return IOFactory::load(self::DIR.'statements.xlsx');
    }

    private function row(Spreadsheet $book, string $sheet, string $keyColumn, string $key): int
    {
        $s = $book->getSheetByName($sheet);
        foreach ($s->getRowIterator() as $r) {
            if ($s->getCell($keyColumn.$r->getRowIndex())->getValue() === $key) {
                return $r->getRowIndex();
            }
        }
        $this->fail("no row {$key} in {$sheet}");
    }

    public function test_workbook_sheets_and_formulas(): void
    {
        $book = $this->book();
        $this->assertSame(self::SHEETS, $book->getSheetNames());

        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coord) {
                $v = (string) $sheet->getCell($coord)->getValue();
                $this->assertStringNotContainsString('#REF!', $v, "{$sheet->getTitle()}!{$coord}");
            }
        }

        // Amount columns hold formulas or nothing: every amount is written by the exporter.
        foreach (['المركز المالى', 'قائمة الدخل الشامل', 'قائمة التدفقات النقدية'] as $name) {
            $s = $book->getSheetByName($name);
            $keys = [];
            foreach ($s->getRowIterator(6) as $r) {
                $n = $r->getRowIndex();
                foreach (['E', 'H'] as $c) {
                    $v = $s->getCell("{$c}{$n}")->getValue();
                    $this->assertTrue($v === null || (is_string($v) && str_starts_with($v, '=')), "{$name}!{$c}{$n}");
                }
                if ($key = $s->getCell("J{$n}")->getValue()) {
                    $keys[] = $key;
                    // The amounts and the note number the exporter writes are in the template's font (spec §1.1).
                    foreach (['B', 'C', 'E', 'H'] as $c) {
                        $this->assertSame(['Sakkal Majalla', 14.0], [$s->getStyle("{$c}{$n}")->getFont()->getName(), (float) $s->getStyle("{$c}{$n}")->getFont()->getSize()], "{$name}!{$c}{$n}");
                    }
                }
            }
            $this->assertSame($keys, array_unique($keys), $name);
            $this->assertFalse($s->getColumnDimension('J')->getVisible(), $name);
        }
    }

    public function test_checks_are_zero_for_balanced_figures(): void
    {
        $book = $this->book();
        $data = $book->getSheetByName('data');
        foreach (['B6' => '2025-01-01', 'B7' => '2025-12-31', 'B8' => '2024-01-01', 'B9' => '2024-12-31'] as $cell => $date) {
            $data->getCell($cell)->setValue(Date::PHPToExcel(new \DateTime($date)));
        }
        $fill = function (string $sheet, array $lines) use ($book) {
            foreach ($lines as $key => [$cy, $py]) {
                $r = $this->row($book, $sheet, 'J', $key);
                $book->getSheetByName($sheet)->getCell("E{$r}")->setValue($cy);
                $book->getSheetByName($sheet)->getCell("H{$r}")->setValue($py);
            }
        };
        $fill('المركز المالى', ['sofp.ppe' => [1000, 900], 'sofp.inventory' => [500, 0], 'sofp.cash' => [300, 200], 'sofp.share_capital' => [1000, 1000], 'sofp.retained_earnings' => [500, 100], 'sofp.trade_payables' => [300, 0]]);
        $fill('قائمة الدخل الشامل', ['is.revenue' => [1000, 300], 'is.cost_of_revenue' => [-500, -200], 'is.general_admin' => [-100, 0]]);
        $fill('قائمة التدفقات النقدية', ['cf.wc_inventory' => [-500, 0], 'cf.wc_trade_payables' => [300, 0], 'cf.ppe_purchases' => [-100, 0]]);
        $cf = $book->getSheetByName('قائمة التدفقات النقدية');
        $cf->getCell('H'.$this->row($book, 'قائمة التدفقات النقدية', 'J', 'cf.opening_cash'))->setValue(100);
        $book->getSheetByName('قائمة التغيرات')->getCell('E'.$this->row($book, 'قائمة التغيرات', 'A', 'soce.comparative.opening'))->setValue(1000);

        $value = fn (string $sheet, string $cell) => $book->getSheetByName($sheet)->getCell($cell)->getCalculatedValue();
        $this->assertSame('31 ديسمبر 2025م', $value('data', 'B10'));
        $this->assertSame(12, (int) $value('data', 'B14'));
        $this->assertSame('قائمة المركز المالي كما في 31 ديسمبر 2025م', $value('المركز المالى', 'A3'));

        $sofp = $this->row($book, 'المركز المالى', 'J', 'sofp.check_balance');
        $diff = $this->row($book, 'قائمة التدفقات النقدية', 'J', 'cf.check_difference');
        $socePy = $this->row($book, 'قائمة التغيرات', 'A', 'soce.comparative.closing');
        $soceCy = $this->row($book, 'قائمة التغيرات', 'A', 'soce.current.closing');
        foreach ([['المركز المالى', "E{$sofp}"], ['المركز المالى', "H{$sofp}"], ['قائمة التدفقات النقدية', "E{$diff}"], ['قائمة التدفقات النقدية', "H{$diff}"], ['قائمة التغيرات', "S{$socePy}"], ['قائمة التغيرات', "S{$soceCy}"]] as [$sheet, $cell]) {
            $this->assertEquals(0, $value($sheet, $cell), "{$sheet}!{$cell}");
        }
        $this->assertEquals(400, $value('قائمة الدخل الشامل', 'E'.$this->row($book, 'قائمة الدخل الشامل', 'J', 'is.net_profit')));
    }

    /* ---------- notes.docx ---------- */

    /** @return array{0: list<string>, 1: string} text nodes of the main part and headers, and the main part's paragraph text */
    private function docx(): array
    {
        $zip = new ZipArchive;
        $zip->open(self::DIR.'notes.docx');
        $nodes = [];
        $body  = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }
            $dom = new DOMDocument;
            $dom->loadXML($zip->getFromName($name));
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', self::W);
            foreach ($xp->query('//w:t') as $t) {
                $nodes[] = $t->textContent;
            }
            if ($name === 'word/document.xml') {
                foreach ($xp->query('//w:p') as $p) {
                    $body .= implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $p))))."\n";
                }
            }
        }
        return [$nodes, $body];
    }

    public function test_placeholders_sit_in_one_text_run(): void
    {
        [$nodes, $body] = $this->docx();
        $inNodes = 0;
        foreach ($nodes as $t) {
            // Whole ${name} tokens only: no ${ or } left over in a run.
            $rest = preg_replace('/\$\{\/?[a-z0-9_]+\}/', '', $t, -1, $n);
            $inNodes += $n;
            $this->assertStringNotContainsString('${', $rest, $t);
        }
        $this->assertSame(preg_match_all('/\$\{/', implode('', $nodes)), $inNodes);
        $this->assertGreaterThan(400, $inNodes);
    }

    public function test_blocks_are_balanced_and_unique(): void
    {
        [, $body] = $this->docx();
        preg_match_all('/^\$\{(\/?)([a-z0-9_]+)\}$/m', $body, $m, PREG_SET_ORDER);
        // A paragraph holding only a value placeholder looks like a marker: blocks are the names that have a closer.
        $blocks = array_column(array_filter($m, fn ($x) => $x[1] === '/'), 2);
        $stack  = [];
        $seen   = [];
        foreach (array_filter($m, fn ($x) => in_array($x[2], $blocks, true)) as [, $close, $name]) {
            if ($close) {
                $this->assertSame($name, array_pop($stack), "closing {$name}");
                continue;
            }
            $this->assertNotContains($name, $seen, "block {$name} twice");
            $seen[]  = $name;
            $stack[] = $name;
        }
        $this->assertSame([], $stack);
        $this->assertCount(70, $seen);   // note_other_assets since the second review; zakat_zero since the third; ppe_comparative gone since the fourth
        // A placeholder with a block's name would be taken for the block by TemplateProcessor.
        foreach ($seen as $name) {
            $this->assertSame(1, substr_count($body, '${'.$name.'}'), $name);
        }
    }

    public function test_no_hard_coded_period_city_or_leftovers(): void
    {
        [$nodes, $body] = $this->docx();
        $text = implode("\n", $nodes);
        // The only years are the 3rd edition's own dates, in the early-application paragraph.
        preg_match_all('/20\d\d/', $text, $years);
        $this->assertSame(['2025', '2027'], array_values(array_unique($years[0])));
        $early = preg_replace('/^.*\$\{early_application\}\n(.*)\n\$\{\/early_application\}.*$/s', '$1', $body);
        $this->assertSame(substr_count($text, '2025') + substr_count($text, '2027'), substr_count($early, '2025') + substr_count($early, '2027'));

        // R4: no proposed-dividends note in any output.
        foreach (['الرياض', '1447', '38.75', 'نموذج قياس الخسائر الائتمانية', 'مخصص اجازات العاملين', '15/أ', 'المقترح توزيعها', 'dividends'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $text);
        }
    }
}
