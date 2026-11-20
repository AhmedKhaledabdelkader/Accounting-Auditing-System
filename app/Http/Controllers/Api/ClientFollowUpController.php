<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientEmail;
use App\Models\Company;
use App\Services\Portal\FollowUp;
use App\Services\Portal\MailSender;
use App\Support\Llm\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Following a client up by email, over the API (the requirements page's panel): what is missing, the AI's draft of the email, sending it from
 * the signed-in accountant's mailbox, a reply entered by hand, and the emails sent and received. A client of another office is a 404.
 */
class ClientFollowUpController extends Controller
{
    public function __construct(private readonly FollowUp $followUp) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $year = $this->year($request, $company);

        return response()->json(['year' => $year, 'client_email' => $company->client_email, 'missing' => $this->followUp->gaps($company, $year)->count(),
            'can_send' => (bool) $company->client_email && MailSender::canSend($request->user()),
            'emails' => ClientEmail::where('company_id', $company->id)->where('year', $year)->latest('id')->limit(50)->get()->map(fn ($e) => self::email($e))->values()]);
    }

    /** The AI's email for what is missing: read, edited and sent with `send` (nothing goes from here). */
    public function draft(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        try {
            $draft = $this->followUp->draft($company, $year);
        } catch (ProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => __('follow_up.draft_failed')], 502);
        }
        if ($draft === null) {
            return response()->json(['to' => $company->client_email, 'draft' => null, 'body' => null, 'message' => __('follow_up.nothing_missing')]);
        }

        return response()->json(['to' => $company->client_email, 'draft' => $draft, 'message' => null,
            'body' => trim(preg_replace('/\n?\[·+\]\s*$/u', '', $this->followUp->compose($company, $draft, '········')))]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'],
            'requests' => ['required', 'array', 'min:1'], 'requests.*' => ['integer']]);
        try {
            $email = $this->followUp->send($company, (int) $data['year'], $data['subject'], trim($data['body']), array_map('intval', $data['requests']), $request->user());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['follow_up' => $e->getMessage()]);   // no address, or no mailbox to send from
        } catch (\Throwable $e) {
            return response()->json(['message' => __('follow_up.send_failed', ['why' => MailSender::explain($e->getMessage())])], 502);
        }

        return response()->json(self::email($email->fresh()), 201);
    }

    /** A reply that came another way (a forwarded email, a call's note), with its files: read by the AI and filed as if it had come by email. */
    public function reply(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound();
        }
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'text' => ['required', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480']]);
        $last = ClientEmail::where('company_id', $company->id)->where('direction', 'out')->latest('id')->first();
        $in = $this->followUp->receive((string) $company->client_email, $last ? 'Re: '.$last->subject : 'Re:', $data['text'], $last?->message_id, $request->file('files', []), $company);

        return response()->json(self::email($in), 201);
    }

    public static function email(ClientEmail $e): array
    {
        return ['id' => $e->id, 'direction' => $e->direction, 'ref' => $e->ref, 'parent_id' => $e->parent_id, 'address' => $e->address, 'subject' => $e->subject, 'body' => $e->body,
            'status' => $e->status, 'request_ids' => $e->request_ids, 'reminders' => (int) $e->reminders, 'analysis' => $e->analysis, 'error' => $e->error,
            'sent_at' => $e->last_sent_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String()];
    }

    private function company(string $id): ?Company
    {
        return \Illuminate\Support\Str::isUuid($id) ? Company::find($id) : null;   // the signed-in office's own: another office's is not found
    }

    private function year(Request $request, Company $company): int
    {
        return (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? 0) ?: ($company->periodYear() ?? now()->year - 1);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'الشركة غير موجودة.'], 404);
    }
}
