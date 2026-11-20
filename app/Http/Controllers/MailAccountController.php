<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Services\Portal\Mailbox;
use App\Services\Portal\MailSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «بريدي الإلكتروني»: the mailbox the accountant sends their clients' emails from. They pick the provider (Gmail, Outlook…) or type the
 * SMTP server, give their address and an app password; it is tested by sending a message to themselves and kept encrypted. The password is
 * never shown again: leaving the field empty keeps the saved one.
 */
class MailAccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('mail-account', ['account' => MailSender::accountOf($request->user()), 'providers' => config('mail_providers'), 'systemMailer' => config('mail.default')]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(Mailbox::rules(MailSender::accountOf($request->user())));
        try {
            ['account' => $saved, 'test' => $why, 'imap' => $imap] = Mailbox::save($request->user(), $data, $request->boolean('imap_enabled'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }
        $note = $why || ! $saved->imap_enabled ? null : ($imap ?? __('mail_account.imap_ok'));

        return back()->with($why ? 'mail_error' : 'status', $why ?? __('mail_account.saved_ok', ['address' => $saved->from_address]).' '.$note);
    }

    public function test(Request $request): RedirectResponse
    {
        $account = MailSender::accountOf($request->user());
        abort_if($account === null, 404);
        $why = MailSender::test($account);

        return back()->with($why ? 'mail_error' : 'status', $why ?? __('mail_account.test_ok', ['address' => $account->from_address]));
    }

    /** Reads the replies now (the scheduler does it every two minutes). */
    public function check(Request $request): RedirectResponse
    {
        $account = MailSender::accountOf($request->user());
        abort_if($account === null, 404);
        $r = app(\App\Services\Portal\ReplyFetcher::class)->run($account);

        return back()->with(is_int($r) ? 'status' : 'mail_error', is_int($r) ? __('mail_account.checked', ['count' => $r]) : $r);
    }

    public function destroy(Request $request): RedirectResponse
    {
        MailAccount::where('user_id', $request->user()->id)->delete();

        return back()->with('status', __('mail_account.removed'));
    }
}
