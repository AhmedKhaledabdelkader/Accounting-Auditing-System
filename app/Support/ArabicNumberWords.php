<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A whole number in Arabic words (R7), as financial statements write a count. Code, never AI, so the same number always
 * reads the same. Up to 999,999,999,999.
 *
 * - of(): the number alone («ثلاثة آلاف ومائتان»), in the nominative or the genitive (accusative = genitive here), with
 *   the forms a masculine or a feminine counted noun takes (ثلاثة/ثلاث, أحد عشر/إحدى عشرة, …).
 * - counted(): the number and its counted noun, the noun in the form the number gives it (حصة، حصتان، حصص), and the
 *   number's last word in construct before the noun (مائتا/مائتي، ألفا/ألفي، مليونا/مليوني): «ثلاثة آلاف ومائتي حصة».
 */
final class ArabicNumberWords
{
    public const NOMINATIVE = 'nominative';
    public const GENITIVE = 'genitive';

    /** The units 1–10 with a masculine counted noun (ثلاثة كتب) and with a feminine one (ثلاث حصص); 2 by case. */
    private const ONES = [
        'm' => ['', 'واحد', ['اثنان', 'اثنين'], 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة'],
        'f' => ['', 'واحدة', ['اثنتان', 'اثنتين'], 'ثلاث', 'أربع', 'خمس', 'ست', 'سبع', 'ثماني', 'تسع', 'عشر'],
    ];

    /** A unit before «وعشرون» …: 1 is أحد … only in 11; in 21 it is واحد / إحدى. */
    private const COMPOUND_ONE = ['m' => 'واحد', 'f' => 'إحدى'];

    private const TEN_OF_TEENS = ['m' => 'عشر', 'f' => 'عشرة'];

    private const TENS = ['', '', ['عشرون', 'عشرين'], ['ثلاثون', 'ثلاثين'], ['أربعون', 'أربعين'], ['خمسون', 'خمسين'], ['ستون', 'ستين'],
        ['سبعون', 'سبعين'], ['ثمانون', 'ثمانين'], ['تسعون', 'تسعين']];

    private const HUNDREDS = ['', 'مائة', null, 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    /** 200 by case, and in construct before its noun. */
    private const TWO_HUNDRED = ['free' => ['مائتان', 'مائتين'], 'construct' => ['مائتا', 'مائتي']];

    /** Each scale (a masculine noun): one; two by case, free and in construct; 3–10; 11–99 (accusative); after 100, or in construct. */
    private const SCALES = [
        1_000_000_000 => ['one' => 'مليار', 'two' => [['ملياران', 'مليارين'], ['مليارا', 'ملياري']], 'plural' => 'مليارات', 'accusative' => 'مليارًا', 'bare' => 'مليار'],
        1_000_000     => ['one' => 'مليون', 'two' => [['مليونان', 'مليونين'], ['مليونا', 'مليوني']], 'plural' => 'ملايين', 'accusative' => 'مليونًا', 'bare' => 'مليون'],
        1_000         => ['one' => 'ألف', 'two' => [['ألفان', 'ألفين'], ['ألفا', 'ألفي']], 'plural' => 'آلاف', 'accusative' => 'ألفًا', 'bare' => 'ألف'],
    ];

    /**
     * The number alone. $gender: the counted noun's ('m' or 'f'; it decides the units' forms of the last group);
     * $construct: the last word is followed by its counted noun (مائتا حصة، ألفا حصة).
     */
    public static function of(int $n, string $gender = 'm', string $case = self::NOMINATIVE, bool $construct = false): string
    {
        if ($n < 0 || $n > 999_999_999_999) {
            throw new InvalidArgumentException("Out of range: {$n}");
        }
        if ($n === 0) {
            return 'صفر';
        }
        $c = $case === self::GENITIVE ? 1 : 0;
        $parts = [];
        foreach (self::SCALES as $size => $names) {
            $count = intdiv($n, $size);
            $n %= $size;
            if ($count > 0) {
                $parts[] = self::scaled($count, $names, $c, $construct && $n === 0);
            }
        }
        if ($n > 0) {
            $parts[] = self::below1000($n, $gender, $c, $construct);
        }

        return implode(' و', $parts);
    }

    /**
     * The number and its counted noun, in the case the sentence gives (genitive after «إلى»).
     *
     * @param array{gender: string, singular: string, dual: array{0: string, 1: string}, plural: string} $noun
     * @return array{words: string, noun: string}
     */
    public static function counted(int $n, array $noun, string $case = self::GENITIVE): array
    {
        $c = $case === self::GENITIVE ? 1 : 0;
        $rest = $n % 100;
        $form = match (true) {
            $n === 2                  => $noun['dual'][$c],
            $rest >= 3 && $rest <= 10 => $noun['plural'],
            default                   => $noun['singular'],   // 1; 11–99 (accusative singular); 100, 1,000 … (genitive singular)
        };

        return ['words' => self::of($n, $noun['gender'], $case, $n > 2), 'noun' => $form];
    }

    /** A count of thousands (millions, …): «ألف», «ألفان», «ثلاثة آلاف», «أحد عشر ألفًا», «مائتا ألف», «مائة وخمسة آلاف». */
    private static function scaled(int $count, array $names, int $c, bool $last): string
    {
        if ($count === 1) {
            return $names['one'];
        }
        if ($count === 2) {
            return $names['two'][$last ? 1 : 0][$c];
        }
        $rest = $count % 100;
        // Followed by the counted noun, the scale is in construct: «ثلاثة آلاف حصة», «عشرين ألف حصة».
        $noun = $rest >= 3 && $rest <= 10 ? $names['plural'] : ($rest >= 11 && ! $last ? $names['accusative'] : $names['bare']);

        // The scale's noun is masculine, and the count is in construct before it (مائتا ألف).
        return self::below1000($count, 'm', $c, true).' '.$noun;
    }

    private static function below1000(int $n, string $gender, int $c, bool $construct): string
    {
        $parts = [];
        if ($n >= 100) {
            $h = intdiv($n, 100);
            $n %= 100;
            $parts[] = $h === 2 ? self::TWO_HUNDRED[$construct && $n === 0 ? 'construct' : 'free'][$c] : self::HUNDREDS[$h];
        }
        if ($n > 0) {
            $parts[] = self::below100($n, $gender, $c);
        }

        return implode(' و', $parts);
    }

    private static function below100(int $n, string $gender, int $c): string
    {
        $unit = fn (int $u) => is_array($w = self::ONES[$gender][$u]) ? $w[$c] : $w;
        if ($n <= 10) {
            return $unit($n);
        }
        if ($n < 20) {
            $first = match ($n) {
                11      => $gender === 'f' ? 'إحدى' : 'أحد',
                12      => $gender === 'f' ? ['اثنتا', 'اثنتي'][$c] : ['اثنا', 'اثني'][$c],
                default => $unit($n - 10),
            };

            return $first.' '.self::TEN_OF_TEENS[$gender];
        }
        $tens = self::TENS[intdiv($n, 10)][$c];
        $u = $n % 10;

        return $u === 0 ? $tens : ($u === 1 ? self::COMPOUND_ONE[$gender] : $unit($u)).' و'.$tens;
    }
}
