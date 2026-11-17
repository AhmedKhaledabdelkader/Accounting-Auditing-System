<?php

namespace App\Services\Statements;

/**
 * The current year's statement of comprehensive income (plan §3.3). P&L balances are year-to-date in every layout.
 * Displayed as the template shows them: income +, expenses −. A post-closing trial balance has no P&L to show (Q22):
 * every line is missing, never 0. OCI is user input: missing until the user says whether there is any
 * (`oci.has_items`); with none, every item is 0 on the user's word; with some, each item is what was entered (empty = 0).
 */
final class IncomeStatementBuilder
{
    /** The «no OCI» answer was derived from the books, not given by the user (OciDerivation). */
    private bool $ociDerived = false;

    public function __construct(private StatementBuilder $builder)
    {
    }

    /**
     * @param array<string, array> $aggregated LineAggregator::aggregate(), after LineAdjustments
     * @param array                $inputs     the report's inputs
     * @param ?array<string, Figure> $comparative every line's comparative figure as displayed; null: none (first year)
     */
    public function build(LineCatalogue $catalogue, array $aggregated, LayoutProfile $profile, array $inputs = [], ?array $comparative = null, bool $ociDerived = false): array
    {
        $this->ociDerived = $ociDerived;
        $structure = $catalogue->statement('comprehensive_income');
        $lines     = [];
        foreach (array_filter($structure, fn ($i) => $i['type'] === 'line') as $item) {
            $key = $item['key'];
            if ($profile->postClosing) {
                $lines[$key] = Figure::missing();
                continue;
            }
            if ($item['input']) {
                $lines[$key] = $this->oci($key, $inputs);
                continue;
            }
            $f = $aggregated[$key]['closing'] ?? new Figure(0.0, 'trial_balance');
            $lines[$key] = $catalogue->side($key) === 'credit' ? $f : new Figure(round(-$f->amount, 2) + 0.0, $f->origin, $f->status, $f->refs);
        }

        return $this->builder->build($structure, $lines, array_map(fn ($a) => $a['rows'], $aggregated), $comparative);
    }

    private function oci(string $line, array $inputs): Figure
    {
        $has = $inputs['oci.has_items'] ?? null;
        if ($has === null) {
            return Figure::missing();
        }
        $key = array_search($line, ReportIntake::OCI, true);

        return $has
            ? new Figure((float) ($inputs[$key] ?? 0.0), 'user_input', 'ok', [['type' => 'input', 'key' => $key]])
            : new Figure(0.0, $this->ociDerived ? 'derived' : 'user_input', 'ok', [['type' => 'input', 'key' => 'oci.has_items']]);
    }
}
