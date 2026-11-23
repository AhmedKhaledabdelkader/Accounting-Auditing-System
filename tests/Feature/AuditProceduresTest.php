<?php

namespace Tests\Feature;

use App\Models\AuditPlan;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditProcedures;
use App\Support\ClientSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** 14. The audit program: the areas and their procedures, the answers, the accountant's own procedures, the sign-offs, the paper. */
class AuditProceduresTest extends TestCase
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
        $this->post("/clients/{$company->id}/materiality", ['year' => 2025, 'percents' => ['total_assets' => 1, 'gross_profit' => 2, 'profit_before_zakat' => 10, 'net_profit' => 10,
            'revenue' => 1, 'total_liabilities' => 1, 'total_equity' => 2], 'bases' => ['total_assets', 'profit_before_zakat', 'revenue'],
            'choice' => 'revenue', 'performance_percent' => 50, 'trivial_percent' => 5])->assertSessionHasNoErrors();

        return $company;
    }

    public function test_the_page_shows_every_area_with_its_procedures_balance_and_significance(): void
    {
        $company = $this->client();
        $p = app(AuditProcedures::class)->build($company, 2025);
        $areas = collect($p['areas'])->keyBy('key');

        $this->assertSame(array_keys(config('audit_procedures.areas')), $areas->keys()->all());
        // Revenue 1,000,000 × 1% = 10,000; performance 5,000: the revenue and the cash are significant.
        $this->assertSame(1000000.0, $areas['revenue']['balance']);
        $this->assertTrue($areas['revenue']['significant']);
        $this->assertTrue($areas['cash']['significant']);
        $this->assertFalse($areas['inventory']['significant']);
        $this->assertSame('not_started', $areas['cash']['status']);
        $this->assertSame($p['totals']['procedures'], $p['totals']['pending']);

        $this->get("/clients/{$company->id}/procedures?year=2025")->assertOk()
            ->assertSee('برنامج المراجعة', false)->assertSee('data-area="cash"', false)->assertSee('5100-03', false)->assertSee('الوجود');
        $this->get('/work/procedures')->assertOk()->assertSee('data-area="revenue"', false);
    }

    public function test_answers_are_kept_and_the_preparer_signs_only_when_nothing_is_pending(): void
    {
        $company = $this->client();
        $codes = array_keys(config('audit_procedures.areas.cash.procedures'));
        $status = array_fill_keys($codes, 'done');
        $status['03'] = 'exception';
        unset($status['08']);

        $this->post("/clients/{$company->id}/procedures/cash", ['year' => 2025, 'status' => $status, 'ref' => ['03' => '5100/3'], 'note' => ['03' => 'بنك الراجحي لم يرد'],
            'conclusion' => 'الرصيد معروض بعدالة'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasErrors('sign');
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasErrors('sign');

        $status['08'] = 'na';
        $this->post("/clients/{$company->id}/procedures/cash", ['year' => 2025, 'status' => $status, 'ref' => ['03' => '5100/3'], 'note' => ['03' => 'بنك الراجحي لم يرد'],
            'conclusion' => 'الرصيد معروض بعدالة']);
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasNoErrors();
        // The preparer can't review their own work: a second accountant does.
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasErrors(['sign' => __('audit_plan.procedures.errors.same_person')]);
        $sara = auth()->user();
        $this->actingAs(User::create(['office_id' => $company->office_id, 'name' => 'منى', 'username' => 'mona', 'password' => 'secret123', 'role_id' => $sara->role_id, 'status' => User::ACTIVE]));
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasNoErrors();
        $this->actingAs($sara);

        $cash = collect(app(AuditProcedures::class)->build($company, 2025)['areas'])->firstWhere('key', 'cash');
        $this->assertSame('reviewed', $cash['status']);
        $this->assertSame(['exception', '5100/3', 'بنك الراجحي لم يرد', 'سارة'], array_values(array_intersect_key($cash['items'][2], array_flip(['status', 'ref', 'note', 'by']))));
        $this->assertSame(1, $cash['count']['exception']);
        $this->assertSame('منى', $cash['reviewed']['name']);

        // A change drops the sign-offs; saving the same answers again doesn't.
        $this->post("/clients/{$company->id}/procedures/cash", ['year' => 2025, 'status' => $status, 'ref' => ['03' => '5100/3'], 'note' => ['03' => 'بنك الراجحي لم يرد'],
            'conclusion' => 'الرصيد معروض بعدالة'])->assertSessionHas('status', __('audit_plan.procedures.unchanged'));
        $this->assertNotNull(AuditPlan::of($company, 2025)->procedures['areas']['cash']['reviewed'] ?? null);
        $this->post("/clients/{$company->id}/procedures/cash", ['year' => 2025, 'status' => ['01' => 'pending'] + $status, 'conclusion' => 'تغيرت']);
        $this->assertArrayNotHasKey('prepared', AuditPlan::of($company, 2025)->procedures['areas']['cash']);
    }

    public function test_an_area_not_applicable_signs_at_once_and_own_procedures_come_and_go(): void
    {
        $company = $this->client();
        $this->post("/clients/{$company->id}/procedures/inventory", ['year' => 2025, 'na' => 1, 'na_reason' => 'لا يوجد مخزون']);
        $this->post("/clients/{$company->id}/procedures/inventory/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasNoErrors();

        $this->post("/clients/{$company->id}/procedures/revenue/add", ['year' => 2025, 'text' => 'مطابقة المبيعات مع منصة فاتورة', 'type' => 'detail', 'assertions' => ['completeness', 'bogus']])
            ->assertSessionHasErrors('assertions.1');
        $this->post("/clients/{$company->id}/procedures/revenue/add", ['year' => 2025, 'text' => 'مطابقة المبيعات مع منصة فاتورة', 'type' => 'detail', 'assertions' => ['completeness']])
            ->assertSessionHasNoErrors();
        $areas = collect(app(AuditProcedures::class)->build($company, 2025)['areas'])->keyBy('key');
        $this->assertSame('na', $areas['inventory']['status']);
        $own = collect($areas['revenue']['items'])->firstWhere('custom', true);
        $this->assertSame(['c1', 'مطابقة المبيعات مع منصة فاتورة', ['completeness']], [$own['code'], $own['text'], $own['assertions']]);

        $this->post("/clients/{$company->id}/procedures/revenue/c1/delete", ['year' => 2025])->assertRedirect();
        $this->assertNull(collect(app(AuditProcedures::class)->build($company, 2025)['areas'])->firstWhere('key', 'revenue')['items'][8] ?? null);
        $this->post("/clients/{$company->id}/procedures/revenue/c9/delete", ['year' => 2025])->assertNotFound();
        $this->post("/clients/{$company->id}/procedures/nowhere", ['year' => 2025])->assertNotFound();
    }

    public function test_each_area_is_tied_to_its_requirements_and_waits_for_them_before_its_sign_off(): void
    {
        $company = $this->client();
        $ask = fn (array $a) => \App\Models\DocumentRequest::create($a + ['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => 2025, 'optional' => false]);
        $statement = $ask(['section' => 'النقدية لدى البنوك', 'number' => 5, 'title' => 'كشف حساب البنوك للفترة من النظام ومن البنك', 'status' => 'pending']);
        $confirmations = $ask(['section' => 'أوراق العمل (يملؤها العميل ويرجعها)', 'paper' => 'bank-confirmations', 'title' => 'مصادقات البنوك', 'status' => 'accepted']);
        $optional = $ask(['section' => 'النقدية لدى البنوك', 'title' => 'مذكرة بالتسويات البنكية', 'status' => 'pending', 'optional' => true]);
        $sales = $ask(['section' => 'الإيرادات', 'title' => 'بيان تفصيلي يوضح الإيرادات', 'status' => 'pending']);
        $register = $ask(['section' => 'عامة', 'title' => 'صورة من السجل التجاري الرئيسي والفرع', 'status' => 'accepted']);

        $areas = collect(app(AuditProcedures::class)->build($company, 2025)['areas'])->keyBy('key');
        $this->assertSame([$statement->id, $confirmations->id, $optional->id], array_column($areas['cash']['requirements'], 'id'));
        $this->assertSame(1, $areas['cash']['awaited'], 'an optional request never holds the sign-off');
        $this->assertSame([$register->id], array_column($areas['acceptance']['requirements'], 'id'));
        $this->assertContains('ISA 505', $areas['cash']['standards']);
        $this->assertSame(['cash'], array_column(AuditProcedures::areasOf($confirmations), 'key'));

        // The evidence of a procedure: one of the area's requirements; another area's is dropped.
        $all = array_fill_keys(array_keys(config('audit_procedures.areas.cash.procedures')), 'done');
        $this->post("/clients/{$company->id}/procedures/cash", ['year' => 2025, 'status' => $all, 'request' => ['03' => $confirmations->id, '04' => $statement->id, '05' => $sales->id]]);
        $cash = collect(app(AuditProcedures::class)->build($company, 2025)['areas'])->firstWhere('key', 'cash');
        $this->assertSame([$confirmations->id, $statement->id, null], [$cash['items'][2]['request'], $cash['items'][3]['request'], $cash['items'][4]['request']]);
        $this->assertSame([false, true], [$cash['items'][2]['evidence_unsettled'], $cash['items'][3]['evidence_unsettled']]);

        // Nothing pending, but the bank statement is still awaited.
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'prepared'])
            ->assertSessionHasErrors(['sign' => __('audit_plan.procedures.errors.requirements_awaited')]);
        $statement->update(['status' => 'accepted']);
        $this->post("/clients/{$company->id}/procedures/cash/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasNoErrors();

        $this->get("/clients/{$company->id}/procedures?year=2025")->assertOk()->assertSee('data-requirement="'.$statement->id.'"', false)->assertSee('ISA 505 — المصادقات الخارجية');
        $this->get("/clients/{$company->id}?year=2025")->assertOk()->assertSee('data-procedure-area="cash"', false);
    }

    public function test_the_step_is_done_when_every_area_is_signed_or_not_applicable(): void
    {
        $company = $this->client();
        $this->assertFalse(ClientSteps::of($company)['procedures']['done']);
        $plan = AuditPlan::of($company, 2025);
        $plan->procedures = ['areas' => array_map(fn () => ['na' => true], config('audit_procedures.areas'))];
        $plan->save();

        $this->assertTrue(ClientSteps::of($company)['procedures']['done']);
    }

    public function test_the_program_exports_as_a_working_paper(): void
    {
        $company = $this->client();
        $this->post("/clients/{$company->id}/procedures/cash", ['year' => 2025, 'status' => ['01' => 'done', '03' => 'exception'], 'ref' => ['03' => '5100/3']]);

        $res = $this->get("/clients/{$company->id}/procedures/export?year=2025")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'prog').'.xlsx';
        file_put_contents($path, $res->getContent());
        $book = IOFactory::load($path);
        $this->assertSame(count(config('audit_procedures.areas')) + 1, $book->getSheetCount());
        $this->assertTrue($book->getSheet(0)->getRightToLeft());
        $cash = $book->getSheetByName(mb_substr('5100 النقد وما في حكمه', 0, 31));
        $rows = $cash->toArray(null, false, false, false);
        $line = collect($rows)->first(fn ($r) => $r[0] === '5100-03');
        $this->assertSame(['استثناء', '5100/3'], [$line[4], $line[5]]);

        $one = $this->get("/clients/{$company->id}/procedures/export?year=2025&area=cash")->assertOk();
        file_put_contents($path, $one->getContent());
        $this->assertSame(2, IOFactory::load($path)->getSheetCount());
        @unlink($path);
    }
}
