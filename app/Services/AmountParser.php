<?php

namespace App\Services;

class AmountParser
{
    /** A cell that is nothing but dashes ("-", "—", "–", "ـ", "--", "(-)"): the usual way to write zero in accounting files. */
    private const ZERO_MARKER = '/^\(?\s*[-‐‑‒–—―−ـ]+\s*\)?$/u';

    private const CURRENCY = ['ر.س', 'ريال', 'ج.م', 'جنيه', 'د.إ', 'درهم', 'sar', 'egp', 'aed', 'usd', 'eur', '$', '€', '£'];

    /**
     * Parse a cell into a number.
     * Empty or only dashes (a zero marker) → 0.0, unparseable → null.
     * Handles thousands separators (1,234.56 / 1.234,56), Arabic-Indic digits, (500) and 500- negatives,
     * currency tokens, and Dr/Cr (مدين/دائن) markers — credit markers make the value negative.
     */
    public static function parse(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $s = mb_strtolower(trim(self::asciiDigits($value)));
        if ($s === '' || preg_match(self::ZERO_MARKER, $s)) {
            return 0.0;
        }

        $negative = false;

        if (preg_match('/(^|\s)(cr|credit|دائن)\.?(\s|$)/u', $s)) {
            $negative = true;
        }
        $s = preg_replace('/(^|\s)(dr|cr|debit|credit|مدين|دائن)\.?(?=\s|$)/u', ' ', $s);
        $s = str_replace(self::CURRENCY, ' ', $s);
        $s = preg_replace('/[\s\x{00A0}\x{200F}\x{200E}]+/u', '', $s);

        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $negative = ! $negative;
            $s = $m[1];
        }
        if (str_starts_with($s, '-')) {
            $negative = ! $negative;
            $s = substr($s, 1);
        } elseif (str_ends_with($s, '-')) {
            $negative = ! $negative;
            $s = substr($s, 0, -1);
        }

        $s = self::normalizeSeparators($s);
        if ($s === null || ! preg_match('/^\d+(\.\d+)?$/', $s)) {
            return null;
        }

        return $negative ? -(float) $s : (float) $s;
    }

    public static function asciiDigits(string $s): string
    {
        return strtr($s, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',',
        ]);
    }

    private static function normalizeSeparators(string $s): ?string
    {
        $hasComma = str_contains($s, ',');
        $hasDot   = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // The separator that appears last is the decimal one.
            return strrpos($s, ',') > strrpos($s, '.')
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        }
        if ($hasComma) {
            if (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
                return str_replace(',', '', $s);
            }
            return substr_count($s, ',') === 1 ? str_replace(',', '.', $s) : null;
        }
        if ($hasDot && substr_count($s, '.') > 1) {
            return preg_match('/^\d{1,3}(\.\d{3})+$/', $s) ? str_replace('.', '', $s) : null;
        }
        return $s;
    }
}
