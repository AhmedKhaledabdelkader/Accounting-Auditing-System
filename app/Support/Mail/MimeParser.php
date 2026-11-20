<?php

namespace App\Support\Mail;

/**
 * Reads a raw email (RFC 5322 / MIME) into what the follow-up needs: who sent it, the subject, the Message-ID and the ones it answers,
 * the text the sender wrote, and the attachments. Handles multipart (nested), base64 and quoted-printable, charsets, encoded headers
 * and filenames (RFC 2047 and RFC 2231). No extension and no package: only mbstring.
 */
final class MimeParser
{
    private const MAX_PARTS = 60;

    /**
     * @return array{from: string, subject: string, message_id: ?string, references: list<string>, text: string, attachments: list<array{name: string, mime: string, data: string}>}
     */
    public static function parse(string $raw): array
    {
        $out = ['text' => '', 'html' => '', 'attachments' => []];
        $count = 0;
        [$headers] = self::split($raw);
        self::walk($raw, $out, $count);
        $text = trim($out['text']) !== '' ? $out['text'] : self::htmlToText($out['html']);

        return [
            'from' => self::address((string) ($headers['from'] ?? '')),
            'subject' => trim(self::decodeWords((string) ($headers['subject'] ?? ''))),
            'message_id' => ($id = self::ids($headers['message-id'] ?? '')) ? $id[0] : null,
            // The newest first: the message it answers directly, then the thread above it.
            'references' => array_values(array_unique(array_merge(self::ids($headers['in-reply-to'] ?? ''), array_reverse(self::ids($headers['references'] ?? ''))))),
            'text' => trim($text),
            'attachments' => $out['attachments'],
        ];
    }

    /** @return array{0: array<string, string>, 1: string} the headers (lower-cased names, unfolded) and the body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            if (($i = strpos($line, ':')) > 0) {
                $name = strtolower(trim(substr($line, 0, $i)));
                $headers[$name] ??= trim(substr($line, $i + 1));   // the first of a repeated header
            }
        }

        return [$headers, $body];
    }

    private static function walk(string $raw, array &$out, int &$count): void
    {
        if (++$count > self::MAX_PARTS) {
            return;
        }
        [$h, $body] = self::split($raw);
        [$type, $params] = self::contentType($h['content-type'] ?? 'text/plain');
        if (str_starts_with($type, 'multipart/') && isset($params['boundary'])) {
            $b = preg_quote($params['boundary'], '/');
            $parts = preg_split("/^--{$b}(?:--)?[ \t]*$/m", $body);
            array_shift($parts);   // the preamble
            foreach ($parts as $part) {
                if (trim($part) !== '' && trim($part) !== '--') {
                    self::walk(ltrim($part, "\n"), $out, $count);
                }
            }

            return;
        }
        $data = self::decodeBody($body, strtolower($h['content-transfer-encoding'] ?? '7bit'));
        $disposition = self::contentType($h['content-disposition'] ?? '');
        $name = self::filename($params, $disposition[1]);
        if ($type === 'message/rfc822') {
            return;   // a forwarded message is not the reply
        }
        if ($name !== null || (str_starts_with($disposition[0], 'attachment')) || (! str_starts_with($type, 'text/') && $type !== '')) {
            $out['attachments'][] = ['name' => $name ?? 'attachment', 'mime' => $type ?: 'application/octet-stream', 'data' => $data];

            return;
        }
        $text = self::toUtf8($data, $params['charset'] ?? 'utf-8');
        $type === 'text/html' ? $out['html'] .= $text : $out['text'] .= $text;
    }

    /** @return array{0: string, 1: array<string, string>} the type (lower-cased) and its parameters */
    private static function contentType(string $value): array
    {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value);
        $type = strtolower(trim(array_shift($parts) ?? ''));
        $params = [];
        $continued = [];
        foreach ($parts as $p) {
            if (! preg_match('/^\s*([A-Za-z0-9_\-*]+)\s*=\s*(.*?)\s*$/s', $p, $m)) {
                continue;
            }
            $v = trim($m[2], '"');
            $key = strtolower($m[1]);
            if (preg_match('/^(.+?)\*(\d+)(\*?)$/', $key, $c)) {   // RFC 2231 continuation: name*0*=…, name*1*=…
                $continued[$c[1]][(int) $c[2]] = [$v, $c[3] === '*'];
            } else {
                $params[$key] = $v;
            }
        }
        foreach ($continued as $name => $chunks) {
            ksort($chunks);
            $params[$name.'*'] = implode('', array_map(fn ($c) => $c[0], $chunks));
        }

        return [$type, $params];
    }

    private static function filename(array $ct, array $cd): ?string
    {
        foreach ([$cd, $ct] as $p) {
            foreach (['filename*', 'name*'] as $k) {
                if (isset($p[$k]) && preg_match("/^([^']*)'[^']*'(.*)$/s", $p[$k], $m)) {
                    return self::safeName(self::toUtf8(rawurldecode($m[2]), $m[1] ?: 'utf-8'));
                }
            }
            foreach (['filename', 'name'] as $k) {
                if (isset($p[$k]) && $p[$k] !== '') {
                    return self::safeName(self::decodeWords($p[$k]));
                }
            }
        }

        return null;
    }

    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return trim(preg_replace('/[\x00-\x1F]/', '', $name)) ?: 'attachment';
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset ?: 'utf-8'));
        if (in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_check_encoding($text, 'UTF-8') ? $text : (string) @iconv('Windows-1256', 'UTF-8//IGNORE', $text);
        }
        // iconv knows the Arabic code pages (windows-1256, iso-8859-6) that mbstring does not.
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
        if ($converted === false || $converted === '') {
            $converted = mb_check_encoding($text, 'UTF-8') ? $text : (string) @iconv('Windows-1256', 'UTF-8//IGNORE', $text);
        }

        return $converted;
    }

    public static function decodeWords(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value);   // the space between two encoded words is not text

        return preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', function ($m) {
            $data = strtolower($m[2]) === 'b' ? base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::toUtf8((string) $data, $m[1]);
        }, $value);
    }

    private static function address(string $from): string
    {
        $from = self::decodeWords($from);
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }

        return strtolower(trim($from));
    }

    /** @return list<string> the Message-IDs in a header, without the angle brackets */
    private static function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $m);

        return $m[1];
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
