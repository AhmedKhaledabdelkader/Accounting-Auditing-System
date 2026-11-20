<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientEmail;
use App\Models\Company;
use App\Services\Portal\FollowUp;
use App\Support\Llm\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Following a client up by email (FollowUp): the AI's draft of what is missing, which the office reads, edits and sends; the replies;
 * and the inbound webhook the mail service posts a reply to.
 */
class FollowUpController extends Controller
{
    public function __construct(private readonly FollowUp $followUp) {}

    /** The AI looks at what is missing and writes the email; the office reads it here before it goes. */
    public function draft(Request $request, Company $company): View|RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        try {
            $draft = $this->followUp->draft($company, $year);
        } catch (ProviderException $e) {
            return back()->withErrors(['follow_up' => $e->getMessage()]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('follow-up draft failed', ['company' => $company->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['follow_up' => __('follow_up.draft_failed')]);
        }
        if ($draft === null) {
            return back()->with('status', __('follow_up.nothing_missing'));
        }

        return view('portal.follow-up', ['company' => $company, 'year' => $year, 'draft' => $draft, 'to' => $company->client_email,
            'body' => $this->followUp->compose($company, $draft, '········')]);
    }

    /** Sends the email as the office edited it. The reference is added to the subject, so the reply finds its way back. */
    public function send(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'],
            'requests' => ['required', 'array'], 'requests.*' => ['integer']]);
        $back = redirect()->route('clients.show', [$company, 'year' => $data['year']]);
        $body = trim(preg_replace('/\n?\[·+\]\s*$/u', '', trim($data['body'])));
        try {
            $email = $this->followUp->send($company, (int) $data['year'], $data['subject'], $body, array_map('intval', $data['requests']), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['follow_up' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $back->withErrors(['follow_up' => __('follow_up.send_failed', ['why' => \App\Services\Portal\MailSender::explain($e->getMessage())])]);
        }

        return $back->with('status', __('follow_up.sent', ['address' => $email->address]));
    }

    /** A reply the office received by other means (a phone call's note, a forwarded email): read and acted on as if it had come in. */
    public function reply(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'text' => ['required', 'string', 'max:20000'], 'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480']]);
        $last = ClientEmail::where('company_id', $company->id)->where('direction', 'out')->latest('id')->first();
        $in = $this->followUp->receive((string) $company->client_email, $last ? 'Re: '.$last->subject : 'Re:', $data['text'], $last?->message_id, $request->file('files', []), $company);

        return redirect()->route('clients.show', [$company, 'year' => $data['year']])->with('status', __('follow_up.reply_read', ['summary' => $in?->analysis['summary'] ?? '']));
    }

    /**
     * The mail service's inbound webhook (Mailgun, Postmark, SendGrid, or a small forwarder): from, subject, text, optional in_reply_to and
     * attachments. It has no session; the secret in X-Inbound-Secret is the only way in, and a reply is acted on only from the client's own address.
     */
    public function inbound(Request $request): JsonResponse
    {
        $secret = (string) config('portal.mail.inbound_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Inbound-Secret'))) {
            abort(404);
        }
        $data = $request->validate(['from' => ['required', 'string', 'max:300'], 'subject' => ['nullable', 'string', 'max:500'], 'text' => ['required', 'string', 'max:200000'],
            'in_reply_to' => ['nullable', 'string', 'max:300'], 'attachments' => ['nullable', 'array', 'max:10'], 'attachments.*' => ['file', 'max:20480']]);
        $in = $this->followUp->receive($data['from'], (string) ($data['subject'] ?? ''), $data['text'], $data['in_reply_to'] ?? null, $request->file('attachments', []));

        return response()->json(['matched' => $in !== null, 'status' => $in?->status]);
    }
}
