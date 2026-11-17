<?php

namespace Tests\Unit;

use App\Services\AccountExtractor;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

class AccountExtractorTest extends TestCase
{
    use TrialBalanceFiles;

    private AccountExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AccountExtractor;
    }

    private function structure(array $columns, array $extra = []): array
    {
        return $this->extractor->normalize(array_replace(['sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2, 'columns' => $columns], $extra));
    }

    private function sheets(array $rows): array
    {
        return [1 => $rows];
    }

    public function test_normalize_fills_missing_fields_and_uppercases_letters(): void
    {
        $s = $this->structure(['account_name' => 'b', 'closing_net' => 'c', 'unknown' => 'Z']);

        $this->assertSame('B', $s['columns']['account_name']);
        $this->assertSame('C', $s['columns']['closing_net']);
        $this->assertNull($s['columns']['account_number']);
        $this->assertSame(AccountExtractor::FIELDS, array_keys($s['columns']));
    }

    public function test_extracts_debit_credit_pairs(): void
    {
        $s = $this->structure(['account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D']);

        $result = $this->extractor->extract(['A' => 1101.0, 'B' => 'الصندوق', 'C' => '1,000', 'D' => 0], $s);

        $this->assertSame('1101', $result['account']['account_number']);
        $this->assertSame(1000.0, $result['account']['closing_debit']);
        $this->assertSame([], $result['problems']);
    }

    public function test_missing_groups_fall_back_to_period_then_closing(): void
    {
        $periodOnly = $this->extractor->extract(
            ['B' => 'x', 'C' => 7, 'D' => 3],
            $this->structure(['account_name' => 'B', 'period_debit' => 'C', 'period_credit' => 'D'])
        )['account'];
        $this->assertSame([7.0, 3.0, 7.0, 3.0], [$periodOnly['opening_debit'], $periodOnly['opening_credit'], $periodOnly['closing_debit'], $periodOnly['closing_credit']]);

        $closingOnly = $this->extractor->extract(
            ['B' => 'x', 'C' => 9],
            $this->structure(['account_name' => 'B', 'closing_net' => 'C'])
        )['account'];
        $this->assertSame([9.0, 0.0], [$closingOnly['period_debit'], $closingOnly['period_credit']]);
    }

    public function test_net_column_sign_and_side_indicator(): void
    {
        $s = $this->structure(['account_name' => 'A', 'closing_net' => 'B', 'closing_side' => 'C']);

        $credit = $this->extractor->extract(['A' => 'x', 'B' => 50, 'C' => 'Cr'], $s)['account'];
        $debit  = $this->extractor->extract(['A' => 'x', 'B' => -50, 'C' => 'مدين'], $s)['account'];
        $signed = $this->extractor->extract(['A' => 'x', 'B' => -50], $s)['account'];

        $this->assertSame([0.0, 50.0], [$credit['closing_debit'], $credit['closing_credit']]);
        $this->assertSame([50.0, 0.0], [$debit['closing_debit'], $debit['closing_credit']]);
        $this->assertSame([0.0, 50.0], [$signed['closing_debit'], $signed['closing_credit']]);
    }

    public function test_splits_number_and_name_in_one_cell(): void
    {
        $s = $this->structure(['account_number' => 'A', 'account_name' => 'A', 'closing_net' => 'B']);

        $cases = [
            '1101 - الصندوق'      => ['1101', 'الصندوق'],
            '1101: Cash'          => ['1101', 'Cash'],
            '١١٠١ الصندوق'        => ['١١٠١', 'الصندوق'],
            '11.01.02 - Bank'     => ['11.01.02', 'Bank'],
            'مصروفات متنوعة'      => ['', 'مصروفات متنوعة'],
        ];

        foreach ($cases as $cell => [$number, $name]) {
            $a = $this->extractor->extract(['A' => $cell, 'B' => 1], $s)['account'];
            $this->assertSame([$number, $name], [$a['account_number'], $a['account_name']], $cell);
        }
    }

    public function test_non_numeric_amount_is_reported_in_arabic(): void
    {
        $s = $this->structure(['account_name' => 'A', 'closing_debit' => 'B', 'closing_credit' => 'C']);

        $result = $this->extractor->extract(['A' => 'x', 'B' => 'N/A', 'C' => 5], $s);

        $this->assertSame(0.0, $result['account']['closing_debit']);
        $this->assertSame(['قيمة غير رقمية في العمود B'], $result['problems']);
    }

    public function test_valid_structure_has_no_errors(): void
    {
        $sheets = $this->sheets([1 => ['A' => 'رقم', 'B' => 'اسم', 'C' => 'مدين', 'D' => 'دائن'], 2 => ['A' => '1', 'B' => 'x', 'C' => 5, 'D' => 0]]);

        $this->assertSame([], $this->extractor->validate($sheets, $this->structure(
            ['account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D']
        )));
    }

    public function test_validation_errors(): void
    {
        $sheets = $this->sheets([1 => ['A' => 'h', 'B' => 'h', 'C' => 'h'], 2 => ['A' => '1', 'B' => 'x', 'C' => 5], 3 => ['A' => '2', 'B' => 'y', 'C' => 6]]);

        $cases = [
            'missing sheet'     => [['account_name' => 'B', 'closing_net' => 'C'], ['sheet' => 5], 'Sheet 5 does not exist or is empty.'],
            'no name'           => [['closing_net' => 'C'], [], 'account_name column is required.'],
            'half pair'         => [['account_name' => 'B', 'closing_debit' => 'C'], [], 'closing: map both closing_debit and closing_credit, or use closing_net.'],
            'side without net'  => [['account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'A', 'closing_side' => 'A'], [], 'closing_side requires closing_net.'],
            'no amounts'        => [['account_name' => 'B'], [], 'No amount columns mapped: map at least one of opening, period or closing.'],
            'unknown column'    => [['account_name' => 'B', 'closing_net' => 'Q'], [], 'closing_net: column Q has no values in sheet 1.'],
            'reused column'     => [['account_name' => 'B', 'closing_net' => 'C', 'period_net' => 'C'], [], 'Column C is mapped to more than one amount field.'],
            'bad first row'     => [['account_name' => 'B', 'closing_net' => 'C'], ['first_data_row' => 40], 'first_data_row 40 is outside the sheet.'],
            'text as amount'    => [['account_name' => 'C', 'closing_net' => 'B'], [], 'closing_net: column B is mostly non-numeric (0 of 2 values are numbers).'],
        ];

        foreach ($cases as $label => [$columns, $extra, $expected]) {
            $errors = $this->extractor->validate($sheets, $this->structure($columns, $extra));
            $this->assertContains($expected, $errors, $label.': '.implode(' / ', $errors));
        }
    }

    public function test_data_rows_exclude_header_rows_and_rows_before_first_data_row(): void
    {
        $sheet = [1 => ['A' => 't'], 3 => ['A' => 'h1'], 4 => ['A' => 'h2'], 5 => ['A' => 'd'], 6 => ['A' => 'h2 repeated']];
        $s     = $this->extractor->normalize(['sheet' => 1, 'header_rows' => [3, 4, 6], 'first_data_row' => 5, 'columns' => []]);

        $this->assertSame([5], array_keys($this->extractor->dataRows($sheet, $s)));
    }

    public function test_present_groups(): void
    {
        $s = $this->structure(['account_name' => 'A', 'opening_net' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D', 'period_debit' => 'E']);

        $this->assertSame(['opening', 'closing'], $this->extractor->presentGroups($s));
    }

    /* ---------- section 2: notes and other sheets ---------- */

    public function test_extract_returns_remarks_as_note(): void
    {
        $s = $this->structure(['account_name' => 'A', 'closing_net' => 'B', 'notes' => 'C']);

        $this->assertSame('غير مطابق مع التحليلي', $this->extractor->extract(['A' => 'x', 'B' => 1, 'C' => ' غير مطابق مع التحليلي '], $s)['note']);
        $this->assertNull($this->extractor->extract(['A' => 'x', 'B' => 1], $s)['note']);
    }

    public function test_infers_headerless_text_column_as_notes(): void
    {
        $sheet = [
            1 => ['A' => 'رقم', 'B' => 'اسم', 'C' => 'رصيد'],
            2 => ['A' => '1', 'B' => 'x', 'C' => 5],
            3 => ['A' => '2', 'B' => 'y', 'C' => 6, 'D' => 'غير مطابق مع التحليلي'],
        ];
        $s = $this->structure(['account_number' => 'A', 'account_name' => 'B', 'closing_net' => 'C']);

        $this->assertSame('D', $this->extractor->withInferredNotes($sheet, $s)['columns']['notes']);
    }

    public function test_does_not_infer_notes_from_numeric_or_headed_columns(): void
    {
        $sheet = [
            1 => ['A' => 'رقم', 'B' => 'اسم', 'C' => 'رصيد', 'E' => 'العملة'],
            2 => ['A' => '1', 'B' => 'x', 'C' => 5, 'D' => 7, 'E' => 'SAR'],
        ];
        $s = $this->structure(['account_number' => 'A', 'account_name' => 'B', 'closing_net' => 'C']);

        $this->assertNull($this->extractor->withInferredNotes($sheet, $s)['columns']['notes']);
    }

    public function test_normalizes_other_sheets(): void
    {
        $s = $this->extractor->normalize(['sheet' => 1, 'columns' => [], 'other_sheets' => [
            ['sheet' => 2, 'type' => 'adjustments', 'description' => 'قيود', 'first_data_row' => 2, 'columns' => ['account_name' => 'a', 'debit' => 'B', 'credit' => 'C']],
            ['sheet' => 3, 'type' => 'weird', 'description' => 'x'],
        ]]);

        $this->assertSame(['account_name' => 'A', 'account_number' => null, 'debit' => 'B', 'credit' => 'C'], $s['other_sheets'][0]['columns']);
        $this->assertSame('other', $s['other_sheets'][1]['type']);
        $this->assertArrayNotHasKey('columns', $s['other_sheets'][1]);
    }

    public function test_validates_adjustments_sheet_structure(): void
    {
        $sheets = [
            1 => [1 => ['A' => 'h', 'B' => 'h', 'C' => 'h'], 2 => ['A' => '1', 'B' => 'x', 'C' => 5]],
            2 => [1 => ['A' => 'قيود', 'B' => 'مدين', 'C' => 'دائن'], 2 => ['A' => 'الصندوق', 'B' => 10]],
        ];
        $base = ['account_name' => 'B', 'closing_net' => 'C'];

        $ok = $this->structure($base, ['other_sheets' => [
            ['sheet' => 2, 'type' => 'adjustments', 'description' => '', 'first_data_row' => 2, 'columns' => ['account_name' => 'A', 'debit' => 'B', 'credit' => 'C']],
        ]]);
        $this->assertSame([], $this->extractor->validate($sheets, $ok));

        $bad = $this->structure($base, ['other_sheets' => [
            ['sheet' => 2, 'type' => 'adjustments', 'description' => '', 'first_data_row' => 9, 'columns' => ['account_name' => 'A', 'debit' => 'Q']],
            ['sheet' => 1, 'type' => 'notes', 'description' => ''],
            ['sheet' => 7, 'type' => 'other', 'description' => ''],
        ]]);
        $errors = $this->extractor->validate($sheets, $bad);
        foreach ([
            'other_sheets: adjustments sheet 2 needs a credit column.',
            'other_sheets: sheet 2 debit: column Q has no values.',
            'other_sheets: sheet 2 first_data_row 9 is outside the sheet.',
            'other_sheets: sheet 1 is the trial balance itself.',
            'other_sheets: sheet 7 does not exist or is empty.',
        ] as $expected) {
            $this->assertContains($expected, $errors, implode(' / ', $errors));
        }
    }

    public function test_adjustment_lines_skip_blank_and_zero_lines(): void
    {
        $sheet = [
            1 => ['A' => 'قيود التسوية', 'B' => 'مدين', 'C' => 'دائن'],
            2 => ['A' => 'بنك الرياض', 'C' => 1751.2],
            3 => ['A' => 'جاري الشريك', 'B' => '1,751.20'],
            4 => ['A' => 'سطر بدون مبلغ'],
            5 => ['B' => 5],
        ];
        $other = $this->extractor->normalize(['columns' => [], 'other_sheets' => [
            ['sheet' => 2, 'type' => 'adjustments', 'description' => '', 'first_data_row' => 2, 'columns' => ['account_name' => 'A', 'debit' => 'B', 'credit' => 'C']],
        ]])['other_sheets'][0];

        $this->assertSame([
            ['row' => 2, 'account_name' => 'بنك الرياض', 'account_number' => '', 'debit' => 0.0, 'credit' => 1751.2],
            ['row' => 3, 'account_name' => 'جاري الشريك', 'account_number' => '', 'debit' => 1751.2, 'credit' => 0.0],
        ], $this->extractor->adjustmentLines($sheet, $other));
    }
}
