<?php

namespace Tests\Feature;

use App\Models\ClassificationCacheEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Side-dependent sub-classes: the cache answer is re-decided by each row's balance side (config side_pairs). */
class SidePairsTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    /** @var array<int, string> row => the AI's sub-class */
    private array $ai = [];

    /** @var list<int> rows the AI was asked about */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['classification.rules_enabled' => true]);   // the accountant's rules apply here, as in a run
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['classification.cache.enabled' => true]);
        $this->fakeLlm($this->standardStructure(), function ($call, array $requested) {
            array_push($this->asked, ...$requested);

            return array_map(fn ($n) => $this->account($n, $this->ai[$n], 95), array_keys($this->ai));
        });
    }

    /** One run: [code, name, closing debit, closing credit, opening [debit, credit]?] per row; the movement reconciles them. */
    private function classify(array $accounts): TestResponse
    {
        $rows = array_map(function ($a) {
            [$oD, $oC] = $a[4] ?? [0, 0];
            $net = ($a[2] - $a[3]) - ($oD - $oC);   // the movement that reconciles opening to closing

            return [$a[0], $a[1], $oD, $oC, max($net, 0), max(-$net, 0), $a[2], $a[3]];
        }, $accounts);
        $id   = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($rows)])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done');
    }

    private function row(TestResponse $run, int $excelRow): array
    {
        return collect($run->json('result.data'))->firstWhere('row', $excelRow);
    }

    public function test_fresh_ai_answers_flip_by_side_too_prepaid_and_accrued_expenses_included(): void
    {
        $this->ai = [2 => 'مصروفات مستحقة', 3 => 'مصروفات مدفوعة مقدماً', 4 => 'عملاء'];
        $run = $this->classify([['1401', 'إيجار', 100, 0], ['2401', 'تأمين طبي', 0, 40], ['1201', 'العميل سعيد', 0, 70]]);
        $this->assertSame([2, 3, 4], $this->asked);   // nothing from the cache

        $this->assertSame(['مصروفات مدفوعة مقدماً', false], [$this->row($run, 2)['sub_class'], $this->row($run, 2)['needs_review']]);
        $this->assertSame('مصروفات مستحقة', $this->row($run, 3)['sub_class']);
        $this->assertSame(['دفعات مقدمة من العملاء', true], [$this->row($run, 4)['sub_class'], $this->row($run, 4)['needs_review']]);
        $this->assertSame(['expenses' => 2], array_column($run->json('result.meta.side_flips'), 'rows', 'pair'));   // the customer: the «عميل» rule's
        // The cache keeps the kind on the pair's base side.
        $this->assertSame('مصروفات مدفوعة مقدماً', ClassificationCacheEntry::where('account_code', '2401')->value('sub_class'));
    }

    public function test_the_accountant_s_counterparts_of_2026_10_20(): void
    {
        $this->ai = [2 => 'دائنون متنوعون', 3 => 'أرصدة دائنة أخرى', 4 => 'إيرادات مستحقة', 5 => 'سلف الموردين', 6 => 'ضرائب مستحقة',
            7 => 'تأمينات لدى الغير', 8 => 'حسابات مدينة لدى الجهات الحكومية'];
        $run = $this->classify([['2501', 'شركة المطابع', 244, 0], ['2502', 'أرصدة أخرى', 10, 0], ['1501', 'إيرادات', 0, 20],
            ['1502', 'سلفة مورد', 0, 30], ['2503', 'ضريبة دخل', 40, 0], ['1503', 'تأمين خطاب ضمان', 0, 50], ['1504', 'ضريبة استقطاع', 0, 60]]);

        $this->assertSame(['مدينون متنوعون', 'أرصدة مدينة أخرى', 'إيرادات مؤجلة', 'موردون', 'حسابات مدينة لدى الجهات الحكومية', 'أرصدة دائنة أخرى'],
            array_map(fn ($n) => $this->row($run, $n)['sub_class'], [2, 3, 4, 5, 6, 7]));
        // «سلفة مورد» in credit: the «مورد» rule's supplier on its own side, no review; the others neither.
        $this->assertSame([false, false], [$this->row($run, 2)['needs_review'], $this->row($run, 5)['needs_review']]);
        // A government receivable that isn't VAT still isn't flipped (the VAT pair holds it first): flagged.
        $this->assertSame(['حسابات مدينة لدى الجهات الحكومية', true], [$this->row($run, 8)['sub_class'], $this->row($run, 8)['needs_review']]);
    }

    public function test_the_review_follows_the_account_s_balance_not_the_ai_s_answer(): void
    {
        // Both suppliers by the «مورد» rule. The AI gave both the debit class: the credit one is a supplier on its own side
        // (no review, though the AI's answer was flipped); the debit one is a supplier in debit (a review, though the AI's
        // answer already sat there).
        $this->ai = [2 => 'دفعات مقدمة للموردين', 3 => 'دفعات مقدمة للموردين'];
        $run = $this->classify([['2023', 'تسهيلات موردين', 0, 387950], ['2022', 'المورد المطابع العصرية', 244, 0]]);

        $credit = $this->row($run, 2);
        $this->assertSame(['موردون', false, []], [$credit['sub_class'], $credit['needs_review'], array_column($credit['data_flags'], 'type')]);
        $debit = $this->row($run, 3);
        $this->assertSame(['دفعات مقدمة للموردين', true, ['side_flip']], [$debit['sub_class'], $debit['needs_review'], array_column($debit['data_flags'], 'type')]);
        $this->assertSame('انعكس التصنيف حسب اتجاه الرصيد: «موردون» ← «دفعات مقدمة للموردين». راجعه: رصيد عميل/مورد على الجانب المعاكس.', $debit['data_flags'][0]['message']);
    }

    public function test_a_saved_account_with_no_number_is_found_whatever_comes_above_it(): void
    {
        // «جمال» has no code: its key needs the parent above, unknown while the row above goes to the AI. Saved once, it
        // comes back from the memory on the next file anyway.
        $this->ai = [2 => 'نقدية لدى البنوك', 3 => 'سلف العاملين'];
        $file = [['1101', 'بنك جديد', 100, 0], ['', 'سالم الحربي', 30, 0]];
        $run = $this->classify($file);
        $this->postJson("/api/v1/classify/jobs/{$run->json('id')}/corrections", ['corrections' => [['row' => 3, 'sub_class' => 'عُهد وسُلف العاملين']]])->assertOk();
        ClassificationCacheEntry::where('account_code', '1101')->delete();   // the row above: the AI's again

        $again = $this->row($this->classify($file), 3);
        $this->assertSame(['عُهد وسُلف العاملين', 'manual_correction'], [$again['sub_class'], $again['classification_source']]);
        $this->assertSame([[], false], [$again['data_flags'], $again['needs_review']]);   // no number, but found by name: no flag
        $this->assertSame(1, ClassificationCacheEntry::where('original_name', 'سالم الحربي')->value('hit_count'));
    }

    public function test_a_pair_s_label_follows_a_taxonomy_rename(): void
    {
        $sub = \App\Models\TaxonomySubClass::where('name', 'عُهد وسُلف دائنه')->first();
        app(\App\Services\TaxonomyManager::class)->updateSub($sub, ['name' => 'عُهد وسُلف دائنة', 'normal_side' => 'credit']);

        $this->assertSame('عُهد وسُلف العاملين ↔ عُهد وسُلف دائنة', \App\Services\ClassificationCache::pairLabel(config('classification.side_pairs.employee_accounts')));
    }

    public function test_a_customer_with_a_credit_balance_flips_with_a_review_flag_and_an_employee_advance_without(): void
    {
        $this->ai = [2 => 'عملاء', 3 => 'عُهد العاملين'];
        $this->classify([['1201', 'العميل أحمد', 100, 0], ['1301', 'عهدة سامي', 50, 0]]);
        $this->asked = [];

        // Next file: both balances turned credit. Both rows come from the cache, re-decided by side.
        $run = $this->classify([['1201', 'العميل أحمد', 0, 100], ['1301', 'عهدة سامي', 0, 50]]);
        $this->assertSame([], $this->asked);

        $customer = $this->row($run, 2);
        $this->assertSame(['دفعات مقدمة من العملاء', true], [$customer['sub_class'], $customer['needs_review']]);
        $this->assertContains('side_flip', array_column($customer['data_flags'], 'type'));
        $employee = $this->row($run, 3);
        // The «عهدة» rule decides it: its own class for a credit, not the pair's.
        $this->assertSame(['عُهد وسُلف دائنه', false, 'rule'], [$employee['sub_class'], $employee['needs_review'], $employee['classification_source']]);

        // Both decided by their rules («عميل», «عهدة»): the rules' summary, not the pairs' flips.
        $this->assertSame([], $run->json('result.meta.side_flips'));
        $this->assertContains('قاعدة «عميل» صنّفت 1 حساب: 1 «دفعات مقدمة من العملاء» (دائن).', $run->json('result.meta.info'));

        // The cache itself keeps the account's kind; it is never rewritten by a flip.
        $this->assertSame('عملاء', ClassificationCacheEntry::where('account_code', '1201')->value('sub_class'));

        // Closing zero: the opening balance decides. (All zero: the row is left out of the classification.)
        $this->assertSame('دفعات مقدمة من العملاء', $this->row($this->classify([['1201', 'العميل أحمد', 0, 0, [0, 30]]]), 2)['sub_class']);

        // A pair switched off in config (and the «عميل» rule off): no flip.
        config(['classification.side_pairs.customers.enabled' => false]);
        \App\Models\ClassificationRule::where('name', 'عميل')->update(['enabled' => false]);
        $this->assertSame('عملاء', $this->row($this->classify([['1201', 'العميل أحمد', 0, 100]]), 2)['sub_class']);
    }

    public function test_a_manual_correction_matching_the_side_flips_later(): void
    {
        $this->ai = [2 => 'موردون'];
        $first = $this->classify([['2101', 'مورد التوريدات', 80, 0]]);
        // Debit balance: the accountant picks the debit member (an advance to the supplier): the kind of account.
        $this->patchJson("/api/v1/classify/jobs/{$first->json('id')}/rows/2", ['sub_class' => 'دفعات مقدمة للموردين'])->assertOk();
        // Stored as the pair's base class (suppliers: موردون), the kind of account; each row's side picks the member.
        $entry = ClassificationCacheEntry::where('account_code', '2101')->sole();
        $this->assertSame(['manual_correction', 'debit', false, 'موردون'], [$entry->source, $entry->side, $entry->side_locked, $entry->sub_class]);

        $credit = $this->row($this->classify([['2101', 'مورد التوريدات', 0, 80]]), 2);
        $this->assertSame(['موردون', false], [$credit['sub_class'], $credit['needs_review']]);
        $debit = $this->row($this->classify([['2101', 'مورد التوريدات', 80, 0]]), 2);
        $this->assertSame(['دفعات مقدمة للموردين', true], [$debit['sub_class'], $debit['needs_review']]);
    }

    public function test_a_manual_correction_against_the_side_is_locked_and_never_flips(): void
    {
        $this->ai = [2 => 'موردون'];
        $first = $this->classify([['2102', 'مورد المعدات', 0, 80]]);
        // Credit balance, yet the accountant picks the debit member: a deliberate override.
        $this->patchJson("/api/v1/classify/jobs/{$first->json('id')}/rows/2", ['sub_class' => 'دفعات مقدمة للموردين'])->assertOk();
        $this->assertTrue(ClassificationCacheEntry::where('account_code', '2102')->value('side_locked'));

        foreach ([[0, 80], [80, 0]] as [$debit, $credit]) {
            $row = $this->row($this->classify([['2102', 'مورد المعدات', $debit, $credit]]), 2);
            $this->assertSame('دفعات مقدمة للموردين', $row['sub_class']);
            $this->assertNotContains('side_flip', array_column($row['data_flags'], 'type'));
        }
    }

    public function test_employee_credits_flip_back_to_advances_when_the_name_says_so(): void
    {
        $this->ai = [2 => 'أمانات العاملين', 3 => 'أمانات العاملين', 4 => 'أمانات العاملين'];
        $this->classify([['1401', 'سلفة خالد', 0, 40], ['1402', 'عهدة منى', 0, 40], ['1403', 'عهد وسلف الموظفين', 0, 40]]);

        // Debit next time: «سلف» in the name → سلف العاملين; «عهدة» / «عهد» → the «عهدة» rule's class.
        $run = $this->classify([['1401', 'سلفة خالد', 40, 0], ['1402', 'عهدة منى', 40, 0], ['1403', 'عهد وسلف الموظفين', 40, 0]]);
        $this->assertSame(['سلف العاملين', 'عُهد وسُلف العاملين', 'عُهد وسُلف العاملين'],
            [$this->row($run, 2)['sub_class'], $this->row($run, 3)['sub_class'], $this->row($run, 4)['sub_class']]);
    }

    public function test_only_vat_accounts_flip_and_other_government_receivables_get_a_review_flag(): void
    {
        $gov = 'حسابات مدينة لدى الجهات الحكومية';
        $this->ai = [2 => $gov, 3 => $gov, 4 => $gov, 5 => $gov];
        $names = [['1601', 'ضريبة ق.م مدخلات'], ['1602', 'VAT Input'], ['1603', 'مصلحة الجمارك - أمانات'], ['1604', 'قسم مبيعات حكومية']];
        $this->classify(array_map(fn ($a) => [$a[0], $a[1], 70, 0], $names));

        $run = $this->classify(array_map(fn ($a) => [$a[0], $a[1], 0, 70], $names));
        $this->assertSame(['ضريبة القيمة المضافة', 'ضريبة القيمة المضافة', $gov, $gov], array_map(fn ($n) => $this->row($run, $n)['sub_class'], [2, 3, 4, 5]));

        // VAT-named: stored as the base class (VAT) already, so a credit row needs no flip. Others (whole-word match:
        // «قسم مبيعات» is not «ق م»): never converted, so kept and flagged.
        $this->assertSame(['ضريبة القيمة المضافة', 'ضريبة القيمة المضافة', $gov, $gov],
            ClassificationCacheEntry::orderBy('account_code')->pluck('sub_class')->all());
        $this->assertNotContains('side_flip', array_column($this->row($run, 2)['data_flags'], 'type'));
        $debit = $this->classify(array_map(fn ($a) => [$a[0], $a[1], 70, 0], $names));
        $this->assertSame([$gov, $gov, $gov, $gov], array_map(fn ($n) => $this->row($debit, $n)['sub_class'], [2, 3, 4, 5]));
        $this->assertSame('info', collect($this->row($debit, 2)['data_flags'])->firstWhere('type', 'side_flip')['severity']);
        foreach ([4, 5] as $n) {
            $row = $this->row($run, $n);
            $this->assertTrue($row['needs_review']);
            $this->assertStringContainsString('الرصيد دائن وهذا عكس طبيعة', collect($row['data_flags'])->firstWhere('type', 'side_contradiction')['message']);
        }
        $this->assertSame([['pair' => 'vat', 'label' => 'ضريبة القيمة المضافة (مدينة ↔ دائنة)', 'rows' => 0, 'flagged' => false, 'contradictions' => 2]],
            $run->json('result.meta.side_flips'));
        $this->assertContains('2 صف رصيدها عكس تصنيفها وليست من الأسماء التي ينعكس تصنيفها (ضريبة القيمة المضافة (مدينة ↔ دائنة))، وعُلّمت للمراجعة.', $run->json('result.meta.info'));
    }
}
