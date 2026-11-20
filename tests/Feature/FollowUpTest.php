<?php

namespace Tests\Feature;

use App\Models\ClientEmail;
use App\Models\ClientFile;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The AI finds what is missing, writes the email, and reads the reply; the office sends and nothing goes without a click. */
class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private array $answers = [];

    private function world(): array
    {
        Storage::fake('local');
        Mail::fake();
        config(['portal.ai_review' => 'off', 'portal.mail.inbound_secret' => 's3cret', 'portal.mail.auto_remind' => true, 'portal.mail.remind_after_days' => 3, 'portal.mail.max_reminders' => 2]);
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'محاسب', 'is_admin' => true]);
        $sara = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'client_name' => 'أحمد السالم', 'client_email' => 'ahmed@client.test', 'cr_number' => '1010123456',
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        User::create(['office_id' => $office->id, 'company_id' => $company->id, 'name' => 'أحمد', 'username' => 'noor', 'password' => 'secret123',
            'role_id' => Role::create(['name_ar' => 'عميل', 'is_client' => true])->id, 'status' => User::ACTIVE]);
        $loans = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'القروض', 'number' => 1, 'title' => 'عقود القروض', 'position' => 1]);
        $bank = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'النقدية', 'number' => 2, 'title' => 'كشف حساب البنك', 'position' => 2, 'status' => 'returned', 'note' => 'الكشف ناقص صفحة']);
        DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'عامة', 'number' => 3, 'title' => 'الهيكل الإداري', 'position' => 3, 'status' => 'accepted']);

        $test = $this;
        $this->app->instance(ClassifierDriver::class, new class($test) implements ClassifierDriver
        {
            public array $calls = [];

            public function __construct(private FollowUpTest $t) {}

            public function classify(ToolCall $call): PromiseInterface
            {
                $this->calls[] = $call;

                return Create::promiseFor(new ToolAnswer($this->t->answerFor($call->tool['name']), null));
            }
        });

        return [$office, $sara, $company, $loans, $bank];
    }

    public function answerFor(string $tool): array
    {
        return $this->answers[$tool];
    }

    public function test_the_ai_writes_the_email_from_what_is_missing_and_the_office_sends_it(): void
    {
        [, $sara, $company, $loans, $bank] = $this->world();
        $this->answers['submit_follow_up'] = ['subject' => 'مستندات ناقصة للسنة المالية 2025', 'intro' => 'نرجو إرسال المستندات الآتية.', 'questions' => ['ما سبب رصيد القرض؟'],
            // The AI names an invented id too: it is dropped, and the bank statement it did not mention stays in the list.
            'items' => [['id' => $loans->id, 'reason' => 'يظهر رصيد قرض في ميزان المراجعة'], ['id' => 99999, 'reason' => 'مختلق']]];
        $this->actingAs($sara);

        $page = $this->post(route('clients.follow-up.draft', $company), ['year' => 2025])->assertOk()->getContent();

        $this->assertStringContainsString('عقود القروض — يظهر رصيد قرض في ميزان المراجعة', $page);
        $this->assertStringContainsString('كشف حساب البنك', $page);
        $this->assertStringContainsString('الكشف ناقص صفحة', $page);
        $this->assertStringContainsString('ما سبب رصيد القرض؟', $page);
        $this->assertStringNotContainsString('الهيكل الإداري', $page);   // accepted: not missing
        $this->assertStringNotContainsString('مختلق', $page);
        Mail::assertNothingSent();   // reading is not sending

        $this->post(route('clients.follow-up.send', $company), ['year' => 2025, 'subject' => 'مستندات ناقصة للسنة المالية 2025', 'body' => "السادة\n1. عقود القروض\n[········]", 'requests' => [$loans->id, $bank->id]])
            ->assertRedirect()->assertSessionHas('status');
        $email = ClientEmail::withoutGlobalScopes()->where('direction', 'out')->first();
        $this->assertSame(['ahmed@client.test', 'sent'], [$email->address, $email->status]);
        $this->assertMatchesRegularExpression('/\[[A-Z0-9]{8}\]$/', $email->subject);
        $this->assertTrue(ClientMessage::withoutGlobalScopes()->where('company_id', $company->id)->where('kind', 'event')->where('body', 'like', '%ahmed@client.test%')->exists());
    }

    public function test_a_client_with_nothing_missing_or_no_address_gets_no_email(): void
    {
        [, $sara, $company] = $this->world();
        $this->actingAs($sara);
        DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->update(['status' => 'accepted']);
        $this->post(route('clients.follow-up.draft', $company), ['year' => 2025])->assertRedirect()->assertSessionHas('status', __('follow_up.nothing_missing'));

        DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->update(['status' => 'pending']);
        $company->update(['client_email' => null]);
        $this->answers['submit_follow_up'] = ['subject' => 's', 'intro' => 'i', 'items' => [], 'questions' => []];
        $this->post(route('clients.follow-up.send', $company), ['year' => 2025, 'subject' => 's', 'body' => 'b', 'requests' => [1]])->assertSessionHasErrors('follow_up');
        $this->assertSame(0, ClientEmail::withoutGlobalScopes()->count());
    }

    private function sentEmail(Company $company, $loans, $bank): ClientEmail
    {
        return app(\App\Services\Portal\FollowUp::class)->send($company, 2025, 'مستندات ناقصة', "السادة\n1. عقود القروض", [$loans->id, $bank->id], null);
    }

    public function test_a_reply_is_matched_read_by_the_ai_and_its_files_go_to_the_requests(): void
    {
        [, , $company, $loans, $bank] = $this->world();
        $out = $this->sentEmail($company, $loans, $bank);
        $this->answers['submit_reply_reading'] = ['summary' => 'أرفق العميل عقد القرض وسيرسل الكشف لاحقًا.', 'needs_accountant' => false, 'files' => [['file_name' => 'عقد القرض.pdf', 'request_id' => $loans->id, 'confident' => true]], 'answers' => [
            ['request_id' => $loans->id, 'state' => 'attached', 'note' => null], ['request_id' => $bank->id, 'state' => 'will_send_later', 'note' => 'الأسبوع القادم'], ['request_id' => 424242, 'state' => 'question', 'note' => 'x']]];
        $file = UploadedFile::fake()->createWithContent('عقد القرض.pdf', '%PDF-1.4 loan');

        $this->post('/mail/inbound', ['from' => 'أحمد <ahmed@client.test>', 'subject' => 'Re: '.$out->subject, 'text' => "مرفق عقد القرض.\nوالكشف الأسبوع القادم.\n\nOn Mon, 17 Nov wrote:\n> السادة", 'attachments' => [$file]],
            ['X-Inbound-Secret' => 's3cret'])->assertOk()->assertJson(['matched' => true, 'status' => 'processed']);

        $in = ClientEmail::withoutGlobalScopes()->where('direction', 'in')->first();
        $this->assertSame($out->id, $in->parent_id);
        $this->assertStringNotContainsString('السادة', $in->body);   // the quoted original is cut
        $this->assertSame('أرفق العميل عقد القرض وسيرسل الكشف لاحقًا.', $in->analysis['summary']);
        $this->assertCount(2, $in->analysis['answers']);   // the answer about an unknown request is dropped
        $this->assertSame(1, ClientFile::withoutGlobalScopes()->where('document_request_id', $loans->id)->where('from_client', true)->count());
        $this->assertSame(DocumentRequest::UPLOADED, $loans->fresh()->status);
        $this->assertSame(DocumentRequest::RETURNED, $bank->fresh()->status);   // «will send later» changes nothing
        $this->assertTrue(ClientMessage::withoutGlobalScopes()->where('company_id', $company->id)->where('from_client', true)->where('body', 'like', '%مرفق عقد القرض%')->exists());
        $this->assertTrue(ClientMessage::withoutGlobalScopes()->where('document_request_id', $bank->id)->where('body', 'like', '%الأسبوع القادم%')->exists());
    }

    public function test_a_reply_from_another_address_or_without_the_secret_or_unknown_is_not_acted_on(): void
    {
        [, , $company, $loans, $bank] = $this->world();
        $out = $this->sentEmail($company, $loans, $bank);
        $this->answers['submit_reply_reading'] = ['summary' => 's', 'needs_accountant' => false, 'answers' => []];
        $payload = ['from' => 'stranger@evil.test', 'subject' => 'Re: '.$out->subject, 'text' => 'هذا رد'];

        $this->post('/mail/inbound', $payload)->assertNotFound();                                        // no secret
        $this->post('/mail/inbound', $payload, ['X-Inbound-Secret' => 'wrong'])->assertNotFound();
        $this->post('/mail/inbound', $payload, ['X-Inbound-Secret' => 's3cret'])->assertOk()->assertJson(['status' => 'unmatched']);   // not the client's address
        $this->assertSame(0, ClientMessage::withoutGlobalScopes()->where('from_client', true)->count());
        $this->post('/mail/inbound', ['from' => 'ahmed@client.test', 'subject' => 'hello', 'text' => 'x'], ['X-Inbound-Secret' => 's3cret'])->assertOk()->assertJson(['matched' => false]);   // answers nothing of ours
        config(['portal.mail.inbound_secret' => '']);
        $this->post('/mail/inbound', $payload, ['X-Inbound-Secret' => ''])->assertNotFound();           // the webhook is off without a secret
    }

    public function test_when_the_ai_cannot_read_the_reply_the_accountant_is_left_with_it(): void
    {
        [$office, $sara, $company, $loans, $bank] = $this->world();
        $this->sentEmail($company, $loans, $bank);
        $this->app->instance(ClassifierDriver::class, new class implements ClassifierDriver
        {
            public function classify(ToolCall $call): PromiseInterface
            {
                return Create::rejectionFor(new \RuntimeException('down'));
            }
        });
        $this->actingAs($sara);

        $this->post(route('clients.follow-up.reply', $company), ['year' => 2025, 'text' => 'سأرسل لاحقًا'])->assertRedirect();

        $in = ClientEmail::withoutGlobalScopes()->where('direction', 'in')->first();
        $this->assertTrue($in->analysis['needs_accountant']);
        $this->assertTrue(ClientMessage::withoutGlobalScopes()->where('company_id', $company->id)->where('from_client', true)->where('body', 'like', '%سأرسل لاحقًا%')->exists());
        $this->get(route('clients.show', $company))->assertOk()->assertSee(__('follow_up.panel.needs_you'));
    }

    public function test_an_unanswered_email_goes_again_after_some_days_up_to_a_limit_and_stops_once_answered(): void
    {
        [, , $company, $loans, $bank] = $this->world();
        $out = $this->sentEmail($company, $loans, $bank);
        $followUp = app(\App\Services\Portal\FollowUp::class);

        $this->assertSame(0, $followUp->remind());   // too soon
        $this->travel(4)->days();
        $this->assertSame(1, $followUp->remind());
        $this->assertSame(0, $followUp->remind());   // just sent
        $this->travel(4)->days();
        $this->assertSame(1, $followUp->remind());
        $this->travel(4)->days();
        $this->assertSame(0, $followUp->remind());   // the limit (2 reminders)
        $this->assertSame(2, $out->fresh()->reminders);
        $this->assertSame(3, ClientEmail::withoutGlobalScopes()->where('ref', $out->ref)->where('direction', 'out')->count());

        // A reply stops them.
        [, , $company2, $l2, $b2] = [null, null, $company, $loans, $bank];
        config(['portal.mail.max_reminders' => 9]);
        $this->answers['submit_reply_reading'] = ['summary' => 's', 'needs_accountant' => false, 'answers' => []];
        $followUp->receive('ahmed@client.test', 'Re: '.$out->subject, 'تمام', null);
        $this->travel(4)->days();
        $this->assertSame(0, $followUp->remind());
        $this->assertNotNull($company2 && $l2 && $b2);

        // With the switch off nothing goes.
        config(['portal.mail.auto_remind' => false]);
        $this->assertSame(0, $followUp->remind());
    }

    public function test_only_the_manager_follows_up_and_only_in_their_own_office(): void
    {
        [$office, , $company] = $this->world();
        $viewerRole = Role::create(['name_ar' => 'مشاهد']);
        $viewerRole->syncPermissions(['clients.view']);
        $this->actingAs(User::create(['office_id' => $office->id, 'name' => 'م', 'username' => 'viewer', 'password' => 'secret123', 'role_id' => $viewerRole->id, 'status' => User::ACTIVE]));
        $this->post(route('clients.follow-up.draft', $company), ['year' => 2025])->assertForbidden();
        $this->get(route('clients.show', $company))->assertOk()->assertDontSee('data-follow-up', false);

        $other = Office::create(['name_ar' => 'ب', 'code' => 'beta']);
        $this->actingAs(User::create(['office_id' => $other->id, 'name' => 'ع', 'username' => 'omar', 'password' => 'secret123', 'role_id' => Role::create(['name_ar' => 'م', 'is_admin' => true])->id, 'status' => User::ACTIVE]));
        $this->post(route('clients.follow-up.draft', $company), ['year' => 2025])->assertNotFound();
    }
}
