<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** PR 13a, for the wizard: a report's company and period changed after creation; the finished runs listed to pick from. */
class ReportUpdateTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private function classifiedRun(string $status = 'done'): ClassificationRun
    {
        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => $status, 'original_name' => 'tb.xlsx', 'progress_total' => 2,
            'result' => $this->runResult([$this->account(2, 'نقدية لدى البنوك', ['closing' => 100]), $this->account(3, 'رأس المال', ['closing' => -100])], ['closing'])]);
    }

    private function report(array $body = []): string
    {
        return $this->postJson(self::URL, $body + ['classification_run_id' => $this->classifiedRun()->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    public function test_the_company_and_period_change(): void
    {
        $id = $this->report();
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة'])->json('id');
        $state = $this->patchJson(self::URL."/{$id}", ['company_id' => $company, 'period_end' => '2025-06-30'])->assertOk()->json();
        $this->assertSame([$company, '2025-01-01', '2025-06-30'], [$state['company_id'], $state['period_start'], $state['period_end']]);
        $this->assertSame($company, $state['company']['id']);
        $this->assertNull($this->patchJson(self::URL."/{$id}", ['company_id' => null])->assertOk()->json('company_id'));

        $this->patchJson(self::URL."/{$id}", ['period_end' => '2024-12-31'])->assertStatus(422)->assertJsonValidationErrors('period_end');
        $this->patchJson(self::URL."/{$id}", ['company_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->patchJson(self::URL."/{$id}", ['period_start' => 'x'])->assertStatus(422);
    }

    public function test_the_period_stays_after_the_comparatives_and_with_the_prior_reports_company(): void
    {
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة'])->json('id');
        $prior = $this->report(['company_id' => $company, 'period_start' => '2024-01-01', 'period_end' => '2024-12-31']);
        $id = $this->report(['company_id' => $company]);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'report', 'prior_report_id' => $prior])->assertOk();

        $this->patchJson(self::URL."/{$id}", ['period_start' => '2024-12-01'])->assertStatus(422)->assertJsonValidationErrors('period_start');
        $other = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة تانية'])->json('id');
        $this->patchJson(self::URL."/{$id}", ['company_id' => $other])->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->patchJson(self::URL."/{$id}", ['period_start' => '2025-02-01'])->assertOk();
    }

    public function test_runs_are_listed_newest_first_without_results(): void
    {
        $done = $this->classifiedRun();
        $this->travel(1)->minutes();
        $newer = $this->classifiedRun();
        $failed = $this->classifiedRun('failed');

        $list = $this->getJson('/api/v1/classify/jobs?status=done')->assertOk()->json('data');
        $this->assertSame([$newer->id, $done->id], array_column($list, 'id'));
        $this->assertSame(['id', 'status', 'original_name', 'company_id', 'rows', 'created_at', 'updated_at', 'status_url'], array_keys($list[0]));
        $this->assertSame(2, $list[0]['rows']);
        $this->assertCount(3, $this->getJson('/api/v1/classify/jobs')->json('data'));
        $this->assertSame([$failed->id], array_column($this->getJson('/api/v1/classify/jobs?status=failed')->json('data'), 'id'));
        $this->getJson('/api/v1/classify/jobs?per_page=500')->assertStatus(422);
    }
}
