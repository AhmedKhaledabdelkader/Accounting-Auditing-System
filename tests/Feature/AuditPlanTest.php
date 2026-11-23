<?php

namespace Tests\Feature;

use App\Models\AuditPlan;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** 12. Materiality and 13. the revenue sample: the pages, the accountant's choice, the sales list read, the sample and its paper. */
class AuditPlanTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private function client(): Company
    {
        Storage::fake('local');
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'محاسب', 'is_admin' => true]);
        $user = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'name_en' => 'Al Noor', 'client_name' => 'أحمد', 'client_email' => 'a@example.test',
            'cr_number' => '1010123456', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        $this->actingAs($user);
        $run = ClassificationRun::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $company->id,
            'result' => $this->runResult([
                $this->account(2, 'نقدية لدى البنوك', ['opening' => 100000, 'period' => [1000000, 400000]]),
                $this->account(3, 'رأس المال', ['opening' => -100000]),
                $this->account(5, 'إيرادات المبيعات', ['period' => [0, 1000000]]),
                $this->account(6, 'تكلفة المشتريات', ['period' => [400000, 0]]),
            ])]);
        $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'company_id' => $company->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated();

        return $company;
    }

    private function salesFile(): UploadedFile
    {
        $book = new Spreadsheet();
        $rows = [['تقرير المبيعات'], ['رقم الفاتورة', 'التاريخ', 'العميل', 'المبلغ قبل الضريبة']];
        foreach (range(1, 100) as $i) {
            $rows[] = ["INV-{$i}", '10/03/2025', "عميل {$i}", $i === 1 ? 60000 : 1000 + ($i % 7) * 100];
        }
        $rows[] = ['', '', 'الإجمالي', 0];
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'sales').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'مبيعات 2025.xlsx', null, null, true);
    }

    private const BASES = ['total_assets' => 1, 'gross_profit' => 2, 'profit_before_zakat' => 10, 'net_profit' => 10, 'revenue' => 1, 'total_liabilities' => 1, 'total_equity' => 2];

    private function setMateriality(Company $company, string $choice, array $extra = []): void
    {
        $this->post("/clients/{$company->id}/materiality", $extra + ['year' => 2025, 'percents' => self::BASES, 'bases' => ['total_assets', 'profit_before_zakat', 'revenue'],
            'choice' => $choice, 'performance_percent' => 50, 'trivial_percent' => 5])->assertRedirect()->assertSessionHasNoErrors();
        // A basis chosen: approved at once (no sample without an approved version); the highest and the average are never approved.
        if (! in_array($choice, ['highest', 'average'], true)) {
            $this->post("/clients/{$company->id}/materiality/approve", ['year' => 2025, 'rationale' => 'الإيرادات أساس مستقر لمنشأة تجارية',
                'change_reason' => 'تعديل النسب'])->assertSessionHasNoErrors();
        }
    }

    public function test_materiality_follows_the_working_papers_four_steps(): void
    {
        $company = $this->client();

        // 1–2. Total assets 700,000 × 1% = 7,000; profit before zakat 600,000 × 10% = 60,000; revenue 1,000,000 × 1% = 10,000;
        // the highest 60,000 and the average 25,666.67. 3. A steady profit: the profit's (proposed). 4. Performance 50% = 30,000,
        // clearly trivial 5% = 3,000.
        $this->get("/clients/{$company->id}/materiality?year=2025")->assertOk()
            ->assertSee('7,000.00')->assertSee('60,000.00')->assertSee('10,000.00')->assertSee('25,666.67')->assertSee('30,000.00')->assertSee('3,000.00');

        // The average of the bases taken, chosen and justified.
        $this->setMateriality($company, 'average', ['reason' => 'متوسط الأسس لتذبذب الربح']);
        $plan = AuditPlan::where('company_id', $company->id)->where('year', 2025)->first();
        $this->assertSame(['average', ['total_assets', 'profit_before_zakat', 'revenue'], 50, 5], [$plan->materiality['choice'], $plan->materiality['bases'],
            (int) $plan->materiality['performance_percent'], (int) $plan->materiality['trivial_percent']]);
        // 25,666.67 × 50% = 12,833.335; × 5% = 1,283.33.
        $this->get("/clients/{$company->id}/materiality?year=2025")->assertOk()->assertSee('25,666.67')->assertSee('1,283.33')->assertSee('متوسط الأسس لتذبذب الربح');
        $m = app(\App\Services\Audit\Materiality::class)->build($company, 2025);
        $this->assertEqualsWithDelta(12833.34, $m['performance'], 0.011);
        // A basis not ticked can't be the one chosen.
        $this->post("/clients/{$company->id}/materiality", ['year' => 2025, 'percents' => self::BASES, 'bases' => ['revenue'], 'choice' => 'total_assets',
            'performance_percent' => 50, 'trivial_percent' => 5])->assertSessionHasErrors('choice');
        $this->get("/work/materiality?client={$company->id}")->assertOk();
    }

    public function test_materiality_can_come_from_the_trial_balance(): void
    {
        $company = $this->client();

        // The trial balance as it is: revenue 1,000,000, total assets 700,000; last year's balance sheet from the openings (100,000).
        $this->get("/clients/{$company->id}/materiality?year=2025&source=trial_balance")->assertOk()
            ->assertSee(__('audit_plan.materiality.tb_prior_note'))->assertSee('1,000,000.00')->assertSee('700,000.00')->assertSee('100,000.00');
        $this->setMateriality($company, 'revenue', ['source' => 'trial_balance']);
        $this->assertSame('trial_balance', AuditPlan::where('company_id', $company->id)->first()->materiality['source']);
        // Saved: the samples rest on it, and the page opens on it.
        $m = app(\App\Services\Audit\Materiality::class)->build($company, 2025);
        $this->assertSame(['trial_balance', 10000.0, 5000.0], [$m['source'], $m['overall'], $m['performance']]);
    }

    public function test_samples_are_drawn_tested_projected_exported_and_deleted(): void
    {
        $company = $this->client();
        $this->setMateriality($company, 'revenue');   // 10,000; performance 5,000; trivial 500

        $this->post("/clients/{$company->id}/sampling/sales", ['year' => 2025, 'file' => $this->salesFile()])->assertRedirect()->assertSessionHasNoErrors();
        $plan = AuditPlan::where('company_id', $company->id)->first();
        $this->assertCount(100, $plan->sales['rows']);

        // A statistical sample and a stratified one, on the whole list.
        $this->post("/clients/{$company->id}/sampling/draw", ['year' => 2025, 'cap_decision' => 'capped_with_approval', 'cap_justification' => 'سقف المكتب 50 بندًا', 'account' => 'all', 'method' => 'mus', 'assurance' => 'moderate'])->assertRedirect();
        $this->post("/clients/{$company->id}/sampling/draw", ['year' => 2025, 'cap_decision' => 'capped_with_approval', 'cap_justification' => 'سقف المكتب 50 بندًا', 'account' => 'all', 'method' => 'strata_amount', 'strata' => 3, 'assurance' => 'low'])->assertRedirect();
        $plan->refresh();
        $this->assertSame(['s1', 's2'], array_column($plan->sampling['samples'], 'id'));
        $mus = $plan->sampling['samples'][0];
        $reasons = array_count_values(array_column($mus['selected'], 'reason'));
        // INV-1 (60,000) is above performance materiality (5,000): a key item; the rest by monetary unit.
        $this->assertSame([1, 0], [$reasons['key'], $mus['selected'][0]['index']]);
        $this->assertGreaterThan(10, $reasons['mus']);
        $this->assertCount(3, $plan->sampling['samples'][1]['strata']);

        // Results: every entry as its book amount, but the key item 1,000 less and one sampled entry 10% less.
        $rows = $plan->sales['rows'];
        $audited = [];
        foreach ($mus['selected'] as $x) {
            $audited[$x['index']] = $rows[$x['index']]['amount'];
        }
        $audited[0] = 59000;
        $other = $mus['selected'][1]['index'];
        $audited[$other] = round($rows[$other]['amount'] * 0.9, 2);
        $this->post("/clients/{$company->id}/sampling/s1/results", ['year' => 2025, 'audited' => $audited, 'note' => [0 => 'خصم غير مسجل']])->assertRedirect();

        $page = $this->get("/clients/{$company->id}/sampling?year=2025&sample=s1")->assertOk()->assertSee('INV-1')->assertSee('خصم غير مسجل');
        $s = app(\App\Services\Audit\RevenueSampling::class)->build($company, 2025, app(\App\Services\Audit\Materiality::class)->build($company, 2025))['samples'][0];
        // Found: 1,000 + 10% of the entry; projected: the key item's 1,000 as it is, the entry's 10% × the interval.
        $this->assertEqualsWithDelta(1000 + $rows[$other]['amount'] * 0.1, $s['found'], 0.02);
        $this->assertEqualsWithDelta(1000 + 0.1 * $s['interval'], $s['projected'], 0.05);
        $this->assertSame('above_trivial', $s['conclusion']);
        $page->assertSee(__('audit_plan.sampling.conclusions.above_trivial'));

        $xlsx = $this->get("/clients/{$company->id}/sampling/export?year=2025&sample=s1")->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $this->post("/clients/{$company->id}/sampling/s2/delete", ['year' => 2025])->assertRedirect();
        $this->assertSame(['s1'], array_column($plan->fresh()->sampling['samples'], 'id'));
    }

    /** Every account's statement as the client's system prints it: a block per account under «: الحساب». */
    private function ledgerFile(array $accounts): UploadedFile
    {
        $grid = [['تقرير كشف حساب']];
        foreach ($accounts as $number => [$name, $lines]) {
            $grid[] = ['', '', '', $name, '', '', '', '', '', '', '', '', '', $number, '', '', '', ': الحساب'];
            $grid[] = ['رصيد', '', 'دائن', '', '', 'مدين', '', 'الوصف', '', '', '', '', 'التاريخ', '', 'الرقم', '', '', 'الفرع'];
            $grid[] = [1, 0, 0, '', '', 0, '', '', '', '', 'رصيد افتتاحي'];
            foreach ($lines as $k => [$debit, $credit]) {
                $grid[] = [$k + 2, 0, $credit, '', '', $debit, '', '', '', '', "قيد {$k}", '', '10/03/2025', '', (string) (100 + $k), '', '', '0001'];
            }
        }
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($grid);
        $path = tempnam(sys_get_temp_dir(), 'ledger').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'كشوفات حساب الحسابات.xlsx', null, null, true);
    }

    public function test_a_sample_opens_on_any_account_of_the_trial_balance(): void
    {
        $company = $this->client();
        $this->setMateriality($company, 'revenue');   // performance 5,000

        // The purchases account (row 6, «حساب 6», number 1006): 40 debits in the ledger, and a credit (a return) left out.
        $purchases = array_map(fn ($i) => [$i === 0 ? 9000 : 1000 + $i * 10, 0], range(0, 39));
        $purchases[] = [0, 700];
        $this->post("/clients/{$company->id}/sampling/sales", ['year' => 2025, 'file' => $this->ledgerFile([
            '1002' => ['حساب 2', [[5000, 0]]], '1006' => ['حساب 6', $purchases]])])->assertSessionHasNoErrors();

        $page = $this->get("/clients/{$company->id}/sampling?year=2025")->assertOk();
        $page->assertSee('value="tb:6"', false)->assertSee('value="tb:2"', false);   // any account of the trial balance is offered

        $this->post("/clients/{$company->id}/sampling/draw", ['year' => 2025, 'cap_decision' => 'capped_with_approval', 'cap_justification' => 'سقف المكتب 50 بندًا', 'account' => 'tb:6', 'method' => 'mus', 'assurance' => 'moderate'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $sample = AuditPlan::where('company_id', $company->id)->first()->sampling['samples'][0];
        $this->assertSame(['debit', 40], [$sample['tb']['side'], $sample['population']['count']]);   // its debits only
        // 40 entries, the 9,000 one (≥ 5,000) a key item outside the cap: the limits hold the 39 left, 5 to 9.
        $this->assertSame([5, 9], [$sample['limits']['min'], $sample['limits']['max']]);
        $this->assertTrue(in_array(['key', 9000.0], array_map(fn ($x) => [$x['reason'], (float) $x['row']['amount']], $sample['selected']), true));
        $this->assertStringContainsString('حساب 6', $sample['account_name']);

        // An account the file doesn't hold: its own statement, uploaded with the sample.
        $this->post("/clients/{$company->id}/sampling/draw", ['year' => 2025, 'cap_decision' => 'capped_with_approval', 'cap_justification' => 'سقف المكتب 50 بندًا', 'account' => 'tb:5', 'method' => 'mus', 'assurance' => 'low'])
            ->assertSessionHasErrors('account');
        $this->post("/clients/{$company->id}/sampling/draw", ['year' => 2025, 'cap_decision' => 'capped_with_approval', 'cap_justification' => 'سقف المكتب 50 بندًا', 'account' => 'tb:5', 'method' => 'strata_amount', 'assurance' => 'low',
            'statement' => $this->ledgerFile(['1005' => ['حساب 5', array_map(fn ($i) => [0, 2000 + $i], range(1, 30))]])])->assertSessionHasNoErrors();
        $revenue = AuditPlan::where('company_id', $company->id)->first()->sampling['samples'][1];
        $this->assertSame(['credit', 30], [$revenue['tb']['side'], $revenue['population']['count']]);
        $this->get("/clients/{$company->id}/sampling?year=2025&sample=s2")->assertOk()->assertSee('حساب 5');

        // A new ledger file keeps the samples (each holds its entries).
        $this->post("/clients/{$company->id}/sampling/sales", ['year' => 2025, 'file' => $this->ledgerFile(['1002' => ['حساب 2', [[100, 0]]]])])->assertSessionHasNoErrors();
        $this->assertCount(2, AuditPlan::where('company_id', $company->id)->first()->sampling['samples']);
    }

    public function test_a_file_without_an_amount_column_is_refused(): void
    {
        $company = $this->client();
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['الاسم', 'المدينة'], ['أحمد', 'الرياض']]);
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->post("/clients/{$company->id}/sampling/sales", ['year' => 2025, 'file' => new UploadedFile($path, 'x.xlsx', null, null, true)])
            ->assertSessionHasErrors(['file' => __('audit_plan.sampling.errors.no_header')]);
    }
}
