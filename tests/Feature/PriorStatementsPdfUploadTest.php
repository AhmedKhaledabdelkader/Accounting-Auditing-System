<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * Last year's issued statements as a PDF, through the API: uploaded (202), the statements' pages found by the AI and
 * shown for the accountant to confirm or correct, then the tables read (202) and reviewed like an Excel file's; nothing is
 * used before the user confirms it in step 2.
 */
class PriorStatementsPdfUploadTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** @var list<string> the tools called, in order */
    private array $calls = [];

    /** The tables' calls fail while this is set. */
    private bool $failTables = false;

    /** The AI takes page 1 (the auditor's report) for a cover. */
    private bool $missReport = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withHeader('Accept-Language', 'ar');   // the messages checked are the Arabic ones
    }

    /** 2025, opening and closing: cash 100 → 150, capital 100, suppliers 30, revenue 200, rent 180. */
    private function report(): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'موردون', ['opening' => 0, 'closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -200]),
            $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 180]),
        ], ['opening', 'closing'])]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    private function pdf(int $pages = 5): UploadedFile
    {
        $path = $this->pdfFile(array_map(fn ($n) => "<p>صفحة {$n}</p>", range(1, $pages)));

        return new UploadedFile($path, 'قوائم 2024.pdf', 'application/pdf', null, true);
    }

    private static function row(string $label, array $cells): array
    {
        return ['label' => $label, 'note' => null, 'cells' => $cells];
    }

    /** A page the AI copied: a title, then its table. */
    private static function page(string $title, array $headers, array $rows): array
    {
        return [['type' => 'heading', 'number' => null, 'text' => $title, 'columns' => [], 'rows' => []],
            ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows]];
    }

    /**
     * The AI: the statements on pages 2, 3 and 4 (cash flows said to be on 5: the accountant corrects it); 2024's figures
     * as in PriorStatementsUploadTest (cash 100 = capital 100; revenue 80, general and administrative (80); capital paid
     * in 100). The pages' calls fail while $failTables is set.
     */
    private function fakeAi(): void
    {
        $years = ['31 ديسمبر 2024م', '31 ديسمبر 2023م'];
        $tables = [
            2 => self::page('قائمة المركز المالي', $years, [
                self::row('الأصول المتداولة', [null, null]),
                self::row('النقد ومعادلات النقد', ['100', '70']),
                self::row('مصاريف مقدمة وأرصدة مدينة أخرى', ['-', '-']),
                self::row('إجمالي الأصول', ['100', '70']),
                self::row('حقوق الملكية', [null, null]),
                self::row('رأس المال', ['100', '70']),
                self::row('الأرباح المبقاة', ['-', '-']),
                self::row('إجمالي حقوق الملكية', ['100', '70']),
                self::row('إجمالي حقوق الملكية والالتزامات', ['١٠٠', '٧٠']),
            ]),
            3 => self::page('قائمة الدخل الشامل', ['2024', '2023'], [
                self::row('إيرادات النشاط', ['80', '60']),
                self::row('المصروفات العمومية والإدارية', ['(80)', '(60)']),
                self::row('صافي الدخل (الخسارة) للسنة', ['-', '-']),
            ]),
            4 => self::page('قائمة التدفقات النقدية', ['2024', '2023'], [
                self::row('التدفقات النقدية من الأنشطة التمويلية', [null, null]),
                self::row('متحصلات من زيادة رأس المال', ['100', '70']),
                self::row('صافي التدفقات النقدية من الأنشطة التمويلية', ['100', '70']),
                self::row('صافي التغير في النقد وما في حكمه', ['100', '70']),
                self::row('النقد وما في حكمه في بداية السنة', ['-', '-']),
                self::row('رصيد النقدية في آخر السنة', ['100', '70']),
            ]),
        ];
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($tables) {
            $body = $request->data();
            $tool = FakeWire::toolName($body);
            $this->calls[] = $tool;
            $provider = FakeWire::providerOf($body);
            if ($tool === 'submit_pages') {
                $page = fn (int $n, string $kind) => ['page' => $n, 'kind' => $kind, 'printed_page' => null, 'rotation' => 0, 'notes' => []];

                return Http::response(FakeWire::answer($provider, $tool, ['pages' => [$page(1, $this->missReport ? 'cover' : 'auditor_report'), $page(2, 'financial_position'),
                    $page(3, 'comprehensive_income'), $page(4, 'notes'), $page(5, 'cash_flows')]]));
            }
            if ($this->failTables) {
                return Http::response(FakeWire::error($provider, 400, 'The document could not be processed'), 400);
            }
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);

            return Http::response(FakeWire::answer($provider, $tool, ['blocks' => $tables[(int) ($m[1] ?? 0)] ?? []]));
        }]);
    }

    private function upload(string $id, UploadedFile $file): array
    {
        return $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => $file], ['Accept' => 'application/json'])->assertStatus(202)->json('comparative.upload');
    }

    private function pages(string $id, array $pages): \Illuminate\Testing\TestResponse
    {
        return $this->putJson(self::URL."/{$id}/prior-year/statements-file/pages", ['pages' => $pages]);
    }

    private function line(array $state, string $statement, string $key): array
    {
        return collect($state['statements'][$statement])->firstWhere('key', $key);
    }

    public function test_the_pages_are_confirmed_then_the_tables_read_and_used_like_an_excel_files(): void
    {
        $id = $this->report();
        $this->fakeAi();

        // Uploaded: the AI looked for the statements (the queue runs at once here); their pages wait for the accountant.
        $upload = $this->upload($id, $this->pdf());
        $found = ['financial_position' => ['from' => 2, 'to' => 2], 'comprehensive_income' => ['from' => 3, 'to' => 3], 'cash_flows' => ['from' => 5, 'to' => 5]];
        $this->assertSame(['pdf', 'pages', 5, $found, $found, [], false], [$upload['kind'], $upload['status'], $upload['pages_total'],
            $upload['pages'], $upload['found'], $upload['blocks'], $upload['in_use']]);
        $this->assertSame(['submit_pages'], $this->calls);
        $this->assertSame(['auditor_report' => ['from' => 1, 'to' => 1]], $upload['skipped']);   // never read (R9)
        $this->assertNull($upload['rejected']);   // with its auditor's report: accepted
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertStatus(422)
            ->assertJsonValidationErrors(['source' => 'لم تُقرأ قوائم الملف بعد. حدد صفحاتها واقرأها أولًا.']);
        // The file itself, to look at its pages.
        $this->get(self::URL."/{$id}/prior-year/statements-file")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // The cash flows are on page 4, not 5: corrected, then the tables are read, one call per statement.
        $pages = ['financial_position' => ['from' => 2, 'to' => 2], 'comprehensive_income' => ['from' => 3, 'to' => 3], 'cash_flows' => ['from' => 4, 'to' => 4]];
        $upload = $this->pages($id, $pages)->assertStatus(202)->json('comparative.upload');
        $this->assertSame(['ready', $pages, $found], [$upload['status'], $upload['pages'], $upload['found']]);
        $this->assertSame(['financial_position', 'comprehensive_income', 'cash_flows'], array_column($upload['blocks'], 'statement'));
        $this->assertCount(4, $this->calls);
        $cash = collect($upload['blocks'][0]['rows'])->firstWhere('label', 'النقد ومعادلات النقد');
        $this->assertSame(['sofp.cash', 'exact', 2, []], [$cash['match'], $cash['certainty'], $cash['page'], $cash['check']]);
        $this->assertSame(4, $upload['blocks'][2]['rows'][0]['page']);
        $this->assertNotNull($upload['cost_usd'] ?? 0.0);

        // Confirmed: the comparatives come from the file, as from an Excel file, and pass the same checks.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertOk()->json();
        $this->assertSame('statements', $state['comparative']['source']);
        $this->assertTrue($state['comparative']['upload']['in_use']);
        $this->assertEquals([100, 'prior_year_statements'], array_values(array_intersect_key($this->line($state, 'financial_position', 'sofp.cash')['comparative'], array_flip(['amount', 'origin']))));
        $this->assertEquals(-80, $this->line($state, 'comprehensive_income', 'is.general_admin')['comparative']['amount']);
        $this->assertEquals(100, $this->line($state, 'cash_flows', 'cf.capital_increase')['comparative']['amount']);
        $this->assertSame([], array_values(array_intersect(array_column(array_filter($state['checks'], fn ($c) => $c['scope'] === 'comparative'), 'code'),
            ['opening_vs_prior_closing', 'sofp_unbalanced', 'prior_file_total'])));
        // The file in use is still there to look at.
        $this->get(self::URL."/{$id}/prior-year/statements-file")->assertOk();
    }

    public function test_pages_are_checked_and_a_file_being_read_waits(): void
    {
        $id = $this->report();
        $this->fakeAi();
        $this->pages($id, ['financial_position' => ['from' => 1, 'to' => 1]])->assertStatus(409);   // no PDF yet
        $this->upload($id, $this->pdf());

        $this->pages($id, ['financial_position' => ['from' => 3, 'to' => 2], 'cash_flows' => ['from' => 1, 'to' => 6]])->assertStatus(422)
            ->assertJsonValidationErrors(['pages.cash_flows.to']);
        $this->pages($id, ['financial_position' => ['from' => 3, 'to' => 2]])->assertStatus(422)
            ->assertJsonValidationErrors(['pages.financial_position.to' => 'يجب أن تكون آخر صفحة بعد أول صفحة أو هي نفسها.']);
        $this->pages($id, ['changes_in_equity' => ['from' => 1, 'to' => 1]])->assertStatus(422)->assertJsonValidationErrors('pages');
        $this->pages($id, ['notes' => ['from' => 1, 'to' => 1]])->assertStatus(422)->assertJsonValidationErrors('pages');

        $report = FinancialReport::find($id);
        $report->update(['prior_upload' => ['status' => 'reading'] + $report->prior_upload]);
        $this->pages($id, ['financial_position' => ['from' => 2, 'to' => 2]])->assertStatus(409)->assertJson(['message' => 'الملف ما زال قيد القراءة. انتظر حتى ينتهي.']);
        $this->assertSame(['submit_pages'], $this->calls);
    }

    public function test_the_accountant_corrects_what_each_page_is(): void
    {
        $id = $this->report();
        $this->fakeAi();
        $upload = $this->upload($id, $this->pdf());
        $this->assertSame(['auditor_report', 'financial_position', 'comprehensive_income', 'notes', 'cash_flows'], array_column($upload['page_kinds'], 'kind'));

        // Page 4 is the income statement continued, printed sideways; page 5 the notes: the statements' pages follow.
        $kinds = [['page' => 1, 'kind' => 'auditor_report'], ['page' => 2, 'kind' => 'financial_position'], ['page' => 3, 'kind' => 'comprehensive_income'],
            ['page' => 4, 'kind' => 'comprehensive_income', 'rotation' => 90], ['page' => 5, 'kind' => 'notes']];
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/pages", ['page_kinds' => $kinds])->assertStatus(202);
        $upload = FinancialReport::find($id)->prior_upload;
        $this->assertSame(['financial_position' => ['from' => 2, 'to' => 2], 'comprehensive_income' => ['from' => 3, 'to' => 4]], $upload['pages']);
        $this->assertSame([0, 0, 0, 90, 0], array_column($upload['page_kinds'], 'rotation'));
        $this->assertSame(['auditor_report' => ['from' => 1, 'to' => 1]], $upload['skipped']);

        // Every page once, a known kind.
        $report = FinancialReport::find($id);
        $report->update(['prior_upload' => ['status' => 'pages'] + $report->prior_upload]);
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/pages", ['page_kinds' => array_slice($kinds, 0, 4)])->assertStatus(422)->assertJsonValidationErrors('page_kinds');
        $kinds[4]['kind'] = 'appendix';
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/pages", ['page_kinds' => $kinds])->assertStatus(422)->assertJsonValidationErrors('page_kinds.4.kind');
    }

    public function test_a_file_with_no_auditors_report_is_refused_until_the_accountant_marks_its_page(): void
    {
        $id = $this->report();
        $this->fakeAi();
        $this->missReport = true;
        $message = 'لا يحتوي الملف على تقرير المراجع المستقل، لذلك لا يمكن قبوله كقوائم معتمدة. إن كان التقرير موجودًا في الملف، فغيّر نوع صفحته في جدول الصفحات إلى «تقرير المراجع».';

        // No page of the auditor's report: said, and its statements are not read.
        $upload = $this->upload($id, $this->pdf());
        $this->assertSame($message, $upload['rejected']);
        $this->pages($id, ['financial_position' => ['from' => 2, 'to' => 2]])->assertStatus(422)->assertJsonValidationErrors(['pages' => $message]);
        $kinds = [['page' => 1, 'kind' => 'cover'], ['page' => 2, 'kind' => 'financial_position'], ['page' => 3, 'kind' => 'comprehensive_income'],
            ['page' => 4, 'kind' => 'notes'], ['page' => 5, 'kind' => 'cash_flows']];
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/pages", ['page_kinds' => $kinds])->assertStatus(422)->assertJsonValidationErrors(['page_kinds' => $message]);
        $this->assertSame(['submit_pages'], $this->calls);

        // A file read before the rule, with no page of it: not used either.
        $report = FinancialReport::find($id);
        $report->update(['prior_upload' => ['status' => 'ready', 'blocks' => []] + $report->prior_upload]);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertStatus(422)->assertJsonValidationErrors(['source' => $message]);
        $report->update(['prior_upload' => ['status' => 'pages'] + $report->prior_upload]);

        // The accountant marks the page the AI missed: accepted, read, never the report itself (R9).
        $kinds[0]['kind'] = 'auditor_report';
        $upload = $this->putJson(self::URL."/{$id}/prior-year/statements-file/pages", ['page_kinds' => $kinds])->assertStatus(202)->json('comparative.upload');
        $this->assertSame(['ready', null, ['auditor_report' => ['from' => 1, 'to' => 1]]], [$upload['status'], $upload['rejected'], $upload['skipped']]);
        $this->assertNotContains(1, array_column(array_merge(...array_column($upload['blocks'], 'rows')), 'page'));
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertOk();
    }

    public function test_a_failed_reading_says_why_and_can_be_tried_again(): void
    {
        $id = $this->report();
        $this->fakeAi();
        $this->failTables = true;
        $this->upload($id, $this->pdf());

        $upload = $this->pages($id, ['financial_position' => ['from' => 2, 'to' => 2]])->assertStatus(202)->json('comparative.upload');
        $this->assertSame('failed', $upload['status']);
        $this->assertStringStartsWith('تعذّرت على النظام قراءة الملف:', $upload['error']);
        $this->assertSame(['financial_position' => ['from' => 2, 'to' => 2]], $upload['pages']);   // kept, to correct and retry

        $this->failTables = false;
        $upload = $this->pages($id, ['financial_position' => ['from' => 2, 'to' => 2]])->assertStatus(202)->json('comparative.upload');
        $this->assertSame(['ready', null], [$upload['status'], $upload['error']]);
    }

    public function test_a_pdf_too_long_or_unreadable_is_refused_and_a_new_file_replaces_the_one_waiting(): void
    {
        $id = $this->report();
        $this->fakeAi();
        config(['financial_statements.prior_pdf.max_pages' => 3]);
        $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => $this->pdf(4)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file' => 'الملف أكبر من 3 صفحة. ارفع صفحات القوائم فقط.']);
        $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => UploadedFile::fake()->create('قوائم.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file' => 'هذا الملف ليس ملف PDF مقروءًا، أو عليه كلمة مرور.']);
        $this->assertSame([], $this->calls);

        $first = FinancialReport::find($id)->prior_upload;
        $this->assertNull($first);
        $this->upload($id, $this->pdf(2));
        $path = FinancialReport::find($id)->prior_upload['path'];
        Storage::disk('local')->assertExists($path);
        $this->upload($id, $this->pdf(3));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(3, FinancialReport::find($id)->prior_upload['pages_total']);
    }

    public function test_a_corrected_word_can_be_kept_as_printed_and_a_right_word_is_never_flagged_again(): void
    {
        $id = $this->report();
        $doc = ['checks' => [], 'flags' => [['code' => 'wording_unknown', 'page' => 14, 'printed' => 'مسعود', 'title' => 'عبدالرحمن بن مسعود', 'words' => ['مسعود']]],
            'corrections' => [['id' => 'c1', 'kind' => 'misprint', 'page' => 9, 'pages' => [9], 'original' => 'للمتلكات', 'corrected' => 'للممتلكات', 'title' => 'الأعمار الإنتاجية للمتلكات']]];
        FinancialReport::find($id)->update(['prior_upload' => ['name' => 'قوائم 2024.pdf', 'uploaded_at' => now()->toIso8601String(), 'year' => 2024, 'kind' => 'pdf',
            'id' => 'u1', 'path' => 'x.pdf', 'pages_total' => 16, 'status' => 'ready', 'pages' => [], 'found' => [], 'blocks' => [], 'expenses_shown_positive' => null, 'document' => $doc]]);
        $issues = fn (array $state) => collect($state['comparative']['upload']['issues'])->keyBy('code');
        app()->setLocale('ar');

        // Corrected, said with the text as read; one click keeps it as printed (and the word becomes known).
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertSame(['id' => 'c1', 'original' => 'للمتلكات', 'corrected' => 'للممتلكات', 'kept_original' => false], $issues($state)['corrected_misprint']['correction']);
        $state = $this->putJson(self::URL."/{$id}/prior-year/statements-file/wording", ['keep_original' => ['c1' => true]])->assertOk()->json();
        $this->assertTrue($issues($state)['corrected_misprint']['correction']['kept_original']);
        $this->assertStringContainsString('بقيت كما هي مطبوعة', $issues($state)['corrected_misprint']['message']);
        $this->assertSame(['original'], array_values(FinancialReport::find($id)->prior_upload['wording_choices']));
        // And back to the correction.
        $state = $this->putJson(self::URL."/{$id}/prior-year/statements-file/wording", ['keep_original' => ['c1' => false]])->assertOk()->json();
        $this->assertFalse($issues($state)['corrected_misprint']['correction']['kept_original']);

        // A name marked as right: no longer flagged, here and in any later file.
        $this->assertSame(['مسعود'], $issues($state)['wording_unknown']['words']);
        $state = $this->putJson(self::URL."/{$id}/prior-year/statements-file/wording", ['known_words' => ['مسعود']])->assertOk()->json();
        $this->assertArrayNotHasKey('wording_unknown', $issues($state)->all());
        $this->assertTrue(isset(\App\Services\Statements\PriorPdf\Vocabulary::of([], 'sa-ifrs-smes-3')['مسعود']));
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/wording", ['keep_original' => ['nope' => true]])->assertStatus(422);
    }

    public function test_a_total_differing_from_its_columns_sum_is_switched_by_the_accountant(): void
    {
        $id = $this->report();
        $total = ['id' => 't1', 'table' => 3, 'row' => 5, 'page' => 12, 'title' => 'الرصيد في 1 يناير 2024', 'printed' => 90002.0, 'sum' => 90000.0, 'statement' => 289999.0, 'ties' => 'choose'];
        $doc = ['checks' => [], 'corrections' => [], 'totals' => [$total],
            'flags' => [['code' => 'total_choice', 'page' => 12, 'table' => 3, 'title' => 'الرصيد في 1 يناير 2024', 'total' => 't1']]];
        FinancialReport::find($id)->update(['prior_upload' => ['name' => 'قوائم 2024.pdf', 'uploaded_at' => now()->toIso8601String(), 'year' => 2024, 'kind' => 'pdf',
            'id' => 'u1', 'path' => 'x.pdf', 'pages_total' => 16, 'status' => 'ready', 'pages' => [], 'found' => [], 'blocks' => [], 'expenses_shown_positive' => null, 'document' => $doc]]);
        $issue = fn (array $state) => collect($state['comparative']['upload']['issues'])->firstWhere('code', 'total_choice');
        app()->setLocale('ar');

        // Both figures said, with the page; the sum used till the accountant chooses.
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertSame(['id' => 't1', 'printed' => 90002, 'sum' => 90000, 'used' => 'sum', 'chosen' => false], $issue($state)['total']);
        $this->assertStringContainsString('90,002', $issue($state)['message']);
        $this->assertStringContainsString('90,000', $issue($state)['message']);
        // The printed total chosen, then back to the default.
        $state = $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['t1' => 'printed']])->assertOk()->json();
        $this->assertSame(['printed', true], [$issue($state)['total']['used'], $issue($state)['total']['chosen']]);
        $this->assertSame(['t1' => 'printed'], FinancialReport::find($id)->prior_upload['total_choices']);
        $state = $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['t1' => null]])->assertOk()->json();
        $this->assertSame(['sum', false], [$issue($state)['total']['used'], $issue($state)['total']['chosen']]);
        // Only the file's totals, and only these two figures.
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['nope' => 'sum']])->assertStatus(422);
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['t1' => 'other']])->assertStatus(422);
    }

    public function test_a_year_ends_balances_are_chosen_among_the_combinations_that_agree(): void
    {
        $id = $this->report();
        $options = [['cost' => 380000.0, 'accumulated' => 90000.0, 'nbv' => 290000.0, 'ties' => false, 'sums' => true, 'picks' => [5 => 'sum', 9 => 'sum']],
            ['cost' => 380000.0, 'accumulated' => 90002.0, 'nbv' => 289998.0, 'ties' => false, 'sums' => false, 'picks' => [5 => 'printed', 9 => 'printed']]];
        $doc = ['checks' => [], 'corrections' => [], 'totals' => [],
            'total_groups' => [['id' => 'g1', 'table' => 3, 'page' => 12, 'year' => 2023, 'statement' => 289999.0, 'status' => 'choose', 'options' => $options, 'default' => 0, 'rows' => [5, 9]]],
            'flags' => [['code' => 'total_group_choose', 'page' => 12, 'table' => 3, 'year' => 2023, 'total_group' => 'g1']]];
        FinancialReport::find($id)->update(['prior_upload' => ['name' => 'قوائم 2024.pdf', 'uploaded_at' => now()->toIso8601String(), 'year' => 2024, 'kind' => 'pdf',
            'id' => 'u1', 'path' => 'x.pdf', 'pages_total' => 16, 'status' => 'ready', 'pages' => [], 'found' => [], 'blocks' => [], 'expenses_shown_positive' => null, 'document' => $doc]]);
        $issue = fn (array $state) => collect($state['comparative']['upload']['issues'])->firstWhere('code', 'total_group_choose');
        app()->setLocale('ar');

        // One flag for the year end: both combinations that agree, the categories' used till the accountant chooses.
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertSame([0, false, ['380,000 − 90,000 = 290,000', '380,000 − 90,002 = 289,998']],
            [$issue($state)['total_group']['used'], $issue($state)['total_group']['chosen'], array_column($issue($state)['total_group']['options'], 'text')]);
        $state = $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['g1' => '1']])->assertOk()->json();
        $this->assertSame([1, true], [$issue($state)['total_group']['used'], $issue($state)['total_group']['chosen']]);
        // Only an option it has.
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['g1' => '2']])->assertStatus(422);
        $this->putJson(self::URL."/{$id}/prior-year/statements-file/totals", ['use' => ['g1' => 'printed']])->assertStatus(422);
    }
}
