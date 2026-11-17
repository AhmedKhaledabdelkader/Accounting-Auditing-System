<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;
use ZipArchive;

/** Adjustment columns in the sheet, the balance checks around them, SUBTOTAL rows, audit requests, manual calculation. */
class InSheetAdjustmentsTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    /** Layout like the Bustan file: opening, movement, adjustments, closing, requests. */
    private const HEADER = ['رقم', 'اسم الحساب', 'أول مدين', 'أول دائن', 'حركة مدينة', 'حركة دائنة', 'تسوية مدين', 'تسوية دائن', 'أخر مدين', 'أخر دائن', 'الطلبات'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function structure(array $extra = []): array
    {
        return array_replace_recursive([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => [
                'account_number' => 'A', 'account_name' => 'B',
                'opening_debit' => 'C', 'opening_credit' => 'D',
                'period_debit' => 'E', 'period_credit' => 'F',
                'adjustment_debit' => 'G', 'adjustment_credit' => 'H',
                'closing_debit' => 'I', 'closing_credit' => 'J',
                'audit_request' => 'K',
            ],
        ], $extra);
    }

    public function test_adjustments_are_part_of_the_reconciliation(): void
    {
        $file = $this->xlsxUpload([
            self::HEADER,
            ['1101', 'الصندوق', 100, 0, 50, 20, 10, 0, 140, 0],   // 100 + 30 + 10 = 140 ✓
            ['1102', 'البنك', 0, 0, 100, 0, 0, 30, 100, 0],       // 100 − 30 = 70 ≠ 100
        ]);
        $this->fakeLlm($this->structure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية لدى البنوك')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.groups', ['opening', 'period', 'adjustment', 'closing'])
            ->assertJsonPath('data.0.adjustment_debit', 10)
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.1.data_flags.0.type', 'reconciliation')
            ->assertJsonPath('data.1.data_flags.0.difference', 30)
            ->assertJsonPath('data.1.data_flags.0.message', 'الرصيد الختامي أعلى من المحسوب بمقدار 30.00');
    }

    public function test_without_adjustment_columns_the_fields_are_zero(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 5, 0, 5, 0]])])
            ->assertOk()
            ->assertJsonPath('meta.groups', ['opening', 'period', 'closing'])
            ->assertJsonPath('data.0.adjustment_debit', 0)
            ->assertJsonPath('data.0.adjustment_credit', 0);
    }

    public function test_unbalanced_adjustments_warn_even_on_a_partial_trial_balance(): void
    {
        $file = $this->xlsxUpload([
            self::HEADER,
            ['1101', 'الصندوق', 0, 0, 0, 0, 15.15, 0, 15.15, 0],
            ['1102', 'البنك', 0, 0, 0, 0, 0, 10, 0, 10],
        ]);
        $this->fakeLlm($this->structure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية لدى البنوك')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.warnings', ['إجمالي مدين التسويات لا يساوي إجمالي دائن التسويات (الفرق 5.15)', '1 حساب نقدية برصيد ختامي دائن: «البنك» (الصف 3 في الملف)'])
            ->assertJsonPath('meta.info', ['هذا الملف ميزان جزئي (فيه أصول فقط)، لذا فعدم التوازن متوقع', '⚠️ الميزان بدون أرصدة افتتاحية. تم قبوله، لكن إن لم تكن الشركة في أول سنة لها: يجب إدخال أرقام السنة السابقة في القوائم المالية (يدويًا أو من ملف قوائمها المالية)، حتى تظهر المقارنة والتدفقات النقدية والتغيرات في حقوق الملكية بشكل صحيح.']);
    }

    public function test_differences_within_materiality_are_rounding_info_not_warnings(): void
    {
        // Full trial balance, off by 0.35 / 0.15 / 0.20 like the Bustan file.
        $file = $this->xlsxUpload([
            self::HEADER,
            ['1101', 'الصندوق', 100, 0, 50.35, 0, 0.15, 0, 150.2, 0],   // 100 + 50.35 + 0.15 = 150.50 → closing short by 0.30 (reconciliation still flags it)
            ['2101', 'مورد', 0, 40, 0, 20, 0, 0, 0, 60],
            ['3101', 'رأس المال', 0, 30, 0, 0, 0, 0, 0, 30],
            ['4101', 'مبيعات', 0, 40, 0, 30, 0, 0, 0, 70],
            ['6101', 'إيجار', 10, 0, 0, 0, 0, 0, 10, 0],
        ]);
        $this->fakeLlm($this->structure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'موردون'), $this->account(4, 'رأس المال'),
            $this->account(5, 'إيرادات المبيعات'), $this->account(6, 'إيجارات'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.warnings', [])
            ->assertJsonPath('meta.info', [
                'فرق تقريب 0.35 في حركة الفترة',
                'فرق تقريب 0.15 في التسويات',
                'فرق تقريب 0.20 في الرصيد الختامي',
            ]);
    }

    public function test_full_trial_balance_checks_all_four_groups_as_warnings(): void
    {
        config(['classification.reject_unbalanced_from' => 100]);   // below the refusal limit: warnings
        $file = $this->xlsxUpload([
            self::HEADER,
            ['1101', 'الصندوق', 100, 0, 50, 0, 5, 0, 155, 0],
            ['2101', 'مورد', 0, 40, 0, 20, 0, 0, 0, 60],
            ['3101', 'رأس المال', 0, 60, 0, 0, 0, 0, 0, 60],
            ['4101', 'مبيعات', 0, 0, 0, 30, 0, 0, 0, 30],
            ['6101', 'إيجار', 0, 0, 10, 0, 0, 0, 10, 0],
        ]);
        $this->fakeLlm($this->structure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'موردون'), $this->account(4, 'رأس المال'),
            $this->account(5, 'إيرادات المبيعات'), $this->account(6, 'إيجارات'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.info', [])
            ->assertJsonPath('meta.warnings', [
                'إجمالي المدين لا يساوي إجمالي الدائن في حركة الفترة (الفرق 10.00)',
                'إجمالي المدين لا يساوي إجمالي الدائن في التسويات (الفرق 5.00)',
                'إجمالي المدين لا يساوي إجمالي الدائن في الرصيد الختامي (الفرق 15.00)',
            ]);
    }

    public function test_subtotal_rows_are_totals_even_if_the_ai_calls_them_accounts(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            self::HEADER,
            ['1101', 'الصندوق', 0, 0, 100, 0, 0, 0, 100, 0],
            ['3101', 'رأس المال', 0, 0, 0, 100, 0, 0, 0, 100],
        ], null, 'A1', true);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('B4', 'الإجمالي');
        foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
            $sheet->setCellValue("{$col}4", "=SUBTOTAL(9,{$col}2:{$col}3)");
        }
        $path = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->fakeLlm($this->structure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال'), $this->account(4, 'نقدية بالصندوق'),   // AI mistake on R4
        ]);

        $this->postJson(self::URL, ['file' => new UploadedFile($path, 'tb.xlsx', null, null, true)])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.skipped_rows', 1)
            ->assertJsonPath('meta.warnings', []);   // R4 used as the total row, and it matches
    }

    public function test_suspected_parent_is_left_out_of_the_totals(): void
    {
        $file = $this->trialBalance([
            ['11', 'النقدية', 0, 0, 300, 0, 300, 0],
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['1102', 'البنك', 0, 0, 200, 0, 200, 0],
            ['31', 'رأس المال', 0, 0, 0, 300, 0, 300],
            ['', 'الإجمالي', 0, 0, 300, 300, 300, 300],
        ]);
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية بالصندوق'), $this->account(4, 'نقدية لدى البنوك'),
            $this->account(5, 'رأس المال'), $this->rowOfType(6, 'total'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'possible_parent')
            ->assertJsonPath('meta.warnings', [])    // counting "11" would give 600 ≠ total row 300
            ->assertJsonPath('meta.info', ['أُزيل صف الإجمالي (الصف 6 في الملف) من التبويب، ومجموعه مطابق لمجموع الحسابات.', '⚠️ الميزان بدون أرصدة افتتاحية. تم قبوله، لكن إن لم تكن الشركة في أول سنة لها: يجب إدخال أرقام السنة السابقة في القوائم المالية (يدويًا أو من ملف قوائمها المالية)، حتى تظهر المقارنة والتدفقات النقدية والتغيرات في حقوق الملكية بشكل صحيح.']);
    }

    public function test_adjustments_sheet_matching_skips_rows_with_in_sheet_adjustments(): void
    {
        $file = $this->workbook([
            'TB'  => [self::HEADER, ['1101', 'الصندوق', 0, 0, 100, 0, 0, 20, 100, 0]],   // diff +20, has in-sheet adjustment
            'ADJ' => [['الحساب', 'رقم', 'مدين', 'دائن'], ['الصندوق', '1101', 20, 0]],
        ]);
        $this->fakeLlm($this->structure(['other_sheets' => [[
            'sheet' => 2, 'type' => 'adjustments', 'description' => '', 'first_data_row' => 2,
            'columns' => ['account_name' => 'A', 'account_number' => 'B', 'debit' => 'C', 'credit' => 'D'],
        ]]]), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.message', 'الرصيد الختامي أعلى من المحسوب بمقدار 20.00')
            ->assertJsonMissingPath('data.0.data_flags.0.matched_adjustment');
    }

    public function test_audit_request_is_returned_but_never_affects_review(): void
    {
        $file = $this->xlsxUpload([
            self::HEADER,
            ['1201', 'سيارات', 45000, 0, 0, 0, 0, 0, 45000, 0, 'عينة من اضافات الاصول الثابتة'],
        ]);
        $this->fakeLlm($this->structure(), [$this->account(2, 'سيارات ووسائل نقل')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.audit_request', 'عينة من اضافات الاصول الثابتة')
            ->assertJsonPath('data.0.source_note', null)
            ->assertJsonPath('data.0.needs_review', false)
            ->assertJsonPath('data.0.note', '');
    }

    public function test_request_on_a_skipped_row_goes_to_info(): void
    {
        $file = $this->xlsxUpload([
            self::HEADER,
            ['1101', 'الصندوق', 0, 0, 1, 0, 0, 0, 1, 0],
            ['', 'الإجمالي', 0, 0, 1, 0, 0, 0, 1, 0, 'مطلوب كشف مطابقة'],
        ]);
        $this->fakeLlm($this->structure(), [$this->account(2, 'نقدية بالصندوق'), $this->rowOfType(3, 'total')]);

        $info = $this->postJson(self::URL, ['file' => $file])->assertOk()->json('meta.info');

        $this->assertContains('طلب مراجعة على الصف 3 (هذا الصف ليس حسابًا): مطلوب كشف مطابقة', $info);
    }

    public function test_structure_prompt_separates_remarks_from_requests_and_knows_adjustments(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);
        $this->postJson(self::URL, ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])->assertOk();

        Http::assertSent(fn ($r) => $this->isStructureCall($r)
            && str_contains($this->systemText($r), 'is audit_request, never notes')
            && str_contains($this->systemText($r), 'map them to the adjustment group')
            && isset($this->toolSchema($r)['properties']['columns']['properties']['audit_request'])
            && isset($this->toolSchema($r)['properties']['columns']['properties']['adjustment_debit']));
    }

    public function test_manual_calculation_mode_adds_a_warning(): void
    {
        $upload = $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]]);
        $zip = new ZipArchive;
        $zip->open($upload->getRealPath());
        $xml = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('calcMode="auto"', $xml);
        $xml = str_replace('calcMode="auto"', 'calcMode="manual"', $xml);   // as Excel saves it in manual mode
        $zip->addFromString('xl/workbook.xml', $xml);
        $zip->close();

        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('meta.warnings.0', 'الملف محفوظ على وضع الحساب اليدوي (Manual Calculation)، فقد تكون قيم المعادلات المحفوظة قديمة. افتحه في Excel ونفّذ Calculate Now (F9) ثم احفظه قبل الرفع.');
    }

    public function test_automatic_calculation_has_no_warning(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])
            ->assertOk()
            ->assertJsonPath('meta.warnings', []);
    }
}
