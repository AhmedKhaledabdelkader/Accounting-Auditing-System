<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Rows left out of the totals although they carry balances: one grouped warning with their closing balances. */
class DroppedRowsTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @param  list<array>  $labels */
    private function classify(array $rows, array $labels)
    {
        $this->fakeLlm($this->standardStructure(), $labels);

        return $this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk();
    }

    public function test_non_account_rows_with_balances_are_one_warning_with_their_closing_balances(): void
    {
        $response = $this->classify([
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],             // R2 account
            ['', 'بند غريب', 0, 0, 250.5, 0, 250.5, 0],           // R3 other, with balances
            ['', 'الأصول المتداولة', 0, 0, 0, 0, 0, 0],            // R4 section, no balances
            ['', 'قسم برصيد', 0, 0, 0, 40, 0, 40],                 // R5 section, with balances
            ['11', 'الأصول', 0, 0, 100, 0, 100, 0],                // R6 parent
            ['', 'الإجمالي', 0, 0, 350.5, 40, 350.5, 40],          // R7 total
        ], [
            $this->account(2, 'نقدية بالصندوق'), $this->rowOfType(3, 'other'), $this->rowOfType(4, 'section'),
            $this->rowOfType(5, 'section'), $this->rowOfType(6, 'parent'), $this->rowOfType(7, 'total'),
        ]);

        $this->assertContains('2 صف فيها أرصدة ولا تدخل في الإجماليات لأنها غير مصنفة كحسابات (الصفوف 3، 5 في الملف). أرصدتها الختامية: مدين 250.50، دائن 40.00. راجعها.',
            $response->json('meta.warnings'));
    }

    public function test_more_than_ten_rows_list_the_first_ten_and_count_the_rest(): void
    {
        $rows   = [['1101', 'الصندوق', 0, 0, 10, 0, 10, 0]];
        $labels = [$this->account(2, 'نقدية بالصندوق')];
        foreach (range(3, 15) as $n) {   // 13 dropped rows
            $rows[]   = ['', "سطر {$n}", 0, 0, 1, 0, 1, 0];
            $labels[] = $this->rowOfType($n, 'other');
        }
        $response = $this->classify($rows, $labels);

        $this->assertContains('13 صف فيها أرصدة ولا تدخل في الإجماليات لأنها غير مصنفة كحسابات (الصفوف 3، 4، 5، 6، 7، 8، 9، 10، 11، 12 و3 صف آخر في الملف). أرصدتها الختامية: مدين 13.00، دائن 0.00. راجعها.',
            $response->json('meta.warnings'));
    }

    public function test_unlabelled_row_without_name_or_number_is_counted(): void
    {
        $response = $this->classify([
            ['1101', 'الصندوق', 0, 0, 10, 0, 10, 0],
            ['', '', 0, 0, 0, 75, 0, 75],   // R3: no label after the retry, nothing to keep it as an account
        ], [$this->account(2, 'نقدية بالصندوق')]);

        $this->assertContains('1 صف فيها أرصدة ولا تدخل في الإجماليات لأنها غير مصنفة كحسابات (الصف 3 في الملف). أرصدتها الختامية: مدين 0.00، دائن 75.00. راجعها.',
            $response->json('meta.warnings'));
    }

    public function test_no_warning_when_only_totals_parents_and_empty_rows_are_left_out(): void
    {
        $this->classify([
            ['1101', 'الصندوق', 0, 0, 10, 0, 10, 0],
            ['', 'الأصول المتداولة', 0, 0, 0, 0, 0, 0],
            ['', 'الإجمالي', 0, 0, 10, 0, 10, 0],
        ], [$this->account(2, 'نقدية بالصندوق'), $this->rowOfType(3, 'section'), $this->rowOfType(4, 'total')])
            ->assertJsonPath('meta.warnings', []);
    }
}
