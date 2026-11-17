<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * PR 14: a version's statements as an xlsx, from its stored payload (Q16): the template filled, whole riyals, zero lines
 * hidden, RTL; a draft marked and listing what's missing and the failing checks; the adjusting entries as journal entries
 * that balance (Q39).
 */
class XlsxExportTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** @var list<Spreadsheet> loaded in a test, freed after it (a workbook holds cycles the suite would otherwise keep) */
    private array $books = [];

    protected function tearDown(): void
    {
        foreach ($this->books as $book) {
            $book->disconnectWorksheets();
        }
        $this->books = [];
        gc_collect_cycles();
        parent::tearDown();
    }

    /** cash 150 = capital 100 + suppliers 30 + profit 20 (sales 200 − rent 180), closing only. */
    private function rows(array $extra = []): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 150.4]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -200.4]),
            $this->account(6, 'إيجارات', ['closing' => 180]),
            ...$extra,
        ];
    }

    private function report(array $rows, array $groups = ['closing']): string
    {
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة التصدير', 'city' => 'جدة', 'cr_number' => '1010000000',
            'cr_date_hijri' => '1440/01/01', 'unified_number' => '7000000000', 'activity' => 'التجارة', 'head_office' => 'جدة'])->json('id');
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'company_id' => $company, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated()->json('id');
    }

    /** First year, every question answered, an adjusting entry: a final. */
    private function finalReport(): string
    {
        $id = $this->report($this->rows());
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk();
        $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [['reason' => 'إثبات مصروف مستحق عن ديسمبر', 'lines' => [
            ['line_key' => 'is.general_admin', 'debit' => 12.4, 'credit' => 0], ['row' => 4, 'debit' => 0, 'credit' => 12.4]]]]])->assertOk();
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => [
            'zakat.zero_reason' => 'الشركة في أول سنة ولا زكاة عليها.', 'oci.has_items' => false, 'approval.body' => 'الشركاء',
            'approval.date' => '2026-02-15', 'partners' => [['name' => 'الشريك الأول', 'shares' => 10, 'share_value' => 10]],
            'first_application.this_period' => false, 'policies.revenue' => ['goods'],
        ]])->assertOk();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $pending = array_filter($state['suggestions'], fn ($s) => $s['status'] === 'pending');
        if ($pending) {
            $this->postJson(self::URL."/{$id}/suggestions", ['decisions' => array_values(array_map(fn ($s) => ['key' => $s['key'], 'action' => 'confirm'], $pending))])->assertOk();
        }
        $warnings = array_filter($this->getJson(self::URL."/{$id}")->json('checks'), fn ($c) => $c['severity'] === 'warning');
        $this->putJson(self::URL."/{$id}/acknowledgements", ['acknowledgements' => array_values(array_map(fn ($c) => ['key' => $c['key']], $warnings))])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'final'])->assertCreated();

        return $id;
    }

    private function download(string $id, int $version = 1): string
    {
        $response = $this->get(self::URL."/{$id}/versions/{$version}/export.xlsx")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));

        return $response->getContent();
    }

    private function book(string $file): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, $file);
        try {
            return $this->books[] = IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function rowOf(Worksheet $sheet, string $column, string $key): int
    {
        foreach ($sheet->getRowIterator() as $r) {
            if ($sheet->getCell($column.$r->getRowIndex())->getValue() === $key) {
                return $r->getRowIndex();
            }
        }
        $this->fail("no {$key}");
    }

    private function shown(Worksheet $sheet, string $coord): mixed
    {
        return $sheet->getCell($coord)->getCalculatedValue();
    }

    private function visibleText(Spreadsheet $book): string
    {
        $text = '';
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCoordinates() as $c) {
                $text .= ' '.$sheet->getCell($c)->getFormattedValue();
            }
        }

        return $text;
    }

    public function test_a_final_fills_the_template(): void
    {
        $id = $this->finalReport();
        $response = $this->get(self::URL."/{$id}/versions/1/export.xlsx")->assertOk();
        $this->assertStringContainsString("filename*=UTF-8''".rawurlencode('القوائم المالية - شركة التصدير - 2025-12-31 - نسخة 1 (نهائي).xlsx'), $response->headers->get('Content-Disposition'));
        $book = $this->book($response->getContent());

        $this->assertSame(['data', 'الغلاف', 'الفهرس', 'المركز المالى', 'قائمة الدخل الشامل', 'قائمة التغيرات', 'قائمة التدفقات النقدية', 'ايضاح الاصول والممتلكات', 'قيود التسوية'], $book->getSheetNames());
        foreach ($book->getAllSheets() as $sheet) {
            $this->assertTrue($sheet->getRightToLeft(), $sheet->getTitle());
            $this->assertSame('', (string) $sheet->getHeaderFooter()->getOddHeader(), $sheet->getTitle());   // not a draft
            $this->assertSame([], $sheet->getComments(), $sheet->getTitle());                                // the template's [review] marks dropped
        }
        $this->assertSame('الغلاف', $book->getActiveSheet()->getTitle());
        $this->assertStringNotContainsString('مسودة', $this->visibleText($book));
        $this->assertSame(['شركة التصدير', 'جدة'], [$book->getSheetByName('data')->getCell('B3')->getValue(), $book->getSheetByName('data')->getCell('B5')->getValue()]);
        $this->assertSame('عن السنة المالية المنتهية في 31 ديسمبر 2025م', $this->shown($book->getSheetByName('الغلاف'), 'A13'));

        // Balance sheet: whole riyals, the note numbers, the totals' formulas; a first year has one column; zero lines hidden.
        $sofp = $book->getSheetByName('المركز المالى');
        $cash = $this->rowOf($sofp, 'J', 'sofp.cash');
        $this->assertSame(150, $sofp->getCell("E{$cash}")->getValue());
        $this->assertSame('150', $sofp->getCell("E{$cash}")->getFormattedValue());
        $this->assertIsInt($sofp->getCell("B{$cash}")->getValue());
        $total = $this->rowOf($sofp, 'J', 'sofp.total_assets');
        $this->assertTrue($sofp->getCell("E{$total}")->isFormula());
        $this->assertEquals(150, $this->shown($sofp, "E{$total}"));
        $this->assertFalse($sofp->getColumnDimension('H')->getVisible());
        $this->assertFalse($sofp->getRowDimension($this->rowOf($sofp, 'J', 'sofp.inventory'))->getVisible());
        $this->assertFalse($sofp->getRowDimension($this->rowOf($sofp, 'J', 'sofp.total_non_current_assets'))->getVisible());
        $this->assertFalse($sofp->getRowDimension($this->rowOf($sofp, 'J', 'sofp.ppe') - 1)->getVisible());   // its heading, no line under it
        $this->assertTrue($sofp->getRowDimension($cash)->getVisible());
        $this->assertEquals(0, $this->shown($sofp, 'E'.$this->rowOf($sofp, 'J', 'sofp.check_balance')));

        // Income statement: an expense in parentheses; the adjusting entry reached it.
        $is = $book->getSheetByName('قائمة الدخل الشامل');
        $ga = $this->rowOf($is, 'J', 'is.general_admin');
        $this->assertSame(-192, $is->getCell("E{$ga}")->getValue());
        $this->assertSame('(192)', $is->getCell("E{$ga}")->getFormattedValue());

        // Changes in equity: no comparative rows in a first year; empty columns hidden, the total shown.
        $soce = $book->getSheetByName('قائمة التغيرات');
        $this->assertFalse($soce->getRowDimension($this->rowOf($soce, 'A', 'soce.comparative.opening'))->getVisible());
        $this->assertTrue($soce->getColumnDimension('Q')->getVisible());
        $this->assertFalse($soce->getColumnDimension('K')->getVisible());   // other reserves: nothing
        $closing = $this->rowOf($soce, 'A', 'soce.current.closing');
        $this->assertEquals($this->shown($sofp, 'E'.$this->rowOf($sofp, 'J', 'sofp.total_equity')), $this->shown($soce, "Q{$closing}"));

        // Cash flow: the closing cash ties to the balance sheet. No PPE: its note's sheet is hidden.
        $cf = $book->getSheetByName('قائمة التدفقات النقدية');
        $this->assertEquals(0, $this->shown($cf, 'E'.$this->rowOf($cf, 'J', 'cf.check_difference')));
        $this->assertSame('hidden', $book->getSheetByName('ايضاح الاصول والممتلكات')->getSheetState());

        // Journal entries (Q39): the account by number and name, or the statement line; each entry balances.
        $j = $book->getSheetByName('قيود التسوية');
        $this->assertSame([1, 'سطر في القوائم: المصروفات العمومية والإدارية', 12.4, null, 'إثبات مصروف مستحق عن ديسمبر'],
            [$j->getCell('A6')->getValue(), $j->getCell('B6')->getValue(), $j->getCell('C6')->getValue(), $j->getCell('D6')->getValue(), $j->getCell('E6')->getValue()]);
        $this->assertSame(['1004 — حساب 4', null, 12.4], [$j->getCell('B7')->getValue(), $j->getCell('C7')->getValue(), $j->getCell('D7')->getValue()]);
        $this->assertSame('إجمالي القيد 1', $j->getCell('B8')->getValue());
        $this->assertEquals([12.4, 12.4, 0], [$this->shown($j, 'C8'), $this->shown($j, 'D8'), $this->shown($j, 'F8')]);
        $this->assertSame('هل جميع القيود متوازنة؟', $j->getCell('B12')->getValue());
        $this->assertSame('نعم', $this->shown($j, 'C12'));
        $this->assertSame('12.40', $j->getCell('C6')->getFormattedValue());
    }

    public function test_the_same_version_always_gives_the_same_file(): void
    {
        $id = $this->finalReport();
        $first = $this->download($id);
        $this->travel(2)->minutes();
        $this->assertSame(sha1($first), sha1($this->download($id)));

        // The report changes after the version: the version's file doesn't.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['approval.body' => 'الجمعية العامة']])->assertOk();
        $this->putJson(self::URL."/{$id}/adjustments", ['entries' => []])->assertOk();
        $this->assertSame(sha1($first), sha1($this->download($id)));
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $this->assertNotSame(sha1($first), sha1($this->download($id, 2)));
    }

    public function test_a_draft_is_marked_and_lists_what_is_missing_and_the_failing_checks(): void
    {
        // PPE with movements: vehicles bought, depreciation of one category; no prior-year source yet.
        $id = $this->report($this->rows([
            $this->account(14, 'سيارات ووسائل نقل', ['opening' => 200, 'period' => [50, 0]]),
            $this->account(15, 'مجمع إهلاك الأصول الثابتة', ['opening' => -40, 'period' => [0, 20]]),
            $this->account(18, 'إهلاك السيارات', ['period' => [20, 0]]),
        ]), ['opening', 'period', 'closing']);
        $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 15, 'category' => 'vehicles']]])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $book = $this->book($this->download($id));

        // Marked: a sheet after the template's, opened first; the cover; every page's header.
        $this->assertSame('ملاحظات المسودة', $book->getSheetNames()[8]);
        $this->assertSame('قيود التسوية', $book->getSheetNames()[9]);
        $this->assertSame('ملاحظات المسودة', $book->getActiveSheet()->getTitle());
        $this->assertSame('مسودة — للمراجعة فقط، غير معتمدة', $book->getSheetByName('الغلاف')->getCell('A15')->getValue());
        foreach ($book->getAllSheets() as $sheet) {
            $this->assertStringContainsString('مسودة', (string) $sheet->getHeaderFooter()->getOddHeader(), $sheet->getTitle());
        }

        // The notes list what blocks a final, every missing figure (by name) and every failing check.
        $notes = $book->getSheetByName('ملاحظات المسودة');
        $text = implode("\n", array_map(fn ($c) => (string) $notes->getCell($c)->getValue(), $notes->getCoordinates()));
        foreach ($state['blocking'] as $b) {
            $this->assertStringContainsString("{$b['label_ar']} ({$b['count']})", $text);
        }
        $this->assertNotEmpty($state['missing']);
        $this->assertStringContainsString('قائمة الدخل الشامل — السنة السابقة', $text);
        $this->assertStringContainsString('ينتظر مصدر أرقام السنة السابقة', $text);
        foreach (array_filter($state['checks'], fn ($c) => $c['severity'] === 'error') as $c) {
            $this->assertStringContainsString($c['message_ar'], $text);
        }
        $this->assertDoesNotMatchRegularExpression('/\b[a-z]+(?:_[a-z0-9]+)+\b/', $text);   // no code

        // A missing figure reads «[ناقص]», never 0, and so does a total over it.
        $is = $book->getSheetByName('قائمة الدخل الشامل');
        $this->assertSame('[ناقص]', $is->getCell('H'.$this->rowOf($is, 'J', 'is.revenue'))->getValue());
        $this->assertSame('[ناقص]', $is->getCell('H'.$this->rowOf($is, 'J', 'is.gross_profit'))->getValue());
        $this->assertTrue($book->getSheetByName('المركز المالى')->getColumnDimension('H')->getVisible());
        $this->assertSame('31 ديسمبر 2024م', $this->shown($book->getSheetByName('data'), 'B12'));   // the year before, when no dates were given

        // The PPE note: a column per category of the note's (its name written over it), the template's others hidden;
        // the year's additions.
        $ppe = $book->getSheetByName('ايضاح الاصول والممتلكات');
        $this->assertSame('visible', $ppe->getSheetState());
        $this->assertSame(['سيارات', true], [$ppe->getCell('C7')->getValue(), $ppe->getColumnDimension('C')->getVisible()]);
        $this->assertFalse($ppe->getColumnDimension('D')->getVisible());
        $this->assertSame(50, $ppe->getCell('C'.$this->rowOf($ppe, 'A', 'ppe.additions'))->getValue());
        $this->assertFalse($ppe->getRowDimension($this->rowOf($ppe, 'A', 'ppe.transfers'))->getVisible());
        $this->assertFalse($ppe->getRowDimension($this->rowOf($ppe, 'A', 'ppe.revaluation'))->getVisible());   // zero in both years

        $this->assertSame('لا توجد قيود تسوية.', $book->getSheetByName('قيود التسوية')->getCell('A5')->getValue());
    }

    public function test_depreciation_and_amortisation_are_separate_lines_also_for_an_older_version(): void
    {
        // R5: «مصروف الإهلاك» and «مصروف الإطفاء», each on its own row; the totals add both.
        $id = $this->report($this->rows([
            $this->account(18, 'إهلاك السيارات', ['closing' => 15]),
            $this->account(19, 'إطفاء الأصول غير الملموسة', ['closing' => 5]),
            $this->account(20, 'إيرادات أخرى', ['closing' => -20]),
        ]));
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $is = $this->book($this->download($id))->getSheetByName('قائمة الدخل الشامل');
        [$dep, $amort] = [$this->rowOf($is, 'J', 'is.depreciation'), $this->rowOf($is, 'J', 'is.amortisation')];
        $this->assertSame([$dep + 1, 'مصروف الإهلاك', 'مصروف الإطفاء'], [$amort, $is->getCell("A{$dep}")->getValue(), $is->getCell("A{$amort}")->getValue()]);
        $this->assertSame([-15, -5], [$is->getCell("E{$dep}")->getValue(), $is->getCell("E{$amort}")->getValue()]);
        $this->assertEquals(20, $this->shown($is, 'E'.$this->rowOf($is, 'J', 'is.net_profit')));

        // A version saved before the split (one line, `is.depreciation_amortisation`): its figure is on «مصروف الإهلاك».
        $version = DB::table('financial_report_versions')->where('report_id', $id)->first();
        $old = str_replace(['"is.amortisation"', '"is.depreciation"'], ['"is.amortisation_gone"', '"is.depreciation_amortisation"'], $version->payload);
        DB::table('financial_report_versions')->where('id', $version->id)->update(['payload' => $old]);
        $is = $this->book($this->download($id))->getSheetByName('قائمة الدخل الشامل');
        $this->assertSame(-15, $is->getCell('E'.$this->rowOf($is, 'J', 'is.depreciation'))->getValue());
    }

    public function test_the_migration_moves_reports_to_the_depreciation_line(): void
    {
        $id = $this->report($this->rows());
        DB::table('financial_reports')->where('id', $id)->update(['account_overrides' => json_encode([['row' => 6, 'line_key' => 'is.depreciation_amortisation']]),
            'prior_figures' => json_encode(['is.depreciation_amortisation' => ['amount' => 7], 'is.general_admin' => ['amount' => 1]])]);
        (include database_path('migrations/2026_10_23_000001_split_depreciation_and_amortisation.php'))->up();

        $report = DB::table('financial_reports')->where('id', $id)->first();
        $this->assertSame([['row' => 6, 'line_key' => 'is.depreciation']], json_decode($report->account_overrides, true));
        $this->assertSame(['is.depreciation' => ['amount' => 7], 'is.general_admin' => ['amount' => 1]], json_decode($report->prior_figures, true));
    }

    public function test_a_revaluation_is_a_row_of_the_ppe_note_and_the_cost_adds_up(): void
    {
        $id = $this->report($this->rows([
            $this->account(14, 'سيارات ووسائل نقل', ['opening' => 200, 'period' => [80, 0]]),
            $this->account(15, 'مجمع إهلاك الأصول الثابتة', ['opening' => -40, 'period' => [0, 20]]),
            $this->account(18, 'إهلاك السيارات', ['period' => [20, 0]]),
        ]), ['opening', 'period', 'closing']);
        $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 15, 'category' => 'vehicles']]])->assertOk();
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['oci.has_items' => true, 'oci.revaluation' => 30]])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $schedule = collect($this->getJson(self::URL."/{$id}")->json('schedules'))->firstWhere('key', 'ppe');
        $rows = array_column($schedule['rows'], null, 'key');
        $this->assertEquals(30, $rows['revaluation']['cells']['vehicles']['amount']);

        $ppe = $this->book($this->download($id))->getSheetByName('ايضاح الاصول والممتلكات');
        $reval = $this->rowOf($ppe, 'A', 'ppe.revaluation');
        $this->assertTrue($ppe->getRowDimension($reval)->getVisible());
        $this->assertSame('فائض إعادة التقويم', $ppe->getCell("B{$reval}")->getValue());
        $this->assertSame([30, 30], [$ppe->getCell("C{$reval}")->getValue(), $this->shown($ppe, "J{$reval}")]);
        // The closing cost is the template's sum over the rows shown, revaluation included, and it is the report's.
        $closing = $this->rowOf($ppe, 'A', 'ppe.cost_closing');
        $this->assertSame("=C{$this->rowOf($ppe, 'A', 'ppe.cost_opening')}+C{$this->rowOf($ppe, 'A', 'ppe.additions')}+C{$this->rowOf($ppe, 'A', 'ppe.transfers')}+C{$reval}-C{$this->rowOf($ppe, 'A', 'ppe.disposals')}",
            $ppe->getCell("C{$closing}")->getValue());
        $this->assertEquals(round($rows['cost_closing']['cells']['vehicles']['amount']), $this->shown($ppe, "C{$closing}"));
        $this->assertTrue($ppe->getCell("J{$closing}")->isFormula());
        $this->assertEquals(round($rows['nbv_closing']['cells']['total']['amount']), $this->shown($ppe, 'J'.$this->rowOf($ppe, 'A', 'ppe.nbv_closing')));
        $this->assertSame('A1:J21', $ppe->getPageSetup()->getPrintArea());
    }

        public function test_entries_name_their_accounts_in_the_state(): void
    {
        $id = $this->report($this->rows());
        $state = $this->putJson(self::URL."/{$id}/adjustments", ['entries' => [['reason' => 'تسوية', 'lines' => [
            ['line_key' => 'is.general_admin', 'debit' => 5, 'credit' => 0], ['row' => 4, 'debit' => 0, 'credit' => 5]]]]])->assertOk()->json();
        $this->assertSame(['1004', 'حساب 4'], [$state['adjustments'][0]['lines'][1]['account_number'], $state['adjustments'][0]['lines'][1]['account_name']]);
        $this->assertArrayNotHasKey('account_number', $state['adjustments'][0]['lines'][0]);
        $this->assertSame(1, FinancialReport::count());
    }

    public function test_unknown_report_or_version(): void
    {
        $id = $this->report($this->rows());
        $this->getJson(self::URL."/{$id}/versions/1/export.xlsx")->assertNotFound()->assertJsonPath('message', 'هذا الإصدار غير موجود.');
        $this->getJson(self::URL.'/'.Str::uuid().'/versions/1/export.xlsx')->assertNotFound()->assertJsonPath('message', 'القوائم المالية هذه غير موجودة.');
    }

    public function test_a_loss_is_called_a_loss_in_the_file(): void
    {
        // Revenue 100, rent 400: a loss of 300, a first year.
        $id = $this->report([$this->account(2, 'نقدية لدى البنوك', ['closing' => 50]), $this->account(3, 'رأس المال', ['closing' => 250]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -100]), $this->account(6, 'إيجارات', ['closing' => 400]), $this->account(4, 'موردون', ['closing' => -600])]);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $book = $this->book($this->download($id));

        $is = $book->getSheetByName('قائمة الدخل الشامل');
        $this->assertSame('صافي الخسارة للسنة', $is->getCell('A'.$this->rowOf($is, 'J', 'is.net_profit'))->getValue());
        $soce = $book->getSheetByName('قائمة التغيرات');
        $this->assertSame('صافي الخسارة للسنة', $soce->getCell('C'.$this->rowOf($soce, 'A', 'soce.current.net_profit'))->getValue());
    }
}
