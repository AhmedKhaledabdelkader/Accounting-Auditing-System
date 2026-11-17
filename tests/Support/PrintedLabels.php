<?php

namespace Tests\Support;

/**
 * Labels read from a PDF, compared as their words, not their typing: an AI's transcription varies in the stretching
 * stroke (ـ), the spaces, the alef's hamza, a final «ى»/«ي» and «ة»/«ه». Numbers are never touched: an amount compares
 * exactly, and a label that is missing is still missing.
 */
trait PrintedLabels
{
    protected static function label(string $text): string
    {
        $text = (string) preg_replace(['/ـ+/u', '/\s+/u'], '', $text);

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }

    /** Every string key and string value as a label, recursively; numbers as they are. */
    protected static function labels(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::label($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[is_string($k) ? self::label($k) : $k] = self::labels($v);
        }

        return $out;
    }
}
