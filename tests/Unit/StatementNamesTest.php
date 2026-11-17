<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * One name per statement everywhere (the accountant's rule R6): «قائمة المركز المالي», «قائمة الدخل الشامل»,
 * «قائمة التغيرات في حقوق الملكية», «قائمة التدفقات النقدية», never a shorter or older name, in the Arabic wording of
 * the pages and in the notes' template.
 */
class StatementNamesTest extends TestCase
{
    private const OTHER_NAMES = '/قائمة (?:الدخل(?! الشامل)|التغيرات(?! في حقوق الملكية)|التدفقات(?! النقدية)|الأرباح أو الخسائر|الربح أو الخسارة|الربح والخسارة|المركز المالى)/u';

    public function test_the_arabic_wording_names_each_statement_one_way(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (glob("{$root}/lang/ar/*.php") as $file) {
            preg_match_all(self::OTHER_NAMES, file_get_contents($file), $m);
            $this->assertSame([], $m[0], basename($file));
        }
    }

    public function test_the_notes_template_names_each_statement_one_way(): void
    {
        $zip = new ZipArchive;
        $zip->open(dirname(__DIR__, 2).'/resources/templates/sa-ifrs-smes-3/notes.docx');
        preg_match_all('/<w:t(?: [^>]*)?>([^<]*)<\/w:t>/', $zip->getFromName('word/document.xml'), $m);
        $zip->close();
        preg_match_all(self::OTHER_NAMES, implode('', $m[1]), $found);
        $this->assertSame([], $found[0]);
    }
}
