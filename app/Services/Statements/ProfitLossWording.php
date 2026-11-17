<?php

namespace App\Services\Statements;

use App\Support\ClassificationKey;

/**
 * Profit or loss in a label by the sign of its figures (R11 keeps last year's lines, not a wrong sign): «صافي الخسارة
 * قبل الزكاة» becomes «صافي الربح قبل الزكاة» when this year is a profit. The years a label speaks for (one year, or
 * the two of a table) decide: all of one sign, the word of that sign; signs differing, the two words («الربح
 * (الخسارة)»). A label with both words already («صافي الدخل (الخسارة)») keeps them only while the signs differ. Each
 * change is said (`changed`).
 */
final class ProfitLossWording
{
    /** The profit word and its loss word, as printed forms (alef and ta marbuta read alike). */
    private const PAIRS = [
        ['أرباح مبقاة', 'خسائر متراكمة'],
        ['الأرباح المبقاة', 'الخسائر المتراكمة'],
        ['الأرباح', 'الخسائر'], ['أرباح', 'خسائر'],
        ['الربح', 'الخسارة'], ['ربح', 'خسارة'],
        ['الدخل', 'الخسارة'], ['دخل', 'خسارة'],
    ];

    /**
     * @param list<?float> $amounts the figures the label speaks for (null: no such figure, not counted; NAN: a figure still
     *                               missing, its sign unknown, so both words stay)
     * @return array{label: string, changed: bool}
     */
    public static function label(string $label, array $amounts): array
    {
        $signs = array_unique(array_map(fn ($a) => is_nan($a) ? 'unknown' : ($a < -0.005 ? 'loss' : 'profit'), array_filter($amounts, fn ($a) => $a !== null && (is_nan($a) || abs($a) > 0.005))));
        if (in_array('unknown', $signs, true)) {
            $signs = ['profit', 'loss'];
        }
        if ($signs === []) {
            return ['label' => $label, 'changed' => false];
        }
        $want = count($signs) === 1 ? reset($signs) : 'both';
        $out = $label;
        $done = false;
        // Both words already: «الربح (الخسارة)», «ربح ( خسارة )», «الدخل (الخسارة)»: one of them while the signs agree.
        foreach (self::PAIRS as [$profit, $loss]) {
            if (preg_match('/'.self::pattern($profit).'\s*\(\s*'.self::pattern($loss).'\s*\)/u', $out, $m)) {
                $out = $want === 'both' ? $out : str_replace($m[0], $want === 'profit' ? $profit : $loss, $out);
                $done = true;
                break;
            }
        }
        // One word: the other when the signs say so, both when they differ.
        foreach ($done ? [] : self::PAIRS as [$profit, $loss]) {
            // «الدخل» is profit only as a result («صافي الدخل»); «ضريبة الدخل» is a tax.
            if (in_array($profit, ['الدخل', 'دخل'], true) && ! preg_match('/(صافي|إجمالي|اجمالي|مجمل)\s+(ال)?دخل/u', $out)) {
                continue;
            }
            $hasLoss = preg_match('/(?<!\p{L})'.self::pattern($loss).'(?!\p{L})/u', $out, $ml) === 1;
            $hasProfit = preg_match('/(?<!\p{L})'.self::pattern($profit).'(?!\p{L})/u', $out, $mp) === 1;
            if ($hasLoss && $want !== 'loss') {
                $out = str_replace($ml[0], $want === 'profit' ? self::profitFor($loss, $profit) : self::profitFor($loss, $profit)." ({$loss})", $out);
            } elseif ($hasProfit && $want !== 'profit') {
                $out = str_replace($mp[0], $want === 'loss' ? $loss : "{$mp[0]} ({$loss})", $out);
            }
            if ($hasLoss || $hasProfit) {
                break;
            }
        }
        // The words that go with each: losses are accumulated, profits retained; «الخسارة» takes «الشاملة».
        if ($out !== $label) {
            $out = strtr($out, ['خسائر مبقاة' => 'خسائر متراكمة', 'الخسائر المبقاة' => 'الخسائر المتراكمة', 'أرباح متراكمة' => 'أرباح مبقاة',
                'الأرباح المتراكمة' => 'الأرباح المبقاة', 'الخسارة الشامل ' => 'الخسارة الشاملة ', 'الخسارة الشامل' => 'الخسارة الشاملة']);
            $out = str_replace(['الخسارة الشاملةة', 'الشاملة الآخر', 'الشاملة الاخر'], ['الخسارة الشاملة', 'الشاملة الأخرى', 'الشاملة الأخرى'], $out);
            $out = str_replace('الأخرىى', 'الأخرى', $out);
        }

        return ['label' => $out, 'changed' => $out !== $label];
    }

    /** The statements' result lines and retained earnings: their labels speak of a profit or a loss. */
    public const LINES = ['is.gross_profit', 'is.operating_profit', 'is.profit_before_zakat', 'is.net_profit', 'is.oci_total', 'is.total_comprehensive_income',
        'sofp.retained_earnings', 'cf.profit_before_zakat'];

    /** The changes in equity's rows of a result. */
    private const EQUITY_ROWS = ['net_profit', 'oci', 'total_comprehensive_income'];

    /**
     * Every label of a built report by its figures' sign: the statements' result lines (both years), the changes in
     * equity's result rows (each year's block by its own), the notes' lines from last year (both years). Each change is
     * an info check, «X» now «Y».
     *
     * @return array the built report, labels changed and `checks` added
     */
    public static function apply(array $built): array
    {
        $said = [];
        $relabel = function (array $row, array $amounts, string $where) use (&$said) {
            $r = self::label($row['label_ar'], $amounts);
            if ($r['changed']) {
                $said[] = ReportChecks::make('label_by_sign', 'info', null, null, null,
                    ReportChecks::msg('label_by_sign', ['from' => $row['label_ar'], 'to' => $r['label']]), ['line_key' => $row['key'] ?? null, 'scope' => $where]);
                $row = ['label_ar' => $r['label'], 'label_by_sign' => true, 'label_printed' => $row['label_ar']] + $row;
            }

            return $row;
        };
        // A result line's figure still missing: its sign is unknown, both words stay (a note's row keeps its own words).
        $amount = fn ($f) => $f instanceof Figure ? ($f->isMissing() ? NAN : $f->amount) : null;
        $known = fn ($f) => $f instanceof Figure && ! $f->isMissing() ? $f->amount : null;
        $firstYear = ($built['comparative']['source'] ?? null) === 'first_year';   // no comparative column
        foreach (['financial_position', 'comprehensive_income', 'cash_flows'] as $st) {
            foreach ($built['statements'][$st] ?? [] as $i => $row) {
                if (in_array($row['key'] ?? null, self::LINES, true)) {
                    $built['statements'][$st][$i] = $relabel($row, [$amount($row['current'] ?? null), $firstYear ? null : $amount($row['comparative'] ?? null)], 'current');
                }
            }
        }
        foreach (['current', 'comparative'] as $block) {
            foreach ($built['statements']['changes_in_equity'][$block] ?? [] as $i => $row) {
                if (in_array($row['key'] ?? null, self::EQUITY_ROWS, true)) {
                    $built['statements']['changes_in_equity'][$block][$i] = $relabel($row, [$amount($row['cells']['total'] ?? null)], $block);
                }
            }
        }
        foreach ($built['breakdowns'] ?? [] as $note => $b) {
            foreach ($b['rows'] as $i => $row) {
                $built['breakdowns'][$note]['rows'][$i] = $relabel($row, [$known($row['current'] ?? null), $known($row['comparative'] ?? null)], 'current');
            }
        }
        $built['checks'] = [...$built['checks'], ...$said];

        return $built;
    }

    /** The profit word for a loss word: «الخسارة» is «الربح» (not «الدخل»). */
    private static function profitFor(string $loss, string $profit): string
    {
        return ['الخسارة' => 'الربح', 'خسارة' => 'ربح'][$loss] ?? $profit;
    }

    /** A word as a pattern that reads hamza and ta marbuta forms alike. */
    private static function pattern(string $word): string
    {
        $chars = mb_str_split(ClassificationKey::name($word));

        return implode('', array_map(fn ($c) => match ($c) {
            'ا' => '[اأإآ]', 'ه' => '[هة]', 'ي' => '[يى]', ' ' => '\s+', default => preg_quote($c, '/'),
        }, $chars));
    }
}
