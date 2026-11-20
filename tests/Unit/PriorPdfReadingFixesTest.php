<?php

namespace Tests\Unit;

use App\Services\Statements\PriorPdf\PageExtractor;
use App\Services\Statements\PriorStatementsFile;
use Tests\TestCase;

/** What scanned statements print that used to be left unread: a lone dot for nil, a minus after the figure, a Hijri date year first. */
class PriorPdfReadingFixesTest extends TestCase
{
    public function test_a_lone_dot_is_the_nil_mark_and_a_minus_after_the_figure_is_a_negative(): void
    {
        foreach (['.', '..', '·'] as $dot) {
            $this->assertSame(['amount' => 0.0, 'status' => 'dash'], array_intersect_key(PageExtractor::cell($dot), ['amount' => 1, 'status' => 1]), $dot);
        }
        $this->assertSame([-3518125.0, 'ok'], [PageExtractor::cell('٣,٥١٨,١٢٥-')['amount'], PageExtractor::cell('٣,٥١٨,١٢٥-')['status']]);
        $this->assertSame([-1200.0, -500.0, 1200.0], [PriorStatementsFile::amount('(1,200)')['amount'], PriorStatementsFile::amount('-500')['amount'], PriorStatementsFile::amount('1,200')['amount']]);
        $this->assertSame('unread', PriorStatementsFile::amount('-5-')['status']);            // two minuses: not a figure
        $this->assertSame('unread', PageExtractor::cell('1?5')['status']);                    // a digit not read stays unread
        $this->assertSame('unread', PageExtractor::cell('1.2.3')['status']);
    }

    public function test_the_registration_date_is_read_year_first_too(): void
    {
        $doc = ['sections' => [['id' => 0, 'parent' => null, 'page' => 9, 'title' => 'نبذة عن الشركة', 'number' => '1',
            'paragraphs' => ['مؤسسة لؤلؤة الطب - مؤسسة فردية سعودية تمارس نشاطها بموجب السجل التجاري رقم ١٠١٠٢٦٦١٧١ بتاريخ ١٤٣٩/٠٢/١٣ هـ الصادر من وزارة التجارة والاستثمار.']]]];
        $facts = \App\Services\Statements\PriorPdf\CompanyFacts::of($doc);

        $this->assertSame('1010266171', $facts['cr_number']['value'] ?? null);
        $this->assertSame('1439/02/13', $facts['cr_date_hijri']['value'] ?? null);
    }
}
