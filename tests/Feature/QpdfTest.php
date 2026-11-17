<?php

namespace Tests\Feature;

use App\Support\Pdf\Qpdf;
use RuntimeException;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/** qpdf: a PDF's page count, a range of its pages as a PDF; a file it can't open throws. */
class QpdfTest extends TestCase
{
    use PdfFiles;

    public function test_pages_are_counted_and_a_range_cut_out(): void
    {
        $path = $this->pdfFile(['<p>غلاف</p>', '<h1>قائمة المركز المالي</h1>', '<h1>قائمة الدخل</h1>', '<p>إيضاحات</p>']);
        $qpdf = new Qpdf;

        $this->assertSame(4, $qpdf->pages($path));
        $cut = tempnam(sys_get_temp_dir(), 'cut');
        file_put_contents($cut, $qpdf->extract($path, 2, 3));
        $this->assertStringStartsWith('%PDF', file_get_contents($cut));
        $this->assertSame(2, $qpdf->pages($cut));
        @unlink($cut);
    }

    public function test_a_page_is_turned_to_read_upright(): void
    {
        // A landscape table printed sideways (brief §8.1): the page cut out and turned clockwise.
        $path = $this->pdfFile(['<p>غلاف</p>', '<h1>قائمة التغيرات في حقوق الملكية</h1>']);
        $cut = tempnam(sys_get_temp_dir(), 'cut');
        file_put_contents($cut, (new Qpdf)->extract($path, 2, 2, 90));
        $this->assertSame(1, (new Qpdf)->pages($cut));
        $this->assertMatchesRegularExpression('#/Rotate 90#', file_get_contents($cut));
        file_put_contents($cut, (new Qpdf)->extract($path, 2, 2));
        $this->assertDoesNotMatchRegularExpression('#/Rotate [1-9]#', file_get_contents($cut));
        @unlink($cut);
    }

    public function test_a_file_that_is_not_a_pdf_throws(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'not a pdf');

        $this->expectException(RuntimeException::class);
        try {
            (new Qpdf)->pages($path);
        } finally {
            @unlink($path);
        }
    }
}
