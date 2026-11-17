<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** The zakat and income tax dialog: the state's `zakat_tax`, the answers, and the charges taken into the statements. */
class ZakatTaxStepTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** Cash 1,150 = capital 100 + partners 400 + suppliers 50 + profit 600 (sales 1,000 − rent 400): a profit, no zakat charge. */
    private function report(array $extra = []): string
    {
        $rows = [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 1150]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'جاري الشريك', ['closing' => -400]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -1000]),
            $this->account(6, 'إيجارات', ['closing' => 400]),
            $this->account(9, 'موردون', ['closing' => -50]),
            ...$extra,
        ];
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, ['closing'])]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    private function figure(array $state, string $statement, string $key): float
    {
        return collect($state['statements'][$statement])->firstWhere('key', $key)['current']['amount'];
    }

    public function test_the_state_carries_the_calculation_and_the_defaults(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report())->assertOk()->json();
        $z = $state['zakat_tax'];
        // Partners' account 400 and capital 100 are equity (the current account of partners as a liability is an owners' balance).
        $this->assertSame(365, $z['period']['days']);
        $this->assertEquals(['saudi_share' => 100.0, 'deduct_lines' => ['sofp.ppe', 'sofp.investment_property', 'sofp.intangible_assets', 'sofp.associates', 'sofp.fvoci_investments']],
            array_intersect_key($z['inputs'], array_flip(['saudi_share', 'deduct_lines'])));
        $this->assertEquals(600.0, $z['profit']['adjusted']);
        $this->assertGreaterThan(0, $z['zakat']['amount']);
        $this->assertEquals(0.0, $z['income_tax']['amount']);
        $this->assertSame([null, null], [$z['charged']['zakat_source'], $z['charged']['tax_source']]);
        $this->assertTrue($z['can_apply']['zakat']);
        $this->assertFalse($z['can_apply']['income_tax']);   // all Saudi: nothing to charge
    }

    public function test_the_answers_are_saved_and_checked(): void
    {
        $id = $this->report();
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['saudi_share' => 60, 'loss_carryforward' => 100]])->assertOk()->json();
        $this->assertEquals([60.0, 100.0], [$state['zakat_tax']['inputs']['saudi_share'], $state['zakat_tax']['inputs']['loss_carryforward']]);
        $this->assertEquals(240.0, $state['zakat_tax']['income_tax']['profit']);   // 40% of 600
        $this->assertEquals(40.0, $state['zakat_tax']['income_tax']['loss_offset']);   // 40% of the losses 100, within a quarter of 240 (60)
        $this->assertGreaterThan(0, $state['zakat_tax']['income_tax']['amount']);
        // A null goes back to the default; a bad answer is refused.
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['saudi_share' => null]])->assertOk()->json();
        $this->assertEquals(100.0, $state['zakat_tax']['inputs']['saudi_share']);
        $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['saudi_share' => 120]])->assertStatus(422)->assertJsonValidationErrors('inputs.saudi_share');
        $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['unknown' => 1]])->assertStatus(422)->assertJsonValidationErrors('inputs.unknown');
        $this->putJson(self::URL.'/'.Str::uuid().'/zakat-tax', ['inputs' => []])->assertNotFound();
    }

    public function test_charging_the_zakat_posts_it_once_and_the_calculation_stays_the_same(): void
    {
        $id = $this->report();
        $before = $this->getJson(self::URL."/{$id}")->json();
        $calculated = $before['zakat_tax']['zakat']['amount'];
        $this->assertContains('zakat_charge_missing', array_column($before['checks'], 'code'));

        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['apply' => ['zakat' => true]])->assertOk()->json();
        // Dr zakat expense, Cr its provision: the profit falls by it, the provision shows it, the balance sheet still balances.
        $this->assertEquals(-$calculated, $this->figure($state, 'comprehensive_income', 'is.zakat'));
        $this->assertEquals($calculated, $this->figure($state, 'financial_position', 'sofp.zakat_provision'));
        $this->assertEquals($this->figure($before, 'comprehensive_income', 'is.net_profit') - $calculated, $this->figure($state, 'comprehensive_income', 'is.net_profit'));
        $this->assertNotContains('zakat_charge_missing', array_column($state['checks'], 'code'));
        $this->assertNotContains('sofp_unbalanced', array_column($state['checks'], 'code'));
        // The calculation is the same after it, and the dialog knows where the charge comes from.
        $z = $state['zakat_tax'];
        $this->assertEquals([$calculated, 'dialog', $calculated], [$z['zakat']['amount'], $z['charged']['zakat_source'], $z['applied']['zakat']]);

        // Taken out again: back to where it was.
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['apply' => ['zakat' => false]])->assertOk()->json();
        $this->assertEquals(0, $this->figure($state, 'comprehensive_income', 'is.zakat'));
        $this->assertNull($state['zakat_tax']['applied']['zakat']);
    }

    public function test_a_charge_typed_in_the_intake_is_never_charged_again(): void
    {
        $id = $this->report();
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.charge' => 25]])->assertOk();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame('input', $state['zakat_tax']['charged']['zakat_source']);
        $this->assertFalse($state['zakat_tax']['can_apply']['zakat']);
        $this->putJson(self::URL."/{$id}/zakat-tax", ['apply' => ['zakat' => true]])->assertStatus(422)->assertJsonValidationErrors('apply.zakat');
    }

    public function test_the_income_tax_of_the_foreign_share_is_charged_with_its_provision(): void
    {
        $id = $this->report();
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['saudi_share' => 50]])->assertOk()->json();
        $tax = $state['zakat_tax']['income_tax']['amount'];
        $this->assertEquals(60.0, $tax);   // 20% of the foreign half of 600
        $this->assertTrue($state['zakat_tax']['can_apply']['income_tax']);

        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['apply' => ['zakat' => true, 'income_tax' => true]])->assertOk()->json();
        $this->assertEquals(-$tax, $this->figure($state, 'comprehensive_income', 'is.income_tax'));
        $this->assertEquals($tax, $this->figure($state, 'financial_position', 'sofp.income_tax_provision'));
        $this->assertNotContains('sofp_unbalanced', array_column($state['checks'], 'code'));
        $this->assertEquals($tax, $state['zakat_tax']['income_tax']['amount']);   // unchanged by being charged
        $this->assertSame('dialog', $state['zakat_tax']['charged']['tax_source']);
    }

    public function test_a_zakat_already_in_the_trial_balance_is_compared_not_charged(): void
    {
        $id = $this->report([$this->account(7, 'مصروف الزكاة الشرعية', ['closing' => 10]), $this->account(8, 'مخصص الزكاة الشرعية', ['closing' => -10])]);
        $z = $this->getJson(self::URL."/{$id}")->json('zakat_tax');
        $this->assertSame('trial_balance', $z['charged']['zakat_source']);
        $this->assertEquals(10.0, $z['charged']['zakat']);
        $this->assertFalse($z['can_apply']['zakat']);
    }

    public function test_the_calculated_zakat_and_tax_are_charged_by_themselves_until_taken_out(): void
    {
        config(['financial_statements.auto_charge' => true]);
        $id = $this->report();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $zakat = $state['zakat_tax']['zakat']['amount'];
        $this->assertGreaterThan(0, $zakat);
        // In the statements (the expense and its provision), in the notes' movement, and no error asking for the charge.
        $this->assertEquals(-$zakat, $this->figure($state, 'comprehensive_income', 'is.zakat'));
        $this->assertEquals($zakat, $this->figure($state, 'financial_position', 'sofp.zakat_provision'));
        $this->assertEquals([$zakat, 'dialog'], [$state['zakat_tax']['charged']['zakat'], $state['zakat_tax']['charged']['zakat_source']]);
        $this->assertNotContains('zakat_charge_missing', array_column($state['checks'], 'code'));
        $note = collect($state['schedules'])->firstWhere('key', 'zakat');
        $this->assertEquals($zakat, collect($note['rows'])->firstWhere('key', 'charge')['cells']['amount']['amount']);

        // It follows the accountant's answers: a foreign owner brings income tax, and less zakat.
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['saudi_share' => 60]])->assertOk()->json();
        $this->assertEquals(-$state['zakat_tax']['zakat']['amount'], $this->figure($state, 'comprehensive_income', 'is.zakat'));
        $this->assertLessThan($zakat, $state['zakat_tax']['zakat']['amount']);
        $this->assertEquals(-$state['zakat_tax']['income_tax']['amount'], $this->figure($state, 'comprehensive_income', 'is.income_tax'));
        $this->assertGreaterThan(0, $state['zakat_tax']['income_tax']['amount']);

        // Taken out in the zakat step, it stays out.
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['apply' => ['zakat' => false]])->assertOk()->json();
        $this->assertEquals(0, $this->figure($state, 'comprehensive_income', 'is.zakat'));
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertEquals(0, $this->figure($state, 'comprehensive_income', 'is.zakat'));
        $this->assertNull($state['zakat_tax']['applied']['zakat']);
        // And charged again there.
        $state = $this->putJson(self::URL."/{$id}/zakat-tax", ['apply' => ['zakat' => true]])->assertOk()->json();
        $this->assertEquals(-$state['zakat_tax']['zakat']['amount'], $this->figure($state, 'comprehensive_income', 'is.zakat'));
    }

    public function test_the_articles_of_association_give_the_owners_share_and_the_partners(): void
    {
        $id = $this->report();
        $company = \App\Models\Company::forceCreate(['name_ar' => 'شركة المثال', 'profile' => ['articles' => ['file' => 'x.pdf', 'partners' => [
            ['name' => 'أحمد السالم', 'nationality' => 'سعودي', 'group' => 'saudi', 'shares' => 60, 'share_value' => 1, 'percentage' => null],
            ['name' => 'جون سميث', 'nationality' => 'بريطاني', 'group' => 'foreign', 'shares' => 40, 'share_value' => 1, 'percentage' => null]]]]]);
        $this->patchJson(self::URL."/{$id}", ['company_id' => $company->id])->assertOk();

        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $z = $state['zakat_tax'];
        $this->assertEquals([60.0, 'articles'], [$z['inputs']['saudi_share'], $z['saudi_share_from']]);
        $this->assertEquals(240.0, $z['income_tax']['profit']);   // the foreign 40% of 600
        $partners = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'partners');
        $this->assertSame(['أحمد السالم', 'جون سميث'], array_column($partners['default'], 'name'));

        // The accountant's own answer wins.
        $z = $this->putJson(self::URL."/{$id}/zakat-tax", ['inputs' => ['saudi_share' => 100]])->assertOk()->json('zakat_tax');
        $this->assertEquals([100.0, 'user'], [$z['inputs']['saudi_share'], $z['saudi_share_from']]);
    }

    public function test_a_sole_proprietorship_is_a_saudis_zakat_on_all_of_it(): void
    {
        $id = $this->report();
        $company = \App\Models\Company::forceCreate(['name_ar' => 'مؤسسة المثال', 'legal_form' => 'sole_proprietorship', 'profile' => ['legal_form_source' => 'user']]);
        $this->patchJson(self::URL."/{$id}", ['company_id' => $company->id])->assertOk();

        $z = $this->getJson(self::URL."/{$id}")->assertOk()->json('zakat_tax');
        $this->assertEquals([100.0, 'sole_proprietorship'], [$z['inputs']['saudi_share'], $z['saudi_share_from']]);
    }
}
