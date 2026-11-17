<?php

namespace Tests\Browser;

use App\Jobs\ReadPriorStatementsPdf;
use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;

/**
 * Step 2 with a PDF of last year's statements: while the system works the page says so and follows it; the pages found
 * are shown to be checked (each a link to the file) and corrected; once read, each row shows its page, and a total that
 * doesn't add up asks to be checked. The AI runs here in the test process (faked), never from the browser.
 */
class PriorStatementsPdfPageTest extends DuskTestCase
{
    use ClassifiedRows, DatabaseMigrations, PdfFiles;

    public function test_the_pages_found_are_shown_to_check_and_the_rows_read_show_their_page(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
        ], ['opening', 'closing'])]);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $report = FinancialReport::find($id);
        $path = "prior-statements/{$id}/page-test.pdf";
        Storage::disk('local')->put($path, file_get_contents($this->pdfFile(['<p>غلاف</p>', '<h1>قائمة المركز المالي</h1>', '<p>إيضاحات</p>'])));
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->deleteDirectory("prior-statements/{$id}"));
        $upload = ['name' => 'قوائم 2024.pdf', 'uploaded_at' => now()->toIso8601String(), 'year' => 2024, 'kind' => 'pdf', 'id' => 'page-test',
            'path' => $path, 'pages_total' => 3, 'status' => 'locating', 'pages' => [], 'found' => [], 'blocks' => [], 'expenses_shown_positive' => null];
        $report->update(['prior_upload' => $upload]);

        $this->browse(function (Browser $browser) use ($id, $report, $upload) {
            // The system is looking for the pages: the page says so, then follows it when they are found.
            $browser->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-pdf-status="locating"]', 10)
                ->assertSeeIn('[data-pdf-status]', 'السيستم بيدوّر على صفحات القوائم');
            // While nothing changes the step isn't drawn again: a mark on it survives a few looks at the state.
            $browser->script("document.querySelector('[data-pdf-status]').dataset.mark = '1'");
            $browser->pause(6000)->assertPresent('[data-pdf-status][data-mark="1"]');
            $found = ['financial_position' => ['from' => 2, 'to' => 2]];
            $report->update(['prior_upload' => ['status' => 'pages', 'pages' => $found, 'found' => $found] + $upload]);
            $browser->waitFor('[data-pdf-pages]', 10)
                ->assertInputValue('[data-field="pages.financial_position.from"]', '2')
                ->assertInputValue('[data-field="pages.cash_flows.from"]', '')
                ->assertSeeIn('tr[data-pdf-statement="financial_position"]', 'السيستم لقاها في صفحة 2')
                ->assertSeeIn('tr[data-pdf-statement="cash_flows"]', 'السيستم مالقاهاش');
            $this->assertStringEndsWith("/financial-reports/{$id}/prior-year/statements-file#page=2", $browser->attribute('[data-pdf-open="financial_position"]', 'href'));
            // A page typed moves the link with it.
            $browser->type('[data-field="pages.cash_flows.from"]', '3');
            $this->assertStringEndsWith('#page=3', $browser->attribute('[data-pdf-open="cash_flows"]', 'href'));
            // The file opens.
            $this->get("/api/v1/financial-reports/{$id}/prior-year/statements-file")->assertOk();

            // Read (the AI faked, in this process): each row shows its page; the total that doesn't add up asks to be checked.
            Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) {
                $row = fn ($label, $amount) => ['label' => $label, 'note' => null, 'cells' => [$amount]];

                return Http::response(FakeWire::answer(FakeWire::providerOf($request->data()), 'submit_page', ['blocks' => [
                    ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024']], 'rows' => [
                        $row('النقد ومعادلات النقد', '100'), $row('رأس المال', '100'), $row('إجمالي حقوق الملكية والالتزامات', '110'),
                    ]],
                ]]));
            }]);
            // A key, as in the other tests: the driver refuses to send without one (the call is faked all the same).
            config(['classification.providers.anthropic.api_key' => 'test-key', 'classification.providers.gemini.api_key' => 'test-key']);
            $report->update(['prior_upload' => ['status' => 'reading', 'pages' => $found, 'found' => $found] + $upload]);
            ReadPriorStatementsPdf::dispatchSync($id, 'page-test', 'read');
            $this->assertSame('ready', $report->fresh()->prior_upload['status'], json_encode($report->fresh()->prior_upload['error'] ?? null));
            $browser->visit('/financial-reports')->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-file-review]', 10)
                ->assertSeeIn('[data-file-statement="financial_position"] h3', 'صفحة 2')
                ->assertSeeIn('[data-file-label="النقد ومعادلات النقد"] td:first-child a', '2')
                ->assertSeeIn('[data-file-label="إجمالي حقوق الملكية والالتزامات"] [data-file-check]', 'مجموعها 100 والمكتوب 110')
                // The statement's last row isn't a total even after reading the page again: said, with the page to open.
                ->assertSeeIn('[data-pdf-issue="check_statement_total"]', 'قائمة المركز المالي (صفحة 2): آخر سطر مش بيساوي مجموع اللي فوقه')
                ->assertPresent('[data-pdf-issue="check_statement_total"] a[href$="#page=2"]')
                // This year's opening against last year's closing, line by line: here they agree.
                ->assertSeeIn('[data-opening-check]', '2 من 2 سطر متساويين.')
                ->assertPresent('details[data-pdf-pages]');

            // A word the file misprinted: corrected, said, and kept as printed with one click.
            $read = $report->fresh()->prior_upload;
            $read['document']['corrections'] = [['id' => 'c1', 'kind' => 'misprint', 'page' => 2, 'pages' => [2], 'original' => 'للمتلكات', 'corrected' => 'للممتلكات', 'title' => 'الأعمار الإنتاجية للمتلكات']];
            $report->update(['prior_upload' => $read]);
            $browser->visit('/financial-reports')->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-wording-correction="c1"]', 10)
                ->assertSeeIn('[data-pdf-issue="corrected_misprint"]', 'مطبوعة غلط، واتصححت لـ «للممتلكات»')
                ->click('[data-wording-correction="c1"]')
                ->waitForTextIn('[data-pdf-issue="corrected_misprint"]', 'فضلت زي ما هي مطبوعة', 10);

            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));
        });
    }

    public function test_the_years_openings_from_the_approved_statements_are_reconciled_and_can_be_switched_off(): void
    {
        // This year's trial balance opens at cash 100, capital 100; last year's approved statements closed at 90 and 90.
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'إيرادات المبيعات', ['closing' => -50]),
        ], ['opening', 'closing'])]);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) {
            $row = fn ($label, $amount) => ['label' => $label, 'note' => null, 'cells' => [$amount]];

            return Http::response(FakeWire::answer(FakeWire::providerOf($request->data()), 'submit_page', ['blocks' => [
                ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024']], 'rows' => [
                    $row('النقد ومعادلات النقد', '90'), $row('إجمالي الأصول', '90'), $row('رأس المال', '90'), $row('إجمالي حقوق الملكية والالتزامات', '90'),
                ]],
            ]]));
        }]);
        config(['classification.providers.anthropic.api_key' => 'test-key', 'classification.providers.gemini.api_key' => 'test-key']);
        $read = app(\App\Services\Statements\PriorStatementsPdf::class)->read($this->pdfFile(['<h1>قائمة المركز المالي</h1>']),
            [['page' => 1, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null]], 2024, new \App\Services\Statements\LineCatalogue('sa-ifrs-smes-3'));
        $file = ['name' => 'قوائم 2024.pdf', 'kind' => 'pdf', 'status' => 'ready', 'year' => 2024, 'uploaded_at' => now()->toIso8601String(), 'pages_total' => 1,
            'pages' => [], 'found' => [], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null];
        FinancialReport::find($id)->update(['comparative_start' => '2024-01-01', 'comparative_end' => '2024-12-31',
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false]]);

        $this->browse(function (Browser $browser) use ($id) {
            // On by default: the year opens at the approved figures; the trial balance's differences in total and per line.
            $browser->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-approved-opening-panel="applied"]', 10)
                ->assertChecked('[data-approved-opening]')
                ->assertSeeIn('[data-approved-opening-total="assets"]', '10')
                ->assertSeeIn('[data-approved-opening-total="equity"]', '10')
                // Cash: the trial balance's closing 150, presented 90 + the year's 50 = 140.
                ->assertSeeIn('[data-approved-opening-line="sofp.cash"] td:nth-child(6)', '140');
            // Last year's figure a note uses (the PDF printed no cash note: the approved line) shows in its field.
            $this->assertSame(['90'], $browser->script("return [...document.querySelectorAll('[data-notes-grid] input[data-field=\"notes.cash\"]')].map((i) => i.value).filter((v) => v !== '')")[0]);
            // Switched off from the page: the trial balance's own figures, the reconciliation still there.
            $browser->uncheck('[data-approved-opening]')->waitFor('[data-approved-opening-panel="off"]', 10)
                ->assertNotChecked('[data-approved-opening]')
                ->assertPresent('[data-approved-opening-line="sofp.cash"]');
            $this->assertFalse(FinancialReport::find($id)->prior_figures['approved_opening']);
            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));
        });
    }

    public function test_an_account_matched_by_its_amount_alone_is_moved_only_by_its_own_click(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(3, 'مخصص الزكاة الشرعية', ['opening' => 50, 'closing' => 50], ['account_name' => 'مخصص الزكاة والدخل']),
            $this->account(5, 'نقدية لدى البنوك', ['opening' => 430, 'closing' => 430], ['account_name' => 'البنك الأهلي']),
            $this->account(6, 'موردون', ['opening' => -400, 'closing' => -400], ['account_name' => 'مورد المواد']),
            $this->account(10, 'موردون', ['opening' => -50, 'closing' => -50], ['account_name' => 'ضريبة القيمة المضافة المستحقة']),
            $this->account(13, 'إيرادات مؤجلة', ['opening' => -30, 'closing' => -30], ['account_name' => 'إيرادات مقدمة']),
        ], ['opening', 'closing'])]);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024'], ['header' => '2023']], 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            1 => [$heading('قائمة المركز المالي'), $table([$row('مصاريف مقدمة وأرصدة مدينة أخرى', ['50', '-'], '8'), $row('النقد ومعادلات النقد', ['430', '400']),
                $row('إجمالي الأصول', ['480', '400']), $row('ذمم تجارية دائنة', ['400', '400']), $row('مصاريف مستحقة وأرصدة دائنة أخرى', ['80', '-'], '11'),
                $row('إجمالي حقوق الملكية والالتزامات', ['480', '400'])])],
            2 => [$heading('الإيضاحات'), $heading('مصاريف مقدمة وأرصدة مدينة أخرى', '8'), $table([$row('الزكاة', ['50', '-']), $row('', ['50', '-'])]),
                $heading('مصاريف مستحقة وأرصدة دائنة أخرى', '11'), $table([$row('ضريبة القيمة المضافة', ['50', '-']), $row('استقطاعات', ['30', '-']), $row('', ['80', '-'])])],
        ];
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answers) {
            $body = $request->data();
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => $answers[(int) ($m[1] ?? 0)] ?? [], 'tables' => []]));
        }]);
        config(['classification.providers.anthropic.api_key' => 'test-key', 'classification.providers.gemini.api_key' => 'test-key']);
        $read = app(\App\Services\Statements\PriorStatementsPdf::class)->read($this->pdfFile(['<h1>قائمة المركز المالي</h1>', '<p>الإيضاحات</p>']),
            [['page' => 1, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null], ['page' => 2, 'kind' => 'notes', 'rotation' => 0, 'printed_page' => null]],
            2024, new \App\Services\Statements\LineCatalogue('sa-ifrs-smes-3'));
        $file = ['name' => 'قوائم 2024.pdf', 'kind' => 'pdf', 'status' => 'ready', 'year' => 2024, 'uploaded_at' => now()->toIso8601String(), 'pages_total' => 2,
            'pages' => [], 'found' => [], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null];
        FinancialReport::find($id)->update(['comparative_start' => '2024-01-01', 'comparative_end' => '2024-12-31',
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false]]);

        $this->browse(function (Browser $browser) use ($id) {
            // The deferred revenue matches last year's «استقطاعات» by its amount alone: said so, left out of «everything».
            $browser->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-comparable]', 10)
                ->assertPresent('[data-comparable-account="13"] [data-comparable-amount-only]')
                ->assertMissing('[data-comparable-account="10"] [data-comparable-amount-only]')
                ->click('[data-comparable-all]')
                ->waitUntilMissing('[data-comparable-account="10"]', 10)
                // The step is drawn again: its row may not be back yet.
                ->waitFor('[data-comparable-accept-amount="13"]', 10);
            $this->assertEqualsCanonicalizing([3, 10], array_column(FinancialReport::find($id)->account_overrides, 'row'));
            // Its own click moves it.
            $browser->click('[data-comparable-accept-amount="13"]')->waitUntilMissing('[data-comparable-accept-amount="13"]', 10);
            $this->assertEqualsCanonicalizing([3, 10, 13], array_column(FinancialReport::find($id)->account_overrides, 'row'));
            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));
        });
    }

    public function test_every_page_is_listed_with_what_it_is_and_corrected(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
        ], ['opening', 'closing'])]);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $path = "prior-statements/{$id}/kinds-test.pdf";
        Storage::disk('local')->put($path, file_get_contents($this->pdfFile(['<p>تقرير المراجع</p>', '<h1>قائمة المركز المالي</h1>', '<p>إيضاحات</p>'])));
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->deleteDirectory("prior-statements/{$id}"));
        $kind = fn (int $page, string $kind, array $notes = []) => ['page' => $page, 'kind' => $kind, 'printed_page' => $page > 1 ? (string) ($page - 1) : null, 'rotation' => 0, 'notes' => $notes];
        FinancialReport::find($id)->update(['prior_upload' => ['name' => 'قوائم 2024.pdf', 'uploaded_at' => now()->toIso8601String(), 'year' => 2024, 'kind' => 'pdf',
            'id' => 'kinds-test', 'path' => $path, 'pages_total' => 3, 'status' => 'pages', 'pages' => ['financial_position' => ['from' => 2, 'to' => 2]],
            'found' => ['financial_position' => ['from' => 2, 'to' => 2]], 'skipped' => ['auditor_report' => ['from' => 1, 'to' => 1]], 'blocks' => [], 'expenses_shown_positive' => null,
            'page_kinds' => [$kind(1, 'auditor_report'), $kind(2, 'financial_position'), $kind(3, 'other', [['number' => '5', 'title' => 'ممتلكات وآلات ومعدات']])]]]);

        $this->browse(function (Browser $browser) use ($id) {
            $browser->visit("/financial-reports/{$id}#prior_year")->waitFor('[data-page-kinds]', 10)
                ->assertSelected('[data-page-kind="1"]', 'auditor_report')
                ->assertSeeIn('tr[data-pdf-page="3"]', '5 ممتلكات وآلات ومعدات')
                ->assertSeeIn('tr[data-pdf-page="2"] td:nth-child(2)', '1');
            // Page 3 is the notes, printed sideways: corrected and sent; nothing is read here (the queue runs in the test process).
            $browser->script("for (const [sel, v] of [['[data-page-kind=\"3\"]', 'notes'], ['[data-page-rotation=\"3\"]', '90']]) {
                const s = document.querySelector(sel); s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }");
            // (The server's queue runs at once with no AI here, so the reading itself fails; what was sent is what matters.)
            $browser->click('[data-pdf-read]');
            $upload = null;
            for ($i = 0; $i < 50 && ($upload['page_kinds'][2]['kind'] ?? null) !== 'notes'; $i++, usleep(200_000)) {
                $upload = FinancialReport::find($id)->prior_upload;
            }
            $this->assertSame([['notes', 90]], [[$upload['page_kinds'][2]['kind'], $upload['page_kinds'][2]['rotation']]]);
            $this->assertSame(['financial_position' => ['from' => 2, 'to' => 2]], $upload['pages']);
        });
    }
}
