<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Validation of AI output against the file: retries, missing/duplicate rows, parents, totals, balance. */
class IntegrityChecksTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function twoAccounts()
    {
        return $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 100, 0, 100],
        ]);
    }

    private function rowCalls(): int
    {
        return Http::recorded(fn (Request $r) => ! $this->isStructureCall($r))->count();
    }

    public function test_missing_row_triggers_retry_and_retry_fills_it(): void
    {
        $this->fakeLlm($this->standardStructure(), fn (int $call) => $call === 1
            ? [$this->account(2, 'نقدية بالصندوق')]
            : [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]);

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])
            ->assertOk()
            ->assertJsonPath('data.1.sub_class', 'رأس المال')
            ->assertJsonPath('meta.needs_review', 0);

        $this->assertSame(2, $this->rowCalls());

        // The retry asks only for the missing row; the labelled one is sent as context.
        $retry = $this->userText(Http::recorded(fn (Request $r) => ! $this->isStructureCall($r))->last()[0]);
        $toClassify = substr($retry, strpos($retry, 'Rows to classify:'));
        $this->assertStringContainsString('R3 ', $toClassify);
        $this->assertStringNotContainsString('R2 ', $toClassify);
    }

    public function test_row_still_missing_after_retry_is_kept_for_review(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.account_name', 'رأس المال')
            ->assertJsonPath('data.1.closing_credit', 100)
            ->assertJsonPath('data.1.sub_class', null)
            ->assertJsonPath('data.1.classification_confidence', 0)
            ->assertJsonPath('data.1.needs_review', true)
            ->assertJsonPath('data.1.note', 'لم يتم تصنيف الحساب');

        $this->assertSame(2, $this->rowCalls());
    }

    public function test_complete_first_answer_does_not_retry(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]);

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])->assertOk();

        $this->assertSame(1, $this->rowCalls());
    }

    public function test_duplicate_row_labels_are_rejected_and_retried(): void
    {
        $this->fakeLlm($this->standardStructure(), fn (int $call) => $call === 1
            ? [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال'), $this->account(3, 'موردون')]
            : [$this->account(3, 'رأس المال')]);

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])
            ->assertOk()
            ->assertJsonPath('data.1.sub_class', 'رأس المال');

        $this->assertSame(2, $this->rowCalls());
    }

    public function test_account_label_with_unknown_sub_class_is_retried(): void
    {
        $this->fakeLlm($this->standardStructure(), fn (int $call) => $call === 1
            ? [$this->account(2, 'تصنيف غير موجود'), $this->account(3, 'رأس المال')]
            : [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])
            ->assertOk()
            ->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق');
    }

    public function test_labels_for_rows_not_requested_are_ignored(): void
    {
        $this->fakeLlm($this->standardStructure(), fn () => [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال'), $this->account(99, 'موردون'),
        ]);

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_blank_unlabelled_rows_are_skipped_not_reported(): void
    {
        $file = $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            [null, null, null, null, null, null, 'ملاحظة', null],
        ]);

        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.skipped_rows', 1);
    }

    public function test_non_numeric_amount_marks_row_for_review(): void
    {
        $file = $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 'N/A', 0],
            ['110102', 'البنك', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 200, 0, 200],
        ]);

        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية لدى البنوك'), $this->account(4, 'رأس المال'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.closing_debit', 0)
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.0.classification_confidence', 95)
            ->assertJsonPath('data.0.data_flags', [
                // The unreadable closing (read as 0) also breaks the reconciliation — both are reported.
                ['type' => 'reconciliation', 'difference' => -100, 'message' => 'الرصيد الختامي أقل من المحسوب بمقدار 100.00',
                    'message_text' => 'The closing balance is lower than calculated by 100.00'],
                ['type' => 'invalid_number', 'difference' => null, 'message' => 'قيمة غير رقمية في العمود G', 'message_text' => 'Non-numeric value in column G'],
            ])
            ->assertJsonPath('data.1.needs_review', false);
    }

    public function test_parent_row_labelled_as_account_is_flagged(): void
    {
        $file = $this->trialBalance([
            ['11', 'النقدية', 0, 0, 300, 0, 300, 0],
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['1102', 'البنك', 0, 0, 200, 0, 200, 0],
        ]);

        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية بالصندوق'), $this->account(4, 'نقدية لدى البنوك'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.0.classification_confidence', 95)
            ->assertJsonPath('data.0.data_flags', [[
                'type' => 'possible_parent', 'difference' => null,
                'message' => 'قد يكون حساباً تجميعياً (رصيده يساوي مجموع الحسابات التي تبدأ برقمه)',
                'message_text' => 'May be a parent account (its balance equals the sum of the accounts starting with its number)',
            ]])
            ->assertJsonPath('data.1.needs_review', false)
            ->assertJsonPath('data.2.needs_review', false);
    }

    public function test_number_prefix_without_matching_sum_is_not_flagged(): void
    {
        // Flat numbering: "1" prefixes "10" but balances are unrelated.
        $file = $this->trialBalance([
            ['1', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['10', 'البنك', 0, 0, 250, 0, 250, 0],
        ]);

        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية لدى البنوك'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.needs_review', false);
    }

    /** One account per major group (assets, liabilities, equity, income, expenses). */
    private function fullTrialBalance(float $expenseDebit)
    {
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'موردون'), $this->account(4, 'رأس المال'),
            $this->account(5, 'إيرادات المبيعات'), $this->account(6, 'إيجارات'),
        ]);

        return $this->trialBalance([
            ['1101', 'الصندوق', 0, 0, 500, 0, 500, 0],
            ['2101', 'مورد', 0, 0, 0, 200, 0, 200],
            ['3101', 'رأس المال', 0, 0, 0, 300, 0, 300],
            ['4101', 'المبيعات', 0, 0, 0, 100, 0, 100],
            ['6101', 'الإيجار', 0, 0, $expenseDebit, 0, $expenseDebit, 0],
        ]);
    }

    public function test_unbalanced_full_trial_balance_is_refused(): void
    {
        config(['classification.reject_unbalanced_from' => 1.00]);
        $this->withHeader('Accept-Language', 'ar')->postJson(self::URL, ['file' => $this->fullTrialBalance(90)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'الميزان غير متزن، لذا رُفض التبويب: حركة الفترة (الفرق 10.00)، الرصيد الختامي (الفرق 10.00). المسموح به فقط فرق تقريب أقل من 1.00. صحّح الملف وارفعه مرة أخرى.');
        // A rounding difference up to the limit passes, as info.
        $this->postJson(self::URL, ['file' => $this->fullTrialBalance(99.5)])->assertOk()
            ->assertJsonPath('meta.info', ['فرق تقريب 0.50 في حركة الفترة', 'فرق تقريب 0.50 في الرصيد الختامي', '⚠️ الميزان بدون أرصدة افتتاحية. تم قبوله، لكن إن لم تكن الشركة في أول سنة لها: يجب إدخال أرقام السنة السابقة في القوائم المالية (يدويًا أو من ملف قوائمها المالية)، حتى تظهر المقارنة والتدفقات النقدية والتغيرات في حقوق الملكية بشكل صحيح.']);
    }

    public function test_the_refusal_limit_is_configured_and_below_it_differences_are_warnings(): void
    {
        config(['classification.reject_unbalanced_from' => 20]);
        $this->postJson(self::URL, ['file' => $this->fullTrialBalance(90)])
            ->assertOk()
            ->assertJsonPath('meta.info', ['⚠️ الميزان بدون أرصدة افتتاحية. تم قبوله، لكن إن لم تكن الشركة في أول سنة لها: يجب إدخال أرقام السنة السابقة في القوائم المالية (يدويًا أو من ملف قوائمها المالية)، حتى تظهر المقارنة والتدفقات النقدية والتغيرات في حقوق الملكية بشكل صحيح.'])
            ->assertJsonPath('meta.warnings', [
                'إجمالي المدين لا يساوي إجمالي الدائن في حركة الفترة (الفرق 10.00)',
                'إجمالي المدين لا يساوي إجمالي الدائن في الرصيد الختامي (الفرق 10.00)',
            ]);
    }

    public function test_balanced_full_trial_balance_has_no_messages(): void
    {
        $this->postJson(self::URL, ['file' => $this->fullTrialBalance(100)])
            ->assertOk()
            ->assertJsonPath('meta.warnings', [])
            ->assertJsonPath('meta.info', ['⚠️ الميزان بدون أرصدة افتتاحية. تم قبوله، لكن إن لم تكن الشركة في أول سنة لها: يجب إدخال أرقام السنة السابقة في القوائم المالية (يدويًا أو من ملف قوائمها المالية)، حتى تظهر المقارنة والتدفقات النقدية والتغيرات في حقوق الملكية بشكل صحيح.']);
    }

    public function test_an_unbalanced_partial_trial_balance_is_refused_too_and_one_riyal_is_enough(): void
    {
        config(['classification.reject_unbalanced_from' => 1.00]);
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]);
        $this->withHeader('Accept-Language', 'ar')->postJson(self::URL, ['file' => $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 99, 0, 99],
        ])])->assertStatus(422)
            ->assertJsonPath('message', 'الميزان غير متزن، لذا رُفض التبويب: حركة الفترة (الفرق 1.00)، الرصيد الختامي (الفرق 1.00). المسموح به فقط فرق تقريب أقل من 1.00. صحّح الملف وارفعه مرة أخرى.');
    }

    /** Below the refusal limit (the test default), a partial trial balance's imbalance is info. */
    public function test_unbalanced_partial_trial_balance_is_info_not_warning(): void
    {
        $file = $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 90, 0, 90],
        ]);

        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.warnings', [])
            ->assertJsonPath('meta.info', ['هذا الملف ميزان جزئي (فيه أصول، حقوق الملكية فقط)، لذا فعدم التوازن متوقع', '⚠️ الميزان بدون أرصدة افتتاحية. تم قبوله، لكن إن لم تكن الشركة في أول سنة لها: يجب إدخال أرقام السنة السابقة في القوائم المالية (يدويًا أو من ملف قوائمها المالية)، حتى تظهر المقارنة والتدفقات النقدية والتغيرات في حقوق الملكية بشكل صحيح.']);
    }

    public function test_account_sums_not_matching_total_row_return_warning(): void
    {
        // A dropped account: remaining rows balance with each other but not with the file total.
        $file = $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 100, 0, 100],
            ['', 'الإجمالي', 0, 0, 150, 150, 150, 150],
        ]);

        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال'), $this->rowOfType(4, 'total'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.warnings', ['مجموع الحسابات لا يطابق صف الإجمالي في الملف (الصف 4 في الملف)']);
    }

    public function test_matching_one_of_several_total_rows_is_enough(): void
    {
        $file = $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['', 'إجمالي الأصول', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 100, 0, 100],
            ['', 'الإجمالي العام', 0, 0, 100, 100, 100, 100],
        ]);

        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'), $this->rowOfType(3, 'total'),
            $this->account(4, 'رأس المال'), $this->rowOfType(5, 'total'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.warnings', []);
    }

    public function test_invalid_structure_is_retried_with_validation_errors(): void
    {
        $this->fakeLlm(
            fn (int $attempt) => $attempt === 1
                ? $this->standardStructure(['columns' => ['account_name' => 'Z']])
                : $this->standardStructure(),
            [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'رأس المال')]
        );

        $this->postJson(self::URL, ['file' => $this->twoAccounts()])->assertOk()->assertJsonPath('meta.total', 2);

        Http::assertSent(fn (Request $r) => $this->isStructureCall($r)
            && str_contains($this->userText($r), 'Your previous answer was rejected by validation')
            && str_contains($this->userText($r), 'account_name: column Z has no values in sheet 1.'));
    }

    public function test_structure_invalid_after_retry_returns_422(): void
    {
        $this->fakeLlm(
            $this->standardStructure(['columns' => ['closing_debit' => 'B']]),  // text column mapped as an amount
            []
        );

        $response = $this->postJson(self::URL, ['file' => $this->twoAccounts()])->assertStatus(422);

        // The actual problems, not a generic message.
        $problems = [
            'قُرئ العمود B على أنه أكثر من حقل في الوقت نفسه: «اسم الحساب» و«مدين آخر المدة».',
            'قُرئ العمود B على أنه «مدين آخر المدة» لكن 0% فقط من قيمه أرقام.',
        ];
        $response->assertJsonPath('problems', $problems)
            ->assertJsonPath('message', "تعذّرت قراءة أعمدة الميزان بشكل صحيح:\n- ".implode("\n- ", $problems));

        $this->assertSame(0, $this->rowCalls());
        Http::assertSentCount(2);
    }
}
