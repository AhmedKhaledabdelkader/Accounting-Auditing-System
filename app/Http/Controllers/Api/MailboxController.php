<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Portal\Mailbox;
use App\Services\Portal\MailSender;
use App\Services\Portal\ReplyFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signed-in accountant's own mailbox over the API («بريدي الإلكتروني»): the clients' emails go from it and their replies are read from it.
 * The password is written, never read back.
 */
class MailboxController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['mailbox' => Mailbox::present(MailSender::accountOf($request->user())), 'providers' => Mailbox::providers()]);
    }

    /** Saves and tests at once: a message to the mailbox's own address, then (replies read) a sign-in to its inbox. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(Mailbox::rules(MailSender::accountOf($request->user())));
        ['account' => $account, 'test' => $test, 'imap' => $imap] = Mailbox::save($request->user(), $data, $request->boolean('imap_enabled', true));

        return response()->json(['mailbox' => Mailbox::present($account), 'test' => ['ok' => $test === null, 'message' => $test],
            'imap' => $test === null && $account->imap_enabled ? ['ok' => $imap === null, 'message' => $imap] : null]);
    }

    public function test(Request $request): JsonResponse
    {
        $account = MailSender::accountOf($request->user());
        if ($account === null) {
            return $this->none();
        }
        $why = MailSender::test($account);

        return response()->json(['ok' => $why === null, 'message' => $why ?? __('mail_account.test_ok', ['address' => $account->from_address])]);
    }

    /** Reads the replies now (the scheduler does it every two minutes). */
    public function check(Request $request): JsonResponse
    {
        $account = MailSender::accountOf($request->user());
        if ($account === null) {
            return $this->none();
        }
        $r = app(ReplyFetcher::class)->run($account);

        return response()->json(is_int($r) ? ['ok' => true, 'taken' => $r, 'message' => null] : ['ok' => false, 'taken' => 0, 'message' => $r]);
    }

    public function destroy(Request $request): Response
    {
        MailSender::accountOf($request->user())?->delete();

        return response()->noContent();
    }

    private function none(): JsonResponse
    {
        return response()->json(['message' => __('follow_up.no_mailbox')], 404);
    }
}
