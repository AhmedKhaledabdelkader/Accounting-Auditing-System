<?php

namespace App\Support\Mail;

/**
 * A minimal IMAP client over a TLS socket (no PHP imap extension): log in, open the inbox read-only (EXAMINE: nothing in the mailbox is
 * ever changed, not even the «read» flag), list the messages newer than a UID and fetch their raw text. Enough for the follow-up's replies.
 */
class ImapMailbox
{
    /** @var resource|null */
    private $stream = null;
    private int $tag = 0;

    public function __construct(private string $host, private int $port, private string $user, private string $password, private int $timeout = 20) {}

    /** Opens the connection and signs in. Throws with the server's own words when it refuses. */
    public function open(): void
    {
        $this->stream = $this->connect();
        stream_set_timeout($this->stream, $this->timeout);
        $greeting = $this->line();
        if (! str_starts_with((string) $greeting, '* OK') && ! str_starts_with((string) $greeting, '* PREAUTH')) {
            throw new \RuntimeException('IMAP greeting: '.trim((string) $greeting));
        }
        $this->command('LOGIN '.$this->quote($this->user).' '.$this->quote($this->password));
        $this->command('EXAMINE INBOX');
    }

    /** @return resource the TLS connection (a test gives a socket pair instead) */
    protected function connect()
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @stream_socket_client("ssl://{$this->host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $stream) {
            throw new \RuntimeException("IMAP connection failed: {$error} ({$errno})");
        }

        return $stream;
    }

    /**
     * The messages with a UID above $afterUid (and, if given, sent since that date), oldest first, at most $limit.
     *
     * @return list<array{uid: int, raw: string}>
     */
    public function fetchAfter(int $afterUid, ?\DateTimeInterface $since = null, int $limit = 30): array
    {
        $criteria = 'UID '.($afterUid + 1).':*'.($since ? ' SINCE '.$since->format('j-M-Y') : '');
        $uids = [];
        foreach ($this->command("UID SEARCH {$criteria}")['lines'] as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $uids = array_map('intval', array_filter(preg_split('/\s+/', trim(substr($line, 8)))));
            }
        }
        // «n:*» always answers with the newest message even when it is not newer than n: keep only the ones after $afterUid.
        $uids = array_values(array_filter($uids, fn ($u) => $u > $afterUid));
        sort($uids);
        $out = [];
        foreach (array_slice($uids, 0, $limit) as $uid) {
            $r = $this->command("UID FETCH {$uid} (BODY.PEEK[])");
            if ($r['literals'] !== []) {
                $out[] = ['uid' => $uid, 'raw' => $r['literals'][0]];
            }
        }

        return $out;
    }

    public function close(): void
    {
        if ($this->stream) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @return array{lines: list<string>, literals: list<string>} the untagged lines and the literals (message bodies) of the answer */
    private function command(string $cmd): array
    {
        $tag = 'A'.++$this->tag;
        $this->write("{$tag} {$cmd}\r\n");
        $lines = [];
        $literals = [];
        while (($line = $this->line()) !== null) {
            // A literal: «… {1234}» then exactly 1234 bytes, then the rest of the line.
            while (preg_match('/\{(\d+)\}\r?\n?$/', $line, $m)) {
                $literals[] = $this->bytes((int) $m[1]);
                $line = rtrim($line)."\n".(string) $this->line();
            }
            if (str_starts_with($line, "{$tag} ")) {
                if (! preg_match("/^{$tag} OK/i", $line)) {
                    throw new \RuntimeException(trim(preg_replace("/^{$tag} /", '', $line)));
                }

                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = rtrim($line);
        }
        throw new \RuntimeException('IMAP connection closed');
    }

    private function quote(string $s): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $s)) {
            throw new \InvalidArgumentException('Invalid character in IMAP login');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
    }

    private function write(string $s): void
    {
        if (@fwrite($this->stream, $s) === false) {
            throw new \RuntimeException('IMAP write failed');
        }
    }

    private function line(): ?string
    {
        $l = fgets($this->stream);
        if ($l === false) {
            $meta = stream_get_meta_data($this->stream);
            if (! empty($meta['timed_out'])) {
                throw new \RuntimeException('IMAP timed out');
            }

            return null;
        }

        return $l;
    }

    private function bytes(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->stream, min(65536, $n - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('IMAP read failed');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
