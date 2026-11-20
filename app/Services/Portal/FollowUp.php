<?php

namespace App\Services\Portal;

use App\Models\ClassificationRun;
use App\Models\ClientEmail;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolCall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Following the client up by email, with the AI doing the looking and the reading:
 *
 *  1. `gaps`: what is missing = the client's open requests (not sent, or sent back) for the year; the optional ones last.
 *  2. `draft`: the AI looks at those and at the trial balance (a loan account with no loan agreement asked for, say), and writes the
 *     email: a subject, a short intro, a reason for each missing document and extra questions. PHP builds the list itself from the
 *     requests (the AI cannot add or drop one) and the office reads the email before it goes (nothing is sent without a click).
 *  3. `send`: the email goes with a reference [ref] in its subject and the portal link; it is kept with what it asked about.
 *  4. `receive`: a reply (the inbound webhook, or entered by hand) is matched to the email by the reference or the Message-ID and must come
 *     from the client's own address. The AI reads it and says what it means: attached, will send later, does not apply, a question.
 *     PHP acts: the text goes into the chat, attachments into the open requests (the reviewer then files them), and what
 *     the AI could not settle is left to the accountant, who is told.
 *  5. `remind`: an email with no reply after some days goes again (if `portal.mail.auto_remind` is on), up to a limit.
 */
final class FollowUp
{
    public const DRAFT_TOOL = [
        'name' => 'submit_follow_up', 'description' => 'The email to the client about the documents still missing.', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['subject', 'intro', 'items', 'questions'], 'properties' => [
            'subject' => ['type' => 'string'],
            'intro' => ['type' => 'string'],
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'reason'], 'properties' => [
                'id' => ['type' => 'integer'], 'reason' => ['type' => ['string', 'null']]]]],
            'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]],
    ];

    public const REPLY_TOOL = [
        'name' => 'submit_reply_reading', 'description' => 'What the client\'s reply says.', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'answers', 'files', 'needs_accountant'], 'properties' => [
            'summary' => ['type' => 'string'],
            'files' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['file_name', 'request_id', 'confident'], 'properties' => [
                'file_name' => ['type' => 'string'], 'request_id' => ['type' => ['integer', 'null']], 'confident' => ['type' => 'boolean']]]],
            'answers' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['request_id', 'state', 'note'], 'properties' => [
                'request_id' => ['type' => ['integer', 'null']],
                'state' => ['type' => 'string', 'enum' => ['attached', 'will_send_later', 'not_applicable', 'question', 'other']],
                'note' => ['type' => ['string', 'null']]]]],
            'needs_accountant' => ['type' => 'boolean'],
        ]],
    ];

    public function __construct(private ClassifierDriver $driver) {}

    /** @return \Illuminate\Support\Collection<int, DocumentRequest> the client's open requests for the year: required first, in the checklist's order */
    public function gaps(Company $company, int $year)
    {
        return DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->whereIn('status', DocumentRequest::OPEN)
            ->whereNull('paper')   // the working papers and recalculations are the AI's to prepare, not the client's to send
            ->orderBy('optional')->orderBy('position')->get();
    }

    /**
     * The AI's email for the missing documents.
     *
     * @return array{subject: string, intro: string, items: list<array{id: int, number: ?int, title: string, reason: ?string, note: ?string}>, questions: list<string>}|null  null = nothing is missing
     */
    public function draft(Company $company, int $year): ?array
    {
        $gaps = $this->gaps($company, $year);
        if ($gaps->isEmpty()) {
            return null;
        }
        $list = $gaps->map(fn ($r) => "[{$r->id}] {$r->title} ({$r->section})".($r->optional ? ' - optional' : '').($r->status === DocumentRequest::RETURNED ? ' - SENT BACK: '.$r->note : ''))->implode("\n");
        $system = "You write a short, polite email in FORMAL Arabic (Saudi audience) from an audit office to its client, asking for the documents still missing for the financial year {$year}. "
            ."RULES. Write the subject and a 1-3 sentence intro. List in items ONLY the documents that need a reason: where the trial balance below shows a concrete one (for example: the books hold a loan balance, so the loan agreement is needed), "
            ."or that were SENT BACK (say what must be corrected). Each item is the document's id and a short reason in Arabic. Documents with no special reason are NOT listed (the office lists them itself); at most 25 items. "
            ."Never add, drop or rename a document: use only ids from the list. questions: at most 3 short clarifying questions the trial balance raises (for example an account with a balance but no supporting document asked for); an empty list when there is none. "
            ."Never invent a figure or a fact; never promise anything on the office's behalf. Never call the document «تقرير»: say «القوائم المالية».\n\nMissing documents:\n{$list}".$this->books($company);
        $call = new ToolCall([['text' => $system]], self::DRAFT_TOOL, "Client: {$company->clientName()} — {$company->name_ar}. Write the email.", 12000);
        $answer = $this->driver->classify($call)->wait();
        $input = $answer->input;
        if ($input === [] || trim((string) ($input['subject'] ?? '')) === '') {
            throw new \RuntimeException('The AI gave no email'.($answer->noToolCall ? " ({$answer->noToolCall})" : ''));
        }
        $reasons = [];
        foreach ((array) ($input['items'] ?? []) as $i) {
            isset($i['id']) && $reasons[(int) $i['id']] = is_string($i['reason'] ?? null) && trim($i['reason']) !== '' ? trim($i['reason']) : null;
        }

        return [
            'subject' => trim($input['subject']), 'intro' => trim((string) $input['intro']),
            // The list is the requests' own, in their order: the AI's ids only add a reason.
            'items' => $gaps->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'reason' => $reasons[$r->id] ?? null,
                'note' => $r->status === DocumentRequest::RETURNED ? $r->note : null])->values()->all(),
            'questions' => array_values(array_filter(array_map(fn ($q) => is_string($q) ? trim($q) : '', array_slice((array) ($input['questions'] ?? []), 0, 3)))),
        ];
    }

    /** The trial balance as lines for the AI (the latest classified one of the client), or a note that there is none. */
    private function books(Company $company): string
    {
        $run = ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'done')->latest()->first();
        if ($run === null) {
            return "\n\nThere is no trial balance for this client yet.";
        }
        $lines = [];
        foreach (array_slice((array) ($run->result['data'] ?? []), 0, 500) as $r) {
            $net = (float) ($r['closing_debit'] ?? 0) - (float) ($r['closing_credit'] ?? 0);
            if (abs($net) > 0.004 && ($r['row_type'] ?? 'account') === 'account') {
                $lines[] = ($r['account_name'] ?? '').' | '.($r['sub_class'] ?? '').' | '.round($net, 2);
            }
        }

        return "\n\nThe trial balance (account | class | closing balance, debit positive):\n".implode("\n", $lines);
    }

    /** The email's text, built from the draft (the AI's words and the requests' own list), as the client reads it. */
    public function compose(Company $company, array $draft, string $ref): string
    {
        $lines = [__('follow_up.greeting', ['name' => $company->clientName()]), '', $draft['intro'], ''];
        foreach ($draft['items'] as $i => $item) {
            $lines[] = ($i + 1).'. '.$item['title'].($item['reason'] ? ' — '.$item['reason'] : '').($item['note'] ? ' ('.__('follow_up.returned', ['note' => $item['note']]).')' : '');
        }
        if ($draft['questions'] !== []) {
            $lines[] = '';
            $lines[] = __('follow_up.questions');
            foreach ($draft['questions'] as $q) {
                $lines[] = '- '.$q;
            }
        }
        array_push($lines, '', __('follow_up.how', ['link' => url('/login').'?office='.($company->office?->code ?? '')]), __('follow_up.reply_hint'), '', __('follow_up.signature'), "[{$ref}]");

        return implode("\n", $lines);
    }

    /** Sends the email (the office has read it); kept with the requests it asked about. A failure is kept and thrown back. */
    public function send(Company $company, int $year, string $subject, string $body, array $requestIds, ?User $by, ?ClientEmail $reminderOf = null): ClientEmail
    {
        $to = trim((string) $company->client_email);
        if ($to === '') {
            throw new \DomainException(__('follow_up.no_address'));
        }
        // The mailbox of whoever sends it (a reminder: of whoever sent the first email).
        $sender = $by ?? ($reminderOf?->sent_by ? User::withoutGlobalScopes()->find($reminderOf->sent_by) : null);
        if (! MailSender::canSend($sender)) {
            throw new \DomainException(__('follow_up.no_mailbox'));
        }
        $ref = $reminderOf?->ref ?? Str::upper(Str::random(8));
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        str_contains($subject, "[{$ref}]") || $subject .= " [{$ref}]";   // the reply's subject carries it back
        $messageId = Str::lower($ref).'.'.Str::random(12)."@{$domain}";
        $email = ClientEmail::withoutGlobalScopes()->create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year, 'direction' => 'out', 'ref' => $ref,
            'parent_id' => $reminderOf?->id, 'message_id' => $messageId, 'address' => $to, 'subject' => $subject, 'body' => $body, 'status' => 'sent', 'request_ids' => $requestIds,
            'last_sent_at' => now(), 'sent_by' => $sender?->id]);
        try {
            MailSender::send($sender, $to, $subject, $body, ['message_id' => $messageId]);
        } catch (\Throwable $e) {
            $email->update(['status' => 'failed', 'error' => mb_substr(MailSender::explain($e->getMessage()), 0, 250)]);
            throw $e;
        }
        $reminderOf?->increment('reminders');
        $this->note($company, null, __('follow_up.sent_note', ['address' => $to, 'count' => count($requestIds)]));

        return $email;
    }

    /**
     * A reply from outside (the inbound webhook, or typed in by the office). Matched to the email it answers, from the client's own address
     * only; then read by the AI and acted on. Returns null when it answers nothing of ours.
     *
     * @param  UploadedFile[]  $attachments
     */
    public function receive(string $from, string $subject, string $text, ?string $inReplyTo, array $attachments = [], ?Company $force = null, ?string $messageId = null): ?ClientEmail
    {
        // The same message is never read twice (the mailbox is read again and again).
        if ($messageId !== null && ClientEmail::withoutGlobalScopes()->where('direction', 'in')->where('message_id', $messageId)->exists()) {
            return null;
        }
        $out = $this->matchOut($subject, $inReplyTo);
        if ($out === null && $force === null) {
            return null;
        }
        $company = $force ?? Company::withoutGlobalScopes()->find($out->company_id);
        $address = Str::lower(trim(preg_replace('/^.*<(.+)>.*$/', '$1', $from)));
        $mine = Str::lower(trim((string) $company->client_email));
        $genuine = $force !== null || ($address !== '' && $address === $mine);   // the office's own entry is trusted; a webhook is only from the client's address
        $text = $this->cleanReply($text);
        $in = ClientEmail::withoutGlobalScopes()->create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $out?->year ?? ($company->periodYear() ?? now()->year - 1),
            'direction' => 'in', 'message_id' => $messageId, 'ref' => $out?->ref ?? Str::upper(Str::random(8)), 'parent_id' => $out?->id, 'address' => $address ?: $from, 'subject' => mb_substr($subject, 0, 250),
            'body' => mb_substr($text, 0, 20000), 'status' => $genuine ? 'received' : 'unmatched', 'request_ids' => $out?->request_ids]);
        if ($genuine) {
            $this->process($in, $company, $attachments);
        }

        return $in->fresh();
    }

    private function matchOut(string $subject, ?string $inReplyTo): ?ClientEmail
    {
        // The message it answers, and the ones above it in the thread (any of ours matches).
        foreach (array_filter(preg_split('/[\s<>,]+/', (string) $inReplyTo)) as $id) {
            if ($hit = ClientEmail::withoutGlobalScopes()->where('direction', 'out')->where('message_id', $id)->first()) {
                return $hit;
            }
        }
        if (preg_match('/\[([A-Z0-9]{8})\]/', $subject, $m)) {
            return ClientEmail::withoutGlobalScopes()->where('direction', 'out')->where('ref', $m[1])->latest('id')->first();
        }

        return null;
    }

    /** The reply's own words: what is quoted below the client's lines (the original, «On … wrote:», «-----») is cut. */
    private function cleanReply(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $cut = preg_split('/(?:^|\n)[ \t]*(?:>|-{3,}|_{3,}|On [^\n]*(?:\n[^\n]*)?wrote:|في [^\n]*(?:\n[^\n]*)?كتب|From:|De :|\[[A-Z0-9]{8}\])/u', $text, 2)[0];

        return trim($cut);   // nothing left = the client wrote nothing, only attached files
    }

    /** The AI reads the reply; PHP files what came and tells the accountant what is left to them. */
    private function process(ClientEmail $in, Company $company, array $attachments): void
    {
        // Everything the client may answer, open or not: a file can belong to a request that was already answered.
        $all = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $in->year)->whereNull('paper')->orderBy('position')->get();
        $ids = $all->pluck('id')->all();
        $list = $all->map(fn ($r) => "[{$r->id}] {$r->title} ({$r->section})".(in_array($r->status, DocumentRequest::OPEN, true) ? ' - OPEN' : ''))->implode("\n");
        $system = "A client replied by email to an audit office that asked for documents. Read the reply and say what it means. RULES. summary: one sentence in Arabic. "
            ."answers: one entry per document the reply says something about (request_id from the list below, or null if it is not about a listed document): state = attached (the client says it is attached), "
            ."will_send_later (they promise it, or give a date), not_applicable (they say it does not apply to them, or they do not have it), question (they ask something), other. note: the client's own words about it, short, or null. "
            ."files: ONE entry for EACH attached file, with its exact file name, and the request it answers: judge by the file name and by what the client wrote about it; each file goes to the ONE request it answers "
            ."(two files may answer two different requests: never put them all in the first). request_id = null and confident = false when you are not sure. "
            ."Copy only what the reply says; never decide for the office. needs_accountant = true when the reply asks a question, disputes something, or says anything the checklist cannot show.\n\nThe client's requests:\n{$list}";
        $attached = $attachments ? "Attached files:\n".implode("\n", array_map(fn ($f) => '- '.$f->getClientOriginalName().' ('.round($f->getSize() / 1024).' KB)', $attachments)) : 'No attachments.';
        $analysis = null;
        try {
            $answer = $this->driver->classify(new ToolCall([['text' => $system]], self::REPLY_TOOL, "The reply:\n{$in->body}\n\n{$attached}", 6000))->wait();
            $analysis = $answer->input !== [] ? $answer->input : null;
        } catch (\Throwable) {
            // The AI could not be asked: the reply still goes to the chat and the accountant reads it.
        }
        $answers = collect($analysis['answers'] ?? [])->filter(fn ($a) => in_array($a['request_id'] ?? null, $ids, true))->values();
        $placement = [];   // file name => request id, from the AI (only ids of the client's own requests, only when it was sure)
        foreach ((array) ($analysis['files'] ?? []) as $f) {
            if (($f['confident'] ?? false) === true && in_array($f['request_id'] ?? null, $ids, true)) {
                $placement[(string) ($f['file_name'] ?? '')] = (int) $f['request_id'];
            }
        }
        $analysis = $analysis === null ? ['summary' => null, 'answers' => [], 'files' => [], 'needs_accountant' => true, 'unavailable' => true]
            : ['summary' => $analysis['summary'] ?? null, 'answers' => $answers->all(), 'needs_accountant' => (bool) ($analysis['needs_accountant'] ?? false), 'files' => []];

        // The text goes into the chat as the client's own line, so the office reads one thread.
        $portalUser = User::withoutGlobalScopes()->where('company_id', $company->id)->first();
        ClientMessage::withoutGlobalScopes()->create(['office_id' => $company->office_id, 'company_id' => $company->id, 'user_id' => $portalUser?->id, 'from_client' => true, 'kind' => ClientMessage::TEXT,
            'body' => $in->body !== '' ? __('follow_up.reply_in_chat', ['text' => Str::limit($in->body, 1500)]) : __('follow_up.reply_no_text', ['count' => count($attachments)])]);
        // What the client said about each document is a note in its thread; nothing is declared for them.
        foreach ($answers as $a) {
            if (in_array($a['state'], ['will_send_later', 'not_applicable', 'question'], true)) {
                $this->note($company, $all->firstWhere('id', $a['request_id']), __("follow_up.state.{$a['state']}", ['note' => $a['note'] ?? '']));
            }
        }
        // Each file goes to ITS request; one the AI was not sure of goes to the «unsorted» request, where the content review (which reads the
        // file itself) moves it to the request it answers, or leaves it for the accountant.
        $by = $portalUser ?? User::withoutGlobalScopes()->find($in->sent_by ?? ClientEmail::withoutGlobalScopes()->find($in->parent_id)?->sent_by);
        if ($attachments && $by) {
            $groups = [];
            foreach ($attachments as $file) {
                $id = $placement[$file->getClientOriginalName()] ?? null;
                $groups[$id ?? 0][] = $file;
            }
            foreach ($groups as $id => $files) {
                $target = $id ? $all->firstWhere('id', $id) : app(ArchiveSorter::class)->unsortedRequest($company, $in->year);
                app(Workspace::class)->upload($target, $files, $by, true);
                foreach ($files as $f) {
                    $analysis['files'][] = ['name' => $f->getClientOriginalName(), 'request_id' => $target->id, 'title' => $target->paper === ArchiveSorter::UNSORTED ? null : $target->title];
                }
            }
            // One line in the general chat says what came and where it was filed (the content review may still move a file: it says so in the request).
            $this->note($company, null, __('follow_up.files_filed', ['files' => collect($analysis['files'])->map(fn ($f) => $f['name'].' → '.($f['title'] ?? __('follow_up.unsorted')))->implode('، ')]));
        }
        $in->update(['status' => 'processed', 'analysis' => $analysis]);
        ClientEmail::withoutGlobalScopes()->where('id', $in->parent_id)->update(['status' => 'sent']);
    }

    /** A line in a thread that is neither side's talk: what happened (shown in the chat). */
    private function note(Company $company, ?DocumentRequest $request, string $body): void
    {
        ClientMessage::withoutGlobalScopes()->create(['office_id' => $company->office_id, 'company_id' => $company->id, 'document_request_id' => $request?->id, 'user_id' => null,
            'from_client' => false, 'kind' => ClientMessage::EVENT, 'body' => $body, 'read_at' => now()]);
    }

    /**
     * Sends again each email nobody answered, after `remind_after_days`, up to `max_reminders`, while documents are still missing.
     * Only when `portal.mail.auto_remind` is on (the command is scheduled daily). Returns how many went.
     */
    public function remind(): int
    {
        if (! config('portal.mail.auto_remind')) {
            return 0;
        }
        $sent = 0;
        $roots = ClientEmail::withoutGlobalScopes()->where('direction', 'out')->where('status', 'sent')->whereNull('parent_id')->get();
        foreach ($roots as $root) {
            $chain = ClientEmail::withoutGlobalScopes()->where('ref', $root->ref)->get();
            $last = $chain->where('direction', 'out')->max('last_sent_at');
            $answered = $chain->where('direction', 'in')->where('status', 'processed')->isNotEmpty();
            if ($answered || $chain->where('direction', 'out')->count() - 1 >= (int) config('portal.mail.max_reminders') || $last === null
                || $last->gt(now()->subDays((int) config('portal.mail.remind_after_days')))) {
                continue;
            }
            $company = Company::withoutGlobalScopes()->find($root->company_id);
            $gaps = $company ? $this->gaps($company, $root->year) : collect();
            if ($gaps->isEmpty()) {
                continue;
            }
            // The same words, the list brought up to date from the requests (the AI is not asked again).
            $draft = ['intro' => __('follow_up.reminder_intro'), 'questions' => [], 'items' => $gaps->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'reason' => null,
                'note' => $r->status === DocumentRequest::RETURNED ? $r->note : null])->all()];
            try {
                $this->send($company, $root->year, __('follow_up.reminder_subject', ['subject' => $root->subject]), $this->compose($company, $draft, $root->ref), $gaps->pluck('id')->all(), null, $root);
                $sent++;
            } catch (\Throwable) {
                // The next run tries again.
            }
        }

        return $sent;
    }
}
