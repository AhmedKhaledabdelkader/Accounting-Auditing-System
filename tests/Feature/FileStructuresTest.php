<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * Different real-world layouts. The AI's structure answer is faked; what's verified is that Laravel
 * reads the exact values from the right cells for each layout.
 */
class FileStructuresTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function assertBalances($response, int $index, array $expected): void
    {
        foreach ($expected as $field => $value) {
            $response->assertJsonPath("data.{$index}.{$field}", $value);
        }
    }

    public function test_english_headers_in_different_column_order(): void
    {
        $file = $this->xlsxUpload([
            ['Closing Cr', 'Closing Dr', 'Description', 'Account Code', 'Movement Dr', 'Movement Cr'],
            [0, 700, 'Cash on hand', '110101', 900, 200],
        ]);

        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => [
                'account_number' => 'D', 'account_name' => 'C',
                'period_debit' => 'E', 'period_credit' => 'F',
                'closing_debit' => 'B', 'closing_credit' => 'A',
            ],
        ], [$this->account(2, 'نقدية بالصندوق')]);

        $response = $this->postJson(self::URL, ['file' => $file])->assertOk()
            ->assertJsonPath('data.0.account_number', '110101')
            ->assertJsonPath('data.0.account_name', 'Cash on hand')
            ->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق');

        // Opening is missing → falls back to period movement (existing behaviour).
        $this->assertBalances($response, 0, [
            'opening_debit' => 900, 'opening_credit' => 200,
            'period_debit' => 900, 'period_credit' => 200,
            'closing_debit' => 700, 'closing_credit' => 0,
        ]);
    }

    public function test_title_rows_and_two_row_grouped_header(): void
    {
        $file = $this->xlsxUpload([
            ['شركة المثال للتجارة'],
            ['ميزان المراجعة عن السنة المنتهية 2026'],
            [],
            ['الحساب', null, 'الرصيد الافتتاحي', null, 'الحركة', null, 'الرصيد الختامي', null],
            ['الرقم', 'الاسم', 'مدين', 'دائن', 'مدين', 'دائن', 'مدين', 'دائن'],
            ['110101', 'الصندوق', 100, 0, 50, 30, 120, 0],
        ]);

        $this->fakeLlm($this->standardStructure(['header_rows' => [4, 5], 'first_data_row' => 6]), [
            $this->account(6, 'نقدية بالصندوق'),
        ]);

        $response = $this->postJson(self::URL, ['file' => $file])->assertOk()->assertJsonPath('meta.total', 1);

        $this->assertBalances($response, 0, [
            'opening_debit' => 100, 'period_debit' => 50, 'period_credit' => 30, 'closing_debit' => 120,
        ]);
    }

    public function test_trial_balance_on_second_sheet(): void
    {
        $file = $this->workbook([
            'Notes' => [['This workbook was exported from the ERP']],
            'TB'    => [self::FULL_HEADER, ['110101', 'الصندوق', 0, 0, 10, 0, 10, 0]],
        ]);

        $this->fakeLlm($this->standardStructure(['sheet' => 2]), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.account_name', 'الصندوق')
            ->assertJsonPath('data.0.closing_debit', 10);

        Http::assertSent(fn ($r) => $this->isStructureCall($r)
            && $this->toolSchema($r)['properties']['sheet']['enum'] === [1, 2]
            && str_contains($this->userText($r), '### Sheet 2'));
    }

    public function test_number_and_name_in_one_cell(): void
    {
        $file = $this->xlsxUpload([
            ['الحساب', 'مدين', 'دائن'],
            ['110101 - الصندوق الرئيسي', 500, 0],
            ['الإيرادات المتنوعة', 0, 500],
        ]);

        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'A', 'closing_debit' => 'B', 'closing_credit' => 'C'],
        ], [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'إيرادات أخرى')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.account_number', '110101')
            ->assertJsonPath('data.0.account_name', 'الصندوق الرئيسي')
            ->assertJsonPath('data.1.account_number', '')
            ->assertJsonPath('data.1.account_name', 'الإيرادات المتنوعة')
            ->assertJsonPath('meta.warnings', []);
    }

    public function test_single_signed_balance_column(): void
    {
        $file = $this->xlsxUpload([
            ['Code', 'Account', 'Balance'],
            ['1101', 'Cash', '1,500.00'],
            ['2101', 'Supplier', '(1,500.00)'],
        ]);

        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'closing_net' => 'C'],
        ], [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'موردون')]);

        $response = $this->postJson(self::URL, ['file' => $file])->assertOk()->assertJsonPath('meta.warnings', []);

        $this->assertBalances($response, 0, ['closing_debit' => 1500, 'closing_credit' => 0]);
        $this->assertBalances($response, 1, ['closing_debit' => 0, 'closing_credit' => 1500]);
    }

    public function test_balance_column_with_debit_credit_indicator(): void
    {
        $file = $this->xlsxUpload([
            ['رقم', 'البيان', 'الرصيد', 'طبيعة الرصيد'],
            ['1101', 'الصندوق', 800, 'مدين'],
            ['3101', 'رأس المال', 800, 'دائن'],
        ]);

        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'closing_net' => 'C', 'closing_side' => 'D'],
        ], [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]);

        $response = $this->postJson(self::URL, ['file' => $file])->assertOk();

        $this->assertBalances($response, 0, ['closing_debit' => 800, 'closing_credit' => 0]);
        $this->assertBalances($response, 1, ['closing_debit' => 0, 'closing_credit' => 800]);
    }

    public function test_arabic_indic_digits_and_european_numbers(): void
    {
        $file = $this->xlsxUpload([
            ['رقم الحساب', 'اسم الحساب', 'مدين', 'دائن'],
            ['١١٠١', 'الصندوق', '١٬٢٥٠٫٥٠', '٠'],
            ['2101', 'مورد', '0', '1.250,50'],
        ]);

        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D'],
        ], [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'موردون')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.account_number', '١١٠١')
            ->assertJsonPath('data.0.closing_debit', 1250.5)
            ->assertJsonPath('data.1.closing_credit', 1250.5)
            ->assertJsonPath('meta.warnings', []);
    }

    public function test_formulas_are_read_as_calculated_values(): void
    {
        $file = $this->xlsxUpload([
            ['رقم الحساب', 'اسم الحساب', 'مدين', 'دائن'],
            ['1101', 'الصندوق', '=100+50', 0],
        ]);

        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D'],
        ], [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.closing_debit', 150);
    }

    public function test_parent_total_and_section_rows_are_excluded(): void
    {
        $file = $this->trialBalance([
            ['', 'الأصول', null, null, null, null, null, null],      // R2 section
            ['11', 'النقدية', 0, 0, 300, 0, 300, 0],                  // R3 parent
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],                // R4
            ['1102', 'البنك', 0, 0, 200, 0, 200, 0],                  // R5
            ['31', 'رأس المال', 0, 0, 0, 300, 0, 300],                // R6
            ['', 'الإجمالي', 0, 0, 300, 300, 300, 300],               // R7 total
        ]);

        $this->fakeLlm($this->standardStructure(), [
            $this->rowOfType(2, 'section'),
            $this->rowOfType(3, 'parent'),
            $this->account(4, 'نقدية بالصندوق'),
            $this->account(5, 'نقدية لدى البنوك'),
            $this->account(6, 'رأس المال'),
            $this->rowOfType(7, 'total'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.skipped_rows', 3)
            ->assertJsonPath('meta.warnings', [])
            ->assertJsonPath('data.0.account_number', '1101')
            ->assertJsonPath('data.2.account_number', '31');
    }
}
