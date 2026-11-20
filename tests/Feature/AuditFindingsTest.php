<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\Office;
use App\Models\PreparedPaper;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\DocumentMatch;
use App\Services\Audit\TrialBalanceBook;
use App\Services\Portal\DocumentReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The client's documents and the recalculations matched with the trial balance, and the findings (ملاحظات المراجعة) the accountant
 * decides on: the page, the API and the workbook.
 */
class AuditFindingsTest extends TestCase
{
    use RefreshDatabase;

    private const ROWS = [
        ['account_number' => '1102001', 'account_name' => 'بنك الراجحي 4521', 'main_class' => 'النقد ومافي حكمه', 'sub_class' => 'نقدية لدى البنوك', 'closing_debit' => 50000, 'closing_credit' => 0],
        ['account_number' => '1102002', 'account_name' => 'البنك الأهلي 7788', 'main_class' => 'النقد ومافي حكمه', 'sub_class' => 'نقدية لدى البنوك', 'closing_debit' => 12000, 'closing_credit' => 0],
        ['account_number' => '1201', 'account_name' => 'عملاء', 'main_class' => 'الذمم المدينة التجارية', 'sub_class' => 'عملاء', 'closing_debit' => 90000, 'closing_credit' => 0],
        ['account_number' => '2101', 'account_name' => 'مخصص نهاية الخدمة', 'main_class' => 'مخصص نهاية الخدمة', 'sub_class' => 'مخصص نهاية الخدمة', 'closing_debit' => 0, 'closing_credit' => 5900],
        ['account_number' => '1301', 'account_name' => 'إيجار مقدم', 'main_class' => 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'sub_class' => 'مصروفات مدفوعة مقدماً', 'closing_debit' => 3000, 'closing_credit' => 0],
        ['account_number' => '1302', 'account_name' => 'سلف العاملين', 'main_class' => 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'sub_class' => 'سلف العاملين', 'closing_debit' => 8000, 'closing_credit' => 0],
    ];

    private function setUpClient(bool $withTb = true): array
    {
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'محاسب', 'is_admin' => true]);
        $sara = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'name_en' => 'Al Noor', 'client_name' => 'أحمد', 'client_email' => 'a@example.test',
            'cr_number' => '1010123456', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        if ($withTb) {
            ClassificationRun::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $company->id,
                'result' => ['data' => array_map(fn ($r, $i) => $r + ['row' => $i + 2, 'row_type' => 'account'], self::ROWS, array_keys(self::ROWS))]]);
        }
        $this->actingAs($sara);

        return [$office, $sara, $company];
    }

    private function balance(array $b): array
    {
        return $b + ['kind' => 'bank', 'label' => '', 'reference' => null, 'counterparty' => null, 'as_of' => '2025-12-31', 'amount' => 0.0, 'side' => null];
    }

    public function test_a_documents_balances_are_matched_with_the_trial_balance(): void
    {
        [, , $company] = $this->setUpClient();
        $book = TrialBalanceBook::for($company, 2025);
        $this->assertTrue($book->latest);   // no statements for the year: the latest trial balance

        $m = DocumentMatch::compare($book, [
            $this->balance(['label' => 'كشف حساب الراجحي', 'reference' => 'SA03 8000 0000 6080 1016 4521', 'counterparty' => 'مصرف الراجحي', 'amount' => 48500]),
            $this->balance(['label' => 'كشف الأهلي', 'reference' => 'SA44 1000 0000 1234 5678 7788', 'amount' => 12001.5]),   // under 2 riyals: the same
            $this->balance(['label' => 'كشف بنك آخر', 'reference' => 'SA99 0000 0000 0000 0000 9999', 'amount' => 700]),
            $this->balance(['kind' => 'customer', 'label' => 'مصادقة عميل', 'counterparty' => 'مؤسسة الفجر', 'amount' => 15000]),   // «عملاء» is all of them
            $this->balance(['label' => 'كشف الراجحي نوفمبر', 'reference' => '4521', 'as_of' => '2025-11-30', 'amount' => 40000]),
        ], '2025-12-31');

        $this->assertSame(['differs', 'matched', 'not_found', 'not_found', 'other_date'], array_column($m, 'status'));
        $this->assertSame(['1102001', 'reference', 48500.0, 50000.0, -1500.0], [$m[0]['account']['number'], $m[0]['by'], $m[0]['document'], $m[0]['book'], $m[0]['difference']]);
        $this->assertSame('1102002', $m[1]['account']['number']);
        $this->assertSame(-10000.0, $m[4]['difference']);
        $this->assertSame('no_tb', DocumentMatch::compare(null, [$this->balance(['amount' => 1])], '2025-12-31')[0]['status']);
    }

    public function test_the_ais_balances_are_cleaned(): void
    {
        $read = DocumentReviewer::normalise(['readable' => true, 'balances' => [
            ['kind' => 'bank', 'label' => 'الرصيد الختامي', 'reference' => 'SA03', 'counterparty' => null, 'as_of' => '31/12/2025', 'amount' => '1500.456', 'side' => null],
            ['kind' => 'spaceship', 'label' => 'x', 'amount' => 5, 'side' => 'sideways'],
            ['kind' => 'bank', 'label' => 'no amount'],
        ]], []);
        $this->assertCount(2, $read['balances']);
        $this->assertSame(['bank', 1500.46, '2025-12-31'], [$read['balances'][0]['kind'], $read['balances'][0]['amount'], $read['balances'][0]['as_of']]);
        $this->assertSame(['other', null], [$read['balances'][1]['kind'], $read['balances'][1]['side']]);
    }

    public function test_the_findings_gather_the_documents_and_recalculations_with_the_entry_and_the_accountants_word(): void
    {
        [$office, , $company] = $this->setUpClient();
        $request = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'النقدية لدى البنوك', 'number' => 1, 'title' => 'كشف حساب البنك', 'position' => 1, 'status' => 'accepted']);
        $file = ClientFile::create(['office_id' => $office->id, 'company_id' => $company->id, 'document_request_id' => $request->id, 'from_client' => true, 'original_name' => 'rajhi.pdf', 'path' => 'x/rajhi.pdf', 'size' => 1]);
        $file->update(['review' => ['status' => 'ok', 'issues' => [], 'tb' => DocumentReviewer::matchBooks($request, [
            $this->balance(['label' => 'رصيد الراجحي', 'reference' => '4521', 'amount' => 48500]),
            $this->balance(['label' => 'رصيد الأهلي', 'reference' => '7788', 'amount' => 12000]),
        ])]]);
        // The end-of-service recalculation: 6,400 against the trial balance's 5,900.
        PreparedPaper::forceCreate(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'kind' => 'recalc', 'key' => 'eosb', 'status' => PreparedPaper::DONE,
            'analysis' => ['kind' => 'recalc', 'key' => 'eosb', 'rows_used' => 2, 'computed' => 6400.0, 'book' => 6100.0, 'difference' => 300.0, 'trial_balance' => null, 'tb_difference' => null,
                'differing' => [['row' => 2, 'name' => 'موظف ثان', 'computed' => 3200.0, 'book' => 2900.0, 'difference' => 300.0]], 'missing' => ['موظف ثالث: بداية التعاقد ناقصة'], 'lines' => []]]);

        $built = $this->getJson("/api/v1/clients/{$company->id}/findings?year=2025")->assertOk()->json();
        $byKey = collect($built['findings'])->keyBy('key');
        $this->assertEqualsCanonicalizing(["doc-{$file->id}-0", 'recalc-eosb', 'recalc-eosb-missing'], $byKey->keys()->all());   // the matched balance is no finding
        $this->assertSame([48500, 50000, -1500], [$byKey["doc-{$file->id}-0"]['amounts']['document'], $byKey["doc-{$file->id}-0"]['amounts']['book'], $byKey["doc-{$file->id}-0"]['amounts']['difference']]);
        $eosb = $byKey['recalc-eosb'];
        $this->assertSame([6400, 5900, 500], [$eosb['amounts']['document'], $eosb['amounts']['book'], $eosb['amounts']['difference']]);
        $this->assertSame(['debit' => 'مصروف مكافأة نهاية الخدمة', 'credit' => 'مخصص نهاية الخدمة', 'amount' => 500], $eosb['entry']);
        $this->assertSame(['total' => 3, 'open' => 3, 'differences' => 2], $built['counts']);

        // The accountant's word: kept, and the finding stays with it.
        $built = $this->putJson("/api/v1/clients/{$company->id}/findings/recalc-eosb", ['year' => 2025, 'status' => 'adjusted', 'comment' => 'سُجل القيد'])->assertOk()->json();
        $eosb = collect($built['findings'])->firstWhere('key', 'recalc-eosb');
        $this->assertSame(['adjusted', 'سُجل القيد'], [$eosb['status'], $eosb['comment']]);
        $this->assertSame(2, $built['counts']['open']);
        $this->assertCount(1, $this->getJson("/api/v1/clients/{$company->id}/findings?year=2025&status=adjusted")->json('findings'));
        $this->putJson("/api/v1/clients/{$company->id}/findings/nothing-here", ['year' => 2025, 'status' => 'accepted'])->assertNotFound();
        $this->putJson("/api/v1/clients/{$company->id}/findings/recalc-eosb", ['year' => 2025, 'status' => 'maybe'])->assertStatus(422);
        $this->getJson('/api/v1/clients/'.Str::uuid().'/findings')->assertNotFound();

        // The workbook, and the page.
        $xlsx = $this->get("/api/v1/clients/{$company->id}/findings/export?year=2025")->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $this->get("/clients/{$company->id}/findings?year=2025")->assertOk()->assertSee('مصروف مكافأة نهاية الخدمة')->assertSee('رصيد الراجحي');
        $this->post("/clients/{$company->id}/findings", ['year' => 2025, 'key' => "doc-{$file->id}-0", 'status' => 'accepted', 'comment' => 'شيك معلق'])->assertRedirect();
        $this->get("/work/findings?client={$company->id}")->assertOk()->assertSee('شيك معلق');
    }

    public function test_a_recalculations_books_figure_is_the_trial_balances_own(): void
    {
        [, , $company] = $this->setUpClient();
        $this->assertSame(5900.0, \App\Services\WorkingPapers\PaperMaker::booksTotal($company, 2025, 'recalc.eosb'));
        // The prepaid expenses only, not the other receivables of the same main class.
        $this->assertSame(3000.0, \App\Services\WorkingPapers\PaperMaker::booksTotal($company, 2025, 'recalc.prepaid'));
        $this->assertNull(\App\Services\WorkingPapers\PaperMaker::booksTotal($company, 2025, 'recalc.ecl'));
        $this->assertNull(\App\Services\WorkingPapers\PaperMaker::booksTotal($company, 2025, 'recalc.payroll'));
    }
}
