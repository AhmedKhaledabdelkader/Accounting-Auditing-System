<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The general Arabic word list is an adaptation of a CC BY-SA 4.0 list (resources/wordlists): it stays a separate data
 * file read at runtime, with its licence and attribution beside it, and no code embeds its words.
 */
class WordListLicenceTest extends TestCase
{
    public function test_the_shared_alike_word_list_keeps_its_licence_beside_it_and_stays_out_of_the_code(): void
    {
        $dir = dirname(__DIR__, 2).'/resources/wordlists';
        $this->assertFileExists("{$dir}/ar-common.txt");
        $licence = (string) file_get_contents("{$dir}/LICENSE-ar-common.txt");
        foreach (['CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', 'FrequencyWords', 'Hermit Dave', 'Changes made'] as $needed) {
            $this->assertStringContainsString($needed, $licence);
        }
        $this->assertStringContainsString('LICENSE-ar-common.txt', (string) file_get_contents("{$dir}/README.md"));

        // Read as data at runtime, never copied into code: no PHP file holds a run of the list's words.
        $sample = array_slice(array_filter(file("{$dir}/ar-common.txt", FILE_IGNORE_NEW_LINES) ?: [], fn ($w) => mb_strlen($w) > 6), 500, 3);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $this->assertStringNotContainsString(implode("\n", $sample), (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }
}
