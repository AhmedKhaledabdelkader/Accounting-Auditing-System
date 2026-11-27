<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** The audit program over the API: the same program as the page, with each area's letter code and each procedure's reference. */
class ProgramApiTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private User $sara;

    private function client(): Company
    {
        Storage::fake('local');
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'محاسب', 'is_admin' => true]);
        $this->sara = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'name_en' => 'Al Noor', 'client_name' => 'أحمد', 'client_email' => 'a@example.test',
            'cr_number' => '1010123456', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        $this->actingAs($this->sara);
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

    public function test_the_program_carries_the_letter_codes(): void
    {
        $company = $this->client();
        $p = $this->getJson("/api/v1/clients/{$company->id}/program?year=2025", self::AR)->assertOk()->json();
        $areas = collect($p['areas'])->keyBy('key');

        $this->assertSame(array_keys(config('audit_procedures.areas')), $areas->keys()->all());
        $this->assertSame(['EA', 'A', 'AA', 'R', 'CO'], [$areas['acceptance']['ref'], $areas['cash']['ref'], $areas['payables']['ref'], $areas['revenue']['ref'], $areas['completion']['ref']]);
        $this->assertSame(['A1', 'A3'], [$areas['cash']['items'][0]['display'], $areas['cash']['items'][2]['display']]);
        $this->assertSame('5100', $areas['cash']['code'], 'the old number stays');
        $this->assertSame('الوجود', $p['assertions']['existence']);
        $this->assertSame('اختبار تفاصيل', $p['types']['detail']);
        $this->assertSame($p['totals']['procedures'], $p['totals']['pending']);
        $this->assertTrue($p['can_manage']);
        // Every area has its own letter code.
        $refs = $areas->pluck('ref')->all();
        $this->assertSame(count($refs), count(array_unique($refs)));
    }

    public function test_answers_own_procedures_and_sign_offs_as_the_page(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/program";
        $status = array_fill_keys(array_keys(config('audit_procedures.areas.cash.procedures')), 'done');
        $status['03'] = 'exception';

        $r = $this->putJson("{$url}/cash", ['year' => 2025, 'status' => $status, 'ref' => ['03' => 'A-3'], 'note' => ['03' => 'بنك الراجحي لم يرد'],
            'conclusion' => 'الرصيد معروض بعدالة'], self::AR)->assertOk()->json();
        $cash = collect($r['areas'])->firstWhere('key', 'cash');
        $this->assertSame([true, 'completed', 'exception', 'A-3', 'سارة'], [$r['changed'], $cash['status'], $cash['items'][2]['status'], $cash['items'][2]['ref'], $cash['items'][2]['by']]);
        $this->assertFalse($this->putJson("{$url}/cash", ['year' => 2025, 'status' => $status, 'ref' => ['03' => 'A-3'], 'note' => ['03' => 'بنك الراجحي لم يرد'],
            'conclusion' => 'الرصيد معروض بعدالة'])->json('changed'));

        // The preparer signs; the same person can't review: 422 with the page's reason.
        $r = $this->postJson("{$url}/cash/sign", ['year' => 2025, 'role' => 'prepared'])->assertOk()->json();
        $this->assertSame('prepared', collect($r['areas'])->firstWhere('key', 'cash')['status']);
        $this->postJson("{$url}/cash/sign", ['year' => 2025, 'role' => 'reviewed'], self::AR)->assertStatus(422)
            ->assertJson(['code' => 'same_person', 'message' => __('audit_plan.procedures.errors.same_person', [], 'ar')]);
        // A pending procedure holds the preparer's sign-off of another area.
        $this->postJson("{$url}/revenue/sign", ['year' => 2025, 'role' => 'prepared'])->assertStatus(422)->assertJson(['code' => 'pending_left']);

        // An own procedure: numbered after the program's (revenue has 8: R9), then deleted.
        $r = $this->postJson("{$url}/revenue/procedures", ['year' => 2025, 'text' => 'مطابقة المبيعات مع منصة فاتورة', 'type' => 'detail', 'assertions' => ['completeness']])
            ->assertCreated()->json();
        $own = collect(collect($r['areas'])->firstWhere('key', 'revenue')['items'])->firstWhere('custom', true);
        $this->assertSame(['c1', 'R'.(count(config('audit_procedures.areas.revenue.procedures')) + 1)], [$own['code'], $own['display']]);
        $this->deleteJson("{$url}/revenue/procedures/c1?year=2025")->assertOk();
        $this->deleteJson("{$url}/revenue/procedures/c9?year=2025", [], self::AR)->assertNotFound()->assertJson(['message' => 'الإجراء غير موجود.']);
    }

    public function test_the_rules_the_client_and_the_area(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/program";
        $this->putJson("{$url}/cash", ['year' => 2025, 'status' => ['01' => 'maybe']])->assertStatus(422)->assertJsonValidationErrors(['status.01']);
        $this->postJson("{$url}/revenue/procedures", ['year' => 2025, 'text' => '', 'type' => 'magic'])->assertStatus(422)->assertJsonValidationErrors(['text', 'type']);
        $this->putJson("{$url}/nowhere", ['year' => 2025], self::AR)->assertNotFound()->assertJson(['message' => 'القسم غير موجود.']);
        $this->getJson('/api/v1/clients/'.Str::uuid().'/program?year=2025', self::AR)->assertNotFound();
    }
}
