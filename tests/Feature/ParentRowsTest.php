<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * Parent rows kept as accounts (ParentRows): by the file's levels (leading marks, leading spaces, indentation, a level
 * column) excluded whatever their codes; by amounts alone only proposed, and only when they explain the file not adding up.
 * Codes, names and amounts are made up; in every case the child's code doesn't start with the parent's.
 */
class ParentRowsTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * A section, a parent (code 4100) over one child (code 7700, same balance), an account the marker skips, capital, the
     * total. $mark(level, name) writes a name at a level.
     */
    private function rows(callable $mark): array
    {
        return [
            ['', $mark(1, 'الأصول المتداولة'), 0, 0, 0, 0, 0, 0],                       // R2 section
            ['4100', $mark(2, '4100- مباني الفرع'), 500, 0, 0, 0, 500, 0],            // R3 parent
            ['7700', $mark(3, 'أعمال إنشائية بالفرع'), 500, 0, 0, 0, 500, 0],          // R4 its only child
            ['9900', 'مورد من غير علامة', 0, 300, 0, 0, 0, 300],                         // R5 no marker
            ['5100', $mark(2, 'رأس المال المدفوع'), 0, 200, 0, 0, 0, 200],               // R6
            ['', 'الإجمالي', 500, 500, 0, 0, 500, 500],                                  // R7 total
        ];
    }

    private function labels(): array
    {
        return [$this->rowOfType(2, 'section'), $this->account(3, 'مباني وإنشاءات'), $this->account(4, 'مباني وإنشاءات'),
            $this->account(5, 'موردون'), $this->account(6, 'رأس المال'), $this->rowOfType(7, 'total')];
    }

    private function classify($file, ?array $structure = null): array
    {
        $this->fakeLlm($structure ?? $this->standardStructure(), $this->labels());

        return $this->postJson(self::URL, ['file' => $file])->assertOk()->json();
    }

    private function flags(array $json, int $row): array
    {
        return collect($json['data'])->firstWhere('row', $row)['data_flags'];
    }

    private function assertParentByLevel(array $json): void
    {
        $flag = collect($this->flags($json, 3))->firstWhere('type', 'parent_level');
        $this->assertNotNull($flag, json_encode($this->flags($json, 3), JSON_UNESCAPED_UNICODE));
        $this->assertSame([[4], 'info'], [$flag['rows'], $flag['severity']]);
        $this->assertStringContainsString('حساب تجميعي حسب مستويات الملف (تحته الصف 4 في الملف)', $flag['message']);
        foreach ([4, 5, 6] as $row) {
            $this->assertSame([], array_intersect(array_column($this->flags($json, $row), 'type'), ['parent_level', 'probable_parent', 'possible_parent']), "row {$row}");
        }
        // The parent is its account's heading.
        $this->assertSame('4100- مباني الفرع', collect($json['data'])->firstWhere('row', 4)['heading']);
        // Left out of the totals: the file balances and matches its total row.
        $this->assertSame([], array_values(array_filter($json['meta']['warnings'], fn ($w) => str_contains($w, 'إجمالي') || str_contains($w, 'الإجمالي'))));
    }

    public function test_leading_marks_make_a_parent_whatever_its_code(): void
    {
        // Each name starts with a space, then one dot per level: the dots are the levels, not the space.
        $this->assertParentByLevel($this->classify($this->trialBalance($this->rows(fn ($level, $name) => ' '.str_repeat('.  ', $level).$name))));
    }

    public function test_leading_spaces_make_a_parent(): void
    {
        $this->assertParentByLevel($this->classify($this->trialBalance($this->rows(fn ($level, $name) => str_repeat('    ', $level).$name))));
    }

    public function test_the_cells_indentation_makes_a_parent(): void
    {
        $levels = [2 => 1, 3 => 2, 4 => 3, 6 => 2];
        $file = $this->styledTrialBalance($this->rows(fn ($level, $name) => $name), function (Worksheet $sheet) use ($levels) {
            foreach ($levels as $row => $level) {
                $sheet->getStyle("B{$row}")->getAlignment()->setIndent($level);
            }
        });
        $this->assertParentByLevel($this->classify($file));
    }

    public function test_a_level_column_makes_a_parent(): void
    {
        $levels = [1, 2, 3, null, 2, null];
        $rows = array_map(fn ($r, $l) => [...$r, $l], $this->rows(fn ($level, $name) => $name), $levels);
        $file = $this->xlsxUpload([[...self::FULL_HEADER, 'المستوى'], ...$rows]);
        $this->assertParentByLevel($this->classify($file));
    }

    public function test_without_levels_a_parent_explaining_the_difference_is_only_proposed(): void
    {
        $json = $this->classify($this->trialBalance($this->rows(fn ($level, $name) => $name)));

        $flag = collect($this->flags($json, 3))->firstWhere('type', 'probable_parent');
        $this->assertSame([4], $flag['rows']);
        $this->assertStringContainsString('قد يكون حسابًا تجميعيًا', $flag['message']);
        $this->assertTrue(collect($json['data'])->firstWhere('row', 3)['needs_review']);
        // Still counted: the accounts exceed the file's total row by its balance, and the check names it.
        $this->assertContains('مجموع الحسابات لا يطابق صف الإجمالي في الملف (الصف 7 في الملف): الفرق يساوي رصيد «4100 — 4100- مباني الفرع» (الصف 3 في الملف)، وقد يكون حسابًا تجميعيًا احتُسب مرتين',
            $json['meta']['warnings']);
    }

    public function test_a_real_tree_of_several_levels_still_leaves_its_parents_out(): void
    {
        // Dots mark a real tree: each parent is the sum of the rows right under it (the grandparent R2 of its children
        // R3 and R6, not of the grandchildren). Full, balanced once the parents are left out, and it matches the total row.
        $mark = fn (int $level, string $name) => ' '.str_repeat('.  ', $level).$name;
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية لدى البنوك'), $this->account(3, 'نقدية لدى البنوك'), $this->account(4, 'نقدية لدى البنوك'),
            $this->account(5, 'نقدية لدى البنوك'), $this->account(6, 'نقدية بالصندوق'), $this->account(7, 'رأس المال'),
            $this->account(8, 'موردون'), $this->account(9, 'إيرادات المبيعات'), $this->account(10, 'إيجارات'), $this->rowOfType(11, 'total'),
        ]);
        $json = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['11', $mark(1, 'النقدية'), 0, 0, 900, 0, 900, 0],              // R2 = R3 + R6
            ['1101', $mark(2, 'البنوك'), 0, 0, 800, 0, 800, 0],             // R3 = R4 + R5
            ['110101', $mark(3, 'بنك الرياض'), 0, 0, 300, 0, 300, 0],      // R4
            ['110102', $mark(3, 'بنك الراجحي'), 0, 0, 500, 0, 500, 0],     // R5
            ['1102', $mark(2, 'الصندوق'), 0, 0, 100, 0, 100, 0],            // R6
            ['31', $mark(1, 'رأس المال'), 0, 0, 0, 600, 0, 600],            // R7
            ['21', $mark(1, 'مورد'), 0, 0, 0, 200, 0, 200],                 // R8
            ['41', $mark(1, 'المبيعات'), 0, 0, 0, 400, 0, 400],             // R9
            ['51', $mark(1, 'الإيجار'), 0, 0, 300, 0, 300, 0],              // R10
            ['', 'الإجمالي', 0, 0, 1200, 1200, 1200, 1200],                 // R11
        ])])->assertOk()->json();

        $this->assertSame([2 => [3, 4, 5, 6], 3 => [4, 5]], array_filter(array_map(fn ($r) => collect($r['data_flags'])->firstWhere('type', 'parent_level')['rows'] ?? null,
            array_column($json['data'], null, 'row'))));
        $this->assertSame([], $json['meta']['warnings']);
    }

    public function test_marks_that_add_nothing_up_are_decoration(): void
    {
        // Dots of every depth, but no row is the sum of the rows under it: every row is an account and the file adds up.
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'مخزون'), $this->account(3, 'مخزون'), $this->account(4, 'نقدية لدى البنوك'),
            $this->account(5, 'رأس المال'), $this->account(6, 'موردون'), $this->rowOfType(7, 'total')]);
        $json = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['1301', ' .  .  .  . قطع غيار', 100, 0, 0, 0, 100, 0],
            ['1302', ' .  .  .  .  . خامات', 400, 0, 0, 0, 400, 0],
            ['1201', ' .  .  .  .  .  . البنك', 700, 0, 50, 0, 750, 0],
            ['3101', ' .  .  . رأس المال', 0, 1000, 0, 0, 0, 1000],
            ['2101', ' .  .  .  . مورد', 0, 200, 0, 50, 0, 250],
            ['', 'الإجمالي', 1200, 1200, 50, 50, 1250, 1250],
        ])])->assertOk()->json();

        foreach ([2, 3, 4, 5, 6] as $row) {
            $this->assertSame([], array_intersect(array_column($this->flags($json, $row), 'type'), ['parent_level', 'probable_parent', 'possible_parent']), "row {$row}");
        }
        $this->assertSame([], array_values(array_filter($json['meta']['warnings'], fn ($w) => str_contains($w, 'إجمالي'))));
    }

    public function test_a_full_trial_balance_off_by_a_probable_parent_is_not_refused(): void
    {
        config(['classification.reject_unbalanced_from' => 1.00]);
        // R2 is R3's parent (no levels): counted, debits exceed credits by its 500; the user excludes it in the report.
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'مباني وإنشاءات'), $this->account(3, 'مباني وإنشاءات'),
            $this->account(4, 'رأس المال'), $this->account(5, 'موردون'), $this->account(6, 'إيرادات المبيعات'), $this->account(7, 'إيجارات')]);
        $json = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['4100', 'مباني الفرع', 0, 0, 500, 0, 500, 0],
            ['7700', 'أعمال إنشائية بالفرع', 0, 0, 500, 0, 500, 0],
            ['5100', 'رأس المال', 0, 0, 0, 300, 0, 300],
            ['2100', 'مورد', 0, 0, 0, 150, 0, 150],
            ['6100', 'المبيعات', 0, 0, 0, 100, 0, 100],
            ['8100', 'الإيجار', 0, 0, 50, 0, 50, 0],
        ])])->assertOk()->json();

        $this->assertContains('probable_parent', array_column($this->flags($json, 2), 'type'));
    }

    public function test_equal_neighbours_in_a_file_that_adds_up_are_not_parents(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية لدى البنوك'), $this->account(4, 'رأس المال')]);
        $json = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['1102', 'البنك', 0, 0, 100, 0, 100, 0],
            ['3101', 'رأس المال', 0, 0, 0, 200, 0, 200],
        ])])->assertOk()->json();

        foreach ([2, 3, 4] as $row) {
            $this->assertSame([], $this->flags($json, $row), "row {$row}");
        }
    }
}
