<?php

namespace App\Services\Statements;

/**
 * Report-level figures the trial balance doesn't hold (Q38, Q39), applied to the closing figure of their lines:
 * - the user's adjusting entries: balanced Dr/Cr lines by account (the row's line or lines, by share) or by line key,
 *   origin `user_adjustment`, refs {type: adjustment, entry};
 * - the zakat charge input: Dr zakat expense, Cr zakat provision, origin `user_input`, refs {type: input, key}; the income
 *   tax charge the same, on its own lines (the zakat and income tax dialog's, `income_tax.charge`);
 * - the current portion of long-term loans (Q36): moved from the non-current to the current loans line, same.
 * Nothing here is created on its own: every amount is one the user entered.
 */
final class LineAdjustments
{
    /**
     * @param array<string, array> $aggregated LineAggregator::aggregate()
     * @param array                $mapping    LineMapper::map()
     * @param list<array>          $entries    [{number, reason, lines: [{row, line_key, debit, credit}]}]
     * @return array{aggregated: array<string, array>, unmapped: list<array{entry: int, row: int}>, zakat: float, postings: list<array>}
     *         zakat: what the entries charged to the zakat line (debit − credit);
     *         postings: each amount posted, {line, row (null for a line or an input), net: debit − credit, origin, ref}
     */
    public function apply(array $aggregated, array $mapping, LineCatalogue $catalogue, array $entries, ?float $zakatCharge, ?float $loansCurrentPortion = null, ?float $incomeTaxCharge = null): array
    {
        $parts    = [];
        $postings = [];
        $unmapped = [];
        $zakat    = 0.0;
        $post = function (string $line, float $net, string $origin, array $ref, ?int $row = null) use (&$parts, &$postings, $catalogue) {
            $amount = $catalogue->side($line) === 'debit' ? $net : -$net;
            $parts[$line][] = ['amount' => $amount, 'origin' => $origin, 'ref' => $ref];
            $postings[] = ['line' => $line, 'row' => $row, 'net' => $net, 'origin' => $origin, 'ref' => $ref];
        };

        foreach ($entries as $entry) {
            $ref = ['type' => 'adjustment', 'entry' => $entry['number']];
            foreach ($entry['lines'] as $l) {
                $net = (float) $l['debit'] - (float) $l['credit'];
                if ($l['line_key'] !== null) {
                    $post($l['line_key'], $net, 'user_adjustment', $ref);
                    $zakat += $l['line_key'] === 'is.zakat' ? $net : 0.0;
                    continue;
                }
                $lines = $mapping['rows'][$l['row']]['lines']['closing'] ?? null;
                if ($lines === null) {
                    $unmapped[] = ['entry' => $entry['number'], 'row' => $l['row']];
                    continue;
                }
                foreach ($lines as $line => $share) {
                    $post($line, round($net * $share, 2), 'user_adjustment', $ref, (int) $l['row']);
                    $zakat += $line === 'is.zakat' ? round($net * $share, 2) : 0.0;
                }
            }
        }
        if ($zakatCharge !== null) {
            $ref = ['type' => 'input', 'key' => 'zakat.charge'];
            $post('is.zakat', $zakatCharge, 'user_input', $ref);
            $post('sofp.zakat_provision', -$zakatCharge, 'user_input', $ref);
        }
        if ($incomeTaxCharge !== null && $incomeTaxCharge != 0.0) {
            $ref = ['type' => 'input', 'key' => 'income_tax.charge'];
            $post('is.income_tax', $incomeTaxCharge, 'user_input', $ref);
            $post('sofp.income_tax_provision', -$incomeTaxCharge, 'user_input', $ref);
        }
        if ($loansCurrentPortion !== null && $loansCurrentPortion != 0.0) {
            $ref = ['type' => 'input', 'key' => 'loans.current_portion'];
            $post('sofp.murabaha_loans_non_current', $loansCurrentPortion, 'user_input', $ref);
            $post('sofp.murabaha_loans_current', -$loansCurrentPortion, 'user_input', $ref);
        }

        foreach ($parts as $line => $items) {
            $base = $aggregated[$line]['closing'] ?? null;
            $origins = array_column($items, 'origin');
            $refs = $base?->refs ?? [];
            foreach ($items as $item) {
                if (! in_array($item['ref'], $refs, true)) {
                    $refs[] = $item['ref'];
                }
            }
            $aggregated[$line] = [
                'closing' => new Figure(round(($base?->amount ?? 0.0) + array_sum(array_column($items, 'amount')), 2) + 0.0,
                    in_array('user_adjustment', $origins, true) ? 'user_adjustment' : 'user_input', $base?->status ?? 'ok', $refs),
            ] + ($aggregated[$line] ?? ['opening' => null, 'period' => null, 'adjustment' => null, 'rows' => [], 'wrong_side' => []]);
        }

        return ['aggregated' => $aggregated, 'unmapped' => $unmapped, 'zakat' => round($zakat, 2), 'postings' => $postings];
    }
}
