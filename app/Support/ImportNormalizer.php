<?php

namespace App\Support;

/**
 * Spelling variants of hand-written classes collapse to one form, so each distinct value is mapped once:
 * ClassificationKey::name, then punctuation and «بالصافي» dropped, «و» split off, «ال» removed from every word.
 * «الذمم الدائنة التجارية» and «ذمم دائنة تجارية» → «ذمم دائنه تجاريه».
 */
final class ImportNormalizer
{
    public static function text(?string $text): string
    {
        $text = ClassificationKey::name(mb_strtolower((string) $text));
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        $words = [];
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if ($w === 'و') {
                continue;
            }
            if (str_starts_with($w, 'وال') && mb_strlen($w) > 4) {
                $w = mb_substr($w, 1);
            }
            if (str_starts_with($w, 'ال') && mb_strlen($w) > 4) {
                $w = mb_substr($w, 2);
            }
            $words[] = $w;
        }
        while ($words && in_array(end($words), ['بالصافي', 'صافي'], true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /** Whether the text has an Arabic letter (a الميزانية value without one is an account name from a shifted column). */
    public static function isArabic(?string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', (string) $text);
    }
}
