<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BalanceXlsxExpectations;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * balance.xlsx end to end with Claude faked: the answers below are what a correct classification looks like,
 * so every PHP result (reconciliation, adjustments matching, abnormal balances, notes, totals) is checked exactly.
 * The same expectations run against the real API in LiveBalanceXlsxTest.
 */
class BalanceXlsxTest extends TestCase
{
    use BalanceXlsxExpectations;
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $custody = fn (int $row) => $this->account($row, 'عُهد العاملين', 90, 'عهدة مع موظف');

        $this->fakeLlm(
            [
                'sheet' => 1, 'header_rows' => [1, 2], 'first_data_row' => 3,
                'columns' => [
                    'account_number' => 'A', 'account_name' => 'B',
                    'opening_debit' => 'C', 'opening_credit' => 'D',
                    'period_debit' => 'E', 'period_credit' => 'F',
                    'closing_debit' => 'G', 'closing_credit' => 'H',
                    'notes' => 'I',
                ],
                'other_sheets' => [[
                    'sheet' => 2, 'type' => 'adjustments', 'description' => 'قيود تسوية',
                    'first_data_row' => 2, 'columns' => ['account_name' => 'A', 'debit' => 'B', 'credit' => 'C'],
                ]],
            ],
            [
                $this->account(3, 'نقدية بالصندوق', 95),
                $custody(4), $custody(5), $custody(6), $custody(7), $custody(8), $custody(9), $custody(10), $custody(11), $custody(12),
                $this->account(13, 'نقدية لدى البنوك', 95),
                $this->account(14, 'نقدية لدى البنوك', 95),
                $this->account(15, 'نقدية لدى البنوك', 90),
                $this->account(16, 'نقدية لدى البنوك', 95),
                $this->account(17, 'تأمينات لدى الغير', 75, 'غالبًا غطاء خطاب ضمان'),
                $this->account(18, 'أوراق قبض', 85),
                $this->account(19, 'بطاقات ائتمان دائنة', 60, 'مش واضح هي فيزا ائتمان ولا مدى'),
                $this->account(20, 'نقدية لدى البنوك', 95),
                $this->account(21, 'عملاء', 95),
                $this->rowOfType(186, 'total'),
            ],
            [
                ['adjustment_row' => 1, 'account_row' => 13],   // بنك الرياض → R13 (110101510)
                ['adjustment_row' => 2],                                   // جاري الشريك → not in TB
                ['adjustment_row' => 3, 'account_row' => 3],    // الصندوق → R3 (110101010)
                ['adjustment_row' => 4],
                ['adjustment_row' => 5, 'account_row' => 7],    // عهدة محمد توفيق → R7 (110101014)
                ['adjustment_row' => 6],
            ]
        );
    }

    public function test_balance_xlsx_results(): void
    {
        $this->assertBalanceXlsxResults($this->postJson(self::URL, ['file' => $this->balanceXlsx()]));
    }

    public function test_under_the_real_limit_it_is_refused_before_any_row_goes_to_the_ai(): void
    {
        // balance.xlsx is off however its rows are read (leaves, with the unnumbered rows, all of them): refused once its
        // columns are known, before the classification calls, so nothing is paid for them.
        config(['classification.reject_unbalanced_from' => 1.0]);
        $this->postJson(self::URL, ['file' => $this->balanceXlsx()])->assertStatus(422)
            ->assertJsonPath('message', 'The trial balance does not balance, so the classification was refused: the opening balance (difference 2,660,914.76), the period movement (difference 523,119.40), the closing balance (difference 2,138,518.17). Only a rounding difference below 1.00 is allowed. Fix the file and upload it again.');
        Http::assertSentCount(1);   // the structure only
    }

    public function test_balance_xlsx_sends_adjustments_and_notes_to_claude(): void
    {
        $this->postJson(self::URL, ['file' => $this->balanceXlsx()])->assertOk();

        Http::assertSent(fn (Request $r) => $this->isStructureCall($r)
            && str_contains($this->userText($r), '### Sheet 2: قيود التسويات')
            && str_contains($this->userText($r), 'I: غير مطابق مع التحليلي'));

        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r)
            && str_contains($this->fileBlock($r), 'A1 | قيود التسويات R2 | بنك الرياض | 0 | 1751.2')
            && str_contains($this->fileBlock($r), 'A6 | قيود التسويات R9 | جاري الشريك | 0 | 1300')
            && str_contains($this->fileBlock($r), 'R18 | 110101526 | شيكات واردة - مؤجلة')
            && str_contains($this->userText($r), 'No rows to classify. Return only adjustment_matches for adjustment lines A1, A2,'));
    }

    public function test_balance_xlsx_meta(): void
    {
        $this->postJson(self::URL, ['file' => $this->balanceXlsx()])
            ->assertOk()
            ->assertJsonPath('meta.skipped_rows', 7)   // the empty row and the 6 zero rows
            ->assertJsonPath('meta.needs_review', 7)   // 8 before a zero row (low confidence) was left out
            ->assertJsonPath('meta.info.0', 'هذا الملف ميزان جزئي (فيه أصول، التزامات فقط)، لذا فعدم التوازن متوقع')
            ->assertJsonPath('meta.adjustments.0', [
                'sheet' => 'قيود التسويات', 'row' => 2, 'account_name' => 'بنك الرياض',
                'debit' => 0, 'credit' => 1751.2, 'account_number' => null, 'account_row' => 13,
            ]);
    }
}
