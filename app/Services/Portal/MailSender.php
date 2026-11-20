<?php

namespace App\Services\Portal;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an email from the accountant's own mailbox (MailAccount: SMTP + an app password) instead of one system mailbox. A user with no
 * mailbox of their own sends from the system's (config `mail`) only when that is a real one (not `log`). The replies come to the
 * accountant's own inbox (Reply-To is their address), so what the client answers is where the accountant already reads.
 */
final class MailSender
{
    /** The mailer for a mailbox: bound in the container so a test can see what is sent without a server. */
    public static function mailerFor(MailAccount $a): mixed
    {
        return app('portal.mailer', ['config' => [
            'transport' => 'smtp', 'host' => $a->host, 'port' => $a->port, 'username' => $a->username, 'password' => $a->password,
            // 465 is implicit TLS (smtps); 587 starts plain and upgrades (STARTTLS).
            'scheme' => $a->security === 'ssl' ? 'smtps' : 'smtp', 'timeout' => 20,
        ]]);
    }

    public static function accountOf(?User $user): ?MailAccount
    {
        return $user ? MailAccount::where('user_id', $user->id)->first() : null;
    }

    /** True when an email can go out for this user: their own mailbox, or a system mailbox that is not the log. */
    public static function canSend(?User $user): bool
    {
        return self::accountOf($user) !== null || config('mail.default') !== 'log';
    }

    /**
     * Sends plain text. Throws when the server refuses (a wrong app password, a blocked port): the caller keeps the email as failed.
     *
     * @param  array{message_id?: string, reply_to?: ?string}  $options
     */
    public static function send(?User $user, string $to, string $subject, string $body, array $options = []): void
    {
        $account = self::accountOf($user);
        $mailer = $account ? self::mailerFor($account) : Mail::mailer();
        $mailer->raw($body, function ($m) use ($account, $to, $subject, $options) {
            $m->to($to)->subject($subject);
            if ($account) {
                $m->from($account->from_address, $account->from_name ?: null);
                $m->replyTo($account->from_address);
            } elseif ($reply = config('portal.mail.reply_to')) {
                $m->replyTo($reply);
            }
            isset($options['message_id']) && $m->getHeaders()->addIdHeader('Message-ID', $options['message_id']);
        });
    }

    /** A message to the mailbox's own address: proves the server, the port and the app password work. Returns null when it went, else why not. */
    public static function test(MailAccount $account): ?string
    {
        try {
            self::mailerFor($account)->raw(__('mail_account.test_body'), function ($m) use ($account) {
                $m->to($account->from_address)->subject(__('mail_account.test_subject'))->from($account->from_address, $account->from_name ?: null);
            });
            $account->update(['last_test_ok' => true, 'last_tested_at' => now(), 'last_error' => null]);

            return null;
        } catch (\Throwable $e) {
            $why = self::explain($e->getMessage());
            $account->update(['last_test_ok' => false, 'last_tested_at' => now(), 'last_error' => mb_substr($why, 0, 250)]);

            return $why;
        }
    }

    /** What an IMAP refusal means (reading the replies). */
    public static function explainImap(string $message): string
    {
        return match (true) {
            (bool) preg_match('/AUTHENTICATIONFAILED|Invalid credentials|LOGIN failed|authenticat|NO \[AUTH|Application-specific/i', $message) => __('mail_account.imap_auth'),
            (bool) preg_match('/timed? ?out|connection (failed|refused|closed)|getaddrinfo|Unable to connect|php_network|No route/i', $message) => __('mail_account.imap_connect'),
            default => __('mail_account.imap_other', ['detail' => mb_substr(preg_replace('/\s+/', ' ', $message), 0, 160)]),
        };
    }

    /** What a server's refusal means to someone who does not read SMTP codes. */
    public static function explain(string $message): string
    {
        return match (true) {
            (bool) preg_match('/\b(535|534|530)\b|authenticat|credentials|username and password|BadCredentials/i', $message) => __('mail_account.err_auth'),
            (bool) preg_match('/timed? ?out|connection refused|could not connect|getaddrinfo|php_network|unable to connect|No route/i', $message) => __('mail_account.err_connect'),
            (bool) preg_match('/certificate|ssl|tls|crypto/i', $message) => __('mail_account.err_tls'),
            default => __('mail_account.err_other', ['detail' => mb_substr(preg_replace('/\s+/', ' ', $message), 0, 160)]),
        };
    }
}
