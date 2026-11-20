<?php

namespace App\Support\Pdf;

use RuntimeException;

/**
 * A PDF's page count in PHP alone, for a server where qpdf can't be installed (shared hosting). The page tree's root
 * (`/Type /Pages` with the largest `/Count`) says it; in a PDF that keeps its objects in compressed object streams (PDF 1.5+)
 * the streams are inflated first. Falls back to counting the page objects. An encrypted file (its streams can't be read)
 * or a file with no pages throws.
 */
final class PdfPages
{
    private const MAX_STREAMS = 400;      // compressed streams inflated at most
    private const MAX_INFLATED = 50_000_000;

    public static function count(string $path): int
    {
        $bytes = (string) @file_get_contents($path);
        if (! str_starts_with(ltrim($bytes), '%PDF')) {
            throw new RuntimeException('not a PDF');
        }
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $bytes)) {
            throw new RuntimeException('encrypted PDF');
        }
        $texts = [$bytes];
        $inflated = 0;
        if (preg_match_all('/\/Type\s*\/ObjStm|\/Filter\s*\/FlateDecode/', $bytes) > 0) {
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m, PREG_PATTERN_ORDER, 0);
            foreach (array_slice($m[1], 0, self::MAX_STREAMS) as $raw) {
                $data = @gzuncompress($raw);
                $data = $data === false ? @gzinflate(substr($raw, 2)) : $data;
                if (is_string($data) && str_contains($data, '/Type')) {
                    $texts[] = $data;
                    if (($inflated += strlen($data)) > self::MAX_INFLATED) {
                        break;
                    }
                }
            }
        }
        $count = 0;
        $pages = 0;
        foreach ($texts as $t) {
            // The tree's nodes: the root counts every page.
            if (preg_match_all('/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)|\/Count\s+(\d+)[^>]*?\/Type\s*\/Pages\b/s', $t, $c, PREG_SET_ORDER)) {
                foreach ($c as $hit) {
                    $count = max($count, (int) ($hit[1] !== '' ? $hit[1] : $hit[2]));
                }
            }
            $pages += preg_match_all('/\/Type\s*\/Page(?![a-zA-Z])/', $t);
        }
        $n = $count ?: $pages;
        if ($n < 1) {
            throw new RuntimeException('no pages found');
        }

        return $n;
    }
}
