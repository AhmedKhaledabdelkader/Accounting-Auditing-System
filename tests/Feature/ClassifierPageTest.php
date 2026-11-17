<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\PageConfig;
use Tests\TestCase;

class ClassifierPageTest extends TestCase
{
    use PageConfig, RefreshDatabase;   // the page lists the companies

    public function test_classifier_page_renders(): void
    {
        $html = $this->get('/classifier')
            ->assertOk()
            ->assertSee('مصنّف ميزان المراجعة')
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->getContent();

        // The script's words reach it through #app-config, in the page's language.
        $t = json_decode($this->appConfig($html)['t'], true);
        $this->assertSame('يصنّف النظام الحسابات', $t['stages']['classifying']);
    }

    public function test_the_company_is_chosen_at_upload(): void
    {
        $company = \App\Models\Company::create(['name_ar' => 'شركة المثال']);
        $html = $this->get('/classifier')->assertOk()->getContent();

        $this->assertStringContainsString('<option value="'.$company->id.'">شركة المثال</option>', $html);
        $this->assertStringContainsString("fd.append('company_id', company)", $html);
    }

    public function test_page_renders_in_english_for_an_english_user(): void
    {
        $user = $this->admin();
        $user->locale = 'en';
        $html = $this->actingAs($user)->get('/classifier')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
        $this->assertStringContainsString('<title>Trial Balance Classifier</title>', $html);
        foreach (['Drag the Excel file here or click to choose', 'Start classification', 'Total accounts', 'Average confidence', 'Needs review only'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertSame('The system is classifying the accounts', json_decode($this->appConfig($html)['t'], true)['stages']['classifying']);

        // The page's own content (upload, progress, column check, results) has no Arabic word left.
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        foreach (['drop', 'go', 'reset', 'loader', 'confirm', 'results'] as $id) {
            $node = $xpath->query("//*[@id='{$id}']")->item(0);
            $text = $node->textContent;
            foreach ($xpath->query('.//@placeholder | .//@title', $node) as $attr) {
                $text .= ' '.$attr->value;
            }
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text, "#{$id} still has Arabic text");
        }
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $xpath->query('//h1')->item(0)->textContent);
    }

    public function test_both_languages_have_the_same_keys_and_the_script_uses_only_existing_ones(): void
    {
        $ar = require lang_path('ar/classifier_page.php');
        $en = require lang_path('en/classifier_page.php');
        $this->assertSame(array_keys(Arr::dot($ar)), array_keys(Arr::dot($en)));

        $html = $this->get('/classifier')->getContent();
        preg_match_all("/\bt\('([a-z_.]*[a-z_])'[,)]/", $html, $m);   // literal keys; built ones (cols.…, groups.…) are in the lang file
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $key) {
            $this->assertArrayHasKey($key, Arr::dot($ar['js']), "t('{$key}') has no entry in classifier_page.js");
        }
    }

    public function test_page_calls_api_with_same_origin_relative_url(): void
    {
        $html = $this->get('/classifier')->getContent();

        // Blade writes the URL into #app-config; the script reads it from there.
        $this->assertSame('/api/v1/classify/jobs', $this->appConfig($html)['submit-url']);
        $this->assertStringContainsString("const SUBMIT_URL = document.getElementById('app-config').dataset.submitUrl;", $html);
        $this->assertDoesNotMatchRegularExpression('#https?://[^"\']*/api/v1/classify#', $html);
    }

    public function test_page_handles_runs_waiting_for_confirmation_and_cancel(): void
    {
        $html = $this->get('/classifier')->getContent();

        foreach (["json.status === 'needs_confirmation'", "json.status === 'cancelled'", "json.status === 'expired'",
            'id="confirm"', 'id="cfConfirm"', 'id="cfCancel"', 'id="cancelQueued"', 'job.confirmation.confirm_url', 'job.cancel_url', 'id="cfAnyway"', 'json.needs_acknowledgement', 'acknowledge_doubts: true'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_opening_a_run_from_the_url_runs_after_every_constant_it_uses(): void
    {
        // ?run=<id> calls showProgress at load; before STAGE_PCT was declared it threw (temporal dead zone) and the run
        // was never polled.
        $html = $this->get('/classifier?run=x')->assertOk()->getContent();

        // The last run reopens too (its id kept in the browser): the store it reads is declared before.
        $this->assertGreaterThan(strpos($html, 'const STAGE_PCT'), strpos($html, "const openRun = new URLSearchParams(location.search).get('run') || store.get(LAST_RUN);"));
        $this->assertGreaterThan(strpos($html, 'const timer'), strpos($html, 'const openRun'));
        $this->assertGreaterThan(strpos($html, 'const LAST_RUN'), strpos($html, 'const openRun'));
    }

    public function test_page_shows_partial_results_in_their_own_banner(): void
    {
        $html = $this->get('/classifier')->getContent();

        foreach (['id="partial"', 'showPartial(json.meta?.unclassified)', 'u.failed_batches', 'u.missed_by_ai', 'id="showUnclassified"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertLessThan(strpos($html, 'id="warnings"'), strpos($html, 'id="partial"'));   // above the normal warnings
    }

    public function test_health_check_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_serve_command_port_comes_from_server_port_env(): void
    {
        $default = Artisan::all()['serve']->getDefinition()->getOption('port')->getDefault();

        $this->assertSame(env('SERVER_PORT'), $default);
    }
}
