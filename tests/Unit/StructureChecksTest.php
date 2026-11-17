<?php

namespace Tests\Unit;

use App\Services\AccountExtractor;
use App\Services\StructureChecks;
use Tests\TestCase;

/** Each structure check: fires on a wrong mapping, stays quiet on a correct one. */
class StructureChecksTest extends TestCase
{
    private AccountExtractor $extractor;
    private StructureChecks $checks;

    private const COLUMNS = [
        'account_number' => 'A', 'account_name' => 'B',
        'opening_debit' => 'C', 'opening_credit' => 'D', 'period_debit' => 'E', 'period_credit' => 'F',
        'closing_debit' => 'G', 'closing_credit' => 'H',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AccountExtractor;
        $this->checks    = new StructureChecks($this->extractor);
    }

    /** Header row 1 + $n accounts that reconcile (closing = opening + movement). */
    private function sheet(int $n = 12, array $header = []): array
    {
        $rows = [1 => $header + ['A' => 'رقم الحساب', 'B' => 'اسم الحساب', 'C' => 'مدين اول المدة', 'D' => 'دائن اول المدة',
            'E' => 'حركة مدينة', 'F' => 'حركة دائنة', 'G' => 'مدين اخر المدة', 'H' => 'دائن اخر المدة']];
        for ($i = 0; $i < $n; $i++) {
            $rows[$i + 2] = ['A' => (string) (1101 + $i), 'B' => "حساب {$i}", 'C' => 100 * $i, 'D' => 0, 'E' => 50, 'F' => 20, 'G' => 100 * $i + 30, 'H' => 0];
        }
        return $rows;
    }

    private function issues(array $sheet, array $columns = [], array $extra = []): array
    {
        $structure = $this->extractor->normalize(array_replace(
            ['sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2, 'columns' => array_replace(self::COLUMNS, $columns)], $extra));

        return $this->checks->check([1 => $sheet], $structure);
    }

    /** @return list<string> "severity:check" */
    private function found(array $issues): array
    {
        return array_map(fn ($i) => "{$i['severity']}:{$i['check']}", $issues);
    }

    public function test_correct_mapping_has_no_issues(): void
    {
        $this->assertSame([], $this->issues($this->sheet()));
    }

    public function test_header_naming_only_the_other_side_is_an_error(): void
    {
        $issues = $this->issues($this->sheet(), ['closing_debit' => 'H', 'closing_credit' => 'G']);

        $this->assertContains('error:header_contradiction', $this->found($issues));
        $this->assertSame('عنوان العمود H هو «دائن اخر المدة» لكنه قُرئ على أنه «مدين آخر المدة».', $issues[0]['user']);
    }

    public function test_headers_naming_both_sides_never_contradict(): void
    {
        $sheet = $this->sheet(12, ['G' => 'الرصيد مدين/دائن', 'H' => 'طبيعة الرصيد']);

        $this->assertNotContains('error:header_contradiction', $this->found($this->issues($sheet, ['closing_debit' => 'G', 'closing_credit' => 'H'])));
        $this->assertNotContains('error:header_contradiction', $this->found($this->issues($sheet, ['closing_debit' => 'H', 'closing_credit' => 'G'])));
    }

    public function test_header_naming_another_group_is_only_a_doubt(): void
    {
        $swapped = $this->issues($this->sheet(), ['opening_debit' => 'G', 'opening_credit' => 'H', 'closing_debit' => 'C', 'closing_credit' => 'D']);
        $this->assertContains('doubt:group_contradiction', $this->found($swapped));
        $this->assertNotContains('error:group_contradiction', $this->found($swapped));

        // "Balance after adjustments" is a closing balance.
        $sheet = $this->sheet(12, ['G' => 'الرصيد بعد التسويات مدين', 'H' => 'الرصيد بعد التسويات دائن']);
        $this->assertSame([], $this->issues($sheet));
    }

    public function test_group_title_in_a_merged_cell_to_the_left_counts(): void
    {
        // Row 1: group titles in the first cell of each merged pair; row 2: مدين/دائن; accounts from row 3.
        $sheet = [
            1 => ['C' => 'الرصيد الافتتاحي', 'E' => 'الحركة', 'G' => 'الرصيد الختامي'],
            2 => ['A' => 'رقم', 'B' => 'اسم', 'C' => 'مدين', 'D' => 'دائن', 'E' => 'مدين', 'F' => 'دائن', 'G' => 'مدين', 'H' => 'دائن'],
        ];
        foreach (array_slice($this->sheet(), 1) as $i => $row) {
            $sheet[$i + 3] = $row;
        }
        $extra = ['header_rows' => [1, 2], 'first_data_row' => 3];

        $this->assertSame([], $this->issues($sheet, [], $extra));
        // D sits under the "opening" title (C:D merged): mapped as closing, it contradicts that title.
        $this->assertContains('doubt:group_contradiction', $this->found($this->issues($sheet, ['closing_credit' => 'D', 'opening_credit' => 'H'], $extra)));
    }

    public function test_one_column_mapped_to_two_fields_is_an_error_except_number_and_name(): void
    {
        $this->assertContains('error:duplicate_mapping', $this->found($this->issues($this->sheet(), ['notes' => 'B'])));

        $combined = [1 => ['A' => 'الحساب', 'G' => 'مدين', 'H' => 'دائن']];
        for ($i = 0; $i < 8; $i++) {
            $combined[$i + 2] = ['A' => (1101 + $i).' - حساب', 'G' => 10, 'H' => 0];
        }
        $this->assertSame([], $this->issues($combined, ['account_number' => 'A', 'account_name' => 'A', 'opening_debit' => null, 'opening_credit' => null, 'period_debit' => null, 'period_credit' => null]));
    }

    public function test_name_column_holding_numbers_is_an_error(): void
    {
        $this->assertContains('error:name_text', $this->found($this->issues($this->sheet(), ['account_name' => 'A', 'account_number' => null])));
    }

    public function test_mostly_empty_name_column_is_flagged(): void
    {
        $sheet = $this->sheet();
        foreach ([3, 4, 5, 6, 7, 8, 9] as $n) {
            unset($sheet[$n]['B']);
        }
        $this->assertContains('doubt:name_fill', $this->found($this->issues($sheet)));
    }

    public function test_account_numbers_that_are_not_codes_or_all_the_same_are_doubts(): void
    {
        $sheet = $this->sheet();
        foreach ($sheet as $n => $row) {
            if ($n > 1) {
                $sheet[$n]['I'] = 'نص طويل مش كود';
                $sheet[$n]['J'] = '1101';
            }
        }
        $this->assertContains('doubt:number_code', $this->found($this->issues($sheet, ['account_number' => 'I'])));
        $this->assertContains('doubt:number_distinct', $this->found($this->issues($sheet, ['account_number' => 'J'])));
    }

    public function test_side_column_without_debit_credit_words_is_an_error(): void
    {
        $sheet = [1 => ['A' => 'رقم', 'B' => 'اسم', 'C' => 'الرصيد', 'D' => 'طبيعة الرصيد', 'E' => 'فرع']];
        for ($i = 0; $i < 8; $i++) {
            $sheet[$i + 2] = ['A' => (string) (1101 + $i), 'B' => "حساب {$i}", 'C' => 100, 'D' => $i % 2 ? 'مدين' : 'دائن', 'E' => 'الرياض'];
        }
        $columns = ['account_number' => 'A', 'account_name' => 'B', 'closing_net' => 'C'];
        $only    = fn (array $c) => $c + array_fill_keys(array_keys(self::COLUMNS), null);

        $this->assertSame([], $this->issues($sheet, $only($columns + ['closing_side' => 'D'])));
        $this->assertContains('error:side_values', $this->found($this->issues($sheet, $only($columns + ['closing_side' => 'E']))));
    }

    public function test_text_column_full_of_numbers_is_a_doubt(): void
    {
        $this->assertContains('doubt:text_numeric', $this->found($this->issues($this->sheet(), ['notes' => 'A', 'account_number' => null])));
    }

    public function test_swapped_opening_and_closing_is_an_error(): void
    {
        $issues = $this->issues($this->sheet(), ['opening_debit' => 'G', 'opening_credit' => 'H', 'closing_debit' => 'C', 'closing_credit' => 'D']);
        $swap   = collect($issues)->firstWhere('check', 'groups_swapped');

        $this->assertSame('error', $swap['severity']);
        $this->assertSame(1.0, $swap['value']);   // every row that tells the two mappings apart favours the exchange
        $this->assertStringStartsWith('Opening and closing columns look swapped: closing = opening + movement holds on 0% of 12 rows as mapped, and on 100% once exchanged', $swap['ai']);
        $this->assertSame('يبدو أن أعمدة «أول المدة» و«آخر المدة» متبادلة: المعادلة متحققة في 0% فقط من الصفوف، وفي 100% لو تم التبديل.', $swap['user']);
        $this->assertNotContains('doubt:row_reconciliation', $this->found($issues));
    }

    public function test_swapped_movement_and_closing_is_an_error_naming_the_pair(): void
    {
        $swap = collect($this->issues($this->sheet(), ['period_debit' => 'G', 'period_credit' => 'H', 'closing_debit' => 'E', 'closing_credit' => 'F']))->firstWhere('check', 'groups_swapped');

        $this->assertSame('error', $swap['severity']);
        $this->assertStringStartsWith('يبدو أن أعمدة «الحركة» و«آخر المدة» متبادلة', $swap['user']);
    }

    public function test_swapped_debit_and_credit_of_one_group_is_named_as_such(): void
    {
        // Generic headers, so only the numbers can tell. Closing sits on both sides depending on the row.
        $sheet = [1 => ['A' => 'Code', 'B' => 'Name', 'C' => 'Amount 1', 'D' => 'Amount 2', 'E' => 'Amount 3', 'F' => 'Amount 4', 'G' => 'Amount 5', 'H' => 'Amount 6']];
        for ($i = 0; $i < 12; $i++) {
            $closing = 100 * $i - 400;
            $sheet[$i + 2] = ['A' => (string) (1101 + $i), 'B' => "حساب {$i}", 'C' => 100 * $i, 'D' => 0, 'E' => 0, 'F' => 400,
                'G' => max($closing, 0), 'H' => max(-$closing, 0)];
        }
        $this->assertSame([], $this->issues($sheet));

        $issues = $this->issues($sheet, ['closing_debit' => 'H', 'closing_credit' => 'G']);
        $this->assertContains('error:sides_swapped', $this->found($issues));
        $this->assertNotContains('error:groups_swapped', $this->found($issues));
        $this->assertSame('يبدو أن عمودي المدين والدائن في «آخر المدة» متبادلان: المعادلة متحققة في 8% فقط من الصفوف، وفي 100% لو تم التبديل.',
            collect($issues)->firstWhere('check', 'sides_swapped')['user']);
    }

    public function test_swapped_opening_and_movement_is_left_to_the_headers(): void
    {
        // closing = opening + movement adds the two: exchanging them keeps every row reconciling.
        $issues = $this->issues($this->sheet(), ['opening_debit' => 'E', 'opening_credit' => 'F', 'period_debit' => 'C', 'period_credit' => 'D']);

        $this->assertNotContains('error:groups_swapped', $this->found($issues));
        $this->assertContains('doubt:group_contradiction', $this->found($issues));   // pausing
    }

    public function test_rows_that_stop_reconciling_without_a_swap_are_a_doubt(): void
    {
        // Closing unrelated to opening + movement either way round: not a swap, just a file that doesn't reconcile.
        $sheet = $this->sheet();
        foreach ($sheet as $n => $row) {
            if ($n > 1) {
                $sheet[$n]['G'] = 7777 + $n;
            }
        }
        $issues = $this->issues($sheet);

        $this->assertContains('doubt:row_reconciliation', $this->found($issues));
        $this->assertNotContains('error:groups_swapped', $this->found($issues));
    }

    public function test_file_reconciling_like_the_weakest_real_file_is_not_flagged(): void
    {
        // 70% of rows reconcile (the lowest share measured on a correct real file).
        $sheet = $this->sheet(20);
        foreach ([2, 5, 8, 11, 14, 17] as $n) {
            $sheet[$n]['G'] += 1000;
        }
        $this->assertSame([], $this->issues($sheet));
    }

    public function test_header_row_taken_as_the_first_data_row_is_an_error(): void
    {
        $this->assertContains('error:first_row_is_header', $this->found($this->issues($this->sheet(), [], ['header_rows' => [], 'first_data_row' => 1])));
    }

    public function test_account_rows_before_the_first_data_row_are_a_doubt(): void
    {
        $this->assertContains('doubt:rows_skipped', $this->found($this->issues($this->sheet(), [], ['first_data_row' => 5])));
    }

    public function test_header_row_after_the_first_data_row_is_an_error(): void
    {
        $this->assertContains('error:header_rows', $this->found($this->issues($this->sheet(), [], ['header_rows' => [1, 3]])));
    }

    public function test_ratio_checks_need_enough_values(): void
    {
        // 3 rows only: a numeric name column is below min_values, so it is not judged.
        $this->assertNotContains('error:name_text', $this->found($this->issues($this->sheet(3), ['account_name' => 'A', 'account_number' => null])));
    }

    public function test_after_classification_flags_a_file_wide_debit_credit_swap(): void
    {
        $row = fn (int $n, bool $abnormal) => ['row' => $n, 'account_name' => "حساب {$n}", 'sub_class' => 'عملاء', 'closing_debit' => 0.0, 'closing_credit' => 500.0,
            'data_flags' => $abnormal ? [['type' => 'abnormal_closing']] : []];

        $mostly = array_map(fn ($n) => $row($n, $n % 10 < 7), range(1, 30));   // 70% abnormal
        $this->assertSame(['doubt:debit_credit_swap'], $this->found($this->checks->afterClassification($mostly)));

        $few = array_map(fn ($n) => $row($n, $n % 10 < 3), range(1, 30));
        $this->assertSame([], $this->checks->afterClassification($few));
        $this->assertSame([], $this->checks->afterClassification(array_slice($mostly, 0, 10)));   // below min_rows
    }

    public function test_after_classification_flags_totals_labelled_as_accounts(): void
    {
        $rows = [
            ['row' => 5, 'account_name' => 'إجمالي الأصول', 'sub_class' => null, 'closing_debit' => 0.0, 'closing_credit' => 0.0, 'data_flags' => []],
            ['row' => 6, 'account_name' => 'Total liabilities', 'sub_class' => null, 'closing_debit' => 0.0, 'closing_credit' => 0.0, 'data_flags' => []],
            ['row' => 7, 'account_name' => 'مجمع الإهلاك', 'sub_class' => null, 'closing_debit' => 0.0, 'closing_credit' => 0.0, 'data_flags' => []],
        ];
        $issues = $this->checks->afterClassification($rows);

        $this->assertSame(['doubt:total_as_account'], $this->found($issues));
        $this->assertStringContainsString('الصفوف 5، 6 في الملف', $issues[0]['user']);
    }

    public function test_normalize_unifies_arabic_spellings(): void
    {
        $this->assertSame('اخر المده داين', StructureChecks::normalize('  آخرُ   المدّة دائن '));
    }
}
