<?php

namespace Tests\Feature;

use App\Models\AuditRisk;
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

/** The risk register over the API: the same register as the page (suggestions, accepting, the procedures that answer a significant risk). */
class RisksApiTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

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

    public function test_the_register_is_suggested_once_and_lists_the_areas_and_procedures(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/risks";

        $r = $this->getJson("{$url}?year=2025", self::AR)->assertOk()->json();
        $this->assertSame([], $r['risks']);
        $this->assertSame('على مستوى القوائم ككل', $r['areas']['fs']);
        $this->assertSame('الوجود', $r['assertions']['existence']);
        $this->assertArrayHasKey('01', $r['procedures']['revenue']);
        $this->assertTrue($r['can_manage']);

        // Revenue made: the presumed fraud risk on revenue (ISA 240) and management override, left «suggested».
        $r = $this->postJson("{$url}/suggest", ['year' => 2025], self::AR)->assertOk()->json();
        $this->assertGreaterThanOrEqual(2, $r['added']);
        $bySource = collect($r['risks'])->keyBy('source');
        $this->assertSame(['suggested', 'high', true, true], [$bySource['revenue_fraud']['status'], $bySource['revenue_fraud']['inherent_rating'],
            $bySource['revenue_fraud']['is_significant'], $bySource['revenue_fraud']['is_fraud']]);
        $this->assertSame($r['added'], $r['counts']['suggested']);

        // Suggested again: nothing twice.
        $this->assertSame(0, $this->postJson("{$url}/suggest", ['year' => 2025])->assertOk()->json('added'));
    }

    public function test_accepting_changing_and_deleting_as_the_page(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/risks";
        $this->postJson("{$url}/suggest", ['year' => 2025])->assertOk();
        $fraud = AuditRisk::where('company_id', $company->id)->where('source', 'revenue_fraud')->firstOrFail();

        // Accepted as it is.
        $r = $this->patchJson("{$url}/{$fraud->id}/status", ['status' => 'accepted'])->assertOk()->json();
        $this->assertSame('accepted', collect($r['risks'])->firstWhere('id', $fraud->id)['status']);

        // Its procedures taken off: a significant risk with no test of details is «unanswered».
        $base = ['year' => 2025, 'area' => 'revenue', 'description' => $fraud->description, 'likelihood' => 2, 'magnitude' => 3, 'is_fraud' => true];
        $r = $this->putJson("{$url}/{$fraud->id}", $base + ['procedures' => []])->assertOk()->json();
        $this->assertTrue(collect($r['risks'])->firstWhere('id', $fraud->id)['unanswered']);
        $this->assertSame(1, $r['counts']['unanswered']);

        // A test of details linked: answered. A code that isn't the area's is dropped.
        $detail = (string) collect(config('audit_procedures.areas.revenue.procedures'))->filter(fn ($p) => $p[2] === 'detail')->keys()->first();
        $r = $this->putJson("{$url}/{$fraud->id}", $base + ['procedures' => [$detail, '99']])->assertOk()->json();
        $row = collect($r['risks'])->firstWhere('id', $fraud->id);
        $this->assertSame([false, [$detail]], [$row['unanswered'], $row['procedures']]);

        // A risk typed by hand: accepted; low × medium = low, not significant. Deleted: it goes.
        $r = $this->postJson($url, ['year' => 2025, 'area' => 'inventory', 'description' => 'مخزون بطيء الحركة', 'likelihood' => 1, 'magnitude' => 2])->assertCreated()->json();
        $mine = collect($r['risks'])->firstWhere('id', $r['id']);
        $this->assertSame(['accepted', 'low', false, null], [$mine['status'], $mine['inherent_rating'], $mine['is_significant'], $mine['source']]);
        $this->deleteJson("{$url}/{$r['id']}")->assertOk();
        $this->assertNull(AuditRisk::find($r['id']));

        // A suggestion deleted is dismissed, so it isn't suggested again.
        $override = AuditRisk::where('company_id', $company->id)->where('source', 'management_override')->firstOrFail();
        $this->deleteJson("{$url}/{$override->id}")->assertOk();
        $this->assertSame('dismissed', $override->fresh()->status);
    }

    public function test_the_rules_the_client_and_the_risk(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/risks";

        $this->postJson($url, ['year' => 2025, 'area' => 'nowhere', 'description' => '', 'likelihood' => 4, 'magnitude' => 0], self::AR)
            ->assertStatus(422)->assertJsonValidationErrors(['area', 'description', 'likelihood', 'magnitude']);
        $this->getJson('/api/v1/clients/'.Str::uuid().'/risks?year=2025', self::AR)->assertNotFound()->assertJson(['message' => 'الشركة غير موجودة.']);
        $this->putJson("{$url}/999999", ['year' => 2025], self::AR)->assertNotFound()->assertJson(['message' => 'الخطر غير موجود.']);
    }
}
