<?php

namespace App\Support;

/**
 * How an amount is written everywhere (R1, Q27): whole riyals, Western digits, «-» for zero, and a negative in
 * parentheses or with a minus sign (config financial_statements.negatives: one setting for the screens, Excel, Word and
 * PDF).
 */
final class Amounts
{
    public static function negatives(): string
    {
        return config('financial_statements.negatives') === 'minus' ? 'minus' : 'parentheses';
    }

    /** A whole riyal as written: 1,234 · (1,234) or -1,234 · «-» for zero. */
    public static function whole(float $amount): string
    {
        $v = round($amount);
        if ($v == 0) {
            return '-';
        }
        $s = number_format(abs($v));

        return $v > 0 ? $s : (self::negatives() === 'minus' ? "-{$s}" : "({$s})");
    }

    /** The Excel number format of the same rule, whole riyals or with $decimals decimals. */
    public static function xlsxFormat(int $decimals = 0): string
    {
        $n = '#,##0'.($decimals > 0 ? '.'.str_repeat('0', $decimals) : '');

        return '[$-409]'.$n.';'.(self::negatives() === 'minus' ? "-{$n}" : "({$n})").';"-"';
    }
}
