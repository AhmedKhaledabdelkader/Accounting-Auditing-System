<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * Fixes found on a real client trial balance, each tested on a generic file:
 * the note-number column, periodic opening/closing stock, other provisions, function labels in the file's own
 * classification, and the statement-level numbering scheme (leave-one-out).
 */
class ClassifierFixesTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /* ---------- note_reference ---------- */

    /** رقم | الاسم | مدين | دائن | إيضاح (closing only) */
    private function noteFile(array $notes): \Illuminate\Http\UploadedFile
    {
        $rows = [['رقم الحساب', 'اسم الحساب', 'مدين', 'دائن', 'إيضاح']];
        foreach ($notes as $i => $note) {
            $rows[] = ['110'.($i + 1), 'بنك '.($i + 1), 100 * ($i + 1), 0, $note];
        }
        return $this->xlsxUpload($rows);
    }

    private function noteStructure(string $role): array
    {
        return ['sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2, 'columns' => [
            'account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D', $role => 'E',
        ]];
    }

    private function bankLabels(int $count): array
    {
        return array_map(fn ($n) => $this->account($n, 'نقدية لدى البنوك'), range(2, $count + 1));
    }

    public function test_a_note_number_column_is_a_reference_not_a_remark(): void
    {
        $this->fakeLlm($this->noteStructure('note_reference'), $this->bankLabels(3));

        $this->postJson(self::URL, ['file' => $this->noteFile([5, '9', '15/أ'])])
            ->assertOk()
            ->assertJsonPath('data.0.note_reference', '5')
            ->assertJsonPath('data.2.note_reference', '15/أ')
            ->assertJsonPath('data.0.source_note', null)
            ->assertJsonPath('data.0.needs_review', false)
            ->assertJsonPath('meta.needs_review', 0);

        Http::assertSent(fn (Request $r) => $this->isStructureCall($r)
            && isset($this->toolSchema($r)['properties']['columns']['properties']['note_reference'])
            && str_contains($this->systemText($r), 'is note_reference, never notes'));
    }

    public function test_note_numbers_read_as_remarks_become_the_note_reference(): void
    {
        $this->fakeLlm($this->noteStructure('notes'), $this->bankLabels(4));

        $response = $this->postJson(self::URL, ['file' => $this->noteFile([5, 6, '(7)', '١٢'])])->assertOk();

        $this->assertSame(['5', '6', '(7)', '١٢'], array_column($response->json('data'), 'note_reference'));
        $this->assertSame([null, null, null, null], array_column($response->json('data'), 'source_note'));
        $this->assertSame(0, $response->json('meta.needs_review'));
        $this->assertStringNotContainsString('الملاحظات', implode(' ', $response->json('meta.warnings')));
    }

    public function test_real_remarks_stay_remarks(): void
    {
        // One remark among note numbers: the column is not all note numbers, so it stays the remarks column.
        $this->fakeLlm($this->noteStructure('notes'), $this->bankLabels(3));

        $this->postJson(self::URL, ['file' => $this->noteFile([5, 'غير مطابق مع التحليلي', 6])])
            ->assertOk()
            ->assertJsonPath('data.1.source_note', 'غير مطابق مع التحليلي')
            ->assertJsonPath('data.1.needs_review', true)
            ->assertJsonPath('data.0.note_reference', null);
    }

    /* ---------- function labels in the file's own classification ---------- */

    private function existingFile(array $rows): \Illuminate\Http\UploadedFile
    {
        return $this->xlsxUpload([['رقم الحساب', 'اسم الحساب', 'بنود الميزانية', 'مدين', 'دائن'], ...$rows]);
    }

    private function fakeWithMap(array $labels, array $map): void
    {
        Http::fake([$this->aiUrl() => function (Request $r) use ($labels, $map) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', ['sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2, 'columns' => [
                    'account_number' => 'A', 'account_name' => 'B', 'existing_classification' => 'C', 'closing_debit' => 'D', 'closing_credit' => 'E',
                ]]));
            }
            $requested = $this->requestedRows($r);
            return Http::response($this->toolResponse('submit_rows', [
                'rows'                   => array_values(array_filter($labels, fn ($l) => in_array($l['row'], $requested, true))),
                'existing_class_matches' => $map,
            ]));
        }]);
    }

    public function test_a_function_label_does_not_contradict_an_expense_nature(): void
    {
        // X1 «تكلفة إيرادات» was mapped to cost only; rent and wages are expenses by nature, under that function.
        $this->fakeWithMap(
            [$this->account(2, 'إيجارات'), $this->account(3, 'أجور ورواتب'), $this->account(4, 'إيرادات المبيعات'), $this->account(5, 'نقدية لدى البنوك')],
            [['existing_id' => 1, 'main_classes' => ['تكلفة البضاعة المباعة']]]
        );

        $data = $this->postJson(self::URL, ['file' => $this->existingFile([
            ['8401', 'ايجارات المستودعات', 'تكلفة إيرادات', 500, 0],
            ['820202', 'أجور عمال تحميل وتنزيل', 'تكلفة إيرادات', 300, 0],
            ['52', 'مبيعات', 'تكلفة إيرادات', 0, 900],          // revenue under a cost label: still a mismatch
            ['1201', 'بنك', 'تكلفة إيرادات', 100, 0],           // balance sheet under a cost label: changes profit
        ])])->assertOk()->json('data');

        $this->assertSame([[], []], [$data[0]['data_flags'], $data[1]['data_flags']]);
        $this->assertSame(['classification_mismatch'], array_column($data[2]['data_flags'], 'type'));
        $this->assertSame(['statement_mismatch'], array_column($data[3]['data_flags'], 'type'));
    }

    public function test_an_expense_name_is_no_doubt_when_the_file_puts_it_on_the_balance_sheet_too(): void
    {
        // «مصاريف رسوم …» classified prepaid, and the file's own column says prepaid: no «the name says expense» warning.
        // Without the file's column, the name still warns.
        $this->fakeWithMap(
            [$this->account(2, 'مصروفات مدفوعة مقدماً'), $this->account(3, 'مصروفات مدفوعة مقدماً')],
            [['existing_id' => 1, 'main_classes' => ['مصروفات مدفوعة مقدما و ارصدة مدينة اخرى']]]
        );

        $data = $this->postJson(self::URL, ['file' => $this->existingFile([
            ['120706', 'مصاريف رسوم اصدار شهادات منتجات', 'مصروفات مدفوعة مقدما وارصدة مدينة أخرى', 100, 0],
            ['120707', 'مصاريف رسوم تسجيل', '', 100, 0],
        ])])->assertOk()->json('data');

        $this->assertSame([], $data[0]['data_flags']);
        $this->assertSame(['statement_mismatch'], array_column($data[1]['data_flags'], 'type'));
    }

    /* ---------- periodic stock and other provisions ---------- */

    public function test_periodic_stock_and_warranty_provisions_sit_on_their_natural_side(): void
    {
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'مخزون أول المدة'),
            $this->account(3, 'مخزون آخر المدة'),
            $this->account(4, 'مخصص ضمان'),
        ]);

        $data = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['61', 'بضاعة أول المدة', 0, 0, 1000, 0, 1000, 0],
            ['901', 'بضاعة آخر المدة', 0, 0, 0, 1500, 0, 1500],
            ['2305', 'مخصص ضمان بضاعة مباعة', 0, 200, 50, 80, 0, 230],
        ])])->assertOk()->json('data');

        $this->assertSame(['قائمة الدخل', 'تكلفة البضاعة المباعة'], [$data[0]['financial_statement'], $data[0]['main_class']]);
        $this->assertSame(['قائمة الدخل', 'تكلفة البضاعة المباعة'], [$data[1]['financial_statement'], $data[1]['main_class']]);
        $this->assertSame(['قائمة المركز المالي', 'مخصصات أخرى'], [$data[2]['financial_statement'], $data[2]['main_class']]);
        // Closing stock is a credit and the provision a liability: neither is on the wrong side.
        foreach ($data as $row) {
            $this->assertSame([], $row['data_flags'], $row['account_name']);
        }

        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r)
            && str_contains($this->systemText($r), '"مخزون أول المدة" / "مخزون آخر المدة"')
            && str_contains($this->systemText($r), '"مخصص ضمان" (a liability)'));
    }

    public function test_statement_sub_classes_sit_on_their_natural_side(): void
    {
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'استثمارات عقارية'),
            $this->account(3, 'استثمارات في شركات زميلة'),
            $this->account(4, 'توزيعات أرباح مستحقة'),
            $this->account(5, 'إيرادات مؤجلة'),
            $this->account(6, 'رد خسائر الانخفاض'),
        ]);

        $data = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['1601', 'عقارات استثمارية - أراضي', 900, 0, 0, 0, 900, 0],
            ['1602', 'استثمار في شركة زميلة', 400, 0, 50, 0, 450, 0],
            ['2207', 'توزيعات أرباح مستحقة للشركاء', 0, 0, 0, 120, 0, 120],
            ['2208', 'إيرادات مقدمة من العملاء غير مكتسبة', 0, 30, 10, 40, 0, 60],
            ['4405', 'رد مخصص ديون مشكوك فيها', 0, 0, 0, 25, 0, 25],
        ])])->assertOk()->json('data');

        $this->assertSame(
            ['استثمارات', 'استثمارات', 'مصروفات مستحقةو ارصدة دائنة اخرى', 'مصروفات مستحقةو ارصدة دائنة اخرى', 'إيرادات أخرى'],
            array_column($data, 'main_class'),
        );
        foreach ($data as $row) {
            $this->assertSame([], $row['data_flags'], $row['account_name']);
        }

        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r)
            && str_contains($this->systemText($r), '→ "استثمارات عقارية", not property, plant and equipment')
            && str_contains($this->systemText($r), '→ "رد خسائر الانخفاض", not "إيرادات أخرى"'));
    }

    /* ---------- statement-level numbering scheme ---------- */

    /**
     * Balance sheet numbered 1–3, income statement 4–8: five assets, five liabilities, five revenues, five expenses,
     * plus $extra rows [code, name, sub_class].
     */
    private function schemeFile(array $extra): array
    {
        $rows   = [];
        $labels = [];
        $add = function (string $code, string $name, string $sub) use (&$rows, &$labels) {
            $rows[]   = $this->balanced($code, $name, $sub);
            $labels[] = $this->account(count($rows) + 1, $sub, 95);
        };
        foreach (range(1, 5) as $i) {
            $add("110{$i}", "بنك {$i}", 'نقدية لدى البنوك');
            $add("210{$i}", "مورد {$i}", 'موردون');
            $add("410{$i}", "مبيعات {$i}", 'إيرادات المبيعات');
            $add("810{$i}", "إيجار {$i}", 'إيجارات');
        }
        foreach ($extra as [$code, $name, $sub]) {
            $add($code, $name, $sub);
        }
        return [$rows, $labels];
    }

    /** Opening = closing on the sub-class's normal side: no reconciliation or wrong-side flag gets in the way. */
    private function balanced(string $code, string $name, string $sub): array
    {
        return app(\App\Repositories\Eloquents\TaxonomyRepository::class)->normalSide($sub) === 'credit'
            ? [$code, $name, 0, 100, 0, 0, 0, 100]
            : [$code, $name, 100, 0, 0, 0, 100, 0];
    }

    public function test_an_account_on_the_wrong_side_of_the_statement_scheme_is_flagged_high(): void
    {
        // Each of 6 and 9 holds one account, classified on the balance sheet: alone, a digit can't confirm itself.
        [$rows, $labels] = $this->schemeFile([['61', 'بضاعة بالمستودع', 'مخزون بضاعة'], ['901', 'بضاعة آخر المدة', 'مخزون بضاعة']]);
        $this->fakeLlm($this->standardStructure(), $labels);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk();
        $rowsByCode = collect($response->json('data'))->keyBy('account_number');

        foreach (['61', '901'] as $code) {
            $row  = $rowsByCode[$code];
            $flag = collect($row['data_flags'])->firstWhere('type', 'prefix_mismatch');
            $this->assertSame('high', $flag['severity'], $code);
            $this->assertStringContainsString('من 1 إلى 2 في قائمة المركز المالي ومن 4 إلى 8 في قائمة الدخل', $flag['message']);
            $this->assertStringContainsString('أي أن هذا الحساب في قائمة الدخل', $flag['message']);
            $this->assertSame([50, true], [$row['classification_confidence'], $row['needs_review']], $code);
            $this->assertStringStartsWith('⛔', $row['note'], $code);
        }
        // Accounts that follow the scheme are not flagged.
        $this->assertSame([], $rowsByCode['1101']['data_flags']);
        $this->assertSame([], $rowsByCode['4101']['data_flags']);
        $this->assertStringContainsString('«بضاعة بالمستودع» (الصف 22 في الملف)', $response->json('meta.warnings.0'));
    }

    public function test_codes_with_letters_count_toward_the_statement_scheme(): void
    {
        [$rows, $labels] = $this->schemeFile([['1204MBA009', 'عميل', 'عملاء'], ['9MX01', 'تسوية مخزون', 'مخزون بضاعة']]);
        $this->fakeLlm($this->standardStructure(), $labels);

        $data = collect($this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk()->json('data'))->keyBy('account_number');

        $this->assertSame([], $data['1204MBA009']['data_flags']);
        $this->assertSame('high', collect($data['9MX01']['data_flags'])->firstWhere('type', 'prefix_mismatch')['severity']);
    }

    public function test_mixed_numbering_has_no_statement_scheme(): void
    {
        // Expenses under 1 as well: no split reaches 95% agreement, so nothing is flagged by the statement check.
        [$rows, $labels] = $this->schemeFile([
            ['1201', 'مصروف 1', 'إيجارات'], ['1202', 'مصروف 2', 'إيجارات'], ['1203', 'مصروف 3', 'إيجارات'],
        ]);
        $this->fakeLlm($this->standardStructure(), $labels);

        $data = $this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk()->json('data');

        foreach ($data as $row) {
            $high = array_filter($row['data_flags'], fn ($f) => ($f['severity'] ?? null) === 'high');
            $this->assertSame([], $high, $row['account_number']);
        }
    }

    public function test_the_family_check_never_uses_the_account_as_its_own_evidence(): void
    {
        // Four assets and one expense under 1: the expense sees only four other accounts (< min_accounts 5), so no
        // per-digit scheme can flag it; with five other assets it is flagged.
        $rows   = [];
        $labels = [];
        foreach (range(1, 4) as $i) {
            $rows[]   = $this->balanced("110{$i}", "بنك {$i}", 'نقدية لدى البنوك');
            $labels[] = $this->account($i + 1, 'نقدية لدى البنوك');
        }
        $rows[]   = $this->balanced('1109', 'إيجار المعرض', 'إيجارات');
        $labels[] = $this->account(6, 'إيجارات');
        $labels[] = $this->account(7, 'نقدية لدى البنوك');   // the 6th account of the second upload (one fake for both)
        $this->fakeLlm($this->standardStructure(), $labels);

        $odd = $this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk()->json('data.4');
        $this->assertSame([], $odd['data_flags']);

        $rows[] = $this->balanced('1105', 'بنك 5', 'نقدية لدى البنوك');

        $odd = $this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk()->json('data.4');
        $this->assertSame('رقم الحساب يبدأ بـ 1، وفي هذا الملف 5 من 5 حساب آخر تبدأ به «أصول»، لكن التصنيف المقترح «مصروفات»',
            collect($odd['data_flags'])->firstWhere('type', 'prefix_mismatch')['message']);
    }

    public function test_contra_accounts_are_neither_evidence_nor_doubt_in_the_family_check(): void
    {
        // Under 2: five liabilities and four accumulated depreciations (numbered with the credits in this chart). The
        // depreciations aren't flagged and don't count as assets, so a customer numbered 2 is a doubt (5 of 5 liabilities).
        $rows = $labels = [];
        foreach (range(1, 5) as $i) {
            $rows[] = $this->balanced("210{$i}", "مورد {$i}", 'دائنون متنوعون');
            $labels[] = $this->account($i + 1, 'دائنون متنوعون');
        }
        foreach (range(1, 4) as $i) {
            $rows[] = $this->balanced("260{$i}", "مجمع اهلاك {$i}", 'مجمع إهلاك الأصول الثابتة');
            $labels[] = $this->account($i + 6, 'مجمع إهلاك الأصول الثابتة');
        }
        $rows[] = $this->balanced('2401', 'المها للنقليات', 'عملاء');
        $labels[] = $this->account(11, 'عملاء');
        $rows[] = $this->balanced('2010', 'رأس المال', 'رأس المال');   // equity numbered with the liabilities: no doubt
        $labels[] = $this->account(12, 'رأس المال');
        $this->fakeLlm($this->standardStructure(), $labels);

        $data = collect($this->postJson(self::URL, ['file' => $this->trialBalance($rows)])->assertOk()->json('data'))->keyBy('row');
        $this->assertNotContains('prefix_mismatch', array_column($data[7]['data_flags'], 'type'));
        $this->assertSame('رقم الحساب يبدأ بـ 2، وفي هذا الملف 6 من 6 حساب آخر تبدأ به «التزامات وحقوق ملكية»، لكن التصنيف المقترح «أصول»',
            collect($data[11]['data_flags'])->firstWhere('type', 'prefix_mismatch')['message']);
        $this->assertNotContains('prefix_mismatch', array_column($data[12]['data_flags'], 'type'));
    }
}
