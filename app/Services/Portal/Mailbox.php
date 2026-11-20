<?php

namespace App\Services\Portal;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saving an accountant's mailbox, the same from the page («بريدي الإلكتروني») and from the API: the rules, the password kept when left
 * empty (but typed again for a new server, port or username), a Gmail app password's spaces dropped, and the test sent at once (SMTP to
 * themselves, then the inbox over IMAP when replies are read).
 */
final class Mailbox
{
    public static function rules(?MailAccount $existing): array
    {
        return [
            'provider' => ['required', Rule::in(array_keys(config('mail_providers')))],
            'from_address' => ['required', 'email:filter', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'in:25,465,587,2525'],
            'security' => ['required', Rule::in(['tls', 'ssl'])],
            'username' => ['required', 'string', 'max:190'],
            'password' => [$existing ? 'nullable' : 'required', 'string', 'max:300'],
            'imap_enabled' => ['nullable', 'boolean'],
            'imap_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'imap_port' => ['nullable', 'integer', 'in:993'],
        ];
    }

    /**
     * Saves (validated $data) and tests.
     *
     * @return array{account: MailAccount, test: ?string, imap: ?string} test / imap: null when it works, else why not (imap null when not read)
     *
     * @throws ValidationException a saved password asked to follow a new server, port or username
     */
    public static function save(User $user, array $data, bool $imapEnabled): array
    {
        $existing = MailSender::accountOf($user);
        $data['imap_enabled'] = $imapEnabled;
        $data['imap_port'] = 993;
        $data['imap_host'] = $data['imap_host'] ?? null;
        // A Gmail app password is shown in groups of four: the spaces are not part of it.
        isset($data['password']) && $data['provider'] === 'gmail' && $data['password'] = preg_replace('/\s+/', '', $data['password']);
        if (empty($data['password'])) {
            // The saved password must not follow a new server or a new login: it is typed again for those.
            if ($existing && ($existing->host !== $data['host'] || $existing->username !== $data['username'] || $existing->port !== (int) $data['port'])) {
                throw ValidationException::withMessages(['password' => __('mail_account.retype')]);
            }
            unset($data['password']);
        }
        $account = MailAccount::updateOrCreate(['user_id' => $user->id], $data);
        $account->update(['last_test_ok' => null, 'last_tested_at' => null, 'last_error' => null, 'imap_last_uid' => null, 'imap_error' => null]);
        // The test goes at once: the accountant learns now, not at the first client, whether the app password works.
        $test = MailSender::test($account);
        $imap = ! $test && $account->imap_enabled ? app(ReplyFetcher::class)->probe($account) : null;

        return ['account' => $account->fresh(), 'test' => $test, 'imap' => $imap];
    }

    /** The mailbox as shown to its owner: never the password. */
    public static function present(?MailAccount $a): ?array
    {
        return $a === null ? null : [
            'provider' => $a->provider, 'from_address' => $a->from_address, 'from_name' => $a->from_name, 'host' => $a->host, 'port' => $a->port, 'security' => $a->security,
            'username' => $a->username, 'has_password' => $a->password !== null && $a->password !== '',
            'imap_enabled' => (bool) $a->imap_enabled, 'imap_host' => $a->imap_host ?: ReplyFetcher::hostOf($a),
            'last_test_ok' => $a->last_test_ok, 'last_tested_at' => $a->last_tested_at?->toIso8601String(), 'last_error' => $a->last_error,
            'imap_checked_at' => $a->imap_checked_at?->toIso8601String(), 'imap_error' => $a->imap_error,
        ];
    }

    /** @return array<string, array{label: string, host: string, port: int, security: string, app_password_url: ?string, imap_host: string}> */
    public static function providers(): array
    {
        return array_map(fn ($p) => ['label' => $p[0], 'host' => $p[1], 'port' => $p[2], 'security' => $p[3], 'app_password_url' => $p[4], 'imap_host' => $p[5] ?? ''], config('mail_providers'));
    }
}
