<?php

namespace Tests\Feature;

use App\Models\ClassificationRule;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\CompanyCodeGroup;
use App\Services\ClassificationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\Support\ClassifiedRows;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * The accountant's classification rules (docs/17-classification-rules.md): balance-sheet accounts only, whole words,
 * the first rule in order, a company's code groups, the class by the side of the balance, the code-group proposals, the
 * preview report, and the page. Made-up names and amounts; the rules are the seeded ones.
 */
class ClassificationRulesTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;
    use TrialBalanceFiles { TrialBalanceFiles::account insteadof ClassifiedRows; ClassifiedRows::account as classifiedAccount; }

    protected function setUp(): void
    {
        parent::setUp();
        config(['classification.rules_enabled' => true]);
    }

    private function rule(string $name): ClassificationRule
    {
        return ClassificationRule::where('name', $name)->firstOrFail();
    }

    /** A classified row: name, number, sub class (its statement from the taxonomy), closing balance (signed). */
    private function row(int $n, string $name, string $number, string $subClass, float $closing, float $opening = 0): array
    {
        return array_merge($this->classifiedAccount($n, $subClass, ['opening' => $opening, 'closing' => $closing]), ['account_name' => $name, 'account_number' => $number]);
    }

    private function hit(ClassificationRules $rules, array $row): ?string
    {
        return $rules->match($row)['rule']->name ?? null;
    }

    public function test_balance_sheet_accounts_only_and_whole_words(): void
    {
        $rules = ClassificationRules::load();

        $this->assertSame('مخصص الزكاة', $this->hit($rules, $this->row(2, 'مخصص الزكاة والدخل', '2030', 'مخصص الزكاة الشرعية', -50)));
        // The same words on income-statement accounts: no rule.
        $this->assertNull($this->hit($rules, $this->row(3, 'مصروف الزكاة', '4050', 'إيجارات', 50)));
        $this->assertNull($this->hit($rules, $this->row(4, 'بدل سكن المدير', '4060', 'إيجارات', 50)));
        // Whole words: «المدير» is «مدير», «المديرية» isn't; «معهد» isn't «عهد».
        $this->assertSame('مدير / شريك', $this->hit($rules, $this->row(5, 'محمد*المدير التنفيذي', '2051', 'دائنون متنوعون', -10)));
        $this->assertNull($this->hit($rules, $this->row(6, 'المديرية العامة للجوازات', '1301', 'أرصدة مدينة أخرى', 10)));
        $this->assertNull($this->hit($rules, $this->row(7, 'معهد التدريب', '1302', 'أرصدة مدينة أخرى', 10)));
        $this->assertSame('عهدة', $this->hit($rules, $this->row(8, 'عهدة- سامي', '1303', 'عُهد العاملين', 10)));
    }

    public function test_the_first_rule_in_order_wins_and_exclude_words_rule_a_rule_out(): void
    {
        $rules = ClassificationRules::load();

        // «مدير / شريك» comes before «مورد»; «تسهيلات موردين» is a supplier (not a kind of its own); the partners' current
        // account keeps its own treatment.
        $this->assertSame('مدير / شريك', $this->hit($rules, $this->row(2, 'المدير مورد', '2201', 'موردون', -10)));
        $this->assertSame('مورد', $this->hit($rules, $this->row(3, 'تسهيلات موردين', '2202', 'موردون', -10)));
        $this->assertNull($this->hit($rules, $this->row(9, 'جاري الشريك أحمد', '2301', 'دائنون متنوعون', -10)));
        $this->assertSame('مدير / شريك', $this->hit($rules, $this->row(10, 'الشريك أحمد', '2302', 'دائنون متنوعون', -10)));
        // The general salaries account isn't an employee's own, and the accumulated amortisation isn't the asset.
        $this->assertSame('رواتب مستحقة (عام)', $this->hit($rules, $this->row(4, 'مرتبات والأجور مستحقة', '2203', 'رواتب وأجور مستحقة', -10)));
        $this->assertSame('مجمع إطفاء ما قبل التشغيل', $this->hit($rules, $this->row(5, 'مجمع اطفاء مصاريف ما قبل التشغيل', '2204', 'مجمع إهلاك الأصول غير الملموسة', -10)));
        $this->assertSame('مصروفات ما قبل التشغيل', $this->hit($rules, $this->row(6, 'مصروفات ما قبل التشغيل', '1201', 'أرصدة مدينة أخرى', 10)));
    }

    public function test_the_class_follows_the_side_and_a_company_code_group_catches_people_s_names(): void
    {
        $company = Company::create(['name_ar' => 'شركة المثال']);
        $employee = $this->rule('موظف (حساب شخصي)');
        $credit = $this->row(2, 'سعيد أحمد', '1024030001', 'رواتب وأجور مستحقة', -500);
        $debit  = $this->row(3, 'فايز', '1024030002', 'سلف العاملين', 300);
        $this->assertNull(ClassificationRules::load($company->id)->match($credit));

        CompanyCodeGroup::create(['company_id' => $company->id, 'prefix' => '10240300', 'rule_id' => $employee->id]);
        $rules = ClassificationRules::load($company->id);
        $m = $rules->match($credit);
        $this->assertSame(['موظف (حساب شخصي)', 'mark'], [$m['rule']->name, $m['by']]);
        $this->assertSame(['side' => 'credit', 'class' => 'عُهد وسُلف دائنه', 'review' => false], ClassificationRules::outcome($m['rule'], $credit));
        $this->assertSame(['side' => 'debit', 'class' => 'عُهد وسُلف العاملين', 'review' => false], ClassificationRules::outcome($rules->match($debit)['rule'], $debit));
        // Other assets on the credit side: no class to move them to, flagged.
        $pre = $this->row(6, 'مصروفات ما قبل التشغيل', '1201', 'أرصدة مدينة أخرى', -10);
        $this->assertSame(['side' => 'credit', 'class' => null, 'review' => true], ClassificationRules::outcome($rules->match($pre)['rule'], $pre));
        // The account's own words beat the code group: the insurance numbered among the marked suppliers.
        CompanyCodeGroup::create(['company_id' => $company->id, 'prefix' => '20220', 'rule_id' => $this->rule('مورد')->id]);
        $marked = ClassificationRules::load($company->id);
        $this->assertSame(['جاري التأمينات الاجتماعية', 'name'], array_values(array_map(fn ($v) => is_object($v) ? $v->name : $v,
            $marked->match($this->row(8, 'جارى مصلحة التامينات الاجتماعية', '2022070000', 'تأمينات اجتماعية مستحقة', 18453)))));
        $this->assertSame(['مورد', 'mark'], array_values(array_map(fn ($v) => is_object($v) ? $v->name : $v,
            $marked->match($this->row(9, 'فندق زارا', '2022080000', 'دائنون متنوعون', -500)))));
        // A closed account: its opening balance decides; another company's groups don't apply.
        $closed = $this->row(4, 'سمير', '1024030003', 'سلف العاملين', 0, -200);
        $this->assertSame('عُهد وسُلف دائنه', ClassificationRules::outcome($rules->match($closed)['rule'], $closed)['class']);
        $this->assertNull(ClassificationRules::load(Company::create(['name_ar' => 'أخرى'])->id)->match($credit));
        // Zakat provision on the debit side: its class stays, flagged for review.
        $zakat = $this->row(5, 'مخصص الزكاة', '2030', 'مخصص الزكاة الشرعية', 40);
        $this->assertSame(['side' => 'debit', 'class' => 'مخصص الزكاة الشرعية', 'review' => true], ClassificationRules::outcome($rules->match($zakat)['rule'], $zakat));
        // The general accrued salaries on the debit side: an employee advance (the accountant, 2026-10-20).
        $salaries = $this->row(7, 'رواتب مستحقة', '2040', 'رواتب وأجور مستحقة', 60);
        $this->assertSame(['side' => 'debit', 'class' => 'سلف العاملين', 'review' => false], ClassificationRules::outcome($rules->match($salaries)['rule'], $salaries));
    }

    public function test_a_code_group_the_ai_mostly_put_in_one_kind_is_proposed_once_no_rule_catches_its_accounts(): void
    {
        $rows = [
            $this->row(2, 'سعيد', '1024030001', 'رواتب وأجور مستحقة', -10),
            $this->row(3, 'فايز', '1024030002', 'سلف العاملين', 10),
            $this->row(4, 'باسم', '1024030003', 'أمانات العاملين', -10),
            $this->row(5, 'معاذ', '1024030004', 'أرصدة مدينة أخرى', 10),
            // Caught by a rule by name: not counted.
            $this->row(6, 'عهدة نقدية', '1101010001', 'عُهد العاملين', 10),
            $this->row(7, 'عهدة سامي', '1101010002', 'عُهد العاملين', 10),
            $this->row(8, 'عهدة أحمد', '1101010003', 'عُهد العاملين', 10),
        ];
        $proposals = ClassificationRules::load()->proposals($rows);

        $this->assertSame([['10240300', 'موظف (حساب شخصي)', [2, 3, 4, 5], 0.75]],
            array_map(fn ($p) => [$p['prefix'], $p['rule']->name, $p['rows'], $p['share']], $proposals));
    }

    public function test_a_run_carries_its_proposals_and_the_link_fills_the_mark_in(): void
    {
        Http::preventStrayRequests();
        $company = Company::create(['name_ar' => 'شركة المثال']);
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'سلف العاملين'), $this->account(3, 'أمانات العاملين'),
            $this->account(4, 'سلف العاملين'), $this->account(5, 'رأس المال')]);
        $json = $this->postJson('/api/v1/classify/trial-balance', ['company_id' => $company->id, 'file' => $this->trialBalance([
            ['1024030001', 'سعيد', 0, 0, 100, 0, 100, 0], ['1024030002', 'فايز', 0, 0, 0, 50, 0, 50],
            ['1024030003', 'باسم', 0, 0, 50, 0, 50, 0], ['3101', 'رأس المال', 0, 0, 0, 100, 0, 100],
        ])])->assertOk()->json('meta.code_group_proposals');
        $rule = $this->rule('موظف (حساب شخصي)');
        $this->assertSame([['prefix' => '10240300', 'rule_id' => $rule->id, 'rule' => $rule->name, 'rows' => [2, 3, 4], 'share' => 1]], $json);

        $html = $this->get("/classification-rules?company_id={$company->id}&prefix=10240300&rule_id={$rule->id}")->assertOk()->getContent();
        $this->assertStringContainsString('value="10240300"', $html);
        $this->assertStringContainsString('<option value="'.$company->id.'" selected>', $html);
    }

    public function test_the_heading_above_each_account_is_kept_and_its_words_catch_it(): void
    {
        Http::preventStrayRequests();
        $this->fakeLlm($this->standardStructure(), [$this->account(3, 'سلف العاملين'), $this->account(4, 'رواتب وأجور مستحقة'),
            $this->rowOfType(5, 'total'), $this->account(6, 'رأس المال'), $this->account(8, 'رأس المال')]);
        $rows = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance([
            ['', 'ذمم موظفين', 0, 0, 0, 0, 0, 0],                       // R2 a heading (zero: never sent to the AI)
            ['1301', 'سعيد', 0, 0, 100, 0, 100, 0],
            ['1302', 'فايز', 0, 0, 0, 40, 0, 40],
            ['', 'إجمالي ذمم الموظفين', 0, 0, 100, 40, 100, 40],          // R5 a total ends it
            ['3101', 'رأس المال', 0, 0, 0, 60, 0, 60],
            ['2201', 'مورد بدون رصيد', 0, 0, 0, 0, 0, 0],                  // R7 a zero account with a number: not a heading
            ['3102', 'رأس المال الإضافي', 0, 0, 0, 5, 0, 5],
        ])])->assertOk()->json('data');

        $this->assertSame([3 => 'ذمم موظفين', 4 => 'ذمم موظفين', 6 => null, 8 => null], array_column($rows, 'heading', 'row'));
        $m = ClassificationRules::load()->match(collect($rows)->firstWhere('row', 4));
        $this->assertSame(['موظف (حساب شخصي)', 'heading'], [$m['rule']->name, $m['by']]);
        $this->assertSame('عُهد وسُلف دائنه', ClassificationRules::outcome($m['rule'], collect($rows)->firstWhere('row', 4))['class']);
    }

    public function test_a_rule_s_account_with_no_balance_takes_the_side_the_file_s_column_names(): void
    {
        // «مصروفات تأمين مستحقة»: nothing in any column, the movement nets to zero. The «مصروفات مقدمة / مستحقة» rule's kind
        // is prepaid (its pair's base), but the file's own column says accrued: accrued.
        Http::preventStrayRequests();
        Http::fake([$this->aiUrl() => fn (\Illuminate\Http\Client\Request $r) => Http::response($this->isStructureCall($r)
            ? $this->toolResponse('submit_structure', ['sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2, 'columns' => [
                'account_number' => 'A', 'account_name' => 'B', 'existing_classification' => 'C', 'period_debit' => 'D', 'period_credit' => 'E',
                'closing_debit' => 'F', 'closing_credit' => 'G']])
            : $this->toolResponse('submit_rows', ['rows' => array_values(array_filter([$this->account(2, 'مصروفات مدفوعة مقدماً'), $this->account(3, 'نقدية لدى البنوك')],
                fn ($l) => in_array($l['row'], $this->requestedRows($r), true))),
                'existing_class_matches' => [['existing_id' => 1, 'main_classes' => ['مصروفات مستحقةو ارصدة دائنة اخرى']], ['existing_id' => 2, 'main_classes' => ['النقد ومافي حكمه']]]]))]);

        $data = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->xlsxUpload([
            ['رقم الحساب', 'اسم الحساب', 'بنود الميزانية', 'مدين الحركة', 'دائن الحركة', 'مدين', 'دائن'],
            ['210312', 'مصروفات تأمين مستحقة', 'مصروفات مستحقة وارصدة دائنة أخرى', 50, 50, 0, 0],
            ['1101', 'بنك', 'النقد وما في حكمه', 100, 0, 100, 0],
        ])])->assertOk()->json('data');

        $this->assertSame(['مصروفات مستحقة', 'rule', []], [$data[0]['sub_class'], $data[0]['classification_source'], $data[0]['data_flags']]);
    }

    public function test_a_run_applies_the_rules_and_flags_misused_accounts(): void
    {
        Http::preventStrayRequests();
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'تأمينات اجتماعية مستحقة'),
            ...array_map(fn ($n) => $this->account($n, 'دائنون متنوعون'), range(3, 7)),
            $this->account(8, 'أرصدة مدينة أخرى'), $this->account(9, 'نقدية لدى البنوك'), $this->account(10, 'نقدية بالصندوق'),
            $this->account(11, 'عُهد العاملين'), $this->account(12, 'أمانات العاملين'), $this->account(13, 'عُهد وسُلف دائنه')]);
        $response = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance([
            ['2201', 'جاري التأمينات الاجتماعية', 0, 0, 100, 0, 100, 0],     // coded 2 (liabilities), a debit: the rule's asset
            ...array_map(fn ($n) => ["220{$n}", "دائن {$n}", 0, 0, 0, 10, 0, 10], range(2, 6)),
            ['', 'سالم الحربي', 0, 0, 30, 0, 30, 0],
            ['1102', 'البنك الأهلي', 0, 0, 0, 20, 0, 20],
            ['1150', 'صندوق قديم - لا تستخدمه', 0, 0, 5, 5, 0, 0],
            ['1301', 'عهدة فاضل', 40, 0, 0, 40, 0, 0],                       // closing zero: the opening's side
            ['1302', 'عهدة منى', 0, 0, 25, 25, 0, 0],                        // no balance anywhere: the side of its kind
            ['1303', 'عهدة ماجد العتيبي', 0, 0, 30, 30, 0, 0],                // likewise, whatever the AI's class
        ])])->assertOk();
        $rows = collect($response->json('data'))->keyBy('row');
        // What each rule did, in the file's info.
        $this->assertContains('قاعدة «عهدة» صنّفت 3 حساب: 1 «عُهد وسُلف العاملين» (مدين) · 2 «عُهد وسُلف العاملين» (بدون رصيد).', $response->json('meta.info'));

        $insurance = $rows[2];
        $this->assertSame(['حسابات مدينة لدى الجهات الحكومية', 'rule', 'جاري التأمينات الاجتماعية', 100],
            [$insurance['sub_class'], $insurance['classification_source'], $insurance['classification_rule'], $insurance['classification_confidence']]);
        $this->assertSame('قاعدة «جاري التأمينات الاجتماعية» (من اسم الحساب)، الرصيد مدين', $insurance['classification_reason']);
        $this->assertSame([], $insurance['data_flags']);   // moved on purpose: no prefix_mismatch
        $this->assertSame(['ai', null], [$rows[3]['classification_source'], $rows[3]['classification_rule']]);

        $this->assertSame(['missing_account_number'], array_column($rows[8]['data_flags'], 'type'));
        $this->assertContains('1 حساب بدون رقم (الصف 8 في الملف)، ولا تشملها مجموعات الكود، لذا عُلّمت للمراجعة.', $response->json('meta.info'));
        $this->assertSame(['cash_credit'], array_column($rows[9]['data_flags'], 'type'));
        $this->assertStringStartsWith('بنك برصيد ختامي دائن 20.00', $rows[9]['data_flags'][0]['message']);
        $this->assertSame(['unused_movement'], array_column($rows[10]['data_flags'], 'type'));
        $this->assertSame(['عُهد وسُلف العاملين', 'rule'], [$rows[11]['sub_class'], $rows[11]['classification_source']]);
        // No balance: the side of the kind (an employee's account: debit), whatever the AI's class (credit for both).
        $this->assertSame(['عُهد وسُلف العاملين', 'rule'], [$rows[12]['sub_class'], $rows[12]['classification_source']]);
        $this->assertSame('عُهد وسُلف العاملين', $rows[13]['sub_class']);
    }

    public function test_the_preview_reports_without_changing_the_run(): void
    {
        $rows = [$this->row(2, 'الأرباح المرحلة', '2103', 'أرباح محتجزة', 100), $this->row(3, 'الصندوق', '1101', 'نقدية بالصندوق', -100)];
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows)]);
        $out = tempnam(sys_get_temp_dir(), 'rules');

        $this->assertSame(0, Artisan::call('classification:rules-preview', ['runs' => [$run->id], '--out' => $out]));
        $report = file_get_contents($out);
        $this->assertStringContainsString('### أرباح مرحلة — 1 حساب (هيتغير 1)', $report);
        $this->assertStringContainsString('| 2 | 2103 | الأرباح المرحلة | الاسم | مدين | أرباح محتجزة | أرباح أو خسائر سنوات سابقة ⟵ |  |', $report);
        $this->assertSame('أرباح محتجزة', $run->fresh()->result['data'][0]['sub_class']);
    }

    public function test_the_page_lists_edits_and_marks(): void
    {
        $this->get('/classification-rules')->assertOk()->assertSee('قواعد التبويب')->assertSee('مدير / شريك')->assertDontSee('تسهيلات موردين');

        $rule = $this->rule('عميل');
        $this->put("/classification-rules/{$rule->id}", ['name' => 'عميل', 'sort' => 5, 'name_words_text' => "عميل، عملاء\nالعملاء المحليين",
            'debit_class' => 'عملاء', 'credit_class' => 'دفعات مقدمة من العملاء', 'review_side' => 'none', 'enabled' => '1'])
            ->assertRedirect('/classification-rules');
        $rule->refresh();
        $this->assertSame([['عميل', 'عملاء', 'العملاء المحليين'], 'دفعات مقدمة من العملاء', true], [$rule->name_words, $rule->credit_class, $rule->enabled]);

        // A class not in the taxonomy is refused.
        $this->put("/classification-rules/{$rule->id}", ['name' => 'عميل', 'sort' => 5, 'credit_class' => 'غير موجود', 'review_side' => 'none'])
            ->assertSessionHasErrors('credit_class');

        $company = Company::create(['name_ar' => 'شركة المثال']);
        $this->post('/classification-rules/code-groups', ['company_id' => $company->id, 'prefix' => '10240300', 'rule_id' => $rule->id])->assertRedirect();
        // The same mark again: already there, said so (with the way back to the run it came from); another kind: refused.
        $this->post('/classification-rules/code-groups', ['company_id' => $company->id, 'prefix' => '10240300', 'rule_id' => $rule->id, 'back' => '/classifier?run=01a1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'هذه المجموعة معلّمة بالفعل بالنوع نفسه.')->assertSessionHas('back', '/classifier?run=01a1');
        $this->post('/classification-rules/code-groups', ['company_id' => $company->id, 'prefix' => '10240300', 'rule_id' => $this->rule('مورد')->id])
            ->assertSessionHasErrors(['prefix' => 'هذه البادئة معلّمة بالفعل للنوع «عميل». احذف العلامة أولًا إذا أردت تغييرها.']);
        $this->post('/classification-rules/code-groups', ['company_id' => $company->id, 'prefix' => '102403', 'rule_id' => $rule->id, 'back' => 'https://evil.example/x'])
            ->assertSessionMissing('back');
        $this->post('/classification-rules/code-groups', ['company_id' => $company->id, 'prefix' => '10-24', 'rule_id' => $rule->id])->assertSessionHasErrors('prefix');
        $this->get('/classification-rules')->assertSee('10240300…');

        $this->delete("/classification-rules/{$rule->id}")->assertRedirect();
        $this->assertSame(0, CompanyCodeGroup::count());
    }
}
