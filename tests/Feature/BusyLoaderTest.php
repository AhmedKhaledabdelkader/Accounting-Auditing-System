<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The loader with a percentage (public/js/busy.js): every page carries it, in the page's language, and the files exist. */
class BusyLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_carries_the_loader_and_its_words(): void
    {
        $html = $this->followingRedirects()->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('/js/busy.js', $html);
        $this->assertStringContainsString('window.BusyConfig', $html);
        $this->assertStringContainsString('جارٍ رفع الملف', $html);
        $this->assertFileExists(public_path('js/busy.js'));
    }

    public function test_both_languages_have_the_same_words(): void
    {
        $ar = array_keys(require lang_path('ar/busy.php'));
        $en = array_keys(require lang_path('en/busy.php'));

        $this->assertSame($en, $ar);
        foreach (['uploading', 'processing', 'elapsed', 'done'] as $key) {
            $this->assertContains($key, $ar);
        }
    }
}
