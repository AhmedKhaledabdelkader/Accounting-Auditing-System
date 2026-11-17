<?php

namespace App\Support;

/**
 * An interface message not yet put into words: a lang key and its parameters, rendered in any locale. A parameter that
 * is a Phrase renders in the same locale; a list renders its items joined with the locale's list separator. Built once,
 * rendered twice where the API carries both: `*_ar` (always Arabic) and its sibling in the request's language.
 *
 * Messages stored for later (a run's warnings, flags and errors, an import's reasons) keep their Arabic text where it
 * always was and the phrase beside it (`toArray()`, read back by `fromArray()`), so the API can word them again in the
 * request's language; a record stored before phrases existed has none and stays Arabic (`localize()`).
 */
final class Phrase
{
    /** @param array<string, mixed> $params */
    private function __construct(
        private ?string $key,
        private array $params = [],
        private array $texts = [],
        private ?array $parts = null,
        private string $separator = '',
    ) {
    }

    /** @param array<string, mixed> $params values: string|int|float, Phrase, or a list of them */
    public static function make(string $key, array $params = []): self
    {
        return new self($key, $params);
    }

    /** Text already worded per locale (e.g. ['ar' => …, 'en' => …]); a locale not given reads the Arabic. */
    public static function text(array $byLocale): self
    {
        return new self(null, [], $byLocale);
    }

    /** Text that is the same in every language (data: an AI reason, a value from the file). */
    public static function raw(string $text): self
    {
        return new self(null, [], ['ar' => $text]);
    }

    /** Parts (Phrases or plain text) joined by a fixed separator in every locale (e.g. a row's note, ' | '). */
    public static function join(array $parts, string $separator): self
    {
        return new self(null, [], [], array_values($parts), $separator);
    }

    public function in(string $locale): string
    {
        if ($this->parts !== null) {
            return implode($this->separator, array_map(fn ($p) => self::render($p, $locale), $this->parts));
        }
        if ($this->key === null) {
            return (string) ($this->texts[$locale] ?? $this->texts['ar'] ?? reset($this->texts));
        }

        return __($this->key, array_map(fn ($v) => self::render($v, $locale), $this->params), $locale);
    }

    /** In the current (request's) locale. */
    public function current(): string
    {
        return $this->in(app()->getLocale());
    }

    /** The Arabic text: what is stored where messages were always stored. */
    public function ar(): string
    {
        return $this->in('ar');
    }

    public static function render(mixed $value, string $locale): string
    {
        return match (true) {
            $value instanceof self => $value->in($locale),
            is_array($value)       => implode(__('report_messages.list_separator', [], $locale), array_map(fn ($v) => self::render($v, $locale), $value)),
            default                => (string) $value,
        };
    }

    /**
     * The phrase as stored JSON: {key, params} (a param is a scalar, a stored phrase, or a list of them), {text: {locale:
     * text}}, or {join: [parts], sep}.
     */
    public function toArray(): array
    {
        if ($this->parts !== null) {
            return ['join' => array_map(fn ($p) => self::store($p), $this->parts), 'sep' => $this->separator];
        }
        if ($this->key === null) {
            return ['text' => $this->texts];
        }

        return ['key' => $this->key, 'params' => array_map(fn ($v) => self::store($v), $this->params)];
    }

    public static function fromArray(array $stored): self
    {
        if (isset($stored['join'])) {
            return self::join(array_map(fn ($p) => self::restore($p), $stored['join']), (string) ($stored['sep'] ?? ''));
        }
        if (isset($stored['text'])) {
            return self::text((array) $stored['text']);
        }

        return self::make((string) $stored['key'], array_map(fn ($v) => self::restore($v), (array) ($stored['params'] ?? [])));
    }

    private static function store(mixed $value): mixed
    {
        return match (true) {
            $value instanceof self => $value->toArray(),
            is_array($value)       => array_values(array_map(fn ($v) => self::store($v), $value)),
            default                => $value,
        };
    }

    private static function restore(mixed $value): mixed
    {
        return match (true) {
            is_array($value) && (isset($value['key']) || isset($value['text']) || isset($value['join'])) => self::fromArray($value),
            is_array($value) => array_map(fn ($v) => self::restore($v), $value),
            default          => $value,
        };
    }

    /**
     * A stored message in the given (default: the request's) locale: from its stored phrase, else the stored text as it
     * is (a record from before phrases, or one whose text is data).
     */
    public static function localize(?string $stored, mixed $phrase, ?string $locale = null): ?string
    {
        if (! is_array($phrase) || $phrase === []) {
            return $stored;
        }
        try {
            return self::fromArray($phrase)->in($locale ?? app()->getLocale());
        } catch (\Throwable) {
            return $stored;   // a malformed phrase never hides the message
        }
    }

    /** A stored list of messages with its parallel list of phrases: each in the locale; without phrases, as stored. */
    public static function localizeList(array $stored, mixed $phrases, ?string $locale = null): array
    {
        $phrases = is_array($phrases) && count($phrases) === count($stored) ? array_values($phrases) : [];

        return array_map(fn ($text, $i) => self::localize($text, $phrases[$i] ?? null, $locale), array_values($stored), array_keys(array_values($stored)));
    }
}
