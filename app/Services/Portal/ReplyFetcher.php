<?php

namespace App\Services\Portal;

use App\Models\ClientEmail;
use App\Models\MailAccount;
use App\Support\Mail\ImapMailbox;
use App\Support\Mail\MimeParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Reads the accountant's own mailbox (IMAP, the same address and app password they send from) for the clients' replies to the follow-up
 * emails, and hands each to FollowUp::receive: the AI reads it, the attachments are filed, the chat is told. Read-only: nothing in the mailbox
 * is changed. Only mail that answers one of OUR emails (its Message-ID, or the [REF] in the subject) and comes from the client's own address
 * is acted on; every other message is skipped. The last UID seen is kept, so a message is never read twice; the first time, only mail since
 * the first email this accountant sent is looked at.
 */
final class ReplyFetcher
{
    public function __construct(private FollowUp $followUp) {}

    /** The mailbox's connection: bound in the container so a test can give it messages without a server. */
    public static function mailboxFor(MailAccount $a): object
    {
        return app('portal.imap', ['account' => $a]);
    }

    /** The IMAP server of an account: the one saved, else the provider's, else the SMTP host with imap in place of smtp. */
    public static function hostOf(MailAccount $a): string
    {
        return $a->imap_host ?: (config("mail_providers.{$a->provider}.5") ?: preg_replace('/^smtp\./i', 'imap.', $a->host));
    }

    /**
     * Reads one mailbox. Returns how many replies were taken; the problem, if any, is kept on the account (and shown to the accountant).
     *
     * @return int|string the number of replies, or why it could not read
     */
    public function run(MailAccount $account): int|string
    {
        if (! $account->imap_enabled) {
            return 0;
        }
        $first = ClientEmail::withoutGlobalScopes()->where('direction', 'out')->where('sent_by', $account->user_id)->oldest('id')->first();
        if ($first === null) {
            return 0;   // nothing sent from this mailbox: nothing to wait for
        }
        $taken = 0;
        \App\Support\Llm\AiBudget::forUser($account->user_id);   // the replies to this accountant's emails are read on their budget
        $locale = app()->getLocale();
        app()->setLocale('ar');   // the scheduler has no page language: the lines written in the chats are for the office and the client, in Arabic
        try {
            $box = self::mailboxFor($account);
            $box->open();
            $messages = $box->fetchAfter((int) $account->imap_last_uid, Carbon::parse($first->created_at)->subDay());
            $box->close();
            $last = (int) $account->imap_last_uid;
            foreach ($messages as ['uid' => $uid, 'raw' => $raw]) {
                $last = max($last, $uid);
                try {
                    $taken += $this->take($account, MimeParser::parse($raw)) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e);   // one bad message does not stop the others
                }
            }
            $account->update(['imap_last_uid' => $last ?: $account->imap_last_uid, 'imap_checked_at' => now(), 'imap_error' => null]);

            return $taken;
        } catch (\Throwable $e) {
            $why = MailSender::explainImap($e->getMessage());
            $account->update(['imap_checked_at' => now(), 'imap_error' => mb_substr($why, 0, 250)]);

            return $why;
        } finally {
            app()->setLocale($locale);
        }
    }

    /** Signs in to the inbox and leaves: proves the IMAP side works. Returns null when it does, else why not. */
    public function probe(MailAccount $account): ?string
    {
        try {
            $box = self::mailboxFor($account);
            $box->open();
            $box->close();
            $account->update(['imap_error' => null, 'imap_checked_at' => now()]);

            return null;
        } catch (\Throwable $e) {
            $why = MailSender::explainImap($e->getMessage());
            $account->update(['imap_error' => mb_substr($why, 0, 250), 'imap_checked_at' => now()]);

            return $why;
        }
    }

    /** All the accounts (the scheduler). Returns the number of replies taken. */
    public function runAll(): int
    {
        $n = 0;
        foreach (MailAccount::where('imap_enabled', true)->get() as $account) {
            $r = $this->run($account);
            $n += is_int($r) ? $r : 0;
        }

        return $n;
    }

    /** One parsed message: ours to answer only when it replies to one of our emails and the sender is the client. */
    private function take(MailAccount $account, array $mail): bool
    {
        if ($mail['from'] === strtolower($account->from_address) || $mail['text'] === '' && $mail['attachments'] === []) {
            return false;   // our own message (a copy in the inbox), or an empty one
        }
        $files = [];
        foreach ($mail['attachments'] as $a) {
            $path = tempnam(sys_get_temp_dir(), 'rep');
            file_put_contents($path, $a['data']);
            $files[] = new UploadedFile($path, $a['name'], $a['mime'], null, true);
        }
        try {
            $in = $this->followUp->receive($mail['from'], $mail['subject'], $mail['text'], implode(' ', array_map(fn ($i) => "<{$i}>", $mail['references'])), $files, null, $mail['message_id']);
        } finally {
            foreach ($files as $f) {
                @unlink($f->getPathname());
            }
        }

        return $in !== null && $in->status === 'processed';
    }
}
