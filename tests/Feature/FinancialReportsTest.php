<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private function classifiedRun(string $status = 'done', ?array $rows = null, array $groups = ['opening', 'period', 'closing']): ClassificationRun
    {
        $rows ??= [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [500, 300]]),
            $this->account(3, 'رأس المال', ['opening' => -100]),
            $this->account(4, 'إيرادات المبيعات', ['period' => [0, 500]]),
            $this->account(5, 'إيجارات', ['period' => [300, 0]]),
            $this->account(6, 'استثمارات طويلة الأجل', ['closing' => 0]),
            $this->account(7, null, ['closing' => 0]),
        ];

        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => $status, 'original_name' => 'tb.xlsx',
            'result' => $status === 'done' ? $this->runResult($rows, $groups) : null]);
    }

    private function create(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL, $body + ['period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
    }

    public function test_create_from_a_done_run_returns_its_state(): void
    {
        $run     = $this->classifiedRun();
        $company = Company::create(['name_ar' => 'شركة المثال']);

        $state = $this->create(['classification_run_id' => $run->id, 'company_id' => $company->id])->assertCreated()->json();

        $this->assertSame(['draft', 'sa-ifrs-smes-3', '2025-01-01', '2025-12-31', null, null, false],
            [$state['status'], $state['template_version'], $state['period_start'], $state['period_end'], $state['comparative_start'], $state['comparative_end'], $state['run_changed']]);
        $this->assertSame('شركة المثال', $state['company']['name_ar']);
        $this->assertSame(['OMC', ['opening', 'period', 'closing'], 1], [$state['layout']['layout'], $state['layout']['groups'], $state['layout']['unclassified']]);
        $this->assertSame(4, $state['mapping']['mapped']);
        $this->assertSame([[6, 'choose', 'حساب 6'], [7, 'unclassified', 'حساب 7']],
            array_map(fn ($u) => [$u['row'], $u['reason'], $u['account_name']], $state['mapping']['unmapped']));
        $this->assertSame("/api/v1/financial-reports/{$state['id']}", $state['status_url']);
        // The current year's statements and checks (PR 6): revenue 500 − rent 300 = 200, unclassified and unmapped rows reported.
        $net = collect($state['statements']['comprehensive_income'])->firstWhere('key', 'is.net_profit');
        $this->assertSame([200, 'derived', 'ok'], [$net['current']['amount'], $net['current']['origin'], $net['current']['status']]);
        $this->assertContains('unclassified_rows', array_column($state['checks'], 'code'));
        $this->assertSame([
            ['key' => 'comparative.comprehensive_income', 'reason' => 'prior_year_source_required', 'input_key' => null],
            ['key' => 'comparative.changes_in_equity', 'reason' => 'prior_year_source_required', 'input_key' => null],
            ['key' => 'comparative.cash_flows', 'reason' => 'prior_year_source_required', 'input_key' => null]],
            array_values(array_filter($state['missing'], fn ($m) => ! str_contains($m['key'], '.') || str_starts_with($m['key'], 'comparative.') || $m['key'] === 'is.oci_total')));

        $report = FinancialReport::find($state['id']);
        $this->assertSame(sha1($run->getRawOriginal('result')), $report->run_result_hash);
        $this->assertNotNull($report->last_computed_at);
        $this->assertSame($state, $this->getJson($state['status_url'])->assertOk()->json());
    }

    public function test_each_lines_accounts_total_is_given_for_the_accounts_step(): void
    {
        // D1: cash 150 = capital 100 + suppliers 30 + profit 20 (sales 200 − rent 180); an entry moves 12 to rent.
        $run = $this->classifiedRun('done', [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 150]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -200]),
            $this->account(6, 'إيجارات', ['closing' => 120]),
            $this->account(7, 'إيجارات', ['closing' => 60]),
        ], ['closing']);
        $id = $this->create(['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->putJson("/api/v1/financial-reports/{$id}/adjustments", ['entries' => [['reason' => 'إيجار مستحق عن ديسمبر', 'lines' => [
            ['line_key' => 'is.general_admin', 'debit' => 12, 'credit' => 0], ['row' => 4, 'debit' => 0, 'credit' => 12]]]]])->assertOk();

        $state = $this->getJson("/api/v1/financial-reports/{$id}")->assertOk()->json();
        $totals = $state['mapping']['line_totals'];
        // As the statements show the lines: income +, expenses −, balance-sheet lines positive on their side; before the entry.
        $this->assertSame(['amount' => 150, 'accounts' => 1], $totals['sofp.cash']);
        $this->assertSame(['amount' => 200, 'accounts' => 1], $totals['is.revenue']);
        $this->assertSame(['amount' => -180, 'accounts' => 2], $totals['is.general_admin']);
        $this->assertSame(['amount' => 30, 'accounts' => 1], $totals['sofp.trade_payables']);
        $ga = collect($state['statements']['comprehensive_income'])->firstWhere('key', 'is.general_admin');
        $this->assertEquals(-192, $ga['current']['amount']);   // the line: the accounts and the entry
    }

    public function test_a_run_that_is_not_done_gives_409(): void
    {
        foreach (['queued', 'running', 'needs_confirmation', 'failed', 'cancelled', 'expired'] as $status) {
            $body = $this->create(['classification_run_id' => $this->classifiedRun($status)->id])->assertStatus(409)->json();
            $this->assertSame($status, $body['run_status']);
            $this->assertNotEmpty($body['message']);
        }
        $this->assertSame(0, FinancialReport::count());
    }

    public function test_validation(): void
    {
        $run = $this->classifiedRun();
        $this->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors(['classification_run_id', 'period_start', 'period_end']);
        $this->create(['classification_run_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('classification_run_id');
        $this->create(['classification_run_id' => $run->id, 'company_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-12-31', 'period_end' => '2025-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('period_end');
        $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '01/01/2025', 'period_end' => '2025-12-31'])
            ->assertStatus(422)->assertJsonValidationErrors('period_start');
        $this->getJson(self::URL.'/'.Str::uuid(), ['Accept-Language' => 'ar'])->assertNotFound()->assertJson(['message' => 'القوائم المالية هذه غير موجودة.']);
        $this->getJson(self::URL.'/'.Str::uuid())->assertNotFound()->assertJson(['message' => 'This report does not exist.']);
    }

    public function test_a_later_correction_of_the_run_is_reported_and_read(): void
    {
        $run = $this->classifiedRun();
        $id  = $this->create(['classification_run_id' => $run->id])->json('id');

        $result = $run->result;
        $result['data'][5] = $this->account(7, 'نقدية بالصندوق', ['closing' => 0]);   // the user classifies the row
        $run->update(['result' => $result]);

        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertTrue($state['run_changed']);
        $this->assertSame([6], array_column($state['mapping']['unmapped'], 'row'));   // the state follows the run as it is now
    }

    public function test_layouts_and_proposals_come_from_the_run(): void
    {
        $rows = [
            $this->account(10, 'مخزون بضاعة', ['opening' => 500, 'period' => [800, 500]]),
            $this->account(11, 'مخزون بضاعة', ['opening' => 0, 'period' => [500, 0]]),
            $this->account(12, 'نقدية لدى البنوك', ['opening' => -50, 'period' => [0, 0]], ['data_flags' => [['type' => 'possible_parent', 'message' => 'm']]]),
        ];
        $state = $this->create(['classification_run_id' => $this->classifiedRun('done', $rows)->id])->json();
        $this->assertSame([[11, 'opening_stock']], array_map(fn ($p) => [$p['row'], $p['kind']], $state['mapping']['proposals']));
        $this->assertSame([['row' => 12, 'reason' => 'possible_parent', 'account_number' => '1012', 'account_name' => 'حساب 12']], $state['mapping']['excluded']);

        $closingOnly = $this->create(['classification_run_id' => $this->classifiedRun('done', null, ['closing'])->id])->json('layout');
        $this->assertSame(['C', ['closing']], [$closingOnly['layout'], $closingOnly['groups']]);
    }

    public function test_list_filters_by_company_and_run(): void
    {
        $run     = $this->classifiedRun();
        $company = Company::create(['name_ar' => 'أ']);
        $this->create(['classification_run_id' => $run->id, 'company_id' => $company->id]);
        $this->create(['classification_run_id' => $run->id]);
        $this->create(['classification_run_id' => $this->classifiedRun()->id]);

        $this->assertSame(3, $this->getJson(self::URL)->assertOk()->json('total'));
        $this->assertSame(1, $this->getJson(self::URL."?company_id={$company->id}")->json('total'));
        $this->assertSame(2, $this->getJson(self::URL."?classification_run_id={$run->id}")->json('total'));
        $this->assertArrayNotHasKey('mapping', $this->getJson(self::URL)->json('data.0'));
        $this->getJson(self::URL.'?company_id=x')->assertStatus(422);
    }

    public function test_deleting_a_company_deletes_its_reports(): void
    {
        $company = Company::create(['name_ar' => 'أ']);
        $this->create(['classification_run_id' => $this->classifiedRun()->id, 'company_id' => $company->id])->assertCreated();
        $company->delete();
        $this->assertSame(0, FinancialReport::count());
    }
}
