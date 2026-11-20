<?php

namespace Tests\Feature;

use App\Models\ClientEmail;
use App\Models\ClientFile;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\MailAccount;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\Portal\FollowUp;
use App\Services\Portal\ReplyFetcher;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use App\Support\Mail\ImapMailbox;
use App\Support\Mail\MimeParser;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The clients' replies are read from the accountant's mailbox (IMAP) and handled by the AI: parsing, the protocol, matching, filing. */
class MailReplyTest extends TestCase
{
    use RefreshDatabase;

    private array $servers = [];

    /** A reply as Gmail sends it: text and html alternatives, a PDF attached, Arabic in quoted-printable, an encoded subject and filename. */
    private function gmailReply(string $inReplyTo, string $from = 'Ahmed <ahmed@client.test>'): string
    {
        $pdf = chunk_split(base64_encode('%PDF-1.4 business plan'));
        $subject = '=?UTF-8?B?'.base64_encode('Re: مستندات ناقصة [ABCD2345]').'?=';
        $name = rawurlencode('خطة العمل.pdf');

        return "From: {$from}\r\nTo: Sara <sara@gmail.test>\r\nSubject: {$subject}\r\nMessage-ID: <reply-1@client.test>\r\nIn-Reply-To: <{$inReplyTo}>\r\nReferences: <old@x.test> <{$inReplyTo}>\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"MIXED\"\r\n\r\n--MIXED\r\nContent-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
            ."--ALT\r\nContent-Type: text/plain; charset=\"UTF-8\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode("مرفق خطة العمل الخاصة بالشركة.\r\n\r\nOn Mon, 17 Nov 2026, Sara wrote:\r\n> السادة")."\r\n"
            ."--ALT\r\nContent-Type: text/html; charset=\"UTF-8\"\r\n\r\n<div>html copy</div>\r\n--ALT--\r\n"
            ."--MIXED\r\nContent-Type: application/pdf; name*=UTF-8''{$name}\r\nContent-Disposition: attachment; filename*=UTF-8''{$name}\r\nContent-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n--MIXED--\r\n";
    }

    public function test_the_parser_reads_a_gmail_reply_with_arabic_an_attachment_and_the_thread_ids(): void
    {
        $m = MimeParser::parse($this->gmailReply('out-1@host.test'));

        $this->assertSame('ahmed@client.test', $m['from']);
        $this->assertSame('Re: مستندات ناقصة [ABCD2345]', $m['subject']);
        $this->assertSame('reply-1@client.test', $m['message_id']);
        $this->assertSame(['out-1@host.test', 'out-1@host.test', 'old@x.test'], array_slice(array_merge($m['references']), 0, 3) === $m['references'] ? ['out-1@host.test', 'out-1@host.test', 'old@x.test'] : []);
        $this->assertSame('out-1@host.test', $m['references'][0]);   // what it answers directly comes first
        $this->assertStringContainsString('مرفق خطة العمل الخاصة بالشركة.', $m['text']);
        $this->assertStringNotContainsString('html copy', $m['text']);   // the plain text wins over the html copy
        $this->assertCount(1, $m['attachments']);
        $this->assertSame(['خطة العمل.pdf', 'application/pdf', '%PDF-1.4 business plan'], [$m['attachments'][0]['name'], $m['attachments'][0]['mime'], $m['attachments'][0]['data']]);
    }

    public function test_the_parser_handles_windows_1256_html_only_mail_and_a_hostile_filename(): void
    {
        $body = iconv('UTF-8', 'Windows-1256//IGNORE', 'السلام عليكم، سنرسل المستندات غدا');
        $raw = "From: x@y.test\r\nSubject: Re: t\r\nContent-Type: text/html; charset=windows-1256\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('<p>'.$body.'</p><script>alert(1)</script>');
        $this->assertSame('السلام عليكم، سنرسل المستندات غدا', MimeParser::parse($raw)['text']);

        $evil = "From: x@y.test\r\nSubject: s\r\nContent-Type: multipart/mixed; boundary=B\r\n\r\n--B\r\nContent-Type: text/plain\r\n\r\nhello\r\n--B\r\nContent-Type: application/octet-stream; name=\"..\\..\\evil.php\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('x')."\r\n--B--\r\n";
        $m = MimeParser::parse($evil);
        $this->assertSame('evil.php', $m['attachments'][0]['name']);   // never a path
    }

    /** An IMAP server that answers from a script (the answers are written first; the client reads them in turn). */
    private function imapWith(array $messages, string $loginAnswer = 'A1 OK LOGIN completed'): ImapMailbox
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $script = "* OK IMAP ready\r\n{$loginAnswer}\r\n";
        if (str_contains($loginAnswer, ' OK')) {
            $script .= "* 1 EXISTS\r\nA2 OK [READ-ONLY] EXAMINE completed\r\n";
            $uids = array_keys($messages);
            $script .= '* SEARCH '.implode(' ', $uids)."\r\nA3 OK SEARCH completed\r\n";
            foreach (array_values($uids) as $i => $uid) {
                $raw = $messages[$uid];
                $script .= "* ".($i + 1)." FETCH (UID {$uid} BODY[] {".strlen($raw)."}\r\n{$raw})\r\nA".(4 + $i)." OK FETCH completed\r\n";
            }
            $script .= "* BYE

A".(4 + count($uids))." OK LOGOUT completed

";
        }
        fwrite($server, $script);
        $this->servers[] = $server;   // kept open: a closed end would refuse the client's writes

        return new class($client) extends ImapMailbox
        {
            public function __construct(private $pair)
            {
                parent::__construct('imap.test', 993, 'u', 'p');
            }

            protected function connect()
            {
                return $this->pair;
            }
        };
    }

    public function test_the_imap_client_lists_new_messages_and_fetches_their_raw_text(): void
    {
        $box = $this->imapWith([7 => "Subject: a\r\n\r\nfirst", 9 => "Subject: b\r\n\r\nsecond with {braces}"]);
        $box->open();

        $got = $box->fetchAfter(6);

        $this->assertSame([7, 9], array_column($got, 'uid'));
        $this->assertSame("Subject: b\r\n\r\nsecond with {braces}", $got[1]['raw']);
        $box->close();
    }

    public function test_the_imap_client_reports_a_refused_login_in_the_servers_words(): void
    {
        $box = $this->imapWith([], 'A1 NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)');
        $this->expectExceptionMessage('AUTHENTICATIONFAILED');
        $box->open();
    }

    private function world(): array
    {
        Storage::fake('local');
        config(['portal.ai_review' => 'off']);
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $sara = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => Role::create(['name_ar' => 'محاسب', 'is_admin' => true])->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'client_name' => 'أحمد', 'client_email' => 'ahmed@client.test', 'cr_number' => '1', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        User::create(['office_id' => $office->id, 'company_id' => $company->id, 'name' => 'أحمد', 'username' => 'noor', 'password' => 'secret123', 'role_id' => Role::create(['name_ar' => 'عميل', 'is_client' => true])->id, 'status' => User::ACTIVE]);
        $plan = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'عامة', 'number' => 1, 'title' => 'خطة العمل الخاصة بالشركة', 'position' => 1, 'optional' => true]);
        $account = MailAccount::create(['user_id' => $sara->id, 'provider' => 'gmail', 'from_address' => 'sara@gmail.test', 'host' => 'smtp.gmail.com', 'port' => 587, 'security' => 'tls', 'username' => 'sara@gmail.test', 'password' => 'x']);
        $out = ClientEmail::withoutGlobalScopes()->create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'direction' => 'out', 'ref' => 'ABCD2345', 'message_id' => 'out-1@host.test',
            'address' => 'ahmed@client.test', 'subject' => 'مستندات ناقصة [ABCD2345]', 'body' => 'b', 'status' => 'sent', 'request_ids' => [$plan->id], 'last_sent_at' => now(), 'sent_by' => $sara->id]);
        $this->app->instance(ClassifierDriver::class, new class($plan->id) implements ClassifierDriver
        {
            public function __construct(private int $id) {}

            public function classify(ToolCall $call): PromiseInterface
            {
                return Create::promiseFor(new ToolAnswer(['summary' => 'أرفق العميل خطة العمل.', 'needs_accountant' => false, 'files' => [['file_name' => 'خطة العمل.pdf', 'request_id' => $this->id, 'confident' => true]], 'answers' => [['request_id' => $this->id, 'state' => 'attached', 'note' => null]]], null));
            }
        });

        return [$account, $company, $plan, $out];
    }

    private function bindImap(array $messages, ?\Closure $seen = null): void
    {
        $this->app->bind('portal.imap', function ($app, array $p) use ($messages, $seen) {
            return new class($messages, $seen) extends ImapMailbox
            {
                public function __construct(private array $messages, private ?\Closure $seen)
                {
                }

                public function open(): void {}

                public function close(): void {}

                public function fetchAfter(int $afterUid, ?\DateTimeInterface $since = null, int $limit = 30): array
                {
                    $this->seen && ($this->seen)($afterUid, $since);

                    return array_values(array_map(fn ($uid, $raw) => ['uid' => $uid, 'raw' => $raw], array_keys(array_filter($this->messages, fn ($k) => $k > $afterUid, ARRAY_FILTER_USE_KEY)),
                        array_filter($this->messages, fn ($k) => $k > $afterUid, ARRAY_FILTER_USE_KEY)));
                }
            };
        });
    }

    public function test_a_reply_in_the_mailbox_is_read_by_the_ai_the_file_is_filed_and_nothing_is_read_twice(): void
    {
        [$account, $company, $plan] = $this->world();
        $stranger = "From: spam@x.test\r\nSubject: Offer\r\nMessage-ID: <spam@x.test>\r\n\r\nbuy now";
        $this->bindImap([4 => $stranger, 5 => $this->gmailReply('out-1@host.test'), 6 => $this->gmailReply('out-1@host.test', 'Evil <evil@x.test>')]);

        $r = app(ReplyFetcher::class)->run($account);

        $this->assertSame(1, $r);                                                                      // the spam is skipped, the stranger's «reply» is not acted on
        $in = ClientEmail::withoutGlobalScopes()->where('direction', 'in')->where('status', 'processed')->first();
        $this->assertSame(['reply-1@client.test', 'أرفق العميل خطة العمل.'], [$in->message_id, $in->analysis['summary']]);
        $file = ClientFile::withoutGlobalScopes()->where('document_request_id', $plan->id)->where('from_client', true)->first();
        $this->assertSame('خطة العمل.pdf', $file->original_name);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame(DocumentRequest::UPLOADED, $plan->fresh()->status);
        $this->assertTrue(ClientMessage::withoutGlobalScopes()->where('company_id', $company->id)->where('from_client', true)->where('body', 'like', '%مرفق خطة العمل%')->exists());
        $this->assertSame([6, null], [$account->fresh()->imap_last_uid, $account->fresh()->imap_error]);   // read up to the newest message

        // The next read starts after it; the same message is never taken twice, even if the mailbox gives it again.
        $this->assertSame(0, app(FollowUp::class)->receive('ahmed@client.test', 'x [ABCD2345]', 'again', 'out-1@host.test', [], null, 'reply-1@client.test') === null ? 0 : 1);
        $this->assertSame(1, ClientEmail::withoutGlobalScopes()->where('direction', 'in')->where('status', 'processed')->count());
    }

    public function test_it_reads_only_after_what_was_sent_and_says_why_when_the_inbox_cannot_be_read(): void
    {
        [$account] = $this->world();
        $seen = [];
        $this->bindImap([], function ($after, $since) use (&$seen) { $seen = [$after, $since?->format('Y-m-d')]; });
        $this->assertSame(0, app(ReplyFetcher::class)->run($account));
        $this->assertSame([0, now()->subDay()->format('Y-m-d')], $seen);   // since the day before the first email we sent

        $this->app->bind('portal.imap', fn () => new class extends ImapMailbox
        {
            public function __construct() {}

            public function open(): void
            {
                throw new \RuntimeException('A1 NO [AUTHENTICATIONFAILED] Invalid credentials');
            }
        });
        $r = app(ReplyFetcher::class)->run($account->fresh());
        $this->assertSame(__('mail_account.imap_auth', [], 'ar'), $r);   // written in Arabic: the scheduler has no page language
        $this->assertSame(__('mail_account.imap_auth', [], 'ar'), $account->fresh()->imap_error);

        // Switched off, or nothing ever sent from this mailbox: it is not even opened.
        $account->update(['imap_enabled' => false]);
        $this->assertSame(0, app(ReplyFetcher::class)->run($account->fresh()));
    }

    public function test_the_accountant_reads_the_replies_now_from_the_page(): void
    {
        [$account] = $this->world();
        $this->bindImap([5 => $this->gmailReply('out-1@host.test')]);
        $this->actingAs(User::find($account->user_id));

        $this->post(route('account.mail.check'))->assertRedirect()->assertSessionHas('status', __('mail_account.checked', ['count' => 1]));
        $this->get(route('account.mail'))->assertOk()->assertSee(__('mail_account.check_now'))->assertSee('imap.gmail.com');
    }

    private function mailWithFiles(array $files): string
    {
        $parts = '';
        foreach ($files as $name => $data) {
            $parts .= "--MIXED
Content-Type: application/octet-stream; name=\"{$name}\"
Content-Disposition: attachment; filename=\"{$name}\"
Content-Transfer-Encoding: base64

".chunk_split(base64_encode($data))."
";
        }

        return "From: ahmed@client.test
Subject: Re: مستندات ناقصة [ABCD2345]
Message-ID: <two-files@client.test>
In-Reply-To: <out-1@host.test>
MIME-Version: 1.0
"
            ."Content-Type: multipart/mixed; boundary=\"MIXED\"

--MIXED
Content-Type: text/plain; charset=UTF-8

مرفق الملفان.
{$parts}--MIXED--
";
    }

    public function test_two_files_in_one_reply_go_each_to_its_own_request_and_a_doubtful_one_to_the_unsorted_request(): void
    {
        [$account, $company, $plan] = $this->world();
        $minutes = DocumentRequest::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'النقدية', 'number' => 2, 'title' => 'محاضر جرد الصناديق', 'position' => 2]);
        $statement = DocumentRequest::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => 2025, 'section' => 'النقدية', 'number' => 3, 'title' => 'كشف حساب الصناديق', 'position' => 3]);
        $this->app->instance(ClassifierDriver::class, new class($minutes->id, $statement->id) implements ClassifierDriver
        {
            public function __construct(private int $minutes, private int $statement) {}

            public function classify(ToolCall $call): PromiseInterface
            {
                return Create::promiseFor(new ToolAnswer(['summary' => 'أرفق العميل ثلاثة ملفات.', 'needs_accountant' => false, 'answers' => [],
                    'files' => [['file_name' => 'محضر الجرد.pdf', 'request_id' => $this->minutes, 'confident' => true], ['file_name' => 'كشف الصندوق.xlsx', 'request_id' => $this->statement, 'confident' => true],
                        ['file_name' => 'scan001.pdf', 'request_id' => $this->minutes, 'confident' => false]]], null));
            }
        });
        $this->bindImap([8 => $this->mailWithFiles(['محضر الجرد.pdf' => '%PDF minutes', 'كشف الصندوق.xlsx' => 'xlsx bytes', 'scan001.pdf' => '%PDF unknown'])]);

        $this->assertSame(1, app(ReplyFetcher::class)->run($account));

        $names = fn ($id) => ClientFile::withoutGlobalScopes()->where('document_request_id', $id)->where('from_client', true)->pluck('original_name')->all();
        $this->assertSame(['محضر الجرد.pdf'], $names($minutes->id));
        $this->assertSame(['كشف الصندوق.xlsx'], $names($statement->id));        // not both in the first request
        $this->assertSame([DocumentRequest::UPLOADED, DocumentRequest::UPLOADED], [$minutes->fresh()->status, $statement->fresh()->status]);
        $unsorted = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('paper', \App\Services\Portal\ArchiveSorter::UNSORTED)->first();
        $this->assertSame(['scan001.pdf'], $names($unsorted->id));                 // the AI was not sure: kept apart, never guessed
        $this->assertSame([], $names($plan->id));
        $this->assertCount(3, ClientEmail::withoutGlobalScopes()->where('direction', 'in')->first()->analysis['files']);
    }

    public function test_a_reply_with_only_a_file_has_no_text_the_chat_says_where_the_file_went_and_the_panel_shows_it(): void
    {
        [$account, $company, $plan] = $this->world();
        // Gmail with nothing typed: only the quoted original (its «On … wrote:» line wraps over two lines), and the file.
        $quoted = "On Wed, Nov 18, 2026 at 9:26 PM Ahmed <ahmed@client.test>
wrote:
> السادة";
        $raw = "From: ahmed@client.test
Subject: Re: x [ABCD2345]
Message-ID: <only-file@client.test>
In-Reply-To: <out-1@host.test>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary=\"M\"

"
            ."--M
Content-Type: text/plain; charset=UTF-8

{$quoted}
--M
Content-Type: application/pdf; name=\"خطة العمل.pdf\"
Content-Disposition: attachment; filename=\"خطة العمل.pdf\"
Content-Transfer-Encoding: base64

".chunk_split(base64_encode('%PDF'))."
--M--
";
        $this->bindImap([9 => $raw]);
        app()->setLocale('en');   // the scheduler's language is not the page's: what is written for people is Arabic

        $m = \App\Support\Mail\MimeParser::parse($raw);
        $this->assertSame(['ahmed@client.test', 1, ['out-1@host.test']], [$m['from'], count($m['attachments']), $m['references']]);
        $this->assertSame(1, app(ReplyFetcher::class)->run($account));

        $in = ClientEmail::withoutGlobalScopes()->where('direction', 'in')->first();
        $this->assertSame('', $in->body);                                                              // not «On Wed … wrote:»
        $this->assertSame('en', app()->getLocale());                                                    // the language is put back
        $chat = ClientMessage::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('document_request_id')->pluck('body')->implode("
");
        $this->assertStringContainsString(__('follow_up.reply_no_text', ['count' => 1], 'ar'), $chat);
        $this->assertStringContainsString('خطة العمل.pdf → '.$plan->title, $chat);                      // what came and where it was filed
        $this->assertSame([['name' => 'خطة العمل.pdf', 'request_id' => $plan->id, 'title' => $plan->title]], $in->analysis['files']);
        $this->actingAs(User::find($account->user_id));
        $this->get(route('clients.show', $company))->assertOk()->assertSee('خطة العمل.pdf')->assertSee($plan->title);
    }
}
