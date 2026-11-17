<?php

namespace Tests\Support;

use Mpdf\Mpdf;

/** PDFs for tests, made with mPDF: one page per HTML string, Arabic text included. */
trait PdfFiles
{
    /** @param list<string> $pages each page's HTML @return string the file's path (removed after the test) */
    protected function pdfFile(array $pages): string
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'mode' => 'utf-8']);
        foreach ($pages as $i => $html) {
            if ($i > 0) {
                $pdf->AddPage();
            }
            $pdf->WriteHTML($html);
        }
        $path = tempnam(sys_get_temp_dir(), 'tst').'.pdf';
        file_put_contents($path, $pdf->Output('', 'S'));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
