<?php

namespace App\Services\Statements;

/**
 * The zakat base and income tax of the year, from the statements' figures (the zakat and income tax dialog).
 *
 * The method is the accountants' zakat sheet (`أحتساب الزكاه.xlsx`, kept with the regulation) under the Zakat Collection
 * Regulation of 1445 H (articles 15, 20–31, 36–41), line for line:
 *
 *   base = equity and what is treated as equity (capital, reserves, retained earnings ± losses, provisions at their end
 *          balance but for end-of-service and leave, dividends declared and not paid, partners' loans)
 *        + non-current liabilities, within the assets deducted (art. 29, 25: after the allocation «التنسيب» when a current
 *          asset is deducted or a non-current one isn't)
 *        + the difference between the adjusted and the book profit (art. 23)
 *        + current liabilities beyond the current assets (art. 29(2)(b))
 *        − the assets deducted (art. 26: fixed assets, intangibles, investments)
 *   minimum base (art. 27) = the lower of the undeducted assets (+ the profit difference) and the adjusted profit
 *   maximum base (art. 28) = equity + the profit difference
 *   final base = the larger of the minimum and the lower of the base and the maximum; never below nothing
 *   zakat = final base × 2.5% × the year's actual days ÷ 354 (art. 15), on the Saudi and GCC owners' share
 *   income tax = 20% of the foreign owners' share of the adjusted profit, after the carried-forward losses (at most a
 *                quarter of the year's profit, the tax regulation's art. 11)
 *
 * Everything is worked on the statements as they stand before the year's own zakat and income tax: what the report has
 * already charged is added back to retained earnings and taken off the provisions, so charging the result changes nothing
 * in the next calculation. The accountant's answers (INPUTS) are the facts the statements can't give.
 */
final class ZakatCalculator
{
    public const RATE = 0.025;
    public const HIJRI_DAYS = 354;
    public const TAX_RATE = 0.20;
    public const LOSS_LIMIT = 0.25;

    /** Equity and what the regulation treats as equity (art. 23(1), 24, 36, 39). */
    private const EQUITY = ['sofp.share_capital', 'sofp.statutory_reserve', 'sofp.other_reserves', 'sofp.retained_earnings', 'sofp.partners_current_equity',
        'sofp.dividends_payable', 'sofp.zakat_provision', 'sofp.income_tax_provision', 'sofp.other_provisions'];

    /** Owners' balances: equity unless the conditions of art. 30 are met (then liabilities), never offset against debit balances (art. 34). */
    private const RELATED = ['sofp.due_to_related_parties_non_current', 'sofp.due_to_related_parties', 'sofp.partners_current_liability'];

    private const NON_CURRENT_LIABILITIES = ['sofp.murabaha_loans_non_current', 'sofp.lease_liabilities_non_current', 'sofp.employee_benefits'];

    private const CURRENT_LIABILITIES = ['sofp.trade_payables', 'sofp.murabaha_loans_current', 'sofp.lease_liabilities_current', 'sofp.bank_overdraft',
        'sofp.contract_liabilities', 'sofp.accruals_other_payables'];

    /** The non-current assets the accountant may deduct (art. 26), and the ones deducted unless said otherwise (art. 43–51). */
    public const NON_CURRENT_ASSETS = ['sofp.ppe', 'sofp.investment_property', 'sofp.intangible_assets', 'sofp.other_assets', 'sofp.associates',
        'sofp.fvoci_investments', 'sofp.amortised_cost_non_current'];
    public const DEDUCT_DEFAULT = ['sofp.ppe', 'sofp.investment_property', 'sofp.intangible_assets', 'sofp.associates', 'sofp.fvoci_investments'];

    private const CURRENT_ASSETS = ['sofp.inventory', 'sofp.contract_assets', 'sofp.trade_receivables', 'sofp.prepayments_other_receivables',
        'sofp.amortised_cost_current', 'sofp.due_from_related_parties', 'sofp.murabaha_deposits', 'sofp.cash'];

    /** The accountant's answers: key → type (`share` 0–100, `amount` ≥ 0, `flag`, `lines`) and the default. */
    public const INPUTS = [
        'saudi_share'             => ['share', 100.0],
        'profit_additions'        => ['amount', 0.0],
        'profit_deductions'       => ['amount', 0.0],
        'loss_carryforward'       => ['amount', 0.0],
        'deducted_current_assets' => ['amount', 0.0],
        'noncurrent_as_current'   => ['amount', 0.0],
        'extra_additions'         => ['amount', 0.0],
        'extra_deductions'        => ['amount', 0.0],
        'related_as_liability'    => ['flag', false],
        'deduct_lines'            => ['lines', self::DEDUCT_DEFAULT],
        // The accountant's own figure for a line of the sheet (line key → amount), instead of the statements': the calculation
        // is theirs to correct. `is.profit_before_zakat` corrects the profit the adjusted profit starts from.
        'overrides'               => ['map', []],
    ];

    /** The lines the accountant may correct (overrides). */
    public static function overridable(): array
    {
        return [...self::EQUITY, ...self::RELATED, ...self::NON_CURRENT_LIABILITIES, ...self::CURRENT_LIABILITIES, ...self::NON_CURRENT_ASSETS, ...self::CURRENT_ASSETS, 'is.profit_before_zakat'];
    }

    /**
     * Errors of the accountant's answers (key → message code), none when valid. A null removes an answer.
     *
     * @param array<string, mixed> $inputs
     * @return array<string, string>
     */
    public static function errors(array $inputs): array
    {
        $errors = [];
        foreach ($inputs as $key => $value) {
            $type = self::INPUTS[$key][0] ?? null;
            if ($type === null) {
                $errors[$key] = 'zakat_input_unknown';
            } elseif ($value === null) {
                continue;
            } elseif ($type === 'share' && ! ((is_int($value) || is_float($value)) && $value >= 0 && $value <= 100)) {
                $errors[$key] = 'zakat_input_share';
            } elseif ($type === 'amount' && ! ((is_int($value) || is_float($value)) && $value >= 0)) {
                $errors[$key] = 'zakat_input_amount';
            } elseif ($type === 'flag' && ! is_bool($value)) {
                $errors[$key] = 'boolean';
            } elseif ($type === 'lines' && ! (is_array($value) && array_is_list($value) && count(array_unique($value)) === count($value)
                    && array_diff($value, self::NON_CURRENT_ASSETS) === [])) {
                $errors[$key] = 'zakat_input_lines';
            } elseif ($type === 'map' && ! (is_array($value) && ($value === [] || ! array_is_list($value))
                    && array_diff(array_keys($value), self::overridable()) === []
                    && array_filter($value, fn ($v) => $v !== null && ! is_int($v) && ! is_float($v)) === [])) {
                $errors[$key] = 'zakat_input_overrides';
            }
        }

        return $errors;
    }

    /** The answers with their defaults. */
    public static function inputs(array $saved): array
    {
        $out = [];
        foreach (self::INPUTS as $key => [$type, $default]) {
            $out[$key] = array_key_exists($key, $saved) && $saved[$key] !== null ? ($type === 'lines' ? array_values($saved[$key]) : $saved[$key]) : $default;
            if ($type === 'map') {
                $out[$key] = array_map('floatval', array_filter((array) $out[$key], fn ($v) => $v !== null));
            }
            $out[$key] = in_array($type, ['share', 'amount'], true) ? (float) $out[$key] : $out[$key];
        }

        return $out;
    }

    /** Days of the period, both ends counted (art. 15: the actual days of the zakat year). */
    public static function days(string $start, string $end): int
    {
        return (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
    }

    /**
     * @param array{financial_position: list<array>, comprehensive_income: list<array>} $statements the report's statements (rows with `current` Figures)
     * @param array<string, mixed> $saved the accountant's answers
     * @param array{start: string, end: string} $period
     * @param array{zakat_source?: ?string, tax_source?: ?string, applied?: array{zakat?: float, income_tax?: float}} $charged where the year's zakat and
     *        income tax are booked now (`trial_balance`, `input`, `dialog` or null), and what the dialog took into the statements
     * @return array<string, mixed>
     */
    public static function of(array $statements, array $saved, array $period, array $charged = []): array
    {
        $in = self::inputs($saved);
        $rows = [];
        foreach (['financial_position', 'comprehensive_income'] as $statement) {
            foreach ($statements[$statement] ?? [] as $r) {
                if (($r['type'] ?? null) === 'line' || ($r['type'] ?? null) === 'total') {
                    $rows[$r['key']] = $r;
                }
            }
        }
        $figure = fn (string $key): ?float => isset($rows[$key]['current']) && ! $rows[$key]['current']->isMissing() ? round($rows[$key]['current']->amount, 2) : null;
        $amount = fn (string $key): float => $figure($key) ?? 0.0;
        $label = fn (string $key): string => $rows[$key]['label_ar'] ?? $key;
        $warnings = [];

        // What the report has charged for the year already, added back: the calculation stands before it.
        $zakatBooked = -$amount('is.zakat');
        $taxBooked = -$amount('is.income_tax');
        // A line the accountant corrected: their figure, the statements' kept beside it (`computed`).
        $overrides = $in['overrides'];
        $row = function (string $line, float $value, array $extra = []) use ($label, $overrides) {
            $edited = array_key_exists($line, $overrides);

            return ['line' => $line, 'label' => $label($line), 'amount' => round($edited ? $overrides[$line] : $value, 2) + 0.0]
                + $extra + ['edited' => $edited, 'computed' => round($value, 2) + 0.0];
        };
        $equity = $liabilitiesNc = $liabilitiesC = [];
        foreach (self::EQUITY as $line) {
            $value = $amount($line);
            $before = match ($line) {
                'sofp.retained_earnings' => $value + $zakatBooked + $taxBooked,
                'sofp.zakat_provision' => $value - $zakatBooked,
                'sofp.income_tax_provision' => $value - $taxBooked,
                default => $value,
            };
            if (abs($before) >= 0.005 || abs($value) >= 0.005 || array_key_exists($line, $overrides)) {
                $equity[] = $row($line, $before, ['reported' => $value + 0.0, 'before_charge' => abs($before - $value) >= 0.005]);
            }
        }
        foreach (self::RELATED as $line) {
            $value = max(0.0, $amount($line));
            if ($value < 0.005) {
                continue;
            }
            if ($in['related_as_liability']) {
                $nonCurrent = $line === 'sofp.due_to_related_parties_non_current';
                $nonCurrent ? $liabilitiesNc[] = $row($line, $value) : $liabilitiesC[] = $row($line, $value);
            } else {
                $equity[] = $row($line, $value, ['related' => true]);
            }
        }
        foreach (self::NON_CURRENT_LIABILITIES as $line) {
            abs($amount($line)) >= 0.005 && $liabilitiesNc[] = $row($line, $amount($line));
        }
        foreach (self::CURRENT_LIABILITIES as $line) {
            abs($amount($line)) >= 0.005 && $liabilitiesC[] = $row($line, $amount($line));
        }
        $deductible = [];
        foreach (self::NON_CURRENT_ASSETS as $line) {
            abs($amount($line)) >= 0.005 && $deductible[] = $row($line, $amount($line), ['deducted' => in_array($line, $in['deduct_lines'], true)]);
        }
        $current = [];
        foreach (self::CURRENT_ASSETS as $line) {
            abs($amount($line)) >= 0.005 && $current[] = $row($line, $amount($line));
        }
        $sum = fn (array $list, ?callable $keep = null) => round(array_sum(array_map(fn ($r) => $r['amount'], $keep ? array_filter($list, $keep) : $list)), 2);

        [$equityTotal, $ncl, $cl, $ca] = [$sum($equity), $sum($liabilitiesNc), $sum($liabilitiesC), $sum($current)];
        $fa = $sum($deductible, fn ($r) => $r['deducted']);

        // The allocation (art. 25): a current asset deducted brings its share of the current liabilities in; a non-current
        // one not deducted takes its share of the non-current liabilities out. Neither beyond the asset itself.
        $a = min($in['deducted_current_assets'], max($ca, 0.0));
        $b = min($in['noncurrent_as_current'], max($fa, 0.0));
        $a < $in['deducted_current_assets'] && $warnings[] = 'allocation_current_capped';
        $b < $in['noncurrent_as_current'] && $warnings[] = 'allocation_non_current_capped';
        $addedFromCurrent = $a > 0 && $ca > 0 ? min($cl * $a / $ca, $a) : 0.0;
        $removedFromNonCurrent = $b > 0 && $fa > 0 ? min($ncl * $b / $fa, $b) : 0.0;
        $nclAfter = round($ncl + $addedFromCurrent - $removedFromNonCurrent, 2);
        $faAfter = round($fa + $a - $b, 2);
        $clAfter = round($cl + $removedFromNonCurrent - $addedFromCurrent, 2);
        $caAfter = round($ca + $b - $a, 2);

        // The profit: the adjusted one starts from the profit before the year's zakat and income tax (the book one is after
        // them in the statements; both are taken before, so their difference is the accountant's adjustments alone).
        $profitBefore = array_key_exists('is.profit_before_zakat', $overrides) ? $overrides['is.profit_before_zakat'] : $figure('is.profit_before_zakat');
        $profitBefore === null && $warnings[] = 'profit_missing';
        $difference = round($in['profit_additions'] - $in['profit_deductions'], 2);
        $adjusted = round(($profitBefore ?? 0.0) + $difference, 2);

        $nclAdded = max(0.0, min($nclAfter, $faAfter));
        $currentExcess = max(0.0, round($clAfter - $caAfter, 2));
        $base = round($equityTotal + $nclAdded + $difference + $currentExcess + $in['extra_additions'] - $faAfter - $in['extra_deductions'], 2);
        $minimum = round(min($caAfter + $difference, $adjusted), 2);
        $maximum = round($equityTotal + $difference, 2);
        $limited = min($base, $maximum);
        $final = max(0.0, max($minimum, $limited));
        $binding = match (true) {
            $final <= 0.0 => 'none',
            $minimum > $limited => 'minimum',
            $base > $maximum => 'maximum',
            default => 'base',
        };

        $days = self::days($period['start'], $period['end']);
        $rate = self::RATE * $days / self::HIJRI_DAYS;
        $share = $in['saudi_share'] / 100;
        $full = round($final * $rate, 2);
        $zakat = round($final * $rate * $share, 2);

        $foreign = 1 - $share;
        $taxShare = max(0.0, $adjusted) * $foreign;
        $lossShare = $in['loss_carryforward'] * $foreign;
        $offset = round(min($lossShare, self::LOSS_LIMIT * $taxShare), 2);
        $taxable = round($taxShare - $offset, 2);
        $tax = round($taxable * self::TAX_RATE, 2);

        $faTotalAll = $sum($deductible);
        $equityTotal <= 0 && $warnings[] = 'equity_not_positive';
        $charged += ['zakat_source' => null, 'tax_source' => null, 'applied' => []];

        return [
            'inputs' => $in, 'defaults' => array_map(fn ($d) => $d[1], self::INPUTS),
            'period' => ['start' => $period['start'], 'end' => $period['end'], 'days' => $days, 'hijri_days' => self::HIJRI_DAYS],
            'equity' => ['rows' => $equity, 'total' => $equityTotal],
            'non_current_liabilities' => ['rows' => $liabilitiesNc, 'total' => $ncl],
            'current_liabilities' => ['rows' => $liabilitiesC, 'total' => $cl],
            'non_current_assets' => ['rows' => $deductible, 'deducted' => $fa, 'total' => $faTotalAll],
            'current_assets' => ['rows' => $current, 'total' => $ca],
            'allocation' => ['current_asset_deducted' => $a, 'non_current_as_current' => $b, 'current_liabilities_added' => round($addedFromCurrent, 2),
                'non_current_liabilities_removed' => round($removedFromNonCurrent, 2)],
            'profit' => ['before_charges' => $profitBefore, 'before_charges_edited' => array_key_exists('is.profit_before_zakat', $overrides), 'before_charges_computed' => $figure('is.profit_before_zakat'), 'difference' => $difference, 'adjusted' => $adjusted],
            'base' => ['equity' => $equityTotal, 'non_current_liabilities' => $nclAdded, 'profit_difference' => $difference, 'current_excess' => $currentExcess,
                'extra_additions' => $in['extra_additions'], 'deducted_assets' => -$faAfter, 'extra_deductions' => -$in['extra_deductions'], 'total' => $base],
            'minimum' => ['assets' => $caAfter, 'profit_difference' => $difference, 'adjusted_profit' => $adjusted, 'total' => $minimum],
            'maximum' => ['equity' => $equityTotal, 'profit_difference' => $difference, 'total' => $maximum],
            'final_base' => round($final, 2), 'binding' => $binding,
            'zakat' => ['rate' => round($rate, 6), 'full' => $full, 'saudi_share' => $in['saudi_share'], 'amount' => $zakat],
            'income_tax' => ['foreign_share' => round($foreign * 100, 4), 'profit' => round($taxShare, 2), 'loss_offset' => $offset, 'taxable' => $taxable,
                'rate' => self::TAX_RATE, 'amount' => $tax],
            'charged' => ['zakat_source' => $charged['zakat_source'], 'tax_source' => $charged['tax_source'], 'zakat' => round($zakatBooked, 2) + 0.0, 'income_tax' => round($taxBooked, 2) + 0.0],
            'applied' => ['zakat' => $charged['applied']['zakat'] ?? null, 'income_tax' => $charged['applied']['income_tax'] ?? null],
            'can_apply' => ['zakat' => in_array($charged['zakat_source'], [null, 'dialog'], true) && $zakat > 0,
                'income_tax' => in_array($charged['tax_source'], [null, 'dialog'], true) && $tax > 0],
            'warnings' => $warnings,
        ];
    }
}
