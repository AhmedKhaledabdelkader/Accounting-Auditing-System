<?php

namespace App\Services\Statements;

/**
 * Whether the year has other comprehensive income, found from the books instead of asked (accountant's review, note 7).
 * OCI is never in a trial balance, but it lands in an equity reserve: so when no reserve other than the statutory one moved
 * in the year, and last year's statements show no OCI either (or there is no reserve at all), the year has none. That is a
 * derived answer, shown as such and open to the user's own; anything else stays a question.
 */
final class OciDerivation
{
    /**
     * @param array<string, array>   $aggregated   LineAggregator::aggregate() (after adjustments)
     * @param ?array<string, Figure> $comparative  the comparative income statement's lines; null: no prior year (first year)
     * @return ?bool false when the year has none by derivation; null when it can't be told (the user answers)
     */
    public static function hasNone(array $aggregated, ?array $comparative, LayoutProfile $profile): ?bool
    {
        if ($profile->postClosing) {
            return null;
        }
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $reserve   = $aggregated['sofp.other_reserves'] ?? null;
        $closing   = $reserve['closing']->amount ?? 0.0;
        $opening   = $reserve !== null && isset($reserve['opening']) && ! $reserve['opening']->isMissing() ? $reserve['opening']->amount : null;
        $none      = abs($closing) <= $tolerance && ($opening === null || abs($opening) <= $tolerance);
        $moved     = $opening !== null && abs($closing - $opening) > $tolerance;
        if ($moved) {
            return null;
        }
        if ($none) {
            return false;
        }
        // A reserve with a balance that did not move: no OCI this year if last year's statements show none either.
        $prior = $comparative['is.oci_total'] ?? null;
        if ($opening !== null && $prior !== null && ! $prior->isMissing() && abs($prior->amount) <= $tolerance) {
            return false;
        }

        return null;
    }
}
