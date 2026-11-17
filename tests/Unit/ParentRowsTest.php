<?php

namespace Tests\Unit;

use App\Services\ParentRows;
use PHPUnit\Framework\TestCase;

/** ParentRows on rows built here: each marker, the levels' gaps, parents by level and by amounts. Made-up names and amounts. */
class ParentRowsTest extends TestCase
{
    /** @param list<array{0: bool, 1: string, 2?: ?int, 3?: ?int, 4?: float}> $spec [account, name, indent, level column, net] */
    private function rows(array $spec): array
    {
        $rows = [];
        foreach ($spec as $i => $s) {
            $rows[$i + 2] = ['account' => $s[0], 'name' => ltrim($s[1]), 'raw' => $s[1], 'indent' => $s[2] ?? null, 'level' => $s[3] ?? null,
                'amounts' => ['closing' => $s[4] ?? 0.0]];
        }

        return $rows;
    }

    public function test_each_marker_gives_the_levels(): void
    {
        $marks = $this->rows([[false, '. أ'], [true, '. . ب'], [true, '. . . ج'], [true, '. . د']]);
        $this->assertSame(['marks', [2 => 1, 3 => 2, 4 => 3, 5 => 2]], array_values(ParentRows::levels($marks)));

        $spaces = $this->rows([[false, 'أ'], [true, '  ب'], [true, '    ج'], [true, '  د']]);
        $this->assertSame(['spaces', [2 => 0, 3 => 2, 4 => 4, 5 => 2]], array_values(ParentRows::levels($spaces)));

        $indent = $this->rows([[false, 'أ', 0], [true, 'ب', 1], [true, 'ج', 2], [true, 'د', 1]]);
        $this->assertSame('indent', ParentRows::levels($indent)['marker']);

        // A level column wins over the others, and its 0 is a level.
        $column = $this->rows([[false, '. أ', 0, 0], [true, '. . ب', 0, 1], [true, '. ج', 0, 2], [true, 'د', 0, 1]]);
        $this->assertSame(['column', [2 => 0, 3 => 1, 4 => 2, 5 => 1]], array_values(ParentRows::levels($column)));
    }

    public function test_no_marker_without_two_levels_or_on_too_few_accounts(): void
    {
        $this->assertNull(ParentRows::levels($this->rows([[true, 'أ'], [true, 'ب'], [true, 'ج']]))['marker']);
        // One space before every name is not a level.
        $this->assertNull(ParentRows::levels($this->rows([[true, ' أ'], [true, ' ب'], [true, 'ج']]))['marker']);
        // Marks on one account in three.
        $this->assertNull(ParentRows::levels($this->rows([[true, '. أ'], [true, '. . ب'], [true, 'ج'], [true, 'د'], [true, 'هـ']]))['marker']);
    }

    public function test_an_unmarked_account_has_no_level_and_breaks_nothing(): void
    {
        $rows = $this->rows([[false, '. قسم'], [true, '. . أب'], [true, 'بدون علامة'], [true, '. . . ابن'], [true, '. . آخر']]);
        ['levels' => $levels] = ParentRows::levels($rows);
        $this->assertNull($levels[4]);
        $this->assertSame([3 => [5]], ParentRows::byLevel($rows, $levels));   // the parent reaches past the unmarked row
    }

    public function test_a_parent_by_level_is_any_row_followed_by_a_deeper_one(): void
    {
        $rows = $this->rows([[false, '. قسم'], [true, '. . أب'], [true, '. . . ابن 1'], [true, '. . . ابن 2'], [false, '. قسم 2'], [true, '. . منفرد']]);
        $this->assertSame([3 => [4, 5]], ParentRows::byLevel($rows, ParentRows::levels($rows)['levels']));
    }

    public function test_a_parent_by_level_must_add_up_its_direct_children(): void
    {
        // أب = ابن 1 + ابن 2 (the grandchild is inside ابن 1); آخر is followed by a deeper row that isn't its sum.
        $rows = $this->rows([[true, '. أب', null, null, 300.0], [true, '. . ابن 1', null, null, 100.0], [true, '. . . حفيد', null, null, 100.0],
            [true, '. . ابن 2', null, null, 200.0], [true, '. آخر', null, null, 50.0], [true, '. . تحته', null, null, 70.0]]);
        $levels = ParentRows::levels($rows)['levels'];
        $this->assertSame([2 => [3, 4, 5], 3 => [4]], ParentRows::byLevel($rows, $levels));
        $this->assertSame($levels, ParentRows::trusted($rows, $levels));
    }

    public function test_marks_with_no_parent_adding_up_are_decoration(): void
    {
        $rows = $this->rows([[true, '. . أ', null, null, 10.0], [true, '. . . ب', null, null, 20.0], [true, '. . ج', null, null, -30.0]]);
        $levels = ParentRows::levels($rows)['levels'];
        $this->assertSame([], ParentRows::byLevel($rows, $levels));
        $this->assertSame([2 => null, 3 => null, 4 => null], ParentRows::trusted($rows, $levels));
        // Zero rows adding up prove nothing.
        $zeros = $this->rows([[true, '. . أ'], [true, '. . . ب'], [true, '. . ج', null, null, 5.0]]);
        $this->assertSame([2 => null, 3 => null, 4 => null], ParentRows::trusted($zeros, ParentRows::levels($zeros)['levels']));
    }

    public function test_by_amounts_the_rows_right_below_add_up(): void
    {
        $rows = $this->rows([[true, 'أب', null, null, 300.0], [true, 'ابن 1', null, null, 100.0], [true, 'ابن 2', null, null, 200.0],
            [true, 'غيره', null, null, 50.0], [false, 'قسم', null, null, 0.0], [true, 'وحيد', null, null, 70.0], [true, 'آخر', null, null, 10.0]]);
        $levels = array_fill_keys(array_keys($rows), null);
        $this->assertSame([2 => [3, 4]], ParentRows::byAmounts($rows, $levels, ['closing']));
        $this->assertSame([], ParentRows::byAmounts($rows, $levels, ['closing'], [2 => true]));
    }

    public function test_only_a_candidate_explaining_the_difference_is_kept(): void
    {
        $row = fn (float $d, float $c) => ['closing_debit' => $d, 'closing_credit' => $c];
        $candidates = [2 => $row(300, 0), 9 => $row(0, 40)];
        // Debits exceed credits by 300: row 2 explains it.
        $this->assertSame([2], ParentRows::explaining($candidates, ['closing' => [1300.0, 1000.0]], [], ['closing']));
        // Together they explain 260.
        $this->assertSame([2, 9], ParentRows::explaining($candidates, ['closing' => [1300.0, 1040.0]], [], ['closing']));
        // Balanced, no total row: nothing to explain.
        $this->assertSame([], ParentRows::explaining($candidates, ['closing' => [1000.0, 1000.0]], [], ['closing']));
        // Balanced, but over the file's total row by row 9's balance.
        $this->assertSame([9], ParentRows::explaining($candidates, ['closing' => [1040.0, 1040.0]], [$row(1040, 1000)], ['closing']));
    }
}
