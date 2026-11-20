<?php

namespace Tests\Feature;

use App\Services\Statements\PriorPdf\Llm;
use App\Services\Statements\PriorPdf\PageExtractor;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use App\Support\Pdf\PdfPages;
use App\Support\Pdf\Qpdf;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/** Shared hosting has no qpdf: the page count is read in PHP and the AI gets the whole file, told which page to read and how it is turned. */
class NoQpdfTest extends TestCase
{
    use PdfFiles;

    public function test_the_page_count_in_php_alone(): void
    {
        $path = $this->pdfFile(['one', 'two', 'three']);
        $this->assertSame(3, PdfPages::count($path));
        foreach (glob(base_path('docs/reference/*.pdf')) as $real) {   // scanned and generated statements, compressed object streams too
            $this->assertGreaterThan(5, PdfPages::count($real), basename($real));
        }
        $junk = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($junk, 'not a pdf');
        $this->expectExceptionMessage('not a PDF');
        PdfPages::count($junk);
    }

    public function test_without_qpdf_the_whole_file_goes_with_the_page_named_and_its_turn(): void
    {
        config(['financial_statements.prior_pdf.qpdf' => 'none']);
        $path = $this->pdfFile(['one', 'two', 'three']);
        $qpdf = app(Qpdf::class);
        $this->assertFalse($qpdf->available());
        $this->assertSame(3, $qpdf->pages($path));
        $this->assertSame(file_get_contents($path), $qpdf->extract($path, 2, 2, 90));

        $driver = new class implements ClassifierDriver
        {
            public array $calls = [];

            public function classify(ToolCall $call): PromiseInterface
            {
                $this->calls[] = $call;

                return Create::promiseFor(new ToolAnswer(['blocks' => [['type' => 'paragraph', 'number' => null, 'text' => 'نص', 'columns' => [], 'rows' => []]]], null));
            }
        };
        $this->app->instance(ClassifierDriver::class, $driver);
        $this->app->forgetInstance(Llm::class);
        app(PageExtractor::class)->extract($path, [['page' => 2, 'kind' => 'notes', 'rotation' => 90, 'printed_page' => null]]);

        $this->assertStringContainsString('The WHOLE file is attached. Read ONLY its page 2', $driver->calls[0]->content);
        $this->assertStringContainsString('turned 90 degrees', $driver->calls[0]->content);
        $this->assertSame(file_get_contents($path), $driver->calls[0]->pdf);
    }

    public function test_with_qpdf_the_page_comes_alone_and_nothing_is_said(): void
    {
        if (! app(Qpdf::class)->available()) {
            $this->markTestSkipped('qpdf is not installed here.');
        }
        $path = $this->pdfFile(['one', 'two', 'three']);
        $this->assertSame(1, PdfPages::count((function () use ($path) {
            $f = tempnam(sys_get_temp_dir(), 'p').'.pdf';
            file_put_contents($f, app(Qpdf::class)->extract($path, 2, 2));

            return $f;
        })()));
    }
}
