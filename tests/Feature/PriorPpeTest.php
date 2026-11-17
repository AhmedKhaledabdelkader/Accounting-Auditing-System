<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use App\Services\Exports\StatementsXlsx;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPpe;
use App\Services\Statements\PriorStatementsPdf;
use App\Services\Statements\ReportStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * The PPE note by last year's categories (Phase 6, R13): categories, rates and useful lives from last year's PDF, this
 * year's accounts placed on them, the opening checked against last year's closing, last year's movement as the note's
 * comparative. Made-up figures.
 */
class PriorPpeTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /**
     * @param list<string> $groups the trial balance's columns (a closing-only one: ['closing'])
     * @param ?string $policy the policies note's sentence on depreciation (its method), null: none
     */
    private function report(array $groups = ['opening', 'closing'], float $vehiclesDepreciation = 12, ?string $policy = 'يتم احتساب الاستهلاك بطريقة القسط الثابت بالنسب التالية:'): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'مباني وإنشاءات', ['opening' => 110, 'period' => [0, 0]], ['account_name' => 'مبنى المصنع']),
            $this->account(3, 'سيارات ووسائل نقل', ['opening' => 50, 'period' => [20, 0]], ['account_name' => 'سيارات']),
            $this->account(4, 'مجمع إهلاك الأصول الثابتة', ['opening' => -25, 'period' => [0, 5]], ['account_name' => 'مجمع إهلاك مبنى المصنع']),
            $this->account(5, 'مجمع إهلاك الأصول الثابتة', ['opening' => -20, 'period' => [0, $vehiclesDepreciation]], ['account_name' => 'مجمع إهلاك السيارات']),
        ], $groups)]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table(['2024', '2023'], [$row('الممتلكات والآلات والمعدات، بالصافي', ['115', '120'], '5'), $row('إجمالي الأصول', ['115', '120'])])],
            3 => [$heading('الإيضاحات'), $heading('السياسات المحاسبية', '4'),
                ...($policy === null ? [] : [['type' => 'paragraph', 'number' => null, 'text' => $policy, 'columns' => [], 'rows' => []]]),
                $table(['النسبة %'], [$row('مباني وانشاءات', ['5']), $row('سيارات', ['25'])]),
                $heading('ممتلكات وآلات ومعدات', '5'), $table(['مباني وانشاءات / ريال', 'سيارات / ريال', 'الإجمالي / ريال'], [
                    $row('التكلفة', [null, null, null]), $row('الرصيد في 1 يناير 2024', ['100', '50', '150']), $row('إضافات خلال السنة', ['10', '-', '10']),
                    $row('الرصيد في 31 ديسمبر 2024', ['110', '50', '160']),
                    $row('الإهلاك المتراكم', [null, null, null]), $row('الرصيد في 1 يناير 2024', ['20', '10', '30']), $row('إضافات خلال السنة', ['5', '10', '15']),
                    $row('الرصيد في 31 ديسمبر 2024', ['25', '20', '45']),
                    $row('صافي القيمة الدفترية', [null, null, null]), $row('في 31 ديسمبر 2024', ['85', '30', '115']), $row('في 31 ديسمبر 2023', ['80', '40', '120']),
                ])],
        ];
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answers) {
            $body = $request->data();
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => $answers[(int) ($m[1] ?? 0)] ?? [], 'tables' => []]));
        }]);
        // Another policy, another file: the reading kept for a file is never reused for another.
        $path = $this->pdfFile(['<h1>قائمة المركز المالي</h1>', '<p>الإيضاحات</p>', '<p>'.($policy ?? '.').'</p>']);
        $kinds = [['page' => 2, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null], ['page' => 3, 'kind' => 'notes', 'rotation' => 0, 'printed_page' => null]];
        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));
        $file = ['name' => 'قوائم 2024.pdf', 'kind' => 'pdf', 'status' => 'ready', 'year' => 2024, 'uploaded_at' => now()->toIso8601String(), 'pages_total' => 3,
            'pages' => [], 'found' => [], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null];
        FinancialReport::find($id)->update(['comparative_start' => '2024-01-01', 'comparative_end' => '2024-12-31',
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false]]);

        return $id;
    }

    public function test_no_life_is_derived_from_a_rate_when_the_method_isnt_given(): void
    {
        $this->assertNoLifeDerived(null, null);
        // A lease's income «بالقسط الثابت» says nothing of the assets' depreciation.
        $this->assertNull(PriorPpe::method(['sections' => [['title' => 'عقود الإيجار', 'paragraphs' => ['تثبت إيرادات الإيجار بالقسط الثابت.']]], 'tables' => []]));
    }

    public function test_no_life_is_derived_from_a_rate_under_another_method(): void
    {
        $this->assertNoLifeDerived('يتم احتساب الاستهلاك بطريقة القسط المتناقص بالنسب التالية:', 'other');
    }

    /** The file gives the rates but not the straight-line method: the rates are shown, no life is made up, a warning says why. */
    private function assertNoLifeDerived(?string $policy, ?string $method): void
    {
        $id = $this->report(policy: $policy);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertSame([['مباني وانشاءات', 5, null], ['سيارات', 25, null]], array_map(fn ($c) => [$c['label'], $c['rate'], $c['life']], $state['ppe_prior']['categories']));
        $fields = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->keyBy('key');
        $this->assertSame([null, null], [$fields['useful_life.c1']['default'], $fields['useful_life.c2']['default']]);
        // Nothing suggested: the life is missing, plainly.
        $required = collect($state['checks'])->first(fn ($c) => $c['code'] === 'empty_required_input' && $c['input_key'] === 'useful_life.c1');
        $this->assertSame(['error', 'مُدخل مطلوب ناقص', false, 'مطلوب: العمر الإنتاجي: مباني وانشاءات.'], [$required['severity'], $required['title_ar'], $required['suggested'], $required['message_ar']]);
        [$check] = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'useful_life_not_derived'));
        $this->assertSame(['warning', $method], [$check['severity'], $check['method']]);
        $this->assertStringContainsString('مباني وانشاءات، سيارات', $check['message_ar']);

        // The accountant gives the lives: nothing left to flag.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['useful_life.c1' => '20', 'useful_life.c2' => '4']])->assertOk();
        $this->assertNotContains('useful_life_not_derived', array_column($this->getJson(self::URL."/{$id}")->json('checks'), 'code'));
    }

    public function test_last_years_categories_rates_and_movement_carry_into_this_years_note(): void
    {
        $id = $this->report();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $pp = $state['ppe_prior'];

        // Last year's categories as printed, with their rates and lives (100 / rate: the file says straight-line).
        $this->assertSame([['مباني وانشاءات', 5, 20], ['سيارات', 25, 4]], array_map(fn ($c) => [$c['label'], $c['rate'], $c['life']], $pp['categories']));
        // Each account on its category: by its opening = last year's closing (the building, its depreciation), by name.
        $this->assertSame([[2, 'c1', 'amount'], [3, 'c2', 'name'], [4, 'c1', 'amount'], [5, 'c2', 'name']],
            array_map(fn ($a) => [$a['row'], $a['category'], $a['by']], $pp['accounts']));
        // This year's opening = last year's closing, each side.
        $this->assertSame([['equal', 'equal'], ['equal', 'equal']], array_map(fn ($c) => array_column($c['opening_check'], 'kind'), $pp['categories']));
        // The cars' additions are depreciated this year: nothing to propose.
        $this->assertSame([], array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'ppe_additions_depreciation')));

        // The note's own categories: the depreciation accounts take their category from last year's (no question), and
        // last year's movement is the comparative, nothing typed.
        $built = app(ReportStatements::class)->build(FinancialReport::find($id));
        $ppe = collect($built['schedules'])->firstWhere('key', 'ppe');
        $this->assertTrue($ppe['split']['accumulated']);
        $cmp = $built['schedules_comparative'];
        $this->assertEquals([100, 10, 20, 5], [$cmp['ppe.cost_opening.buildings']->amount, $cmp['ppe.additions.buildings']->amount,
            $cmp['ppe.acc_opening.buildings']->amount, $cmp['ppe.depreciation.buildings']->amount]);
        $this->assertEquals(10, $cmp['ppe.depreciation.vehicles']->amount);
        // Step 2 shows last year's movement as read, by last year's categories, not an empty grid of the template's.
        $entry = $state['comparative']['entry'];
        $this->assertSame(['page' => 3], $entry['ppe_from_pdf']);
        $this->assertEquals([100, 5], [$entry['schedules_prior']['ppe.cost_opening.buildings'], $entry['schedules_prior']['ppe.depreciation.buildings']]);
        // Useful lives from last year's rates, one per category of last year's.
        $fields = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->keyBy('key');
        $this->assertSame([['useful_life.c1', '20'], ['useful_life.c2', '4']], [[$fields['useful_life.c1']['key'], $fields['useful_life.c1']['default']], [$fields['useful_life.c2']['key'], $fields['useful_life.c2']['default']]]);

        // Each suggested life is to confirm, not missing information: said so, and still an error until accepted.
        $check = collect($state['checks'])->first(fn ($c) => ($c['input_key'] ?? null) === 'useful_life.c1');
        $this->assertSame(['input_to_confirm', 'يحتاج إلى تأكيد', 'warning', true, false], [$check['code'], $check['title_ar'], $check['severity'], $check['suggested'], $check['acknowledged']]);
        $this->assertStringStartsWith('يحتاج إلى تأكيد: العمر الإنتاجي: مباني وانشاءات', $check['message_ar']);
        // Never acknowledged away: only saving the answer confirms it, and then nothing is left to say.
        $this->putJson(self::URL."/{$id}/acknowledgements", ['acknowledgements' => [['key' => $check['key']]]])->assertStatus(422)->assertJsonValidationErrors('acknowledgements.0.key');
        $this->assertContains('warnings_unacknowledged', array_column($this->getJson(self::URL."/{$id}")->json('blocking'), 'reason'));
        $saved = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['useful_life.c1' => '20']])->assertOk()->json('checks');
        $this->assertSame([], array_values(array_filter($saved, fn ($c) => ($c['input_key'] ?? null) === 'useful_life.c1')));

        // The notes' useful-life table: a row per category of last year's, each its own life.
        $lives = $this->getJson(self::URL."/{$id}")->json('narrative.items.useful_life_label');
        $this->assertSame([['مباني وانشاءات', '20'], ['سيارات', '4']], array_map(fn ($r) => [$r['useful_life_label'], $r['useful_life_years']], $lives));

        // The accountant moves an account (searchable list), and back.
        $this->patchJson(self::URL."/{$id}/notes", ['ppe' => ['3' => 'none']])->assertOk();
        $moved = collect($this->getJson(self::URL."/{$id}")->json('ppe_prior.accounts'))->firstWhere('row', 3);
        $this->assertSame([null, 'choice'], [$moved['category'], $moved['by']]);
        $this->patchJson(self::URL."/{$id}/notes", ['ppe' => ['3' => 'c9']])->assertStatus(422)->assertJsonValidationErrors('ppe.3');
        $this->patchJson(self::URL."/{$id}/notes", ['ppe' => ['3' => null]])->assertOk();
        $this->assertSame('name', collect($this->getJson(self::URL."/{$id}")->json('ppe_prior.accounts'))->firstWhere('row', 3)['by']);
    }

    public function test_additions_with_no_depreciation_get_a_proposal_at_last_years_rate_never_posted(): void
    {
        // The cars: 20 added, nothing depreciated this year; last year's rate 25%.
        $id = $this->report(['opening', 'period', 'closing'], vehiclesDepreciation: 0);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $proposed = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'ppe_additions_depreciation'));
        $this->assertSame([['warning', 'c2', 5]], array_map(fn ($c) => [$c['severity'], $c['category'], $c['amount']], $proposed));
        $this->assertStringContainsString('لم يُسجَّل', $proposed[0]['message_ar']);
        // Nothing posted: the expense and the cars' depreciation stay the trial balance's (none).
        $this->assertEquals(0, collect($state['statements']['comprehensive_income'])->firstWhere('key', 'is.depreciation')['current']['amount']);
        $this->assertEquals(0, $state['ppe_prior']['categories'][1]['current']['depreciation']);
    }

    public function test_a_closing_only_trial_balance_takes_last_years_categories_without_an_opening_to_check(): void
    {
        $id = $this->report(['closing']);
        $pp = $this->getJson(self::URL."/{$id}")->assertOk()->json('ppe_prior');

        // No opening: nothing placed by amount, nothing compared; by name where the names say it, the rest left to choose.
        $this->assertSame(['مباني وانشاءات', 'سيارات'], array_column($pp['categories'], 'label'));
        $this->assertSame([[2, null, 'none'], [3, 'c2', 'name'], [4, null, 'none'], [5, 'c2', 'name']],
            array_map(fn ($a) => [$a['row'], $a['category'], $a['by']], $pp['accounts']));
        $this->assertSame([[], []], array_column($pp['categories'], 'opening_check'));
    }

    public function test_the_files_show_last_years_categories_as_columns_any_number_of_them(): void
    {
        $id = $this->report();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $sets = $state['narrative']['columns']['ppe_category'];
        $cells = fn (string $key) => array_map(fn ($s) => $s[$key]['amount'], $sets);

        // A column per category of last year's, as printed: this year's figures from its accounts, last year's as printed.
        $this->assertSame(['مباني وانشاءات', 'سيارات'], array_column($sets, 'ppe_category'));
        $this->assertEquals([110, 50], $cells('ppe_cy_cost_opening_c'));
        // No movement columns: the year's movements are the note's, missing till the user gives them.
        $this->assertEquals([null, null], $cells('ppe_cy_additions_c'));
        $this->assertEquals([80, 38], $cells('ppe_cy_nbv_closing_c'));
        $this->assertEquals([100, 50], $cells('ppe_py_cost_opening_c'));
        $this->assertEquals([10, 0], $cells('ppe_py_additions_c'));
        $this->assertNotContains('ppe_columns_own', array_column($state['checks'], 'code'));
        // The notes page: the column repeated per category; one movement table, the current year's (IFRS for SMEs 17.31).
        $tables = collect(collect($state['notes'])->firstWhere('key', 'ppe')['blocks'])->where('type', 'table')->values();
        $this->assertCount(1, $tables);
        $this->assertSame(['', 'مباني وانشاءات', 'سيارات', 'الإجمالي'], array_column($tables[0]['rows'][1]['cells'], 'text_ar'));
        $this->assertArrayNotHasKey('ppe.comparative_table', $state['inputs'] ?? []);

        // The Word file: the same columns; the Excel file: the categories' names over the template's first columns.
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $docx = $this->get(self::URL."/{$id}/versions/1/export.docx")->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $docx);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('مباني وانشاءات', $xml);
        $this->assertStringNotContainsString('${ppe_', $xml);
        $xlsx = $this->get(self::URL."/{$id}/versions/1/export.xlsx")->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);
        $sheet = IOFactory::load($path)->getSheetByName('ايضاح الاصول والممتلكات');
        @unlink($path);
        $this->assertSame(['مباني وانشاءات', 'سيارات'], [$sheet->getCell('C7')->getValue(), $sheet->getCell('D7')->getValue()]);
        $this->assertSame([true, true, false], [$sheet->getColumnDimension('C')->getVisible(), $sheet->getColumnDimension('D')->getVisible(), $sheet->getColumnDimension('E')->getVisible()]);

        // More categories than the template has columns (9 for 7): columns inserted before its last, the totals over all.
        $payload = FinancialReportVersion::where('report_id', $id)->first()->exportPayload();
        $one = $payload['narrative']['columns']['ppe_category'][1];
        $payload['narrative']['columns']['ppe_category'] = array_map(fn ($i) => ['ppe_category' => "فئة {$i}"] + $one, range(1, 9));
        $book = IOFactory::load($this->saved(app(StatementsXlsx::class)->build($payload, now()->toIso8601String())));
        $sheet = $book->getSheetByName('ايضاح الاصول والممتلكات');
        $cost = $this->keyedRow($sheet, 'ppe.cost_opening');
        $this->assertSame(['فئة 1', 'فئة 9', 'الإجمالي'], [$sheet->getCell('C7')->getValue(), $sheet->getCell('K7')->getValue(), $sheet->getCell('L7')->getValue()]);
        $this->assertSame("=SUM(C{$cost}:K{$cost})", $sheet->getCell("L{$cost}")->getValue());
        // An inserted column's own formula (a row whose parts are all known: the movements are missing here).
        $nbv = $this->keyedRow($sheet, 'ppe.nbv_comparative');
        $this->assertSame("=J{$cost}-J".$this->keyedRow($sheet, 'ppe.acc_dep_opening'), $sheet->getCell("J{$nbv}")->getValue());
        $this->assertSame(50, $sheet->getCell("K{$cost}")->getValue());
        $this->assertMatchesRegularExpression('/:\$?L\$?\d+$/', $sheet->getPageSetup()->getPrintArea());
    }

    private function saved(string $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $file);

        return $path;
    }

    private function keyedRow($sheet, string $key): int
    {
        for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
            if ($sheet->getCell("A{$r}")->getValue() === $key) {
                return $r;
            }
        }
        $this->fail("no row {$key}");
    }

    public function test_last_years_categories_not_adding_up_to_the_notes_own_give_the_notes_own_flagged(): void
    {
        // The car and its depreciation put on last year's buildings: that category holds accounts of two of the note's own
        // categories, its figures can't be told apart: the note's own categories, said (P3).
        $id = $this->report();
        $this->patchJson(self::URL."/{$id}/notes", ['ppe' => ['3' => 'c1', '5' => 'c1']])->assertOk();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertSame(['مباني', 'سيارات'], array_column($state['narrative']['columns']['ppe_category'], 'ppe_category'));
        $this->assertContains('ppe_columns_own', array_column($state['checks'], 'code'));

        // A closing-only trial balance: each of last year's categories alone on one of the note's own takes its figures;
        // the building's accounts not placed (no opening to place them by): a column of the note's own after last year's.
        $state = $this->getJson(self::URL.'/'.$this->report(['closing']))->assertOk()->json();
        $this->assertSame(['مباني وانشاءات', 'سيارات', 'مباني'], array_column($state['narrative']['columns']['ppe_category'], 'ppe_category'));
        $this->assertNotContains('ppe_columns_own', array_column($state['checks'], 'code'));
    }
}
