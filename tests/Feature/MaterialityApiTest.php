<?php

namespace Tests\Feature;

use App\Models\AuditPlan;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\MaterialityVersion;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** The materiality over the API: the same working paper as the page, saving the choice and approving versions. */
class MaterialityApiTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const BASES = ['total_assets' => 1, 'gross_profit' => 2, 'profit_before_zakat' => 10, 'net_profit' => 10, 'revenue' => 1, 'total_liabilities' => 1, 'total_equity' => 2];

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

    public function test_the_working_paper_is_the_pages(): void
    {
        $company = $this->client();

        // Total assets 700,000 × 1% = 7,000; profit before zakat 600,000 × 10% = 60,000; revenue 1,000,000 × 1% = 10,000.
        $m = $this->getJson("/api/v1/clients/{$company->id}/materiality?year=2025", ['Accept-Language' => 'ar'])->assertOk()->json();
        $b = collect($m['benchmarks'])->keyBy('key');
        $this->assertEquals([7000, 60000, 10000], [$b['total_assets']['materiality'], $b['profit_before_zakat']['materiality'], $b['revenue']['materiality']]);
        $this->assertSame('الإيرادات', $b['revenue']['label']);
        $this->assertEquals(60000, $m['aggregates']['highest']['materiality']);
        $this->assertTrue($m['has_report']);
        $this->assertFalse($m['saved']);
        $this->assertNull($m['approved']);
        $this->assertSame([], $m['versions']);
        $this->assertTrue($m['can_manage']);
    }

    public function test_saving_and_approving_make_versions(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/materiality";

        // Approving before saving: the page's reason.
        $this->postJson("{$url}/approve", ['year' => 2025, 'rationale' => 'سبب'])->assertStatus(422)->assertJsonPath('code', 'not_saved');

        $saved = $this->putJson($url, ['year' => 2025, 'percents' => self::BASES, 'bases' => ['total_assets', 'profit_before_zakat', 'revenue'],
            'choice' => 'revenue', 'performance_percent' => 50, 'trivial_percent' => 5, 'reason' => 'الإيرادات أساس مستقر'])->assertOk()->json();
        $this->assertTrue($saved['saved']);
        $this->assertEquals([10000, 5000, 500], [$saved['overall_rounded'], $saved['performance'], $saved['trivial']]);
        $this->assertSame('revenue', AuditPlan::where('company_id', $company->id)->first()->materiality['choice']);

        $v1 = $this->postJson("{$url}/approve", ['year' => 2025])->assertOk()->json();
        $this->assertSame(1, $v1['approved']['version']);
        $this->assertCount(1, $v1['versions']);

        // A change needs a reason, then makes version 2.
        $this->putJson($url, ['year' => 2025, 'percents' => self::BASES, 'bases' => ['total_assets', 'revenue'], 'choice' => 'total_assets',
            'performance_percent' => 60, 'trivial_percent' => 5, 'reason' => 'إجمالي الأصول'])->assertOk();
        $this->postJson("{$url}/approve", ['year' => 2025])->assertStatus(422)->assertJsonPath('code', 'change_reason_required');
        $this->postJson("{$url}/approve", ['year' => 2025, 'change_reason' => 'تغير النشاط'])->assertOk()->assertJsonPath('approved.version', 2);
        $this->assertSame(2, MaterialityVersion::where('company_id', $company->id)->count());
    }

    public function test_the_rules_and_the_client(): void
    {
        $company = $this->client();
        $url = "/api/v1/clients/{$company->id}/materiality";

        // A basis not ticked can't be the one chosen; a percent out of range is refused.
        $this->putJson($url, ['year' => 2025, 'percents' => self::BASES, 'bases' => ['revenue'], 'choice' => 'total_assets',
            'performance_percent' => 50, 'trivial_percent' => 5])->assertStatus(422)->assertJsonValidationErrors('choice');
        $this->putJson($url, ['year' => 2025, 'percents' => self::BASES, 'bases' => ['revenue'], 'choice' => 'revenue',
            'performance_percent' => 5, 'trivial_percent' => 5])->assertStatus(422)->assertJsonValidationErrors('performance_percent');
        $this->getJson('/api/v1/clients/'.Str::uuid().'/materiality?year=2025')->assertNotFound();
        $this->getJson('/api/v1/clients/not-a-uuid/materiality?year=2025')->assertNotFound();
    }
}
