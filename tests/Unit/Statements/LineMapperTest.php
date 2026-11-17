<?php

namespace Tests\Unit\Statements;

use App\Services\Statements\LineMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

class LineMapperTest extends TestCase
{
    use ClassifiedRows;

    public static function layouts(): array
    {
        return ['C' => [['closing']], 'OC' => [['opening', 'closing']], 'OMC' => [['opening', 'period', 'closing']]];
    }

    private function map(array $rows, array $groups = ['opening', 'period', 'closing'], array $options = []): array
    {
        return app(LineMapper::class)->map($rows, $this->profile($rows, $groups), $options);
    }

    private function line(array $mapping, int $row, string $group = 'closing'): ?string
    {
        $lines = $mapping['rows'][$row]['lines'][$group] ?? null;
        return $lines === null ? null : array_key_first($lines);
    }

    #[DataProvider('layouts')]
    public function test_sub_class_rule_wins_over_main_class_rule(array $groups): void
    {
        $m = $this->map([
            $this->account(2, 'نقدية بالصندوق', ['opening' => 5, 'closing' => 10]),
            $this->account(3, 'احتياطي نظامي', ['opening' => -50, 'closing' => -50]),
            $this->account(4, 'احتياطي عام', ['opening' => -20, 'closing' => -20]),
        ], $groups);

        $this->assertSame('sofp.cash', $this->line($m, 2));
        $this->assertSame('main_class', $m['rows'][2]['source']);
        $this->assertSame('sofp.statutory_reserve', $this->line($m, 3));
        $this->assertSame('sofp.other_reserves', $this->line($m, 4));
        $this->assertSame('sub_class', $m['rows'][4]['source']);
        $this->assertSame('ok', $m['rows'][4]['status']);
    }

    public function test_side_rules_decide_per_column_group(): void
    {
        // Overdrawn last year, in funds now; and the reverse.
        $m = $this->map([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => -300, 'closing' => 200]),
            $this->account(3, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => -80]),
            $this->account(4, 'جاري الشريك', ['opening' => -10, 'closing' => 40]),
            $this->account(5, 'نقدية بالصندوق', ['opening' => 0, 'closing' => -0.5]),   // rounding, not an overdraft
        ], ['opening', 'closing']);

        $this->assertSame(['sofp.cash', 'sofp.bank_overdraft'], [$this->line($m, 2), $this->line($m, 2, 'opening')]);
        $this->assertSame(['sofp.bank_overdraft', 'sofp.cash'], [$this->line($m, 3), $this->line($m, 3, 'opening')]);
        $this->assertSame('side_rule', $m['rows'][3]['source']);
        $this->assertSame('main_class', $m['rows'][2]['source']);   // the current year decides the source
        $this->assertSame(['sofp.due_from_related_parties', 'sofp.partners_current_liability'], [$this->line($m, 4), $this->line($m, 4, 'opening')]);
        $this->assertSame('sofp.cash', $this->line($m, 5));
    }

    public function test_expenses_take_their_line_from_their_function(): void
    {
        $m = $this->map([
            $this->account(2, 'إيجارات', ['period' => [100, 0]]),
            $this->account(3, 'دعاية وإعلان', ['period' => [50, 0]]),
            // The client's own column names a function: it is the default for the row (Q34).
            $this->account(4, 'إيجارات', ['period' => [70, 0]], ['existing_classification' => 'تكلفة الإيرادات']),
            $this->account(5, 'أجور ورواتب', ['period' => [70, 0]], ['existing_note_classification' => 'مصاريف بيع وتوزيع']),
            $this->account(6, 'إهلاك السيارات', ['period' => [30, 0]]),
            $this->account(7, 'فوائد قروض', ['period' => [20, 0]]),
            $this->account(8, 'خسائر فروق عملة', ['period' => [5, 0]]),
            // A label that names no function changes nothing; a nature line (zakat) never takes a function.
            $this->account(9, 'إيجارات', ['period' => [10, 0]], ['existing_classification' => 'المصروفات']),
            $this->account(10, 'مصروف الزكاة الشرعية', ['period' => [10, 0]], ['existing_classification' => 'مصاريف إدارية وعمومية']),
            // Depreciation and amortisation are separate lines (R5), also when the client's column names amortisation.
            $this->account(11, 'إطفاء الأصول غير الملموسة', ['period' => [12, 0]]),
            $this->account(12, 'إيجارات', ['period' => [3, 0]], ['existing_classification' => 'اطفاء مصروفات ما قبل التشغيل']),
        ]);

        $this->assertSame('is.general_admin', $this->line($m, 2));
        $this->assertSame(['function_default', 'general_admin', null], [$m['rows'][2]['source'], $m['rows'][2]['function'], $m['rows'][2]['client_function']]);
        $this->assertSame('is.selling_marketing', $this->line($m, 3));
        $this->assertSame('is.cost_of_revenue', $this->line($m, 4));
        $this->assertSame(['function_client', 'cost', 'general_admin'], [$m['rows'][4]['source'], $m['rows'][4]['function'], $m['rows'][4]['default_function']]);
        $this->assertSame('is.selling_marketing', $this->line($m, 5));
        $this->assertSame('is.depreciation', $this->line($m, 6));
        $this->assertSame('is.finance_cost', $this->line($m, 7));
        $this->assertSame('is.other_expenses', $this->line($m, 8));
        $this->assertSame('is.general_admin', $this->line($m, 9));
        $this->assertSame('is.zakat', $this->line($m, 10));
        $this->assertSame(['is.amortisation', 'is.amortisation'], [$this->line($m, 11), $this->line($m, 12)]);
    }

    public function test_doubted_rows_keep_their_line_and_wait_for_confirmation(): void
    {
        $high = ['type' => 'prefix_mismatch', 'severity' => 'high', 'message' => 'رقم الحساب في نطاق قائمة الدخل'];
        $m = $this->map([
            $this->account(2, 'مخزون بضاعة', ['closing' => 100], ['data_flags' => [$high]]),
            $this->account(3, 'مخزون بضاعة', ['closing' => 100], ['data_flags' => [['type' => 'prefix_mismatch', 'message' => 'm']]]),
            $this->account(4, 'إيجارات', ['period' => [10, 0]], ['data_flags' => [['type' => 'statement_mismatch', 'severity' => 'high', 'message' => 'تصنيف الملف']], 'existing_classification' => 'تكلفة المبيعات']),
            $this->account(5, 'إيرادات مستحقة', ['closing' => 30]),
        ]);

        $this->assertSame(['sofp.inventory', 'pending_confirmation'], [$this->line($m, 2), $m['rows'][2]['status']]);
        $this->assertSame([['code' => 'prefix_mismatch', 'message' => $high['message']]], $m['rows'][2]['reasons']);
        $this->assertSame('ok', $m['rows'][3]['status']);   // a plain prefix_mismatch is a hint, not a doubt
        // The client's function still applies, but the row shows both and waits.
        $this->assertSame(['is.cost_of_revenue', 'pending_confirmation', 'general_admin'], [$this->line($m, 4), $m['rows'][4]['status'], $m['rows'][4]['default_function']]);
        $this->assertSame(['sofp.contract_assets', 'pending_confirmation', 'confirm_rule'], [$this->line($m, 5), $m['rows'][5]['status'], $m['rows'][5]['reasons'][0]['code']]);
    }

    public function test_unmapped_excluded_and_choices(): void
    {
        $m = $this->map([
            $this->account(2, 'استثمارات طويلة الأجل', ['closing' => 500]),
            $this->account(3, null, ['closing' => 7]),
            $this->account(4, 'نقدية لدى البنوك', ['closing' => 300], ['data_flags' => [['type' => 'possible_parent', 'message' => 'm']]]),
            $this->account(5, 'استثمارات قصيرة الأجل', ['closing' => 50]),
        ]);

        $this->assertSame(['row' => 2, 'reason' => 'choose'], array_intersect_key($m['unmapped'][2], ['row' => 1, 'reason' => 1]));
        $this->assertContains('sofp.associates', $m['unmapped'][2]['candidates']);
        $this->assertContains('sofp.investment_property', $m['unmapped'][2]['candidates']);
        $this->assertSame('unclassified', $m['unmapped'][3]['reason']);
        $this->assertSame(['row' => 4, 'reason' => 'possible_parent'], $m['excluded'][4]);
        $this->assertSame(['sofp.amortised_cost_current', 'sofp.murabaha_deposits'], $m['unmapped'][5]['candidates']);
        $this->assertSame([], $m['rows']);
    }

    public function test_statement_sub_classes_map_to_their_own_lines(): void
    {
        $m = $this->map([
            $this->account(2, 'استثمارات عقارية', ['closing' => 500]),
            $this->account(3, 'استثمارات في شركات زميلة', ['closing' => 300]),
            $this->account(4, 'توزيعات أرباح مستحقة', ['closing' => -100]),
            $this->account(5, 'إيرادات مؤجلة', ['closing' => -60]),
            $this->account(6, 'رد خسائر الانخفاض', ['period' => [0, 20]]),
        ]);

        $this->assertSame([], $m['unmapped']);
        $this->assertSame(
            [2 => 'sofp.investment_property', 3 => 'sofp.associates', 4 => 'sofp.dividends_payable', 5 => 'sofp.contract_liabilities', 6 => 'is.impairment_reversals'],
            array_map(fn ($r) => array_key_first($r['lines']['closing']), $m['rows']),
        );
        foreach ($m['rows'] as $row) {
            $this->assertSame(['sub_class', 'ok'], [$row['source'], $row['status']]);
        }
    }

    public function test_overrides_win_and_may_split_an_account(): void
    {
        $rows = [
            $this->account(2, 'استثمارات طويلة الأجل', ['closing' => 500]),
            $this->account(3, null, ['closing' => 7]),
            $this->account(4, 'نقدية لدى البنوك', ['closing' => 300], ['data_flags' => [['type' => 'possible_parent', 'message' => 'm']]]),
            $this->account(5, 'أجور ورواتب', ['period' => [100, 0]], ['data_flags' => [['type' => 'statement_mismatch', 'severity' => 'high', 'message' => 'm']]]),
            $this->account(6, 'نقدية لدى البنوك', ['closing' => -40]),
        ];
        $m = $this->map($rows, options: ['overrides' => [
            2 => 'sofp.investment_property', 3 => 'sofp.prepayments_other_receivables', 4 => 'sofp.murabaha_deposits',
            5 => ['is.cost_of_revenue' => 0.6, 'is.general_admin' => 0.4], 6 => 'sofp.cash',
        ]]);

        $this->assertSame([], $m['unmapped']);
        $this->assertSame([], $m['excluded']);
        $this->assertSame('sofp.investment_property', $this->line($m, 2));
        $this->assertSame(['override', 'ok'], [$m['rows'][5]['source'], $m['rows'][5]['status']]);
        $this->assertSame(['is.cost_of_revenue' => 0.6, 'is.general_admin' => 0.4], $m['rows'][5]['lines']['closing']);
        $this->assertSame('sofp.cash', $this->line($m, 6));   // the user's choice beats the side rule

        foreach ([['sofp.nope'], [['is.general_admin' => 0.5]], [['is.general_admin' => 1.2, 'is.cost_of_revenue' => -0.2]]] as [$bad]) {
            try {
                $this->map($rows, options: ['overrides' => [5 => $bad]]);
                $this->fail('accepted '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_partners_current_account_follows_the_report_choice(): void
    {
        $rows = [$this->account(2, 'جاري الشريك', ['closing' => -900])];
        $this->assertSame('sofp.partners_current_liability', $this->line($this->map($rows), 2));
        $this->assertSame('sofp.partners_current_equity', $this->line($this->map($rows, options: ['partners_current' => 'equity']), 2));
        $this->assertSame('sofp.due_to_related_parties', $this->line($this->map([$this->account(3, 'اطراف ذات علاقة دائنة', ['closing' => -900])]), 3));
    }
}
