<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\ClientFile;
use App\Models\FinancialReport;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Offices and the client portal: sign-in with the office's code, each office's data its own, the accountant's clients (their
 * login, the audit checklist, review), the client's uploads, and the chat between them.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private function office(string $code = 'alpha', bool $platform = false): Office
    {
        return Office::create(['name_ar' => "مكتب {$code}", 'code' => $code, 'is_platform' => $platform]);
    }

    private function staff(Office $office, string $username = 'sara', ?array $permissions = null): User
    {
        $role = Role::create(['name_ar' => 'محاسب '.$username, 'is_admin' => $permissions === null]);
        if ($permissions !== null) {
            $role->syncPermissions($permissions);
        }

        return User::create(['office_id' => $office->id, 'name' => 'سارة علي', 'username' => $username, 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
    }

    /** A client of the office made by its accountant, through the page. @return array{0: Company, 1: User} */
    /** What the new-client form sends: the client, the company with its registration and contract files, the period, the login. */
    private function clientForm(string $name, string $username = 'alnoor', array $over = []): array
    {
        return $over + ['client_name' => 'عميل '.$name, 'client_email' => "{$username}@example.test", 'name_ar' => $name, 'name_en' => 'Co '.$username,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'registration_file' => UploadedFile::fake()->createWithContent('cr.pdf', "CR-{$name}"), 'articles_file' => UploadedFile::fake()->createWithContent('articles.pdf', "ART-{$name}"),
            'username' => $username, 'password' => 'Client2025'];
    }

    private function client(Office $office, User $accountant, string $name = 'شركة النور', string $username = 'alnoor', bool $checklist = true): array
    {
        $this->actingAs($accountant);
        $this->bindAi();
        $this->post('/clients', $this->clientForm($name, $username) + ['checklist' => $checklist ? 1 : 0, 'year' => 2025])->assertRedirect();
        $company = Company::where('name_ar', $name)->firstOrFail();

        return [$company, User::where('company_id', $company->id)->firstOrFail()];
    }

    public function test_sign_in_needs_the_offices_code_and_the_same_username_can_live_in_two_offices(): void
    {
        $this->signOut();
        $a = $this->office('alpha');
        $b = $this->office('beta');
        $this->staff($a);
        $this->staff($b);   // «sara» in both

        $this->post('/login', ['username' => 'sara', 'password' => 'secret123'])->assertSessionHasErrors('office_code');
        $this->post('/login', ['office_code' => 'nope', 'username' => 'sara', 'password' => 'secret123'])->assertSessionHasErrors(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة.']);
        $this->assertGuest();
        $this->post('/login', ['office_code' => 'Alpha', 'username' => 'Sara', 'password' => 'secret123'])->assertRedirect('/');   // any case
        $this->assertAuthenticatedAs(User::where(['office_id' => $a->id, 'username' => 'sara'])->first());
        $this->post('/logout');
        $this->post('/login', ['office_code' => 'beta', 'username' => 'sara', 'password' => 'secret123'])->assertRedirect('/');
        $this->assertAuthenticatedAs(User::where(['office_id' => $b->id, 'username' => 'sara'])->first());
    }

    public function test_a_disabled_office_does_not_sign_in(): void
    {
        $this->signOut();
        $office = $this->office('alpha');
        $this->staff($office);
        $office->update(['status' => Office::DISABLED]);

        $this->post('/login', ['office_code' => 'alpha', 'username' => 'sara', 'password' => 'secret123'])->assertSessionHasErrors(['username' => 'هذا المكتب متوقف. يُرجى التواصل مع مدير المنصة.']);
        $this->assertGuest();
    }

    public function test_sign_up_joins_the_office_with_that_code(): void
    {
        $this->signOut();
        $a = $this->office('alpha');
        $open = Role::create(['name_ar' => 'مساعد', 'open_for_signup' => true]);
        $client = Role::client();
        $form = ['name' => 'منى', 'username' => 'mona', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'role_id' => $open->id];

        $this->post('/register', ['office_code' => 'nope'] + $form)->assertSessionHasErrors('office_code');
        $this->post('/register', ['office_code' => 'alpha', 'role_id' => $client->id] + $form)->assertSessionHasErrors('role_id');   // a client has no sign-up
        $this->post('/register', ['office_code' => 'alpha'] + $form)->assertRedirect('/login');
        $this->assertSame([$a->id, User::PENDING], [User::where('username', 'mona')->value('office_id'), User::where('username', 'mona')->value('status')]);
        $this->post('/register', ['office_code' => 'alpha'] + $form)->assertSessionHasErrors('username');   // taken in that office
        $b = $this->office('beta');
        $this->post('/register', ['office_code' => 'beta'] + $form)->assertRedirect('/login');             // free in another
        $this->assertSame(2, User::where('username', 'mona')->count());
    }

    public function test_an_office_sees_only_its_own_companies_and_reports(): void
    {
        $a = $this->office('alpha');
        $b = $this->office('beta');
        $sara = $this->staff($a);
        $omar = $this->staff($b, 'omar');

        $this->actingAs($sara);
        $mine = Company::create(['name_ar' => 'شركة ألفا']);
        $this->actingAs($omar);
        $theirs = Company::create(['name_ar' => 'شركة بيتا']);
        $this->assertSame($b->id, $theirs->office_id);

        $this->actingAs($sara);
        $this->assertSame(['شركة ألفا'], Company::pluck('name_ar')->all());
        $this->assertNull(Company::find($theirs->id));
        $this->getJson("/api/v1/companies/{$theirs->id}")->assertNotFound();
        $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->get("/clients/{$theirs->id}")->assertNotFound();
        $this->get('/clients')->assertOk()->assertSee('شركة ألفا')->assertDontSee('شركة بيتا');
    }

    public function test_the_accountant_makes_a_client_with_a_login_and_the_audit_checklist(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        $this->actingAs($sara);
        $this->bindAi();

        $this->get('/clients/new')->assertOk()->assertSee('حساب دخول العميل')->assertSee('صورة السجل التجاري')->assertSee('عقد تأسيس الشركة')->assertSee('البريد الإلكتروني للعميل');
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['password' => 'short']) + ['checklist' => 1, 'year' => 2025])->assertSessionHasErrors('password');
        $this->post('/clients', $this->clientForm('شركة النور', 'نور'))->assertSessionHasErrors('username');
        // Everything is asked: the client, the email, both names, the two files.
        $this->post('/clients', ['username' => 'alnoor', 'password' => 'Client2025'])->assertSessionHasErrors(['client_name', 'client_email', 'name_ar', 'name_en', 'registration_file']);
        $this->assertSame(0, Company::count());

        $response = $this->post('/clients', $this->clientForm('شركة النور', 'AlNoor') + ['checklist' => 1, 'year' => 2025]);
        $company = Company::where('name_ar', 'شركة النور')->first();
        $response->assertRedirect(route('clients.show', $company))->assertSessionHas('credentials', ['username' => 'alnoor', 'password' => 'Client2025']);

        // The AI read the registration: its number is the company's, with what else it printed; the contract's partners were read too.
        $this->assertMatchesRegularExpression('/^\d{10}$/', $company->cr_number);
        $this->assertSame(['عميل شركة النور', 'alnoor@example.test', 'Co AlNoor', 'تجارة الجملة', 'الدمام'], [$company->client_name, $company->client_email, $company->name_en, $company->activity, $company->city]);
        $this->assertSame(['1445/03/10', '2023-09-25'], [$company->cr_date_hijri, $company->cr_date->format('Y-m-d')]);
        $this->assertSame($company->cr_number, $company->profile['registration']['number']);
        Storage::disk('local')->assertExists($company->profile['registration']['file']);
        $this->assertSame(['أحمد', 'جون'], array_column($company->profile['articles']['partners'], 'name'));
        $this->assertSame(['2025-01-01', '2025-12-31', 12, 31], [$company->period_start->toDateString(), $company->period_end->toDateString(), $company->fiscal_year_end_month, $company->fiscal_year_end_day]);

        $login = User::where('company_id', $company->id)->first();
        $this->assertSame([$office->id, 'alnoor', User::ACTIVE, true, 'عميل شركة النور', 'alnoor@example.test'], [$login->office_id, $login->username, $login->status, $login->role->is_client, $login->name, $login->email]);
        $this->assertSame(['portal.use'], $login->role->permissions());
        $this->assertSame(81, DocumentRequest::where('company_id', $company->id)->count());
        $this->assertSame(range(1, 81), DocumentRequest::where('company_id', $company->id)->whereNotNull('number')->orderBy('number')->pluck('number')->all());
        $this->assertSame('بيان تفصيلي (تحليل) للذمم الدائنة في 31 ديسمبر 2025', DocumentRequest::where('company_id', $company->id)->where('number', 45)->value('title'));

        // The password is shown once; the page names the client, the company, the registration number and the period.
        $this->get(route('clients.show', $company))->assertOk()->assertSee('Client2025')->assertSee('alpha')->assertSee('عميل شركة النور')->assertSee($company->cr_number)->assertSee('alnoor@example.test');
        $this->get(route('clients.show', $company))->assertDontSee('Client2025');
        $this->get(route('clients.documents', [$company, 'registration']))->assertOk();
        $this->get(route('clients.documents', [$company, 'articles']))->assertOk();
        $this->get(route('clients.documents', [$company, 'other']))->assertNotFound();

        // The same username is free in another office, taken in this one.
        $this->post('/clients', $this->clientForm('شركة ثانية', 'alnoor'))->assertSessionHasErrors('username');
        // Applying the checklist again adds nothing.
        $this->post("/clients/{$company->id}/checklist", ['year' => 2025])->assertRedirect()->assertSessionHas('status', 'جميع بنود القائمة كانت مطلوبة من قبل.');
        $this->assertSame(81, DocumentRequest::where('company_id', $company->id)->count());
    }

    public function test_a_company_is_one_client_by_its_registration_number(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        $this->actingAs($sara);
        $this->bindAi();
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor'))->assertRedirect();
        $first = Company::first();

        // The same registration (the same file) for another client is refused, naming the client it belongs to; nothing is made.
        $again = $this->clientForm('شركة النور', 'second', ['client_name' => 'عميل تاني', 'name_ar' => 'اسم تاني']);
        $again['registration_file'] = UploadedFile::fake()->createWithContent('cr.pdf', 'CR-شركة النور');
        $this->post('/clients', $again)->assertSessionHasErrors('registration_file');
        $this->assertStringContainsString('عميل شركة النور', session('errors')->first('registration_file'));
        $this->assertStringContainsString($first->cr_number, session('errors')->first('registration_file'));
        $this->assertSame([1, 1], [Company::count(), User::where('company_id', '<>', null)->count()]);

        // Not a registration, or no number in it: refused with what is wrong.
        $bad = fn (string $content) => $this->clientForm('شركة غريبة', 'strange', ['registration_file' => UploadedFile::fake()->createWithContent('cr.pdf', $content)]);
        $this->post('/clients', $bad('NOT-CR'))->assertSessionHasErrors('registration_file');
        $this->assertStringContainsString('ليس سجلًا تجاريًا', session('errors')->first('registration_file'));
        $this->post('/clients', $bad('UNREADABLE-NUMBER'))->assertSessionHasErrors('registration_file');
        $this->assertStringContainsString('رقم السجل التجاري', session('errors')->first('registration_file'));
        $this->assertSame(1, Company::count());

        // Another office may have the same company: a number is unique inside an office.
        $other = $this->staff($this->office('beta'), 'omar');
        $this->actingAs($other);
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor'))->assertRedirect();
        $this->assertSame(2, Company::withoutGlobalScopes()->where('cr_number', $first->cr_number)->count());
    }

    public function test_the_period_is_a_date_to_a_date_with_their_years(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $this->actingAs($this->staff($office));
        $this->bindAi();
        // The end after the start, and about a year (no more than 400 days).
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['period_start' => '2025-07-01', 'period_end' => '2025-07-01']))->assertSessionHasErrors('period_end');
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['period_start' => '2025-07-01', 'period_end' => '2025-06-30']))->assertSessionHasErrors('period_end');
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['period_start' => '2025-07-01', 'period_end' => '2027-07-01']))->assertSessionHasErrors('period_end');
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['period_start' => '01/07/2025']))->assertSessionHasErrors('period_start');
        $this->assertSame(0, Company::count());
        // 1/7/2025 to 1/7/2026, as typed.
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['period_start' => '2025-07-01', 'period_end' => '2026-07-01']) + ['checklist' => 1])->assertRedirect();
        $company = Company::first();
        $this->assertSame(['2025-07-01', '2026-07-01', 7, 1, 7, 1], [$company->period_start->toDateString(), $company->period_end->toDateString(), $company->fiscal_year_start_month, $company->fiscal_year_start_day,
            $company->fiscal_year_end_month, $company->fiscal_year_end_day]);
        // The checklist for 2026 is for exactly that period, and its titles name the end.
        $request = DocumentRequest::where('company_id', $company->id)->where('number', 45)->first();
        $this->assertSame(['2025-07-01', '2026-07-01', 2026, 'بيان تفصيلي (تحليل) للذمم الدائنة في 1 يوليو 2026'], [$request->period_start->toDateString(), $request->period_end->toDateString(), $request->year, $request->title]);
        $this->get(route('clients.show', $company))->assertSee('من 1 يوليو 2025 إلى 1 يوليو 2026');
        // The next year's is the same period a year on.
        $this->assertSame(['2026-07-01', '2027-07-01'], array_values(array_map(fn ($d) => $d->format('Y-m-d'), $company->periodEndingIn(2027))));
    }
    public function test_a_client_is_deleted_with_everything_of_theirs(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        [$other, $otherLogin] = $this->client($office, $sara, 'شركة الأمل', 'alamal');
        $tb = DocumentRequest::where('company_id', $company->id)->where('number', 1)->first();
        $this->actingAs($login);
        $this->post("/portal/requests/{$tb->id}/files", ['files' => [UploadedFile::fake()->createWithContent('ميزان.xlsx', $this->xlsxBytes('GONE'))]])->assertRedirect();
        $this->post('/portal/archive', ['archive' => $this->zip(['ميزان المراجعة.xlsx' => $this->xlsxBytes('GONE2')]), 'year' => 2025])->assertRedirect();
        ClientMessage::create(['office_id' => $office->id, 'company_id' => $company->id, 'from_client' => true, 'body' => 'مرحبًا']);

        // A trial balance uploaded for the company and a financial statement made from it, with last year's file; and the same for the other client.
        Storage::disk('local')->put('classification/run-a.xlsx', 'TB-A');
        Storage::disk('local')->put("prior-statements/report-a/up.pdf", 'PRIOR-A');
        $run = ClassificationRun::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $company->id, 'file_path' => 'classification/run-a.xlsx', 'result' => ['data' => []]]);
        $report = FinancialReport::forceCreate(['id' => 'report-a-'.uniqid(), 'company_id' => $company->id, 'classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'template_version' => 'sa-ifrs-smes-3',
            'prior_upload' => ['path' => 'prior-statements/report-a/up.pdf']]);
        Storage::disk('local')->put('classification/run-b.xlsx', 'TB-B');
        $runB = ClassificationRun::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $other->id, 'file_path' => 'classification/run-b.xlsx', 'result' => ['data' => []]]);
        $reportB = FinancialReport::forceCreate(['id' => 'report-b-'.uniqid(), 'company_id' => $other->id, 'classification_run_id' => $runB->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'template_version' => 'sa-ifrs-smes-3']);
        $registration = $company->fresh()->profile['registration']['file'];
        $articles = $company->fresh()->profile['articles']['file'];
        $clientPath = ClientFile::where('original_name', 'ميزان.xlsx')->value('path');
        $archivePath = \App\Models\ClientArchive::first()->path;
        foreach ([$registration, $articles, $clientPath, $archivePath, 'classification/run-a.xlsx', 'prior-statements/report-a/up.pdf'] as $p) {
            Storage::disk('local')->assertExists($p);
        }

        // The name is typed to confirm; a wrong name deletes nothing; without the permission nothing is possible.
        $this->actingAs($sara);
        $this->delete(route('clients.destroy', $company), ['confirm' => 'اسم آخر'])->assertSessionHasErrors('confirm');
        $this->assertTrue(Company::whereKey($company->id)->exists());
        $viewer = $this->staff($office, 'manager', ['clients.view', 'clients.manage']);
        $this->actingAs($viewer);
        $this->delete(route('clients.destroy', $company), ['confirm' => $company->clientName()])->assertForbidden();
        $this->actingAs($sara);
        $this->get(route('clients.show', $company))->assertSee('حذف العميل نهائيًا');
        $this->delete(route('clients.destroy', $company), ['confirm' => $company->clientName()])->assertRedirect(route('clients.index'))->assertSessionHas('status');

        // Gone: the company, the login, every request, file, message and archive, the statement, the trial balance, and every file on disk.
        $this->assertFalse(Company::withoutGlobalScopes()->whereKey($company->id)->exists());
        $this->assertSame(0, User::withoutGlobalScopes()->where('company_id', $company->id)->count() + User::withoutGlobalScopes()->whereKey($login->id)->count());
        foreach ([DocumentRequest::class, ClientFile::class, ClientMessage::class, \App\Models\ClientArchive::class] as $model) {
            $this->assertSame(0, $model::withoutGlobalScopes()->where('company_id', $company->id)->count(), $model);
        }
        $this->assertFalse(FinancialReport::withoutGlobalScopes()->whereKey($report->id)->exists());
        $this->assertFalse(ClassificationRun::withoutGlobalScopes()->whereKey($run->id)->exists());
        foreach ([$registration, $articles, $clientPath, $archivePath, 'classification/run-a.xlsx', 'prior-statements/report-a/up.pdf'] as $p) {
            Storage::disk('local')->assertMissing($p);
        }
        // The other client is untouched.
        $this->assertTrue(Company::whereKey($other->id)->exists() && User::whereKey($otherLogin->id)->exists());
        $this->assertTrue(FinancialReport::withoutGlobalScopes()->whereKey($reportB->id)->exists() && ClassificationRun::withoutGlobalScopes()->whereKey($runB->id)->exists());
        $this->assertSame(81, DocumentRequest::where('company_id', $other->id)->count());
        Storage::disk('local')->assertExists('classification/run-b.xlsx');
        $this->get('/clients')->assertDontSee('<h3>عميل شركة النور</h3>', false)->assertSee('<h3>عميل شركة الأمل</h3>', false);
        // Another office never reaches it.
        $this->actingAs($this->staff($this->office('beta'), 'omar'));
        $this->delete(route('clients.destroy', $other), ['confirm' => 'x'])->assertNotFound();
    }
    public function test_the_accountant_edits_a_clients_details(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $other = $this->client($office, $sara, 'شركة الأمل', 'alamal')[0];
        $this->actingAs($sara);

        $this->get(route('clients.edit', $company))->assertOk()->assertSee('عميل شركة النور')->assertSee($company->cr_number);
        $form = fn (array $over = []) => $over + ['client_name' => 'أحمد السالم', 'client_email' => 'ahmed@example.test', 'name_ar' => 'شركة النور المحدودة', 'name_en' => 'Al Noor Ltd',
            'period_start' => '2025-07-01', 'period_end' => '2026-06-30'];
        $this->put(route('clients.update', $company), $form())->assertRedirect(route('clients.show', $company));
        $company->refresh();
        $this->assertSame(['أحمد السالم', 'ahmed@example.test', 'شركة النور المحدودة', 'Al Noor Ltd', '2025-07-01', '2026-06-30'], [$company->client_name, $company->client_email, $company->name_ar, $company->name_en, $company->period_start->toDateString(), $company->period_end->toDateString()]);
        // The login follows: its name and email.
        $this->assertSame(['أحمد السالم', 'ahmed@example.test'], [$login->fresh()->name, $login->fresh()->email]);

        // A registration number typed to fix a misreading: ten digits, and not another client's.
        $this->put(route('clients.update', $company), $form(['cr_number' => '123']))->assertSessionHasErrors('cr_number');
        $this->put(route('clients.update', $company), $form(['cr_number' => $other->cr_number]))->assertSessionHasErrors('cr_number');
        $this->put(route('clients.update', $company), $form(['cr_number' => '1010101010']))->assertRedirect();
        $this->assertSame('1010101010', $company->fresh()->cr_number);
        // A new registration file is read again and its number replaces the typed one; another client's file is refused.
        $new = UploadedFile::fake()->createWithContent('cr2.pdf', 'CR-NEW-ONE');
        $this->put(route('clients.update', $company), $form(['registration_file' => $new, 'cr_number' => '1010101010']))->assertRedirect();
        $this->assertNotSame('1010101010', $company->fresh()->cr_number);
        $this->assertSame($company->fresh()->cr_number, $company->fresh()->profile['registration']['number']);
        $theirs = UploadedFile::fake()->createWithContent('cr3.pdf', 'CR-شركة الأمل');
        $this->put(route('clients.update', $company), $form(['registration_file' => $theirs]))->assertSessionHasErrors('registration_file');
        // One person can own several companies: the same email is allowed (the second login simply doesn't carry it).
        $this->put(route('clients.update', $company), $form(['client_email' => 'alamal@example.test']))->assertRedirect();
        $this->assertSame(['alamal@example.test', null], [$company->fresh()->client_email, $login->fresh()->email]);
        $this->put(route('clients.update', $company), $form(['client_email' => 'not-an-email']))->assertSessionHasErrors('client_email');
        // Without a manager's permission it is not possible.
        $viewer = $this->staff($office, 'viewer', ['clients.view']);
        $this->actingAs($viewer);
        $this->get(route('clients.edit', $company))->assertForbidden();
        $this->put(route('clients.update', $company), $form())->assertForbidden();
        // Another office never reaches it.
        $this->actingAs($this->staff($this->office('beta'), 'omar'));
        $this->get(route('clients.edit', $company))->assertNotFound();
    }

    public function test_the_list_shows_the_client_first_and_under_it_the_company_and_its_registration(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company] = $this->client($office, $sara);
        $this->get('/clients')->assertOk()->assertSeeInOrder(['<h3>عميل شركة النور</h3>', 'شركة النور', $company->cr_number], false);
    }
    public function test_the_main_portal_shows_the_clients_and_what_waits(): void
    {
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company] = $this->client($office, $sara);
        $request = DocumentRequest::where('company_id', $company->id)->first();
        $request->update(['status' => DocumentRequest::UPLOADED]);
        ClientMessage::create(['office_id' => $office->id, 'company_id' => $company->id, 'from_client' => true, 'body' => 'ابعتلي القائمة']);

        $this->get('/clients')->assertOk()->assertSee('البوابة الرئيسية')->assertSee('شركة النور')->assertSee('1 للمراجعة')->assertSee('1 رسالة جديدة')->assertSee('كود المكتب');
        $this->get('/')->assertRedirect('/clients');
        // The menu counts what waits: one document and one message.
        $this->get('/clients')->assertSee('2 بانتظار الرد');
    }

    public function test_a_client_signs_in_to_their_portal_and_nothing_else(): void
    {
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $this->signOut();

        $this->post('/login', ['office_code' => 'alpha', 'username' => 'alnoor', 'password' => 'Client2025'])->assertRedirect('/');
        $this->get('/')->assertRedirect('/portal');
        $this->get('/portal')->assertOk()->assertSee('شركة النور')->assertSee('مطلوب منك')->assertSee('ميزان مراجعة معتمد');
        $this->get('/clients')->assertForbidden();
        $this->get('/financial-reports')->assertForbidden();
        $this->get('/classifier')->assertForbidden();
        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();

        // A switched-off login is signed out.
        $this->actingAs($login);
        $login->update(['status' => User::DISABLED]);
        $this->get('/portal')->assertRedirect('/login');
    }

    public function test_the_checklist_no_longer_asks_the_client_for_working_papers_or_recalculations(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company] = $this->client($office, $sara);

        // The AI prepares them (PaperMaker): the client is asked only for documents.
        $this->assertSame(0, DocumentRequest::where('company_id', $company->id)->whereNotNull('paper')->count());
        $this->assertGreaterThan(0, DocumentRequest::where('company_id', $company->id)->count());

        // A request of the accountant's own outside the checklist stays possible (no paper).
        $this->actingAs($sara);
        $this->post("/clients/{$company->id}/requests", ['year' => 2025, 'section' => 'طلبات أخرى', 'title' => 'عقد إيجار المستودع'])->assertRedirect();
        $this->assertNull(DocumentRequest::where('title', 'عقد إيجار المستودع')->first()->paper);
    }

    /** A one-cell workbook (a real xlsx, so the upload rules accept it). */
    private function xlsxBytes(string $mark): string
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', $mark);
        $path = tempnam(sys_get_temp_dir(), 'xb');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function fakeAi(array $byFile): object
    {
        config(['portal.ai_review' => 'auto']);

        return $this->bindAi($byFile);
    }

    /**
     * A stand-in for the AI: the answers set for a file name, else the built-in ones: a commercial registration whose number comes from the
     * file's own bytes (so each file is another company) and a contract with two partners.
     */
    private function bindAi(array $byFile = []): object
    {
        config(['financial_statements.prior_pdf.cache' => sys_get_temp_dir().'/review-'.uniqid()]);
        $driver = new class($byFile) implements \App\Support\Llm\ClassifierDriver
        {
            public array $calls = [];

    public function __construct(private array $byFile) {}

            public function classify(\App\Support\Llm\ToolCall $call): \GuzzleHttp\Promise\PromiseInterface
            {
                $this->calls[] = $call;
                foreach ($this->byFile as $name => $answer) {
                    if (str_contains($call->content, $name)) {
                        if ($answer instanceof \Throwable) {
                            return \GuzzleHttp\Promise\Create::rejectionFor($answer);
                        }

                        return \GuzzleHttp\Promise\Create::promiseFor(new \App\Support\Llm\ToolAnswer($answer, null));
                    }
                }
                if ($call->tool['name'] === 'submit_assignments') {
                    preg_match_all('/^\[(\d+)\] (.+)$/m', $call->system[0]['text'], $cat, PREG_SET_ORDER);
                    preg_match_all('/^\[(\d+)\] (.+?) \(/m', $call->content, $names, PREG_SET_ORDER);
                    $words = ['ميزان' => 'ميزان مراجعة', 'السجل' => 'السجل التجاري', 'بنك' => 'كشف حساب البنوك'];
                    $out = [];
                    foreach ($names as [, $i, $path]) {
                        $request = null;
                        foreach ($words as $inName => $inTitle) {
                            if (str_contains($path, $inName)) {
                                foreach ($cat as [, $id, $title]) {
                                    if (str_contains($title, $inTitle)) {
                                        $request ??= (int) $id;
                                    }
                                }
                            }
                        }
                        $out[] = ['index' => (int) $i, 'request_id' => $request, 'confident' => $request !== null];
                    }

                    return \GuzzleHttp\Promise\Create::promiseFor(new \App\Support\Llm\ToolAnswer(['assignments' => $out], null));
                }
                if ($call->tool['name'] === 'submit_registration') {
                    return \GuzzleHttp\Promise\Create::promiseFor(new \App\Support\Llm\ToolAnswer(['readable' => true, 'is_commercial_registration' => ! str_contains((string) $call->pdf, 'NOT-CR'),
                        'registration_number' => str_contains((string) $call->pdf, 'UNREADABLE-NUMBER') ? null : substr(str_pad((string) crc32((string) $call->pdf), 10, '7'), 0, 10), 'unified_number' => null,
                        'company_name' => str_contains((string) $call->pdf, 'OTHER-NAME') ? 'مؤسسة الأمل' : null, 'activity' => 'تجارة الجملة', 'city' => 'الدمام', 'issue_date' => '2023-09-25', 'issue_date_hijri' => '1445/03/10', 'expiry_date' => '2099-01-01'], null));
                }
                if ($call->tool['name'] === 'submit_partners') {
                    return \GuzzleHttp\Promise\Create::promiseFor(new \App\Support\Llm\ToolAnswer(['partners' => [['name' => 'أحمد', 'nationality' => 'السعودية', 'shares' => 60, 'share_value' => 10, 'percentage' => null], ['name' => 'جون', 'nationality' => 'بريطاني', 'shares' => 40, 'share_value' => 10, 'percentage' => null]], 'capital' => 1000, 'shares_total' => 100, 'share_value' => 10], null));
                }
                throw new \RuntimeException('no answer for '.$call->content);
            }
        };
        $this->app->instance(\App\Support\Llm\ClassifierDriver::class, $driver);
        $this->app->forgetInstance(\App\Services\Statements\PriorPdf\Llm::class);   // scoped: it keeps the driver it was made with

        return $driver;
    }

    private function doc(array $over = []): array
    {
        return $over + ['readable' => true, 'pages_unclear' => false, 'document_type' => 'سجل تجاري', 'matching_request_ids' => [], 'company_name' => 'شركة النور', 'registration_number' => '1010',
            'period_year' => null, 'document_date' => '2024-01-01', 'expiry_date' => '2099-01-01', 'issuer' => 'وزارة التجارة', 'has_signature_or_stamp' => null, 'amounts' => [], 'summary' => 'سجل تجاري ساري.'];
    }

    public function test_the_ai_reviews_what_the_client_uploads_instead_of_the_accountant(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $company->update(['cr_number' => '1010', 'name_ar' => 'شركة النور']);
        $cr = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%السجل التجاري%')->first();
        $license = DocumentRequest::where('company_id', $company->id)->where('number', 45)->first();   // the payables' detail: another request
        $send = fn (string $name, string $body) => $this->post("/portal/requests/{$cr->id}/files", ['files' => [UploadedFile::fake()->createWithContent($name, $body)]])->assertRedirect();

        // A commercial registration, the company's own, valid: recognised by its words and accepted.
        $ai = $this->fakeAi([
            'good.pdf' => $this->doc(['matching_request_ids' => [$cr->id]]),
            'other-company.pdf' => $this->doc(['matching_request_ids' => [$cr->id], 'company_name' => 'مؤسسة الأمل التجارية', 'registration_number' => '2020']),
            'expired.pdf' => $this->doc(['matching_request_ids' => [$cr->id], 'expiry_date' => '2020-05-01']),
            'blurry.pdf' => $this->doc(['readable' => false]),
            'payables.pdf' => $this->doc(['document_type' => 'بيان الذمم الدائنة', 'matching_request_ids' => [$license->id], 'company_name' => null, 'registration_number' => null]),
            'unknown.pdf' => $this->doc(['document_type' => 'فاتورة كهرباء', 'matching_request_ids' => []]),
        ]);
        $this->actingAs($login);
        $send('good.pdf', 'GOOD');
        $this->assertSame(DocumentRequest::ACCEPTED, $cr->fresh()->status);
        $file = $cr->files()->where('from_client', true)->first();
        $this->assertSame('ok', $file->review['status']);
        $this->assertSame('سجل تجاري', $file->review['read']['document_type']);
        $this->assertTrue(ClientMessage::where('document_request_id', $cr->id)->where('kind', 'ai')->where('body', 'like', '%قبل المراجع الآلي الطلب%')->exists());
        // What the AI was asked: the document and the client's requests (never the company's name: it only copies).
        $this->assertStringContainsString('صورة من السجل التجاري', $ai->calls[0]->system[0]['text']);
        $this->assertStringNotContainsString('شركة النور', $ai->calls[0]->content);

        // Another company's registration goes back with what is wrong, and the request is open again.
        $send('other-company.pdf', 'OTHER');
        $request = $cr->fresh();
        $this->assertSame(DocumentRequest::RETURNED, $request->status);
        $this->assertStringContainsString('مؤسسة الأمل التجارية', $request->note);

        // An expired one and an unreadable one each say so.
        $send('expired.pdf', 'EXPIRED');
        $this->assertStringContainsString('منتهي الصلاحية بتاريخ 2020-05-01', $cr->fresh()->note);
        $send('blurry.pdf', 'BLUR');
        $this->assertStringContainsString('غير مقروء', $cr->fresh()->note);

        // A file that is the answer to another request is moved there by itself.
        $send('payables.pdf', 'PAY');
        $moved = ClientFile::where('original_name', 'payables.pdf')->first();
        $this->assertSame($license->id, $moved->document_request_id);
        $this->assertSame(DocumentRequest::ACCEPTED, $license->fresh()->status);

        // A document that answers nothing asked is not accepted.
        $send('unknown.pdf', 'UNKNOWN');
        $this->assertStringContainsString('فاتورة كهرباء', $cr->fresh()->note);

        // The accountant still sees every verdict and can reopen: nothing is final.
        $this->actingAs($sara);
        $this->get("/clients/{$company->id}")->assertOk()->assertSee('المراجع الآلي');
        $this->put("/clients/{$company->id}/requests/{$cr->id}", ['status' => 'accepted'])->assertRedirect();
        $this->assertSame(DocumentRequest::ACCEPTED, $cr->fresh()->status);
    }

    public function test_when_the_ai_cannot_be_asked_the_accountant_is_left_with_the_file_and_the_client_is_told(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $cr = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%السجل التجاري%')->first();
        $this->fakeAi(['down.pdf' => new \RuntimeException('provider down')]);
        $this->actingAs($login);
        $this->post("/portal/requests/{$cr->id}/files", ['files' => [UploadedFile::fake()->createWithContent('down.pdf', 'DOWN')]])->assertRedirect();

        $this->assertSame(DocumentRequest::UPLOADED, $cr->fresh()->status);   // in the accountant's tray as before
        $this->assertSame('unavailable', $cr->files()->where('from_client', true)->first()->review['status']);
        $this->assertTrue(ClientMessage::where('document_request_id', $cr->id)->where('body', 'like', '%وسيراجعه المحاسب بنفسه%')->exists());
    }

    public function test_suggest_mode_keeps_the_verdict_but_changes_nothing(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $cr = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%السجل التجاري%')->first();
        $this->fakeAi(['good.pdf' => $this->doc(['matching_request_ids' => [$cr->id]])]);
        config(['portal.ai_review' => 'suggest']);
        $this->actingAs($login);
        $this->post("/portal/requests/{$cr->id}/files", ['files' => [UploadedFile::fake()->createWithContent('good.pdf', 'GOOD2')]])->assertRedirect();

        $this->assertSame(DocumentRequest::UPLOADED, $cr->fresh()->status);
        $this->assertSame('ok', $cr->files()->where('from_client', true)->first()->review['status']);
    }

    public function test_the_accountant_picks_what_the_client_uploaded_instead_of_uploading_again(): void
    {
        Storage::fake('local');
        \Illuminate\Support\Facades\Queue::fake();
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $tbRequest = DocumentRequest::where('company_id', $company->id)->where('number', 1)->first();   // the approved trial balance
        $articlesRequest = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%عقد تأسيس%')->first();
        $this->actingAs($login);
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $this->post("/portal/requests/{$tbRequest->id}/files", ['files' => [UploadedFile::fake()->createWithContent('ميزان.xlsx', $this->xlsxBytes('OLD'))]])->assertRedirect();
        $this->post("/portal/requests/{$tbRequest->id}/files", ['files' => [UploadedFile::fake()->createWithContent('ميزان معتمد.xlsx', $this->xlsxBytes('NEW'))]])->assertRedirect();
        $this->post("/portal/requests/{$articlesRequest->id}/files", ['files' => [UploadedFile::fake()->createWithContent('عقد.pdf', 'CONTRACT')]])->assertRedirect();

        // The list for a purpose: only files that can serve it, the likeliest (the request that names it, the newest) first and suggested.
        $this->actingAs($sara);
        $tb = $this->getJson("/clients/{$company->id}/files?purpose=trial_balance")->assertOk()->json();
        $this->assertSame(['ميزان معتمد.xlsx', 'ميزان.xlsx'], array_column($tb['files'], 'name'));
        $this->assertSame($tb['files'][0]['id'], $tb['suggested']);
        $this->assertSame($tbRequest->id, $tb['files'][0]['request']['id']);
        $this->assertSame(['عقد.pdf'], array_column($this->getJson("/clients/{$company->id}/files?purpose=articles")->json('files'), 'name'));
        // Last year's statements may be a pdf too: the contract fits by its kind but is not suggested (its request doesn't name them).
        $prior = $this->getJson("/clients/{$company->id}/files?purpose=prior_statements")->assertOk()->json();
        $this->assertNull($prior['suggested']);
        $this->getJson("/clients/{$company->id}/files?purpose=nonsense")->assertStatus(422);

        // The classifier takes the picked file as an upload, with the client attached; nothing of the portal's is moved.
        $id = $tb['files'][0]['id'];
        $run = $this->postJson('/api/v1/classify/jobs', ['client_file_id' => $id, 'company_id' => $company->id])->assertStatus(202)->json();
        $stored = \App\Models\ClassificationRun::find($run['id']);
        $this->assertSame(['ميزان معتمد.xlsx', $company->id], [$stored->original_name, $stored->company_id]);
        $this->assertSame(Storage::disk('local')->get(ClientFile::find($id)->path), Storage::disk('local')->get($stored->file_path));   // the same bytes, a copy
        $this->assertTrue(ClientFile::whereKey($id)->exists());
        // A file that is not there (or not the client's) is refused, and a pdf is not a trial balance.
        $this->postJson('/api/v1/classify/jobs', ['client_file_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('client_file_id');
        $pdf = ClientFile::where('original_name', 'عقد.pdf')->first();
        $this->postJson('/api/v1/classify/jobs', ['client_file_id' => $pdf->id])->assertStatus(422)->assertJsonValidationErrors('file');

        // Another office never sees it.
        $other = $this->staff($this->office('beta'), 'omar');
        $this->actingAs($other);
        $this->getJson("/clients/{$company->id}/files?purpose=trial_balance")->assertNotFound();
        $this->postJson('/api/v1/classify/jobs', ['client_file_id' => $id])->assertStatus(422);
    }

    public function test_the_articles_picked_from_the_clients_uploads_are_read(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $articlesRequest = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%عقد تأسيس%')->first();
        $this->actingAs($login);
        $this->post("/portal/requests/{$articlesRequest->id}/files", ['files' => [UploadedFile::fake()->createWithContent('عقد-تأسيس.pdf', '%PDF-1.4 contract')]])->assertRedirect();
        $file = ClientFile::where('original_name', 'عقد-تأسيس.pdf')->first();

        $this->fakeAi(['Copy the partners' => ['partners' => [['name' => 'أحمد', 'nationality' => 'السعودية', 'shares' => 60, 'share_value' => 10, 'percentage' => null],
            ['name' => 'جون', 'nationality' => 'بريطاني', 'shares' => 40, 'share_value' => 10, 'percentage' => null]], 'capital' => 1000, 'shares_total' => 100, 'share_value' => 10]]);
        config(['portal.ai_review' => 'off']);
        $this->actingAs($sara);
        $read = $this->postJson("/api/v1/companies/{$company->id}/articles", ['client_file_id' => $file->id])->assertOk()->json();
        $this->assertSame([60, 'عقد-تأسيس.pdf'], [$read['articles']['zakat_share'], $read['articles']['name']]);
    }
    /** A zip of the given files (name => content), made the way a client would. */
    private function zip(array $files, string $name = 'ملفاتي.zip'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zp').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    public function test_a_client_sends_one_compressed_file_and_each_document_goes_to_its_request(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $year = 2025;
        $tb = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%ميزان مراجعة معتمد%')->first();
        $cr = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%السجل التجاري%')->first();
        $bank = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%كشف حساب البنوك%')->first();
        $this->actingAs($login);
        $zip = $this->zip([
            'ميزان المراجعة 2025.xlsx' => $this->xlsxBytes('TB'),
            'قانوني/السجل التجاري.pdf' => '%PDF-1.4 CR',
            'بنوك/كشف حساب بنك الراجحي.pdf' => '%PDF-1.4 BANK',
            'ملف غامض.pdf' => '%PDF-1.4 ???',
            'برنامج.exe' => 'MZ',
            '../evil.pdf' => 'evil',
            '__MACOSX/ميزان.pdf' => 'mac',
            '.مخفي.pdf' => 'hidden',
            'داخلي.zip' => 'PK',
        ]);
        $this->post('/portal/archive', ['archive' => $zip, 'year' => $year])->assertRedirect()->assertSessionHas('status');

        $archive = \App\Models\ClientArchive::first();
        $this->assertSame(['done', $year, true], [$archive->status, $archive->year, $archive->from_client]);
        $this->assertSame([4, 3, 1], [$archive->summary['total'], $archive->summary['placed'], $archive->summary['unsorted']]);
        $this->assertEqualsCanonicalizing(['not_allowed', 'unsafe_path', 'system_file', 'system_file', 'nested_archive'], array_column($archive->summary['skipped'], 'reason'));

        // Each file is in the request its name says, as the client's upload, and the request is waiting for review.
        $in = fn (DocumentRequest $r) => $r->files()->where('from_client', true)->pluck('original_name')->all();
        $this->assertSame(['ميزان المراجعة 2025.xlsx'], $in($tb));
        $this->assertSame(['السجل التجاري.pdf'], $in($cr));
        $this->assertSame(['كشف حساب بنك الراجحي.pdf'], $in($bank));
        foreach ([$tb, $cr, $bank] as $r) {
            $this->assertSame(DocumentRequest::UPLOADED, $r->fresh()->status);
        }
        // What the names don't place waits in a request of its own, which is never asked of the client.
        $unsorted = DocumentRequest::where('company_id', $company->id)->where('paper', 'unsorted')->first();
        $this->assertSame(['ملف غامض.pdf'], $in($unsorted));
        $this->assertSame([DocumentRequest::UPLOADED, true], [$unsorted->status, $unsorted->optional]);
        $this->assertTrue(ClientMessage::where('company_id', $company->id)->whereNull('document_request_id')->where('body', 'like', '%: 4 ملف، وُضع%')->exists());
        // The files live on the private disk, and nothing was left in the temporary folder.
        Storage::disk('local')->assertExists(ClientFile::where('original_name', 'السجل التجاري.pdf')->value('path'));
        $this->assertSame([], glob(storage_path('app/private/tmp-archives/*')) ?: []);
        // The page says what happened.
        $this->get('/portal')->assertOk()->assertSee('ملفاتي.zip')->assertSee('4 ملف: 3 في طلباتها و1 بلا تصنيف');
    }

    public function test_the_content_review_files_what_the_names_could_not_place(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $payables = DocumentRequest::where('company_id', $company->id)->where('number', 45)->first();
        // The names say nothing about «غامض»; its content is the payables' detail.
        $this->fakeAi(['ملف غامض.pdf' => $this->doc(['document_type' => 'بيان الذمم الدائنة', 'matching_request_ids' => [$payables->id], 'company_name' => null, 'registration_number' => null])]);
        $this->actingAs($login);
        $this->post('/portal/archive', ['archive' => $this->zip(['ملف غامض.pdf' => '%PDF-1.4 X']), 'year' => 2025])->assertRedirect();

        $file = ClientFile::where('original_name', 'ملف غامض.pdf')->first();
        $this->assertSame($payables->id, $file->document_request_id);   // moved by the content review
        $this->assertSame(DocumentRequest::ACCEPTED, $payables->fresh()->status);
        $unsorted = DocumentRequest::where('company_id', $company->id)->where('paper', 'unsorted')->first();
        $this->assertSame(DocumentRequest::NOT_APPLICABLE, $unsorted->status);   // empty again: nothing to do
    }

    public function test_an_archive_that_cannot_be_used_says_why(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $this->actingAs($login);
        $this->bindAi();
        $status = fn () => \App\Models\ClientArchive::orderByDesc('id')->first();

        // Nothing acceptable inside; not an archive; too many files; a rar with no tool to unpack it.
        $this->post('/portal/archive', ['archive' => $this->zip(['برنامج.exe' => 'MZ']), 'year' => 2025])->assertRedirect();
        $this->assertSame(['failed', 'empty'], [$status()->status, $status()->error]);
        $this->post('/portal/archive', ['archive' => UploadedFile::fake()->createWithContent('x.zip', 'not a zip'), 'year' => 2025])->assertRedirect();
        $this->assertSame(['failed', 'unreadable'], [$status()->status, $status()->error]);
        config(['portal.archive_max_files' => 2]);
        $this->post('/portal/archive', ['archive' => $this->zip(['a.pdf' => '1', 'b.pdf' => '2', 'c.pdf' => '3']), 'year' => 2025])->assertRedirect();
        $this->assertSame(['failed', 'too_many_files'], [$status()->status, $status()->error]);
        config(['portal.archive_max_files' => 150, 'portal.archive_tool' => '/nonexistent/7z']);
        $this->post('/portal/archive', ['archive' => UploadedFile::fake()->createWithContent('y.rar', 'Rar!'), 'year' => 2025])->assertRedirect();
        $this->assertSame(['failed', 'no_tool'], [$status()->status, $status()->error]);
        $this->get('/portal')->assertSee('أداة فك هذا النوع من الملفات');

        // Only what we unpack, only a year the client has requests for, only the client's own company.
        $this->post('/portal/archive', ['archive' => UploadedFile::fake()->createWithContent('a.exe', 'MZ'), 'year' => 2025])->assertSessionHasErrors('archive');
        $this->post('/portal/archive', ['archive' => $this->zip(['a.pdf' => '1']), 'year' => 1999])->assertSessionHasErrors('year');
        $this->assertSame(4, \App\Models\ClientArchive::count());
    }

    public function test_the_office_sends_a_clients_files_in_one_compressed_file_too(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company] = $this->client($office, $sara);
        $this->actingAs($sara);
        $this->post("/clients/{$company->id}/archive", ['archive' => $this->zip(['ميزان المراجعة.xlsx' => $this->xlsxBytes('TB')]), 'year' => 2025])->assertRedirect();

        $tb = DocumentRequest::where('company_id', $company->id)->where('title', 'like', '%ميزان مراجعة معتمد%')->first();
        $file = $tb->files()->first();
        $this->assertSame(['ميزان المراجعة.xlsx', false], [$file->original_name, $file->from_client]);   // the office's own: nothing asked of the client
        $this->assertSame(DocumentRequest::PENDING, $tb->fresh()->status);
        $this->get("/clients/{$company->id}")->assertOk()->assertSee('ارفع جميع ملفاتك في ملف واحد');
        // Another office never reaches it; a viewer cannot.
        $this->actingAs($this->staff($office, 'viewer', ['clients.view']));
        $this->post("/clients/{$company->id}/archive", ['archive' => $this->zip(['a.pdf' => '1']), 'year' => 2025])->assertForbidden();
        $this->actingAs($this->staff($this->office('beta'), 'omar'));
        $this->post("/clients/{$company->id}/archive", ['archive' => $this->zip(['a.pdf' => '1']), 'year' => 2025])->assertNotFound();
    }

    public function test_the_requests_follow_the_companys_fiscal_period(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $this->actingAs($this->staff($office));
        $this->bindAi();
        // A year from 1 July to 30 June: the checklist for 2025 is for 1 July 2024 to 30 June 2025, and its titles say so.
        $this->post('/clients', $this->clientForm('شركة النور', 'alnoor', ['period_start' => '2024-07-01', 'period_end' => '2025-06-30']) + ['checklist' => 1, 'year' => 2025])->assertRedirect();
        $company = Company::first();
        $request = DocumentRequest::where('company_id', $company->id)->where('number', 45)->first();
        $this->assertSame(['2024-07-01', '2025-06-30', 2025], [$request->period_start->toDateString(), $request->period_end->toDateString(), $request->year]);
        $this->assertSame('بيان تفصيلي (تحليل) للذمم الدائنة في 30 يونيو 2025', $request->title);
        $this->get(route('clients.show', $company))->assertSee('من 1 يوليو 2024 إلى 30 يونيو 2025');
        // Every request of the year carries the period, the papers too.
        $this->assertSame(0, DocumentRequest::where('company_id', $company->id)->whereNull('period_end')->count());
    }
    public function test_a_client_uploads_and_the_accountant_reviews(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $request = DocumentRequest::where('company_id', $company->id)->where('number', 1)->first();

        $this->actingAs($login);
        $this->post("/portal/requests/{$request->id}/files", ['files' => [UploadedFile::fake()->create('tb.xlsx', 40, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')]])
            ->assertRedirect()->assertSessionHas('status', 'تم رفع الملف.');
        $file = ClientFile::where("from_client", true)->first();
        $this->assertSame([DocumentRequest::UPLOADED, 'tb.xlsx', true, $company->id], [$request->fresh()->status, $file->original_name, $file->from_client, $file->company_id]);
        Storage::disk('local')->assertExists($file->path);
        $this->get("/portal/files/{$file->id}")->assertOk()->assertDownload('tb.xlsx');

        // Only what the checklist allows is taken: no scripts, nothing too big.
        $this->post("/portal/requests/{$request->id}/files", ['files' => [UploadedFile::fake()->create('run.php', 1)]])->assertSessionHasErrors('files.0');
        $this->post("/portal/requests/{$request->id}/files", ['files' => [UploadedFile::fake()->create('big.pdf', config('portal.max_file_kb') + 1, 'application/pdf')]])->assertSessionHasErrors('files.0');
        $this->assertSame(1, ClientFile::where("from_client", true)->count());

        // The accountant sees it, downloads it, sends it back with a note: the client is told in the chat and uploads again.
        $this->actingAs($sara);
        $this->get(route('clients.show', $company))->assertSee('tb.xlsx')->assertSee('تم الرفع - بانتظار المراجعة');
        $this->get(route('clients.files.download', [$company, $file]))->assertOk()->assertDownload('tb.xlsx');
        $url = route('clients.requests.review', [$company, $request]);
        $this->put($url, ['status' => 'returned'])->assertSessionHasErrors('note');
        $this->put($url, ['status' => 'uploaded'])->assertSessionHasErrors('status');
        $this->put($url, ['status' => 'returned', 'note' => 'الميزان ناقص شهر ديسمبر'])->assertRedirect();
        $this->assertSame([DocumentRequest::RETURNED, 'الميزان ناقص شهر ديسمبر'], [$request->fresh()->status, $request->fresh()->note]);

        $this->actingAs($login);
        $this->get('/portal')->assertSee('مطلوب تعديل')->assertSee('الميزان ناقص شهر ديسمبر');
        $this->getJson('/portal/messages?request='.$request->id)->assertOk()->assertJsonFragment(['body' => 'مطلوب تعديل: الميزان ناقص شهر ديسمبر', 'mine' => false])->assertJsonPath('unread', []);
        $this->post("/portal/requests/{$request->id}/files", ['files' => [UploadedFile::fake()->create('tb2.xlsx', 41)]])->assertRedirect();
        $this->assertSame(DocumentRequest::UPLOADED, $request->fresh()->status);

        $this->actingAs($sara);
        $this->put($url, ['status' => 'accepted'])->assertRedirect();
        $this->assertSame([DocumentRequest::ACCEPTED, null], [$request->fresh()->status, $request->fresh()->note]);

        // Once accepted the client can no longer remove their file or say it doesn't apply.
        $this->actingAs($login);
        $this->delete("/portal/files/{$file->id}")->assertForbidden();
        $this->post("/portal/requests/{$request->id}/declare", ['not_applicable' => 1])->assertSessionHas('error_status');
        $this->assertSame(DocumentRequest::ACCEPTED, $request->fresh()->status);
    }

    public function test_a_client_can_say_a_document_does_not_apply_and_take_it_back(): void
    {
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $request = DocumentRequest::where('company_id', $company->id)->where('number', 9)->first();   // a conditional line («إن وجد»)

        $this->actingAs($login);
        $this->post("/portal/requests/{$request->id}/declare", ['not_applicable' => 1])->assertSessionHas('status');
        $this->assertSame(DocumentRequest::NOT_APPLICABLE, $request->fresh()->status);
        $this->post("/portal/requests/{$request->id}/declare", ['not_applicable' => 0])->assertSessionHas('status');
        $this->assertSame(DocumentRequest::PENDING, $request->fresh()->status);
    }

    public function test_a_client_reaches_only_their_own_company_and_files(): void
    {
        Storage::fake('local');
        $a = $this->office('alpha');
        $b = $this->office('beta');
        $sara = $this->staff($a);
        $omar = $this->staff($b, 'omar');
        [$mine, $me] = $this->client($a, $sara);
        [$sameOffice, $other] = $this->client($a, $sara, 'شركة الأمل', 'alamal');
        [$elsewhere] = $this->client($b, $omar, 'شركة بيتا', 'beta1');
        $this->actingAs($sara);
        $theirRequest = DocumentRequest::where('company_id', $sameOffice->id)->first();
        $this->actingAs($other);
        $this->post("/portal/requests/{$theirRequest->id}/files", ['files' => [UploadedFile::fake()->create('secret.pdf', 5, 'application/pdf')]])->assertRedirect();
        $file = ClientFile::where("from_client", true)->first();

        $this->actingAs($me);
        $this->get("/portal/files/{$file->id}")->assertNotFound();
        $this->delete("/portal/files/{$file->id}")->assertNotFound();
        $this->post("/portal/requests/{$theirRequest->id}/files", ['files' => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')]])->assertNotFound();
        $this->post("/portal/requests/{$theirRequest->id}/declare", ['not_applicable' => 1])->assertNotFound();
        $this->getJson('/portal/messages?request='.$theirRequest->id)->assertNotFound();
        $this->get("/clients/{$sameOffice->id}")->assertForbidden();

        // Another office's accountant: not found, never forbidden-but-exists.
        $this->actingAs($omar);
        $this->get(route('clients.files.download', [$sameOffice, $file]))->assertNotFound();
        $this->get(route('clients.show', $mine))->assertNotFound();
        $this->get(route('clients.files.download', [$elsewhere, $file]))->assertNotFound();   // right office's company, wrong company's file
    }

    public function test_the_chat_is_shared_by_the_two_sides_and_counts_what_is_unread(): void
    {
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara);
        $request = DocumentRequest::where('company_id', $company->id)->first();

        $this->actingAs($login);
        $this->postJson('/portal/messages', ['body' => 'السلام عليكم'])->assertCreated();
        $this->postJson('/portal/messages', ['body' => 'عن الميزان', 'request' => $request->id])->assertCreated();
        $this->postJson('/portal/messages', ['body' => '   '])->assertUnprocessable();

        $this->actingAs($sara);
        $this->get('/clients')->assertSee('2 رسالة جديدة');
        // The general thread and a request's thread are apart; reading one marks only it read.
        $this->getJson("/clients/{$company->id}/messages")->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'السلام عليكم')
            ->assertJsonPath('messages.0.mine', false)->assertJsonPath('unread.'.$request->id, 1);
        $this->postJson("/clients/{$company->id}/messages", ['body' => 'أهلاً، ارفع الميزان'])->assertCreated();
        $this->getJson("/clients/{$company->id}/messages?request={$request->id}")->assertJsonPath('messages.0.body', 'عن الميزان')->assertJsonPath('unread', []);

        $this->actingAs($login);
        $this->getJson('/portal/messages')->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.body', 'أهلاً، ارفع الميزان')->assertJsonPath('messages.1.mine', false)->assertJsonPath('messages.0.mine', true);
        $this->getJson('/portal/messages?after='.ClientMessage::where('body', 'السلام عليكم')->value('id'))->assertJsonCount(1, 'messages');
        $this->assertSame(0, ClientMessage::where('from_client', false)->whereNull('read_at')->count());
        // A request of another company is no thread of this one.
        $other = Company::create(['name_ar' => 'شركة تانية', 'office_id' => $office->id]);
        $foreign = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $other->id, 'year' => 2025, 'section' => 'عامة', 'title' => 'x']);
        $this->postJson('/portal/messages', ['body' => 'x', 'request' => $foreign->id])->assertNotFound();
    }

    public function test_only_the_manage_permission_changes_a_clients_requests(): void
    {
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company] = $this->client($office, $sara);
        $viewer = $this->staff($office, 'omar', ['clients.view']);
        $request = DocumentRequest::where('company_id', $company->id)->first();

        $this->actingAs($viewer);
        $this->get('/clients')->assertOk()->assertDontSee('عميل جديد');
        $this->get(route('clients.show', $company))->assertOk();
        $this->get('/clients/new')->assertForbidden();
        $this->put(route('clients.requests.review', [$company, $request]), ['status' => 'accepted'])->assertForbidden();
        $this->delete(route('clients.requests.destroy', [$company, $request]))->assertForbidden();
        $this->postJson("/clients/{$company->id}/messages", ['body' => 'x'])->assertForbidden();
        $this->getJson("/clients/{$company->id}/messages")->assertOk();
    }

    public function test_the_accountant_adds_and_removes_requests_and_changes_the_login(): void
    {
        Storage::fake('local');
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        [$company, $login] = $this->client($office, $sara, checklist: false);
        $this->assertSame(0, DocumentRequest::count());

        $this->post("/clients/{$company->id}/requests", ['year' => 2025, 'section' => 'عامة', 'title' => 'صورة الرخصة', 'format' => 'pdf', 'optional' => 1])->assertRedirect();
        $request = DocumentRequest::first();
        $this->assertSame(['صورة الرخصة', 'pdf', true, null], [$request->title, $request->format, $request->optional, $request->number]);
        $this->post("/clients/{$company->id}/requests/{$request->id}/files", ['files' => [UploadedFile::fake()->create('a.pdf', 3, 'application/pdf')]])->assertRedirect();
        $file = ClientFile::where("from_client", false)->first();
        $this->assertFalse($file->from_client);
        $this->assertSame(DocumentRequest::PENDING, $request->fresh()->status);   // the office's own upload asks nothing of the client
        $path = $file->path;
        $this->delete("/clients/{$company->id}/requests/{$request->id}")->assertRedirect();
        $this->assertSame([0, 0], [DocumentRequest::count(), ClientFile::count()]);
        Storage::disk('local')->assertMissing($path);

        // A new password signs the client in with it; switching the login off locks them out.
        $this->put("/clients/{$company->id}/access", ['password' => 'NewPass2025'])->assertRedirect()->assertSessionHas('credentials');
        $this->signOut();
        $this->post('/login', ['office_code' => 'alpha', 'username' => 'alnoor', 'password' => 'Client2025'])->assertSessionHasErrors('username');
        $this->post('/login', ['office_code' => 'alpha', 'username' => 'alnoor', 'password' => 'NewPass2025'])->assertRedirect('/');
        $this->post('/logout');
        $this->actingAs($sara);
        $this->put("/clients/{$company->id}/access", ['active' => 0])->assertRedirect();
        $this->assertSame(User::DISABLED, $login->fresh()->status);
        $this->signOut();
        $this->post('/login', ['office_code' => 'alpha', 'username' => 'alnoor', 'password' => 'NewPass2025'])->assertSessionHasErrors('username');
    }

    public function test_a_company_made_elsewhere_can_be_given_a_portal_login(): void
    {
        $office = $this->office('alpha');
        $sara = $this->staff($office);
        $this->actingAs($sara);
        $company = Company::create(['name_ar' => 'شركة قديمة']);
        $this->get('/clients')->assertSee('بلا حساب دخول');

        $this->put("/clients/{$company->id}/access", ['username' => 'old.co', 'password' => 'Client2025'])->assertRedirect()->assertSessionHas('credentials');
        $this->assertSame(1, User::where('company_id', $company->id)->count());
    }

    public function test_roles_and_offices_belong_to_the_platform_office(): void
    {
        $platform = $this->office('plat', true);
        $a = $this->office('alpha');
        $boss = $this->staff($platform, 'boss');
        $sara = $this->staff($a);   // an admin role, but of an ordinary office

        $this->actingAs($sara);
        $this->get('/admin/users')->assertOk()->assertSee('alpha');
        $this->get('/admin/roles')->assertForbidden();
        $this->get('/admin/offices')->assertForbidden();
        $this->post('/admin/offices', [])->assertForbidden();

        $this->actingAs($boss);
        $this->get('/admin/roles')->assertOk();
        $this->get('/admin/offices')->assertOk()->assertSee('alpha')->assertSee('plat');
        $this->post('/admin/offices', ['name_ar' => 'مكتب جديد', 'code' => 'Gamma-1', 'admin_name' => 'خالد', 'admin_username' => 'khaled', 'admin_password' => 'secret123'])->assertRedirect();
        $gamma = Office::byCode('gamma-1');
        $this->assertNotNull($gamma);
        $this->assertSame([true], [User::where(['office_id' => $gamma->id, 'username' => 'khaled'])->first()->isAdmin()]);
        $this->post('/admin/offices', ['name_ar' => 'x', 'code' => 'alpha', 'admin_name' => 'y', 'admin_username' => 'yy', 'admin_password' => 'secret123'])->assertSessionHasErrors('code');
        $this->post('/admin/offices', ['name_ar' => 'x', 'code' => 'Bad Code', 'admin_name' => 'y', 'admin_username' => 'yy', 'admin_password' => 'secret123'])->assertSessionHasErrors('code');
        $this->put("/admin/offices/{$platform->id}", ['status' => 'disabled'])->assertSessionHasErrors('status');
        $this->put("/admin/offices/{$a->id}", ['status' => 'disabled'])->assertRedirect();
        $this->assertSame(Office::DISABLED, $a->fresh()->status);
    }

    public function test_the_users_page_is_the_offices_own_staff(): void
    {
        $a = $this->office('alpha');
        $b = $this->office('beta');
        $sara = $this->staff($a);
        $omar = $this->staff($b, 'omar');
        [$company, $login] = $this->client($a, $sara);
        $this->actingAs($sara);

        $this->get('/admin/users')->assertOk()->assertSee('data-user="sara"', false)->assertDontSee('data-user="omar"', false)->assertDontSee('data-user="alnoor"', false);
        $this->put(route('admin.users.update', $omar), ['role_id' => $omar->role_id, 'status' => 'active'])->assertNotFound();
        $this->put(route('admin.users.password', $omar), ['password' => 'Hacked2025'])->assertNotFound();
        $this->put(route('admin.users.password', $login), ['password' => 'Hacked2025'])->assertNotFound();   // a client's login is changed from the client's page
        $this->put(route('admin.users.update', $sara), ['role_id' => Role::client()->id, 'status' => 'active'])->assertSessionHasErrors('role_id');   // no staff member becomes a client
    }

    public function test_the_office_commands_make_an_office_and_its_admin(): void
    {
        $this->artisan('office:create', ['code' => 'delta', 'name' => 'مكتب دلتا'])->assertSuccessful();
        $this->artisan('office:create', ['code' => 'delta', 'name' => 'مكرر'])->assertFailed();
        $this->artisan('users:create-admin', ['username' => 'dana', '--office' => 'delta', '--password' => 'secret123'])->assertSuccessful();
        $this->artisan('users:create-admin', ['username' => 'dana', '--office' => 'nope', '--password' => 'secret123'])->assertFailed();

        $delta = Office::byCode('delta');
        $this->assertTrue(User::where(['office_id' => $delta->id, 'username' => 'dana'])->first()->isAdmin());
    }
}
