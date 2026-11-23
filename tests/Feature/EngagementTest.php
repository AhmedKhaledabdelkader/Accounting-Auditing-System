<?php

namespace Tests\Feature;

use App\Models\AdjustingEntry;
use App\Models\AuditPlan;
use App\Models\AuditRisk;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\MaterialityVersion;
use App\Models\Office;
use App\Models\ReviewNote;
use App\Models\Role;
use App\Models\User;
use App\Services\Engagement\Engagement;
use App\Services\Statements\ReportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** The engagement file (2026-11-23): the steps in the office's order, locks and blockers, materiality versions, risks, entries, misstatements, sign-offs, notes, release. */
class EngagementTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private User $sara;
    private User $mona;
    private ClassificationRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        config(['engagement.enforce_locks' => true]);
    }

    private function client(): Company
    {
        Storage::fake('local');
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'محاسب', 'is_admin' => true]);
        $this->sara = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $this->mona = User::create(['office_id' => $office->id, 'name' => 'منى', 'username' => 'mona', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'name_en' => 'Al Noor', 'client_name' => 'أحمد', 'client_email' => 'a@example.test',
            'cr_number' => '1010123456', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        $this->actingAs($this->sara);
        $this->run = ClassificationRun::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $company->id,
            'result' => $this->runResult([
                $this->account(2, 'نقدية لدى البنوك', ['opening' => 100000, 'period' => [1000000, 400000]]),
                $this->account(3, 'رأس المال', ['opening' => -100000]),
                $this->account(5, 'إيرادات المبيعات', ['period' => [0, 1000000]]),
                $this->account(6, 'تكلفة المشتريات', ['period' => [400000, 0]]),
            ])]);
        $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->run->id, 'company_id' => $company->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated();
        // Revenue 1,000,000 × 1% = 10,000; performance 5,000; trivial 500.
        $this->post("/clients/{$company->id}/materiality", ['year' => 2025, 'percents' => ['total_assets' => 1, 'gross_profit' => 2, 'profit_before_zakat' => 10, 'net_profit' => 10,
            'revenue' => 1, 'total_liabilities' => 1, 'total_equity' => 2], 'bases' => ['total_assets', 'profit_before_zakat', 'revenue'],
            'choice' => 'revenue', 'performance_percent' => 50, 'trivial_percent' => 5])->assertSessionHasNoErrors();

        return $company;
    }

    private function eng(Company $company): array
    {
        return app(Engagement::class)->status($company, 2025);
    }

    private function codes(Company $company, string $step): array
    {
        return array_column(array_filter($this->eng($company)['blockers'], fn ($b) => $b['step_key'] === $step), 'code');
    }

    public function test_the_status_follows_the_offices_order_and_locks_a_step_before_what_it_rests_on(): void
    {
        $company = $this->client();
        $s = $this->getJson("/api/v1/engagements/{$company->id}/status?year=2025")->assertOk()->json();

        $this->assertSame(['acceptance', 'trial_balance', 'prior', 'classification', 'risks', 'materiality', 'procedures', 'requirements', 'papers', 'recalc', 'sampling',
            'findings', 'adjustments', 'zakat', 'statements', 'completion', 'release'], array_column($s['steps'], 'step_key'));
        $steps = collect($s['steps'])->keyBy('step_key');
        $this->assertSame('ready_for_review', $steps['classification']['status']);
        $this->assertSame('in_progress', $steps['materiality']['status'], 'saved, not approved: never shown approved');
        $this->assertTrue($steps['procedures']['locked']);
        $this->assertTrue($steps['sampling']['locked']);
        $this->assertContains('MATERIALITY_NOT_APPROVED', array_column(array_filter($s['blockers'], fn ($b) => $b['step_key'] === 'sampling'), 'code'));
        $this->assertArrayHasKey('message_en', $s['blockers'][0]);
        // The client's registration and articles aren't in yet: the first phase isn't done.
        $this->assertSame(['acceptance', 'in_progress'], [$s['current_phase'], $steps['acceptance']['status']]);

        // The page of a locked step shows what it waits for; the same JSON on the web.
        $this->get("/clients/{$company->id}/procedures?year=2025")->assertOk()->assertSee('data-locked="procedures"', false)->assertSee('data-blocker="MATERIALITY_NOT_APPROVED"', false);
        $this->get("/clients/{$company->id}/engagement/status?year=2025")->assertOk()->assertJsonPath('year', 2025);
        $this->get("/clients/{$company->id}/engagement?year=2025")->assertOk()->assertSee('data-phase="planning"', false)->assertSee('data-step="risks"', false);
    }

    public function test_materiality_is_approved_as_versions_and_a_new_one_flags_the_samples(): void
    {
        $company = $this->client();
        $approve = fn (array $d = []) => $this->post("/clients/{$company->id}/materiality/approve", $d + ['year' => 2025]);

        $approve(['rationale' => ''])->assertSessionHasErrors('approve', null, 'approve');
        $approve(['rationale' => 'الإيرادات أساس مستقر'])->assertSessionHasNoErrors();
        $v1 = MaterialityVersion::current($company, 2025);
        $this->assertSame([1, 10000.0, 5000.0, 500.0], [$v1->version, $v1->overall, $v1->performance, $v1->trivial]);
        $this->assertSame('approved', collect($this->eng($company)['steps'])->firstWhere('step_key', 'materiality')['status']);

        // A sample drawn on version 1.
        $plan = AuditPlan::of($company, 2025);
        $plan->sampling = ['samples' => [['id' => 's1', 'account' => 'all', 'selected' => [['index' => 0, 'reason' => 'key', 'row' => ['amount' => 6000.0]]],
            'results' => ['0' => ['audited' => 6000.0]], 'materiality_version_id' => $v1->id, 'population' => ['total' => 6000.0], 'method' => 'mus']]];
        $plan->save();
        $this->assertNotContains('SAMPLES_REASSESS', $this->codes($company, 'sampling'));

        // The highest is never approved; a change needs its reason; then version 2, and the sample is to be reassessed.
        $this->post("/clients/{$company->id}/materiality", ['year' => 2025, 'percents' => ['total_assets' => 1, 'gross_profit' => 2, 'profit_before_zakat' => 10, 'net_profit' => 10,
            'revenue' => 1, 'total_liabilities' => 1, 'total_equity' => 2], 'bases' => ['total_assets', 'profit_before_zakat', 'revenue'], 'choice' => 'highest',
            'performance_percent' => 50, 'trivial_percent' => 5]);
        $approve(['rationale' => 'الأعلى'])->assertSessionHasErrors(['approve' => __('engagement.materiality.errors.aggregate_choice')], null, 'approve');
        $this->post("/clients/{$company->id}/materiality", ['year' => 2025, 'percents' => ['total_assets' => 1, 'gross_profit' => 2, 'profit_before_zakat' => 10, 'net_profit' => 10,
            'revenue' => 1, 'total_liabilities' => 1, 'total_equity' => 2], 'bases' => ['total_assets', 'profit_before_zakat', 'revenue'], 'choice' => 'revenue',
            'performance_percent' => 60, 'trivial_percent' => 5]);
        $approve(['rationale' => 'الإيرادات'])->assertSessionHasErrors(['approve' => __('engagement.materiality.errors.change_reason_required')], null, 'approve');
        $approve(['rationale' => 'الإيرادات', 'change_reason' => 'مخاطر أقل بعد فهم الرقابة'])->assertSessionHasNoErrors();
        $this->assertSame([2, 6000.0], [MaterialityVersion::current($company, 2025)->version, MaterialityVersion::current($company, 2025)->performance]);
        $this->assertSame(1, MaterialityVersion::where('version', 1)->first()->version, 'version 1 stays as it was');
        $this->assertContains('SAMPLES_REASSESS', $this->codes($company, 'sampling'));
    }

    public function test_risks_are_suggested_and_a_significant_one_needs_a_test_of_details(): void
    {
        $company = $this->client();
        $this->post("/clients/{$company->id}/risks/suggest", ['year' => 2025])->assertSessionHasNoErrors();
        $risks = AuditRisk::where('company_id', $company->id)->get()->keyBy('source');
        $this->assertTrue($risks->has('revenue_fraud') && $risks->has('management_override'));
        $this->assertSame(['suggested', 'high', true, true], [$risks['revenue_fraud']->status, $risks['revenue_fraud']->inherent_rating, $risks['revenue_fraud']->is_significant, $risks['revenue_fraud']->is_fraud]);
        $this->assertContains('SUGGESTED_RISKS', $this->codes($company, 'risks'));
        // Suggested again: nothing twice.
        $this->post("/clients/{$company->id}/risks/suggest", ['year' => 2025]);
        $this->assertSame($risks->count(), AuditRisk::where('company_id', $company->id)->count());

        foreach ($risks as $r) {
            $this->post("/clients/{$company->id}/risks/{$r->id}", ['status' => 'accepted'])->assertSessionHasNoErrors();
        }
        // The revenue fraud risk without its procedures: the plan is blocked.
        $r = $risks['revenue_fraud'];
        $this->post("/clients/{$company->id}/risks/{$r->id}", ['year' => 2025, 'area' => 'revenue', 'description' => $r->description, 'likelihood' => 2, 'magnitude' => 3,
            'is_fraud' => 1, 'procedures' => []])->assertSessionHasNoErrors();
        $this->assertContains('SIGNIFICANT_RISK_UNANSWERED', $this->codes($company, 'procedures'));
        $detail = collect(config('audit_procedures.areas.revenue.procedures'))->filter(fn ($p) => $p[2] === 'detail')->keys()->first();
        $this->post("/clients/{$company->id}/risks/{$r->id}", ['year' => 2025, 'area' => 'revenue', 'description' => $r->description, 'likelihood' => 2, 'magnitude' => 3,
            'is_fraud' => 1, 'procedures' => [(string) $detail]]);
        $unanswered = array_filter($this->eng($company)['blockers'], fn ($b) => $b['code'] === 'SIGNIFICANT_RISK_UNANSWERED' && str_contains($b['message_ar'], mb_substr($r->description, 0, 30)));
        $this->assertSame([], $unanswered);
        $this->assertSame('high', \App\Services\Engagement\RiskRegister::assurance($company, 2025, 'revenue'), 'a high risk on revenue: the sample starts at factor 3');

        // The preparer signs the risks; the same person can't review them; someone else can.
        $this->post("/clients/{$company->id}/engagement/risks/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasNoErrors();
        $this->post("/clients/{$company->id}/engagement/risks/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasErrors(['sign' => __('engagement.sign_errors.same_person')]);
        $this->actingAs($this->mona);
        $this->post("/clients/{$company->id}/engagement/risks/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('approved', collect($this->eng($company)['steps'])->firstWhere('step_key', 'risks')['status']);

        // A change to the risks voids the sign-offs: signed again on what it now says.
        $this->post("/clients/{$company->id}/risks", ['year' => 2025, 'area' => 'inventory', 'description' => 'مخزون بطيء الحركة', 'likelihood' => 1, 'magnitude' => 2]);
        $step = collect($this->eng($company)['steps'])->firstWhere('step_key', 'risks');
        $this->assertSame([false, 'in_progress'], [$step['signoffs']['prepared']['valid'], $step['status']]);
    }

    public function test_adjusting_entries_make_the_adjusted_trial_balance_and_a_refused_one_is_a_misstatement(): void
    {
        $company = $this->client();
        $this->post("/clients/{$company->id}/materiality/approve", ['year' => 2025, 'rationale' => 'الإيرادات']);
        $entry = fn (array $lines, string $d = 'تسوية') => $this->post("/clients/{$company->id}/adjustments", ['year' => 2025, 'description' => $d, 'type' => 'adjusting', 'lines' => $lines]);

        $entry([['row' => 2, 'debit' => 20000], ['row' => 5, 'credit' => 15000]])->assertSessionHasErrors('entry');
        $entry([['row' => 2, 'debit' => 20000], ['row' => 99, 'credit' => 20000]])->assertSessionHasErrors('entry');
        $entry([['row' => 6, 'debit' => 20000], ['row' => 2, 'credit' => 20000]], 'مشتريات غير مسجلة')->assertSessionHasNoErrors();
        $e1 = AdjustingEntry::first();
        $this->assertSame([AdjustingEntry::PROPOSED, 1], [$e1->status, $e1->number]);
        $this->assertContains('ENTRIES_UNDECIDED', $this->codes($company, 'adjustments'));
        $this->assertContains('ENTRIES_UNDECIDED', $this->codes($company, 'zakat'));

        // Posted: the cost row's closing grows by 20,000 in the statements' reading; the report is now out of date. Unposted: as before.
        $source = app(ReportSource::class);
        $closing = fn () => collect($source->result($this->run->fresh())['data'])->firstWhere('row', 6)['closing_debit'];
        $hash = $source->hash($this->run->fresh());
        $this->post("/clients/{$company->id}/adjustments/{$e1->id}/move", ['action' => 'post'])->assertSessionHasNoErrors();
        $this->assertSame(420000.0, (float) $closing());
        $this->assertNotSame($hash, $source->hash($this->run->fresh()));
        $this->assertContains('STATEMENTS_STALE', $this->codes($company, 'statements'));
        $this->post("/clients/{$company->id}/adjustments/{$e1->id}/move", ['action' => 'unpost']);
        $this->assertSame(400000.0, (float) $closing());
        $this->assertSame($hash, $source->hash($this->run->fresh()));

        // Refused by the client: a factual misstatement of 20,000, above the materiality (10,000): no unmodified opinion.
        $this->post("/clients/{$company->id}/adjustments/{$e1->id}/move", ['action' => 'reject'])->assertSessionHasNoErrors();
        $m = \App\Models\Misstatement::first();
        $this->assertSame(['entry', 'factual', 20000.0, -20000.0], [$m->source, $m->kind, $m->amount, $m->effect_pl]);
        $this->assertNotContains('ENTRIES_UNDECIDED', $this->codes($company, 'adjustments'));
        $this->assertContains('MISSTATEMENTS_ABOVE_MATERIALITY', $this->codes($company, 'release'));
        $this->post("/clients/{$company->id}/release", ['year' => 2025, 'opinion' => 'qualified', 'basis' => 'تحريف غير مصحح في المشتريات']);
        $this->assertNotContains('MISSTATEMENTS_ABOVE_MATERIALITY', $this->codes($company, 'release'));
        // A misstatement below the trivial threshold is listed, not counted.
        $this->post("/clients/{$company->id}/misstatements", ['year' => 2025, 'kind' => 'judgmental', 'description' => 'تقدير', 'amount' => 400]);
        $this->assertSame(20000.0, $this->eng($company)['facts']['misstatements']['uncorrected']);
        $this->get("/clients/{$company->id}/adjustments?year=2025")->assertOk()->assertSee('data-misstatements', false)->assertSee('مشتريات غير مسجلة');
    }

    public function test_entries_are_imported_from_a_spreadsheet_and_the_unbalanced_one_is_refused(): void
    {
        $company = $this->client();
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['رقم القيد', 'رقم الحساب', 'اسم الحساب', 'مدين', 'دائن', 'البيان'],
            [1, '1006', '', 5000, 0, 'مصروف مستحق'], [1, '', 'حساب 2', 0, 5000, ''],
            [2, '1002', '', 3000, 0, 'غير متوازن'], [2, '1005', '', 0, 2000, ''],
            [3, '9999', '', 100, 0, 'حساب مجهول'], [3, '1002', '', 0, 100, '']]);
        $path = tempnam(sys_get_temp_dir(), 'je').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->post("/clients/{$company->id}/adjustments/import", ['year' => 2025, 'file' => new UploadedFile($path, 'قيود.xlsx', null, null, true)])
            ->assertSessionHasNoErrors()->assertSessionHas('entries_refused');
        $this->assertSame(['مصروف مستحق', 'import', 'قيود.xlsx'], [AdjustingEntry::first()->description, AdjustingEntry::first()->source_type, AdjustingEntry::first()->source_file]);
        $this->assertSame(1, AdjustingEntry::count());
        $this->assertCount(2, session('entries_refused'));
    }

    public function test_review_notes_block_the_sign_off_and_the_release_until_closed_and_the_partner_issues(): void
    {
        $company = $this->client();
        $plan = AuditPlan::of($company, 2025);
        $plan->completion = ['subsequent_events' => 'none', 'going_concern' => 'no_doubt', 'representations' => 'received'];
        $plan->save();

        // A note on the completion memo: the reviewer can't sign it while open, and the release is blocked.
        $this->actingAs($this->mona);
        $this->post("/clients/{$company->id}/engagement/completion/notes", ['year' => 2025, 'text' => 'أرفق خطاب الإقرار الموقّع'])->assertSessionHasNoErrors();
        $note = ReviewNote::first();
        $this->assertContains('NOTES_BLOCK_RELEASE', $this->codes($company, 'release'));
        $this->actingAs($this->sara);
        config(['engagement.enforce_locks' => false]);   // the completion's own prerequisites aren't the point here
        $this->post("/clients/{$company->id}/engagement/completion/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasNoErrors();
        $this->actingAs($this->mona);
        $this->post("/clients/{$company->id}/engagement/completion/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasErrors(['sign' => __('engagement.sign_errors.notes_open')]);

        // Answered by the preparer; only the one who raised it closes it.
        $this->actingAs($this->sara);
        $this->post("/clients/{$company->id}/notes/{$note->id}/answer", ['response' => 'أُرفق'])->assertSessionHasNoErrors();
        $this->post("/clients/{$company->id}/notes/{$note->id}/close")->assertSessionHasErrors('sign');
        $this->actingAs($this->mona);
        $this->post("/clients/{$company->id}/notes/{$note->id}/close")->assertSessionHasNoErrors();
        $this->post("/clients/{$company->id}/engagement/completion/sign", ['year' => 2025, 'role' => 'reviewed'])->assertSessionHasNoErrors();
        $this->assertNotContains('NOTES_BLOCK_RELEASE', $this->codes($company, 'release'));

        // The release: an opinion, the preparer, the partner (someone else), then the issue, kept with its fingerprint.
        $this->post("/clients/{$company->id}/materiality/approve", ['year' => 2025, 'rationale' => 'الإيرادات']);
        $this->actingAs($this->sara);
        $this->post("/clients/{$company->id}/release", ['year' => 2025, 'opinion' => 'unmodified'])->assertSessionHasNoErrors();
        $this->post("/clients/{$company->id}/release/issue", ['year' => 2025])->assertSessionHasErrors(['release' => __('engagement.release.needs_partner')]);
        $this->post("/clients/{$company->id}/engagement/release/sign", ['year' => 2025, 'role' => 'prepared'])->assertSessionHasNoErrors();
        $this->actingAs($this->mona);
        $this->post("/clients/{$company->id}/engagement/release/sign", ['year' => 2025, 'role' => 'approved'])->assertSessionHasNoErrors();
        $this->post("/clients/{$company->id}/release/issue", ['year' => 2025])->assertSessionHasNoErrors();
        $release = AuditPlan::of($company, 2025)->release;
        $this->assertSame(['منى', 1], [$release['issued_name'], $release['snapshot']['materiality_version']]);
        $this->assertNotNull($release['snapshot']['run_hash']);
        $this->assertSame('approved', collect($this->eng($company)['steps'])->firstWhere('step_key', 'release')['status']);
        $this->post("/clients/{$company->id}/release", ['year' => 2025, 'opinion' => 'qualified', 'basis' => 'x'])->assertSessionHasErrors('release');
        $this->assertGreaterThan(5, \App\Models\AuditLog::where('company_id', $company->id)->count(), 'every change is logged');
    }

    public function test_a_sample_needs_an_approved_materiality_and_a_decision_above_the_cap(): void
    {
        $company = $this->client();
        $book = new Spreadsheet();
        $rows = [['التاريخ', 'رقم الفاتورة', 'العميل', 'المبلغ']];
        for ($i = 1; $i <= 300; $i++) {
            $rows[] = ['2025-03-01', "INV-{$i}", 'عميل', 3000];
        }
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'sales').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->post("/clients/{$company->id}/sampling/sales", ['year' => 2025, 'file' => new UploadedFile($path, 'مبيعات.xlsx', null, null, true)])->assertSessionHasNoErrors();
        config(['engagement.enforce_locks' => false]);

        $draw = fn (array $d = []) => $this->post("/clients/{$company->id}/sampling/draw", $d + ['year' => 2025, 'account' => 'all', 'method' => 'mus', 'assurance' => 'high']);
        $draw()->assertSessionHasErrors(['assurance' => __('engagement.sampling.needs_approved')]);
        $this->post("/clients/{$company->id}/materiality/approve", ['year' => 2025, 'rationale' => 'الإيرادات'])->assertSessionHasNoErrors();
        // 900,000 × 3 ÷ 5,000 = 540 > the cap 50: a decision is asked.
        $draw()->assertSessionHasErrors('cap_decision');
        $draw(['cap_decision' => 'lower_assurance'])->assertSessionHasErrors(['cap_decision' => __('engagement.sampling.errors.justification')]);
        $draw(['cap_decision' => 'lower_assurance', 'cap_justification' => 'مبيعات متجانسة بقيم صغيرة'])->assertSessionHasNoErrors();
        $s = AuditPlan::of($company, 2025)->sampling['samples'][0];
        $this->assertSame([540, 50, 'lower_assurance', 0.28, 'below', MaterialityVersion::current($company, 2025)->id],
            [$s['calculated_size'], $s['executed_size'], $s['cap_decision'], $s['achieved_cf'], $s['achieved_level'], $s['materiality_version_id']]);
        $this->get("/clients/{$company->id}/sampling?year=2025&sample=s1")->assertOk()->assertSee('data-achieved-cf', false)->assertSee('0.28');
    }

    public function test_every_new_page_opens_with_its_step_bar(): void
    {
        $company = $this->client();
        $this->post("/clients/{$company->id}/risks/suggest", ['year' => 2025]);
        $this->post("/clients/{$company->id}/materiality/approve", ['year' => 2025, 'rationale' => 'الإيرادات']);
        config(['engagement.enforce_locks' => false]);
        foreach (['risks' => 'data-risk=', 'materiality' => 'data-materiality-versions', 'adjustments' => 'data-entries', 'completion' => 'data-completion',
            'release' => 'data-opinion', 'classification' => 'data-step-chip="classification"', 'engagement' => 'data-phases'] as $page => $marker) {
            $html = $this->get("/clients/{$company->id}/{$page}?year=2025")->assertOk()->assertSee($marker, false);
            if ($page !== 'engagement') {
                $html->assertSee('data-engagement-bar="'.$page.'"', false);
            }
        }
        foreach (['engagement', 'classification', 'risks', 'adjustments', 'completion', 'release'] as $w) {
            $this->get("/work/{$w}?client={$company->id}")->assertOk();
        }
        $this->get("/clients/{$company->id}/materiality?year=2025")->assertSee('data-version="1"', false);
    }
}
