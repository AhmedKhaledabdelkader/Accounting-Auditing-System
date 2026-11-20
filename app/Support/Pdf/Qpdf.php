<?php

namespace App\Support\Pdf;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * qpdf (config financial_statements.prior_pdf.qpdf): a PDF's page count, and a range of its pages as a PDF of its own.
 * Any PDF qpdf opens, compressed object streams included (the free FPDI refuses those). An encrypted file that needs a
 * password, or anything else qpdf can't open, throws.
 *
 * Without qpdf (shared hosting: nothing can be installed, or QPDF_BINARY=none): the page count is read in PHP (PdfPages) and
 * `extract` gives the whole file: the AI is then told which page to read and how it is turned (PageExtractor).
 */
class Qpdf
{
    /** The message's start when the qpdf program itself can't be run (not installed, wrong QPDF_BINARY). */
    public const MISSING = 'qpdf is not installed';

    private ?bool $available = null;

    /** Whether qpdf can be run here (installed, QPDF_BINARY not `none`, and the server lets PHP start a program). */
    public function available(): bool
    {
        if (in_array(strtolower((string) config('financial_statements.prior_pdf.qpdf')), ['', 'none', 'off'], true)) {
            return false;
        }

        return $this->available ??= (function () {
            try {
                $this->run(['--version']);

                return true;
            } catch (\Throwable) {
                return false;   // not installed, or proc_open disabled (shared hosting)
            }
        })();
    }

    public function pages(string $path): int
    {
        if (! $this->available()) {
            return PdfPages::count($path);
        }
        $out = $this->run(['--show-npages', $path]);
        if (! preg_match('/^\s*(\d+)\s*$/', $out, $m) || (int) $m[1] < 1) {
            throw new RuntimeException('qpdf: no page count');
        }

        return (int) $m[1];
    }

    /**
     * Pages $from–$to (1-based, inclusive) of the file, as the bytes of a new PDF; turned $rotate degrees clockwise (a
     * landscape table printed sideways, read upright).
     */
    public function extract(string $path, int $from, int $to, int $rotate = 0): string
    {
        if (! $this->available()) {
            return (string) file_get_contents($path);   // the whole file: the caller names the page and its turn in words
        }
        $target = tempnam(sys_get_temp_dir(), 'pages').'.pdf';
        try {
            $this->run(['--empty', '--pages', $path, "{$from}-{$to}", '--', ...($rotate % 360 ? ['--rotate=+'.($rotate % 360)] : []), $target]);

            return (string) file_get_contents($target);
        } finally {
            @unlink($target);
            @unlink(substr($target, 0, -4));
        }
    }

    /** qpdf's output; exit 3 is success with warnings (a damaged but readable file). */
    private function run(array $args): string
    {
        $result = Process::timeout(60)->run([config('financial_statements.prior_pdf.qpdf'), ...$args]);
        if (! in_array($result->exitCode(), [0, 3], true)) {
            $said = trim($result->errorOutput() ?: $result->output());
            if (preg_match('/not recognized|not found|No such file|cannot find/i', $said)) {
                throw new RuntimeException(self::MISSING.': '.$said);
            }
            throw new RuntimeException('qpdf: '.$said);
        }

        return $result->output();
    }
}
