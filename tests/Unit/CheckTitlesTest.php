<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every check a report can raise has a title, in Arabic and English (the review lists checks by their title; a code
 * with none would show as a code). The PDF reader's own checks are issues with their own messages (PriorPdfIssues).
 */
class CheckTitlesTest extends TestCase
{
    public function test_every_check_code_has_a_title(): void
    {
        $codes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/PriorPdf/')) {
                continue;
            }
            preg_match_all("/(?:->check|ReportChecks::make|self::make)\\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
            $codes = [...$codes, ...$m[1]];
        }
        $this->assertNotEmpty($codes);
        foreach (['ar', 'en'] as $locale) {
            $titles = trans('statements.checks', [], $locale);
            foreach (array_unique($codes) as $code) {
                $this->assertArrayHasKey($code, $titles, "lang/{$locale}/statements.php checks.{$code}");
            }
        }
    }
}
