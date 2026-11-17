<?php

namespace App\Services\Statements;

use App\Repositories\Contracts\TaxonomyRepositoryInterface;

/**
 * Sums mapped rows per line (plan §4.2 step 3): closing, opening when the file has it, and the period and adjustment
 * movements when it has them. Amounts are signed by the line's side (a credit line's credit balance is positive).
 * A row on the wrong side of its line is counted and listed in wrong_side, never netted away silently; a contra account
 * (an allowance under receivables) is expected to sit on the other side.
 */
final class LineAggregator
{
    public function __construct(private LineCatalogue $catalogue, private TaxonomyRepositoryInterface $taxonomy)
    {
    }

    /**
     * @param list<array> $rows    the run's rows
     * @param array       $mapping LineMapper::map() result
     * @return array<string, array{closing: ?Figure, opening: ?Figure, period: ?array, adjustment: ?array, rows: list<int>, wrong_side: list<array>}>
     */
    public function aggregate(array $rows, array $mapping, LayoutProfile $profile): array
    {
        $tolerance = (float) config('classification.materiality_threshold', 1.0);
        // Classes normal on either side (accumulated losses) are never on the wrong side; other assets' amortisation is a contra.
        $eitherSide = config('classification.either_side_classes', []);
        $amortisation = $this->catalogue->otherAssetsAmortisation();
        $byRow     = array_column($rows, null, 'row');
        $acc       = [];

        foreach ($mapping['rows'] as $n => $m) {
            $row     = $byRow[$n];
            $pending = $m['status'] === 'pending_confirmation';

            foreach (['closing' => $profile->closing($row), 'opening' => $profile->opening($row)] as $group => $net) {
                if ($net === null) {
                    continue;
                }
                foreach ($m['lines'][$group] as $line => $share) {
                    $amount = round($this->signed($line, $net) * $share, 2);
                    $this->add($acc, $line, $group, $amount, $n, $share, $pending);
                    $sub = $row['sub_class'] ?? null;
                    $expected = ($m['contra'] ?? false) || $this->isContra($sub) || in_array($sub, $amortisation, true) ? -1 : 1;
                    if ($amount * $expected < -$tolerance && ! in_array($sub, $eitherSide, true)) {
                        $acc[$line]['wrong_side'][] = ['row' => $n, 'group' => $group, 'amount' => $amount];
                    }
                }
            }
            // Movements follow the closing line(s).
            foreach (['period', 'adjustment'] as $group) {
                $movement = $profile->movement($row, $group);
                if ($movement === null) {
                    continue;
                }
                foreach ($m['lines']['closing'] as $line => $share) {
                    foreach (['debit', 'credit'] as $side) {
                        $this->add($acc, $line, "{$group}.{$side}", round($movement[$side] * $share, 2), $n, $share, $pending);
                    }
                }
            }
        }

        $out = [];
        foreach ($acc as $line => $a) {
            $figure = fn (string $key, string $origin) => isset($a[$key])
                ? new Figure(round(array_sum($a[$key]['amounts']), 2), $origin, $a[$key]['pending'] ? 'pending_confirmation' : 'ok', $a[$key]['refs'])
                : null;
            $movement = fn (string $group) => $profile->has($group)
                ? ['debit' => $figure("{$group}.debit", 'trial_balance'), 'credit' => $figure("{$group}.credit", 'trial_balance')]
                : null;
            $out[$line] = [
                'closing'    => $profile->has('closing') || $profile->has('period') ? ($figure('closing', 'trial_balance') ?? new Figure(0.0, 'trial_balance')) : null,
                'opening'    => $profile->has('opening') ? ($figure('opening', 'trial_balance_opening') ?? new Figure(0.0, 'trial_balance_opening')) : null,
                'period'     => $movement('period'),
                'adjustment' => $movement('adjustment'),
                'rows'       => array_values(array_unique($a['rows'])),
                'wrong_side' => $a['wrong_side'] ?? [],
            ];
        }

        return $out;
    }

    private function add(array &$acc, string $line, string $key, float $amount, int $row, float $share, bool $pending): void
    {
        $acc[$line]['rows'][] = $row;
        $acc[$line][$key]['amounts'][] = $amount;
        $acc[$line][$key]['refs'][]    = ['type' => 'row', 'row' => $row] + ($share < 1.0 ? ['share' => $share] : []);
        $acc[$line][$key]['pending']   = ($acc[$line][$key]['pending'] ?? false) || $pending;
    }

    /** debit − credit → positive when on the line's side. */
    private function signed(string $line, float $net): float
    {
        return $this->catalogue->side($line) === 'debit' ? $net : -$net;
    }

    /** A contra account (an allowance, purchase returns): its normal side is the minority side of its main class. */
    public function isContra(?string $subClass): bool
    {
        $entry = $subClass !== null ? $this->taxonomy->find($subClass) : null;
        if ($entry === null) {
            return false;
        }
        $sides = array_count_values(array_column(array_filter($this->taxonomy->map(), fn ($e) => $e[1] === $entry[1]), 2));
        return ($sides[$entry[2]] ?? 0) < ($sides[$entry[2] === 'debit' ? 'credit' : 'debit'] ?? 0);
    }
}
