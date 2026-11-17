<?php

namespace Tests\Feature;

use App\Models\ClassificationImport;
use App\Services\Statements\Figure;
use App\Services\Statements\LayoutProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\Support\ArabicText;
use Tests\Support\BusyRun;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * The pages are Arabic: no check code, severity, status, origin, layout, group, line key, input key or other identifier
 * is visible on any of them. Every code the statements produce has its Arabic name in lang/ar/statements.php.
 */
class ArabicPagesTest extends TestCase
{
    use ArabicText, BusyRun, ClassifiedRows, RefreshDatabase;

    /** Every name in the labels file. */
    private function labels(): array
    {
        return Lang::get('statements', [], 'ar');
    }

    public function test_every_code_has_an_arabic_name(): void
    {
        $labels = $this->labels();
        preg_match_all("/check\\('([a-z_]+)'/", file_get_contents(app_path('Services/Statements/ReportChecks.php')).file_get_contents(app_path('Services/Statements/NotesBuilder.php')), $m);
        $this->assertNotEmpty($m[1]);
        foreach ([
            'checks'   => $m[1],
            'statuses' => Figure::STATUSES,
            'origins'  => Figure::ORIGINS,
            'groups'   => LayoutProfile::GROUPS,
            'layouts'  => ['C', 'OC', 'OMC'],
            'severities' => ['error', 'warning', 'info'],
            'missing_reasons' => ['post_closing_tb', 'input_required', 'prior_year_source_required', 'prior_year_incomplete', 'movement_required', 'suggestion_rejected', 'category_split_required',
                'note_incomplete', 'company_required', 'company_profile_incomplete'],
            'notes' => array_keys(config('financial_statements.templates.sa-ifrs-smes-3.notes')),
            'policies' => [...array_keys(config('financial_statements.templates.sa-ifrs-smes-3.policies')), 'policy_leases_lessor', 'policy_subsidiaries', 'policy_foreign_currency'],
            'visibility' => ['always', 'auto', 'forced_on', 'forced_off'],
            'going_concern' => \App\Services\Statements\NotesBuilder::GOING_CONCERN,
            'schedules' => array_keys(config('financial_statements.templates.sa-ifrs-smes-3.schedules')),
            'schedule_rows' => [...\App\Services\Statements\AssetNotesBuilder::COST, ...\App\Services\Statements\AssetNotesBuilder::ACCUMULATED, 'nbv_closing', 'nbv_opening',
                'opening', 'charge', 'prior_year_adjustments', 'paid', 'used', 'written_off', 'declared', 'reversal', 'closing', 'gross', 'deferred_finance_cost', 'net', 'non_current', 'current'],
            'schedule_columns' => ['total', 'amount'],
            'suggestion_statuses' => ['pending', 'confirmed', 'edited', 'rejected'],
            'tags' => array_keys(config('financial_statements.templates.sa-ifrs-smes-3.tags')),
            'prior_sources' => \App\Services\Statements\CurrentYearStatements::SOURCES,
            'scopes' => ['current', 'comparative'],
            'unmapped_reasons' => ['unclassified', 'no_rule', 'choose'],
            'blocking' => ['errors', 'warnings_unacknowledged', 'missing', 'pending_suggestions', 'pending_mappings'],
            'version_kinds' => ['draft', 'final'],
        ] as $set => $codes) {
            foreach ($codes as $code) {
                $this->assertNotEmpty($labels[$set][$code] ?? null, "no Arabic name for {$set}.{$code}");
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $labels[$set][$code], "{$set}.{$code}");
            }
        }
        foreach (['first_application.effect' => ['none', 'effect'], 'going_concern.decision' => ['include', 'exclude'], 'policies.inventory' => ['general', 'manufacturing'],
            'policies.employee_benefits' => ['labour_law', 'actuarial'], 'policies.revenue' => \App\Services\Statements\ReportIntake::REVENUE_STREAMS] as $field => $options) {
            foreach ($options as $o) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', data_get($labels['choices'], "{$field}.{$o}") ?? '', "choices.{$field}.{$o}");
                $this->assertIsArray(__("statements.choices.{$field}", [], 'ar'), $field);
            }
        }
        foreach (config('financial_statements.templates.sa-ifrs-smes-3.schedules') as $key => $cfg) {
            foreach (array_keys($cfg['categories'] ?? []) as $category) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $labels['categories'][$key][$category] ?? '', "categories.{$key}.{$category}");
            }
            foreach (['increase', 'decrease'] as $row) {
                $this->assertArrayHasKey($cfg[$row] ?? 'opening', $labels['schedule_rows'], "{$key}.{$row}");
            }
        }
    }

    public function test_the_detector_finds_codes_in_visible_text_only(): void
    {
        $html = '<p>الفحص sofp_unbalanced على السطر is.zakat، الأعمدة OMC، المدخل zakat.charge، pending_confirmation</p>'
            .'<script>const x = "tb_partial";</script><span title="wrong_side_balance">نص</span>';
        $this->assertEqualsCanonicalizing(['sofp_unbalanced', 'OMC', 'pending_confirmation', 'is.zakat', 'zakat.charge'], $this->codesIn($html));
    }

    public function test_the_classification_pages_show_no_codes(): void
    {
        $import = ClassificationImport::create(['file_name' => 'ملف المحاسب.xlsx', 'status' => ClassificationImport::PENDING, 'kind' => ClassificationImport::FILE]);
        foreach (['/classifier', '/classification-cache', '/classification-memory', "/classification-cache/imports/{$import->id}/review"] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame([], $this->codesIn($html), $url);
            // Values the scripts put on the page come through Arabic maps, never the raw code.
            $this->assertStringNotContainsString('|| i.status}', $html, $url);
            $this->assertStringNotContainsString('|| m.method)', $html, $url);
            $this->assertStringNotContainsString('queue:work', $html, $url);
        }
    }
}
