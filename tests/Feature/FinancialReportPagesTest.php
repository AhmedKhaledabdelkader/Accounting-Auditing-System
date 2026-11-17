<?php

namespace Tests\Feature;

use App\Http\Controllers\FinancialReportPagesController;
use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\Support\ArabicText;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * PR 13a: the financial-report pages' shells (list, new report, wizard) and their PageConfig: Arabic, relative URLs, every
 * string a script uses defined in lang/ar/financial_reports.php. What the scripts draw is the browser tests' (tests/Browser).
 */
class FinancialReportPagesTest extends TestCase
{
    use ArabicText, ClassifiedRows, RefreshDatabase;

    private function classifiedRun(string $status = 'done'): ClassificationRun
    {
        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => $status, 'original_name' => 'ميزان.xlsx',
            'result' => $status === 'done' ? $this->runResult([$this->account(2, 'نقدية لدى البنوك', ['closing' => 100]), $this->account(3, 'رأس المال', ['closing' => -100]),
                $this->account(4, null, ['closing' => 0])], ['closing']) : null]);
    }

    private function config(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.PageConfig = (\{.*?\});<\/script>/s', $html, $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_pages_are_arabic_shells_with_relative_urls(): void
    {
        $run = $this->classifiedRun();
        $id  = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        foreach (['/financial-reports' => 'list', '/financial-reports/new' => 'new', "/financial-reports/new?run={$run->id}" => 'new', "/financial-reports/{$id}" => 'wizard'] as $uri => $page) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertSame([], $this->codesIn($html), $uri);
            $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
            $config = $this->config($html);
            $this->assertSame($page, $config['page']);
            $this->assertSame('/api/v1', $config['api']);
            foreach ($config['urls'] as $url) {
                $this->assertStringStartsWith('/', $url, $uri);
            }
            $this->assertStringNotContainsString('://', json_encode([$config['api'], $config['urls']]));
        }
        $config = $this->config($this->get("/financial-reports/{$id}")->getContent());
        $this->assertSame([$id, $run->id, FinancialReportPagesController::STEPS], [$config['report_id'], $config['run_id'], $config['steps']]);
        $config = $this->config($this->get("/financial-reports/new?run={$run->id}")->getContent());
        $this->assertSame([null, 1], [$config['run']['unusable'], $config['run']['unclassified']]);
    }

    public function test_unknown_or_unfinished_runs_and_reports(): void
    {
        $html = $this->get('/financial-reports/'.Str::uuid())->assertNotFound()->getContent();
        $this->assertStringContainsString('هذه القوائم المالية غير موجودة.', $this->visibleText($html));
        $this->get('/financial-reports/new?run='.Str::uuid())->assertNotFound();

        $waiting = $this->classifiedRun('running');
        $config = $this->config($this->get("/financial-reports/new?run={$waiting->id}")->assertOk()->getContent());
        $this->assertStringContainsString('ما زال قيد التنفيذ', $config['run']['unusable']);
    }

    public function test_every_string_a_script_uses_is_arabic_in_the_lang_file(): void
    {
        $ui = Lang::get('financial_reports.ui', [], 'ar');
        $expand = [
            'source_hint_${k}' => array_map(fn ($s) => "source_hint_{$s}", ['none', 'first_year', 'report', 'run', 'manual']),
            'step_${k}' => array_map(fn ($s) => "step_{$s}", FinancialReportPagesController::STEPS),
            'step_${step}' => [], 'step_${ctx.step}' => [],
            'month_${i + 1}' => array_map(fn ($m) => "month_{$m}", range(1, 12)),
            'group_${schedule}' => ['group_changes_in_equity', 'group_cash_flows'],
            'suggestion_${x.status}' => array_map(fn ($s) => "suggestion_{$s}", ['pending', 'confirmed', 'edited', 'rejected']),
            'missing_${cells[n].reason}' => ['missing_movement_required', 'missing_suggestion_rejected'],
            'visibility_${v}' => ['visibility_auto', 'visibility_on', 'visibility_off'],
            'excluded_${x.reason}' => ['excluded_possible_parent', 'excluded_parent_level'],
            'pdf_rotation_${r}' => array_map(fn ($r) => "pdf_rotation_{$r}", [0, 90, 180, 270]),
            'pdf_skipped_${part}' => ['pdf_skipped_auditor_report'],
            'opening_signal_${cmp.opening_signal}' => array_map(fn ($s) => "opening_signal_{$s}", ['accumulated_depreciation', 'implied_opening', 'opening_column', 'retained_earnings', 'unknown']),
        ];
        // The opening and zakat steps' keys by their parts: every key of the family has to exist.
        $family = fn (string $pattern) => array_values(array_filter(array_keys($ui), fn ($k) => preg_match($pattern, $k)));
        $expand += [
            'opening_rel_${r.code}' => $family('/^opening_rel_(equation_tb|equation_approved|differences_net|equity_other_than_retained|opening_cash)$/'),
            'opening_rel_${r.code}_why' => $family('/^opening_rel_.+_why$/'),
            'opening_affects_${k}' => $family('/^opening_affects_(financial_position|changes_in_equity|cash_flows|notes_ppe)$/'),
            'zakat_step_${key}' => $family('/^zakat_step_(equity|non_current_liabilities|profit_difference|current_excess|extra_additions|deducted_assets|extra_deductions|base|minimum|maximum|final_base)$/'),
            'zakat_art_${key}' => $family('/^zakat_art_(equity|non_current_liabilities|profit_difference|current_excess|extra_additions|deducted_assets|extra_deductions|base|minimum|maximum|final_base)$/'),
            'zakat_charge_${kind}' => ['zakat_charge_zakat', 'zakat_charge_income_tax'],
            'zakat_source_${source}' => $family('/^zakat_source_(trial_balance|input|dialog|none)$/'),
            'zakat_in_${key}' => $family('/^zakat_in_(profit_additions|profit_deductions|loss_carryforward|deducted_current_assets|noncurrent_as_current|extra_additions|extra_deductions)$/'),
            'zakat_in_${key}_hint' => $family('/^zakat_in_(profit_additions|profit_deductions|loss_carryforward|deducted_current_assets|noncurrent_as_current|extra_additions|extra_deductions)_hint$/'),
            'zakat_warning_${w}' => $family('/^zakat_warning_/'),
            'zakat_binding_${z.binding}' => $family('/^zakat_binding_(base|minimum|maximum|none)$/'),
            'zakat_binding_${z.binding}_why' => $family('/^zakat_binding_.+_why$/'),
        ];
        $used = [];
        foreach (glob(public_path('js/financial-report/{,steps/}*.js'), GLOB_BRACE) as $file) {
            preg_match_all("/\\bui\\(\\s*[`']([^`']+)[`']/", file_get_contents($file), $m);
            preg_match_all("/\\bui\\([^()]*\\?\\s*'([a-z_0-9]+)'\\s*:\\s*'([a-z_0-9]+)'/", file_get_contents($file), $t);
            $used = [...$used, ...$m[1], ...$t[1], ...$t[2]];
        }
        $this->assertNotEmpty($used);
        foreach (array_unique($used) as $key) {
            foreach (str_contains($key, '${') ? $expand[$key] ?? $this->fail("unexpanded key {$key}") : [$key] as $k) {
                $this->assertArrayHasKey($k, $ui, "lang/ar/financial_reports.php ui.{$k}");
            }
        }
        foreach ($ui as $key => $text) {
            foreach ((array) $text as $part) {   // a group of words (the PDF's page kinds) too
                $this->assertTrue($key === 'missing_figure' || preg_match('/\p{Arabic}/u', $part) === 1, "ui.{$key} is not Arabic");
            }
        }
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', Lang::get('statements.template_versions.sa-ifrs-smes-3', [], 'ar'));
    }

    public function test_the_classifier_links_a_finished_run_to_a_new_report_and_no_longer_to_the_preview(): void
    {
        $html = $this->get('/classifier')->assertOk()->getContent();
        $this->assertStringContainsString('/financial-reports/new?run=', $html);
        $this->assertStringNotContainsString('preview/', $html);
        $this->assertStringNotContainsString('معاينة القوائم', $html);
    }

    public function test_the_old_preview_links_lead_to_the_review_step(): void
    {
        $run = $this->classifiedRun();
        $this->get("/financial-statements/preview/{$run->id}")->assertRedirect("/financial-reports/new?run={$run->id}");   // no report yet

        $old = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2024-01-01', 'period_end' => '2024-12-31'])->json('id');
        $this->travel(1)->minutes();
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->get("/financial-reports/{$old}/preview")->assertStatus(301)->assertRedirect("/financial-reports/{$old}#review");
        $this->get("/financial-statements/preview/{$run->id}")->assertRedirect("/financial-reports/{$id}#review");   // its latest report

        // An unknown one still redirects; the page it reaches says it doesn't exist.
        $this->get('/financial-statements/preview/'.($unknown = Str::uuid()))->assertRedirect("/financial-reports/new?run={$unknown}");
        $this->get("/financial-reports/new?run={$unknown}")->assertNotFound();
    }
}
