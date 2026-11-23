<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\FinancialReport;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A client's file: its seven steps in the side menu, in order, each with its page. */
class ClientFilePagesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Office $office, string $username = 'sara', ?array $permissions = null): User
    {
        $role = Role::create(['name_ar' => 'محاسب '.$username, 'is_admin' => $permissions === null]);
        $permissions !== null && $role->syncPermissions($permissions);

        return User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => $username, 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
    }

    private function company(Office $office, array $over = []): Company
    {
        return Company::forceCreate($over + ['office_id' => $office->id, 'name_ar' => 'شركة النور', 'name_en' => 'Al Noor', 'client_name' => 'أحمد السالم', 'client_email' => 'a@example.test',
            'cr_number' => '1010123456', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
    }

    public function test_the_side_menu_has_the_seven_steps_in_order_and_marks_what_is_done(): void
    {
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $sara = $this->staff($office);
        $company = $this->company($office);
        $this->actingAs($sara);

        $page = $this->get(route('clients.file.registration', $company))->assertOk()->getContent();
        $html = mb_substr($page, mb_strpos($page, '<nav class="cf-nav"'));   // the side menu
        $labels = array_map(fn ($k) => __("client_file.nav.steps.{$k}"), ['details', 'registration', 'articles']);
        preg_match_all('/<span class="t">\s*(.*?)\s*(?:<small|<\/span>)/su', $html, $m);
        $this->assertSame($labels, array_map('trim', $m[1]));   // the client's own steps, in this order (4 to 7 are in the main menu)
        // Only the registration step is the current one; the client is named at the top of the menu.
        $this->assertSame(1, substr_count($page, 'aria-current="page"'));
        $this->assertStringContainsString('أحمد السالم', $html);

        // Nothing is done but the details (the client's names, email and period are all there).
        $steps = \App\Support\ClientSteps::of($company);
        $this->assertSame([true, false, false, false, false, false, false, false, false, false, false, false, false, false], array_values(array_map(fn ($s) => $s['done'], $steps)));

        // Each step is done by its own thing.
        $company->update(['profile' => ['registration' => ['file' => 'x.pdf', 'name' => 'x.pdf', 'number' => '1010123456', 'read_at' => now()->toIso8601String()],
            'articles' => ['file' => 'a.pdf', 'name' => 'a.pdf', 'read_at' => now()->toIso8601String(), 'partners' => [['name' => 'أحمد', 'nationality' => 'سعودي', 'group' => 'saudi', 'shares' => 1, 'share_value' => 1, 'percentage' => null]]]]]);
        $run = ClassificationRun::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $company->id, 'result' => ['data' => []]]);
        $report = FinancialReport::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'company_id' => $company->id, 'classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'template_version' => 'sa-ifrs-smes-3', 'prior_figures' => ['source' => 'statements'], 'zakat_tax' => ['applied' => ['zakat' => 100]]]);
        $steps = \App\Support\ClientSteps::of($company->fresh());
        $this->assertSame([true, true, true, true, true, true, true, false, false, false, false, false, false, false], array_values(array_map(fn ($s) => $s['done'], $steps)));
        $this->assertSame([1, 1], [$steps['trial_balance']['count'], $steps['statements']['count']]);
        $this->assertNotNull($report);
    }

    public function test_every_step_has_its_page(): void
    {
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $this->actingAs($this->staff($office));
        $company = $this->company($office, ['profile' => ['registration' => ['file' => 'x.pdf', 'name' => 'سجل.pdf', 'number' => '1010123456', 'company_name' => 'شركة النور', 'activity' => 'تجارة', 'city' => 'الدمام',
            'issue_date' => '2023-09-25', 'issue_date_hijri' => '1445/03/10', 'expiry_date' => '2099-01-01', 'read_at' => now()->toIso8601String()],
            'articles' => ['file' => 'a.pdf', 'name' => 'عقد.pdf', 'read_at' => now()->toIso8601String(), 'capital' => 1000, 'shares_total' => 100, 'share_value' => 10, 'partners' => [
                ['name' => 'أحمد', 'nationality' => 'سعودي', 'group' => 'saudi', 'shares' => 60, 'share_value' => 10, 'percentage' => null],
                ['name' => 'جون', 'nationality' => 'بريطاني', 'group' => 'foreign', 'shares' => 40, 'share_value' => 10, 'percentage' => null]]]]]);

        $this->get(route('clients.edit', $company))->assertOk()->assertSee(__('client_file.nav.steps.details'))->assertSee('cf-nav', false);
        $this->get(route('clients.show', $company))->assertOk()->assertSee(__('client_file.nav.steps.requirements'))->assertSee('cf-chips', false);
        // The work steps are chips under a bar naming the client (not a side menu), numbered 4 to 10, in order.
        $work = $this->get(route('clients.file.zakat', $company))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="cf-nav"', $work);
        preg_match('/<ol class="cf-chips".*?<\/ol>/su', $work, $chips);
        preg_match_all('/<span class="n">(\d+)<\/span>\s*(.*?)\s*<span class="st/su', $chips[0], $c);
        // In the office's order (config/engagement.php, 2026-11-23), numbered by the engagement's steps (8 and 10 hold several pages).
        $this->assertSame(['2', '3', '4', '5', '6', '7', '8', '8', '8', '9', '10', '10', '11', '12', '13', '14'], $c[1]);
        $this->assertSame(array_map(fn ($k) => __("client_file.nav.steps.{$k}"), ['trial_balance', 'prior', 'classification', 'risks', 'materiality', 'procedures', 'requirements', 'papers',
            'recalc', 'sampling', 'findings', 'adjustments', 'zakat', 'statements', 'completion', 'release']), array_map('trim', $c[2]));
        $this->get(route('clients.file.registration', $company))->assertOk()->assertSee('1010123456')->assertSee('تجارة')->assertSee(__('client_file.registration.valid'))->assertSee('سجل.pdf');
        $this->get(route('clients.file.articles', $company))->assertOk()->assertSee('جون')->assertSee(__('client_file.articles.groups.foreign'))->assertSee('60%');
        $this->get(route('clients.file.trial-balance', $company))->assertOk()->assertSee(__('client_file.trial_balance.none_files'))->assertSee(__('client_file.trial_balance.no_runs'));
        $this->get(route('clients.file.prior', $company))->assertOk()->assertSee(__('client_file.prior.none'))->assertSee(__('client_file.prior.no_statements'));
        $this->get(route('clients.file.statements', $company))->assertOk()->assertSee(__('client_file.statements.none'))->assertSee(__('client_file.statements.no_runs'));
        $this->get(route('clients.file.zakat', $company))->assertOk()->assertSee(__('client_file.zakat.share_text', ['share' => 60, 'foreign' => 40]))->assertSee(__('client_file.zakat.none'));
        // The requests and the chat stay on the client's own page, linked from the menu.
        $this->get(route('clients.file.zakat', $company))->assertSee(route('clients.show', $company));
    }

    public function test_the_pages_list_what_the_client_uploaded_and_the_statements(): void
    {
        Storage::fake('local');
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $sara = $this->staff($office);
        $company = $this->company($office);
        $request = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'القوائم المالية', 'number' => 1,
            'title' => 'ميزان مراجعة معتمد بعد التسويات', 'position' => 1, 'status' => 'uploaded']);
        $prior = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'مستندات إعداد القوائم المالية', 'number' => 2,
            'title' => 'القوائم المالية المعتمدة والمصدّرة للسنة السابقة (PDF أو Excel)', 'position' => 2, 'status' => 'uploaded']);
        $previous = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2024, 'section' => 'القوائم المالية', 'number' => 1,
            'title' => 'ميزان مراجعة معتمد بعد التسويات', 'position' => 1, 'status' => 'uploaded']);
        foreach ([[$request, 'ميزان 2025.xlsx', 'xlsx'], [$previous, 'ميزان 2024.xlsx', 'xlsx'], [$prior, 'قوائم 2024.pdf', 'pdf']] as [$r, $name, $ext]) {
            ClientFile::create(['office_id' => $office->id, 'company_id' => $company->id, 'document_request_id' => $r->id, 'from_client' => true, 'original_name' => $name, 'path' => "client-files/{$name}", 'size' => 10,
                'review' => ['status' => 'ok']]);
        }
        $run = ClassificationRun::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'ميزان 2025.xlsx', 'company_id' => $company->id, 'result' => ['data' => []]]);
        $report = FinancialReport::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'company_id' => $company->id, 'classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'template_version' => 'sa-ifrs-smes-3', 'status' => 'draft', 'prior_figures' => ['source' => 'statements']]);
        $this->actingAs($sara);

        // The trial balance: the current period's file and the previous one's, each under its own period.
        $html = $this->get(route('clients.file.trial-balance', $company))->assertOk()->getContent();
        $current = mb_strpos($html, 'data-period="2025"');
        $before = mb_strpos($html, 'data-period="2024"');
        $this->assertTrue($current !== false && $before !== false && $current < $before);
        $this->assertStringContainsString('ميزان 2025.xlsx', mb_substr($html, $current, $before - $current));
        $this->assertStringContainsString('ميزان 2024.xlsx', mb_substr($html, $before, 2500));
        $this->assertStringContainsString('data-classify="', $html);   // the accountant can classify a picked file
        $this->assertStringContainsString('/financial-reports/new?run='.$run->id, $html);
        // Last year's statements, and the opening balances compared right there: the wizard's step, inside the client's file.
        $prior = $this->get(route('clients.file.prior', $company))->assertOk()->assertSee('قوائم 2024.pdf')->assertSee('id="app"', false)->getContent();
        $config = json_decode(\Illuminate\Support\Str::between($prior, 'window.PageConfig = ', ';</script>'), true);
        $this->assertSame([$report->id, ['opening'], true], [$config['report_id'], $config['steps'], $config['embedded']]);
        $this->assertSame("/financial-reports/{$report->id}#notes", $config['step_urls']['notes']);   // the other steps are links back to the wizard
        $this->assertSame(route('clients.file.zakat', $company, false)."?report={$report->id}", $config['step_urls']['zakat']);
        // The statements, and zakat.
        $this->get(route('clients.file.statements', $company))->assertOk()->assertSee("/financial-reports/{$report->id}", false)->assertSee(__('client_file.statements.statuses.draft'));
        // The wizard of these statements no longer holds the two steps: they are the client's file's, and the wizard links to them.
        $wizard = $this->get("/financial-reports/{$report->id}")->assertOk();
        $c = json_decode(\Illuminate\Support\Str::between($wizard->getContent(), 'window.PageConfig = ', ';</script>'), true);
        $this->assertSame(['company', 'prior_year', 'accounts', 'movements', 'notes', 'review', 'versions'], $c['steps']);
        $this->assertSame(['opening', 'zakat'], array_keys($c['step_urls']));
        $zakat = $this->get(route('clients.file.zakat', $company))->assertOk()->getContent();
        $this->assertSame(['zakat'], json_decode(\Illuminate\Support\Str::between($zakat, 'window.PageConfig = ', ';</script>'), true)['steps']);
        // A viewer cannot classify (no button), only look.
        $this->actingAs($this->staff($office, 'viewer', ['clients.view']));
        $this->get(route('clients.file.trial-balance', $company))->assertOk()->assertDontSee('data-classify="', false);
    }

    public function test_replacing_the_registration_or_the_articles_needs_the_manage_permission(): void
    {
        Storage::fake('local');
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $company = $this->company($office);
        $this->actingAs($this->staff($office, 'viewer', ['clients.view']));
        $file = fn (string $name) => UploadedFile::fake()->createWithContent($name, '%PDF-1.4');
        $this->post(route('clients.file.registration.update', $company), ['registration_file' => $file('cr.pdf')])->assertForbidden();
        $this->post(route('clients.file.articles.update', $company), ['articles_file' => $file('a.pdf')])->assertForbidden();
        $this->get(route('clients.file.registration', $company))->assertOk()->assertDontSee('name="registration_file"', false);

        $this->actingAs($this->staff($office, 'manager', ['clients.view', 'clients.manage']));
        $this->post(route('clients.file.registration.update', $company), [])->assertSessionHasErrors('registration_file');
        $this->post(route('clients.file.articles.update', $company), ['articles_file' => UploadedFile::fake()->createWithContent('a.xlsx', 'x')])->assertSessionHasErrors('articles_file');
        $this->get(route('clients.file.registration', $company))->assertOk()->assertSee('name="registration_file"', false);
    }

    public function test_another_office_and_a_client_cannot_open_the_pages(): void
    {
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $company = $this->company($office);
        $this->actingAs($this->staff(Office::create(['name_ar' => 'مكتب ب', 'code' => 'beta']), 'omar'));
        foreach (['registration', 'articles', 'trial-balance', 'prior', 'statements', 'zakat', 'papers', 'recalculation'] as $step) {
            $this->get(route("clients.file.{$step}", $company))->assertNotFound();
        }
        $client = User::create(['office_id' => $office->id, 'company_id' => $company->id, 'name' => 'عميل', 'username' => 'client1', 'password' => 'secret123',
            'role_id' => (Role::client() ?? Role::create(['name_ar' => 'عميل', 'is_client' => true]))->id, 'status' => User::ACTIVE]);
        $this->actingAs($client);
        foreach (['registration', 'articles', 'trial-balance', 'prior', 'statements', 'zakat', 'papers', 'recalculation'] as $step) {
            $this->get(route("clients.file.{$step}", $company))->assertForbidden();
        }
    }

    public function test_the_work_steps_of_the_main_menu_follow_the_client_opened_last(): void
    {
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $sara = $this->staff($office);
        $a = $this->company($office, ['name_ar' => 'شركة النور', 'client_name' => 'أحمد السالم', 'cr_number' => '1010000001']);
        $b = $this->company($office, ['name_ar' => 'شركة الأمل', 'client_name' => 'منى العلي', 'cr_number' => '1010000002']);
        $this->actingAs($sara);

        // The main menu: the clients, then the engagement file and its steps in the office's order; the old trial-balance and statements links are these.
        $html = $this->get('/clients')->assertOk()->getContent();
        $menu = mb_substr($html, mb_strpos($html, '<aside'));
        $order = array_map(fn ($k) => __("nav.items.{$k}.0"), ['clients', 'engagement', 'trial_balance', 'prior', 'classification', 'risks', 'materiality', 'procedures', 'sampling', 'adjustments', 'zakat', 'statements', 'completion', 'release']);
        $at = -1;
        foreach ($order as $label) {
            $pos = mb_strpos($menu, '<span class="label">'.$label, $at + 1);
            $this->assertNotFalse($pos, $label);
            $at = $pos;
        }
        $this->assertStringNotContainsString('href="/classifier"', $menu);
        foreach (['/work/trial-balance', '/work/prior-statements', '/work/statements', '/work/zakat'] as $href) {
            $this->assertStringContainsString("href=\"{$href}\"", $menu);
        }

        // With no client open, a work step asks which; choosing one goes on to the same step for them.
        $choose = $this->get('/work/zakat')->assertOk();
        $choose->assertSee('data-choose-client', false)->assertSee('أحمد السالم')->assertSee('منى العلي')->assertSee('/work/zakat?client='.$a->id, false);
        $this->get('/work/zakat?client='.$b->id)->assertOk()->assertSee('منى العلي')->assertSee('cf-chips', false);

        // The client opened last is remembered by every work step, and named under the work section of the menu.
        $this->get('/work/trial-balance')->assertOk()->assertSee('منى العلي')->assertSee(__('client_file.nav.steps.trial_balance'));
        $this->get(route('clients.show', $a));
        $page = $this->get('/work/statements')->assertOk();
        $page->assertSee('class="cur-client"', false)->assertSee(__('nav.current_client'));
        $this->assertSame($a->id, session('current_client'));
        $this->get('/work/prior-statements')->assertOk()->assertSee('أحمد السالم');
        // The current page is the active link of the menu, not also «clients».
        $zakat = $this->get('/work/zakat')->getContent();
        $menu = mb_substr($zakat, mb_strpos($zakat, '<aside'), mb_strpos($zakat, '</aside>') - mb_strpos($zakat, '<aside'));
        preg_match_all('/<a href="([^"]*)"[^>]*class="on"/', $menu, $on); $this->assertSame(['/work/zakat'], $on[1]);
        $this->assertMatchesRegularExpression('#<a href="/work/zakat"[^>]*class="on"#', $menu);

        // «Change client» forgets the choice.
        $this->get('/work/zakat?change=1')->assertOk()->assertSee('data-choose-client', false);
        $this->assertNull(session('current_client'));
        // Another office's client is never taken, whatever the address or the session says.
        $other = $this->company(Office::create(['name_ar' => 'مكتب ب', 'code' => 'beta']), ['cr_number' => '1010000009', 'client_name' => 'عميل مكتب ب']);
        $this->get('/work/zakat?client='.$other->id)->assertOk()->assertSee('data-choose-client', false)->assertDontSee('عميل مكتب ب');
        // Only who may see clients: a client's own login cannot.
        $client = User::create(['office_id' => $office->id, 'company_id' => $a->id, 'name' => 'عميل', 'username' => 'client1', 'password' => 'secret123',
            'role_id' => (Role::client() ?? Role::create(['name_ar' => 'عميل', 'is_client' => true]))->id, 'status' => User::ACTIVE]);
        $this->actingAs($client);
        $this->get('/work/zakat')->assertForbidden();
    }
}
