<?php

namespace Tests\Feature;

use App\Models\ClientEmail;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\MailAccount;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The accountant's own mailbox: entered after signing in, tested, kept encrypted, and used for the clients' emails. */
class MailAccountTest extends TestCase
{
    use RefreshDatabase;

    /** What the mailer was asked to send, and with which SMTP settings; $fail makes the server refuse. */
    public array $sent = [];
    public array $configs = [];
    public ?string $fail = null;

    private function fakeSmtp(): void
    {
        $this->app->bind('portal.mailer', function ($app, array $p) {
            $this->configs[] = $p['config'];

            return new class($this)
            {
                public function __construct(private MailAccountTest $t) {}

                public function raw(string $body, \Closure $callback): void
                {
                    $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
                    $callback($message);
                    $m = $message->getSymfonyMessage();
                    if ($this->t->fail !== null) {
                        throw new \RuntimeException($this->t->fail);
                    }
                    $this->t->sent[] = ['to' => $m->getTo()[0]->getAddress(), 'from' => $m->getFrom()[0]->getAddress(), 'reply' => isset($m->getReplyTo()[0]) ? $m->getReplyTo()[0]->getAddress() : null,
                        'subject' => $m->getSubject(), 'body' => $body, 'id' => $m->getHeaders()->has('Message-ID')];
                }
            };
        });
    }

    private function staff(string $username = 'sara', ?array $permissions = null): User
    {
        $office = Office::firstOrCreate(['code' => 'alpha'], ['name_ar' => 'مكتب']);
        $role = Role::create(['name_ar' => 'محاسب '.$username, 'is_admin' => $permissions === null]);
        $permissions !== null && $role->syncPermissions($permissions);

        return User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => $username, 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
    }

    private function form(array $over = []): array
    {
        return $over + ['provider' => 'gmail', 'from_address' => 'sara@gmail.test', 'from_name' => 'سارة', 'host' => 'smtp.gmail.com', 'port' => 587, 'security' => 'tls', 'username' => 'sara@gmail.test', 'password' => 'abcd efgh ijkl mnop'];
    }

    public function test_the_accountant_saves_the_mailbox_and_it_is_tested_and_kept_encrypted(): void
    {
        $this->fakeSmtp();
        $sara = $this->staff();
        $this->actingAs($sara);

        $this->get(route('account.mail'))->assertOk()->assertSee('smtp.gmail.com')->assertSee('Gmail');
        $this->post(route('account.mail.save'), $this->form())->assertRedirect()->assertSessionHas('status');

        $account = MailAccount::where('user_id', $sara->id)->first();
        $this->assertTrue($account->last_test_ok);
        $this->assertSame('abcdefghijklmnop', $account->password);                                          // read back by the model
        $this->assertStringNotContainsString('abcd', (string) \DB::table('mail_accounts')->value('password'));   // encrypted at rest
        $this->assertSame(['smtp', 'smtp.gmail.com', 587], [$this->configs[0]['scheme'], $this->configs[0]['host'], $this->configs[0]['port']]);
        $this->assertSame('abcdefghijklmnop', $this->configs[0]['password']);
        $this->assertSame(['sara@gmail.test', 'sara@gmail.test'], [$this->sent[0]['to'], $this->sent[0]['from']]);   // the test goes to themselves
        $page = $this->get(route('account.mail'))->assertOk()->getContent();
        $this->assertStringNotContainsString('abcd efgh', $page);                                              // the password is never shown again
        $this->assertStringContainsString(__('mail_account.works'), $page);

        // A new port needs the password typed again; with it, 465 is implicit TLS.
        $this->post(route('account.mail.save'), $this->form(['port' => 465, 'security' => 'ssl', 'password' => null]))->assertSessionHasErrors('password');
        $this->post(route('account.mail.save'), $this->form(['port' => 465, 'security' => 'ssl']))->assertSessionHas('status');
        $this->assertSame('smtps', end($this->configs)['scheme']);
        // Left empty when only the name changes: the saved password stays.
        $this->post(route('account.mail.save'), $this->form(['port' => 465, 'security' => 'ssl', 'from_name' => 'سارة أحمد', 'password' => '']))->assertSessionHas('status');
        $this->assertSame('abcdefghijklmnop', $account->fresh()->password);
    }

    public function test_a_refused_login_says_what_to_do_and_is_remembered(): void
    {
        $this->fakeSmtp();
        $this->fail = 'Failed to authenticate on SMTP server. Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted".';
        $this->actingAs($this->staff());

        $this->post(route('account.mail.save'), $this->form())->assertRedirect()->assertSessionHas('mail_error', __('mail_account.err_auth'));

        $account = MailAccount::first();
        $this->assertFalse($account->last_test_ok);
        $this->assertSame(__('mail_account.err_auth'), $account->last_error);
        $this->fail = 'Connection could not be established with host "smtp.x.test:587": Unable to connect (Connection timed out)';
        $this->post(route('account.mail.test'))->assertSessionHas('mail_error', __('mail_account.err_connect'));
    }

    public function test_validation_and_who_may_open_it(): void
    {
        $this->fakeSmtp();
        $this->actingAs($this->staff());
        $this->post(route('account.mail.save'), $this->form(['from_address' => 'not-an-email', 'host' => 'bad host!', 'port' => 99, 'password' => '']))->assertSessionHasErrors(['from_address', 'host', 'port', 'password']);
        $this->assertSame(0, MailAccount::count());

        $this->actingAs($this->staff('viewer', ['clients.view']));
        $this->get(route('account.mail'))->assertForbidden();
        $this->post(route('account.mail.save'), $this->form())->assertForbidden();

        // Each accountant has their own: another's is never shown or tested.
        $omar = $this->staff('omar', ['clients.manage']);
        MailAccount::create($this->form(['user_id' => $omar->id]));
        $this->actingAs($this->staff('mona', ['clients.manage']));
        $this->get(route('account.mail'))->assertOk()->assertDontSee('sara@gmail.test');
        $this->post(route('account.mail.test'))->assertNotFound();
    }

    public function test_the_clients_email_goes_from_the_senders_own_mailbox_and_the_reply_comes_back_to_it(): void
    {
        $this->fakeSmtp();
        config(['mail.default' => 'log']);   // no system mailbox: only the accountant's own works
        $sara = $this->staff();
        $company = Company::forceCreate(['office_id' => $sara->office_id, 'name_ar' => 'شركة النور', 'client_name' => 'أحمد', 'client_email' => 'ahmed@client.test', 'cr_number' => '1', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        $request = DocumentRequest::create(['office_id' => $sara->office_id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'ع', 'number' => 1, 'title' => 'عقود القروض', 'position' => 1]);
        $this->actingAs($sara);
        $payload = ['year' => 2025, 'subject' => 'مستندات ناقصة', 'body' => 'السادة', 'requests' => [$request->id]];

        // No mailbox and none for the system: refused, and the page says where to set it up.
        $this->post(route('clients.follow-up.send', $company), $payload)->assertSessionHasErrors('follow_up');
        $this->assertSame(0, ClientEmail::withoutGlobalScopes()->count());
        $this->get(route('clients.show', $company))->assertSee(route('account.mail'), false);

        MailAccount::create($this->form(['user_id' => $sara->id]));
        $this->sent = [];
        $this->post(route('clients.follow-up.send', $company), $payload)->assertRedirect()->assertSessionHas('status');
        $this->assertSame(['ahmed@client.test', 'sara@gmail.test', 'sara@gmail.test', true], [$this->sent[0]['to'], $this->sent[0]['from'], $this->sent[0]['reply'], $this->sent[0]['id']]);
        $email = ClientEmail::withoutGlobalScopes()->first();
        $this->assertSame([$sara->id, 'sent'], [$email->sent_by, $email->status]);

        // A reminder goes from the mailbox of whoever sent the first email, even with nobody signed in (the scheduler).
        config(['portal.mail.auto_remind' => true, 'portal.mail.remind_after_days' => 1]);
        \Illuminate\Support\Facades\Auth::logout();
        $this->travel(2)->days();
        $this->assertSame(1, app(\App\Services\Portal\FollowUp::class)->remind());
        $this->assertSame('sara@gmail.test', end($this->sent)['from']);

        // A refused login is kept on the email as failed, in words.
        $this->actingAs($sara);
        $this->fail = 'Expected response code "235" but got code "535"';
        $this->post(route('clients.follow-up.send', $company), $payload)->assertSessionHasErrors('follow_up');
        $this->assertSame(__('mail_account.err_auth'), ClientEmail::withoutGlobalScopes()->where('status', 'failed')->value('error'));
    }
}
