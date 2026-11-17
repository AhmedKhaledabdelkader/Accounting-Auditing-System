<?php

namespace Tests\Browser;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorStatementsPdf;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;

/**
 * Step 2 with last year's statements read from a PDF: last year's PPE movement shown as read, by last year's categories;
 * what was typed and not saved survives another save in the step. Made-up figures.
 */
class PriorYearStepTest extends DuskTestCase
{
    use ClassifiedRows, DatabaseMigrations, PdfFiles;

    /** A report whose last year's statements (a PDF, read here with the AI faked) are in use: two PPE categories. */
    private function report(): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'ميزان 2025.xlsx', 'result' => $this->runResult([
            $this->account(2, 'مباني وإنشاءات', ['opening' => 110, 'period' => [0, 0]], ['account_name' => 'مبنى المصنع']),
            $this->account(3, 'سيارات ووسائل نقل', ['opening' => 50, 'period' => [20, 0]], ['account_name' => 'سيارات']),
            $this->account(4, 'مجمع إهلاك الأصول الثابتة', ['opening' => -25, 'period' => [0, 5]], ['account_name' => 'مجمع إهلاك مبنى المصنع']),
            $this->account(5, 'مجمع إهلاك الأصول الثابتة', ['opening' => -20, 'period' => [0, 12]], ['account_name' => 'مجمع إهلاك السيارات']),
            $this->account(6, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [0, 20]], ['account_name' => 'بنك أ']),
            $this->account(7, 'رأس المال', ['opening' => -215], ['account_name' => 'رأس المال']),
            $this->account(8, 'إهلاك الأصول الثابتة', ['period' => [17, 0]], ['account_name' => 'إهلاك']),
        ], ['opening', 'period', 'closing'])]);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table(['2024', '2023'], [$row('الممتلكات والآلات والمعدات، بالصافي', ['115', '120'], '5'),
                $row('النقد ومعادلات النقد', ['100', '90']), $row('إجمالي الأصول', ['215', '210']), $row('رأس المال', ['215', '210']),
                $row('إجمالي حقوق الملكية والالتزامات', ['215', '210'])])],
            3 => [$heading('الإيضاحات'), $heading('السياسات المحاسبية', '4'),
                ['type' => 'paragraph', 'number' => null, 'text' => 'يتم احتساب الاستهلاك بطريقة القسط الثابت بالنسب التالية:', 'columns' => [], 'rows' => []],
                $table(['النسبة %'], [$row('مباني وانشاءات', ['5']), $row('سيارات', ['25'])]),
                $heading('ممتلكات وآلات ومعدات', '5'), $table(['مباني وانشاءات / ريال', 'سيارات / ريال', 'الإجمالي / ريال'], [
                    $row('التكلفة', [null, null, null]), $row('الرصيد في 1 يناير 2024', ['100', '50', '150']), $row('إضافات خلال السنة', ['10', '-', '10']),
                    $row('الرصيد في 31 ديسمبر 2024', ['110', '50', '160']),
                    $row('الإهلاك المتراكم', [null, null, null]), $row('الرصيد في 1 يناير 2024', ['20', '10', '30']), $row('إضافات خلال السنة', ['5', '10', '15']),
                    $row('الرصيد في 31 ديسمبر 2024', ['25', '20', '45']),
                    $row('صافي القيمة الدفترية', [null, null, null]), $row('في 31 ديسمبر 2024', ['85', '30', '115']), $row('في 31 ديسمبر 2023', ['80', '40', '120'])])],
        ];
        config(['classification.providers.anthropic.api_key' => 'test-key', 'classification.providers.gemini.api_key' => 'test-key']);
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answers) {
            $body = $request->data();
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => $answers[(int) ($m[1] ?? 0)] ?? [], 'tables' => []]));
        }]);
        $path = $this->pdfFile(['<h1>قائمة المركز المالي</h1>', '<p>الإيضاحات</p>', '<p>.</p>']);
        $kinds = [['page' => 2, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null], ['page' => 3, 'kind' => 'notes', 'rotation' => 0, 'printed_page' => null]];
        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));
        $file = ['name' => 'قوائم 2024.pdf', 'kind' => 'pdf', 'status' => 'ready', 'year' => 2024, 'uploaded_at' => now()->toIso8601String(), 'pages_total' => 3,
            'pages' => [], 'found' => [], 'page_kinds' => [['page' => 1, 'kind' => 'auditor_report', 'rotation' => 0, 'printed_page' => null], ...$kinds], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null];
        FinancialReport::find($id)->update(['comparative_start' => '2024-01-01', 'comparative_end' => '2024-12-31',
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false]]);

        return $id;
    }

    public function test_last_years_ppe_movement_shows_as_read_and_typing_survives_another_save(): void
    {
        $id = $this->report();
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            $browser->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-ppe-from-pdf]');
            // Last year's PPE movement by last year's categories, as read (not an empty grid of the template's).
            $table = $browser->script("return document.querySelector('[data-ppe-from-pdf] table').innerText")[0];
            foreach (['مباني وانشاءات', 'سيارات', '100', '110', '25'] as $text) {
                $this->assertStringContainsString($text, $table);
            }
            $this->assertSame(0, $browser->script("return document.querySelectorAll('[data-schedules-grid] input[data-field^=\"schedules.ppe.\"]').length")[0]);

            // A figure typed in the equity grid, in brackets as the statements print a negative, not saved yet …
            $browser->script("document.querySelector('[data-equity-grid]').open = true;");
            $field = '[data-field="changes_in_equity.dividends.retained_earnings"]';
            $browser->waitFor($field)->type($field, '(40)');
            // … then another save in the step (the approved openings' setting) draws the step again: the figure is still there.
            $browser->click('[data-approved-opening]');
            $browser->waitUsing(5, 100, fn () => $browser->script("return !! document.querySelector('.msg.warn') && [...document.querySelectorAll('.msg.warn')].some((b) => b.innerText.includes('لم يُحفظ بعد'))")[0]);
            $this->assertSame('(40)', $browser->value($field));

            // Saved: kept, as a negative.
            $browser->click('[data-approved-opening]');
            $browser->waitUsing(5, 100, fn () => $browser->value($field) === '(40)');
            $browser->press('حفظ السنة السابقة')->waitUsing(5, 100, fn () => ($report->fresh()->prior_figures['changes_in_equity']['dividends']['retained_earnings'] ?? null) !== null);
            $this->assertEquals(-40, $report->fresh()->prior_figures['changes_in_equity']['dividends']['retained_earnings']);
        });
    }

    public function test_figures_typed_as_printed_survive_refreshes_and_save_without_overwriting_saved_ones(): void
    {
        $id = $this->report();
        $report = FinancialReport::findOrFail($id);
        // A figure saved earlier for last year's equity.
        $report->update(['prior_figures' => ['changes_in_equity' => ['capital_increase' => ['share_capital' => 10.0]]] + $report->prior_figures]);
        $typed = ['dividends' => '(1,234)', 'other_retained_movements' => '-', 'transfer_to_reserve' => '—', 'prior_year_adjustments' => '١٬٢٣٤٫٥'];
        $field = fn (string $row) => "[data-field=\"changes_in_equity.{$row}.retained_earnings\"]";

        $this->browse(function (Browser $browser) use ($id, $report, $typed, $field) {
            $browser->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-equity-grid]');
            $browser->script("document.querySelector('[data-equity-grid]').open = true;");
            $this->assertSame('10', $browser->value('[data-field="changes_in_equity.capital_increase.share_capital"]'));
            foreach ($typed as $row => $value) {
                $browser->type($field($row), $value);
            }
            // Two other saves in the step draw it again from the server: every figure typed is still there, as typed.
            // Clicked in the page: the step may be redrawn between finding the button and clicking it.
            $save = fn () => $browser->script("document.querySelector('[data-approved-opening]').click();");
            foreach ([1, 2] as $_) {
                $save();
                $browser->pause(300)
                    ->waitUsing(5, 100, fn () => $browser->script("return [...document.querySelectorAll('.msg.warn')].some((b) => b.innerText.includes('لم يُحفظ بعد'))")[0]);
                foreach ($typed as $row => $value) {
                    $this->assertSame($value, $browser->value($field($row)), $row);
                }
            }
            // Nothing typed is saved before «حفظ السنة السابقة».
            $this->assertArrayNotHasKey('dividends', $report->fresh()->prior_figures['changes_in_equity']);

            // Saved: bracket negative, a dash zero, Arabic-Indic digits read; the figure saved earlier kept.
            $browser->press('حفظ السنة السابقة')->waitUsing(5, 100, fn () => isset($report->fresh()->prior_figures['changes_in_equity']['dividends']));
            $equity = $report->fresh()->prior_figures['changes_in_equity'];
            $this->assertEquals([-1234, 0, 0, 1234.5, 10], [$equity['dividends']['retained_earnings'], $equity['other_retained_movements']['retained_earnings'],
                $equity['transfer_to_reserve']['retained_earnings'], $equity['prior_year_adjustments']['retained_earnings'], $equity['capital_increase']['share_capital']]);
            // Once saved, nothing waits: no «not saved» notice after the step is drawn again.
            $browser->waitFor('[data-approved-opening]')->pause(300);
            $save();
            $browser->pause(500);
            $this->assertFalse($browser->script("return [...document.querySelectorAll('.msg.warn')].some((b) => b.innerText.includes('لم يُحفظ بعد'))")[0]);
            $save();
            $browser->pause(300);
        });
    }
}
