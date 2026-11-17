<?php

namespace App\Services\Statements;

/**
 * The current year's statement of financial position (plan §3.2). Lines are positive on their own side. In a pre-closing
 * trial balance the year's result still sits in the P&L accounts, so retained earnings = the account balance + net profit
 * (derived). In a post-closing one the balance already holds it.
 */
final class BalanceSheetBuilder
{
    private const RETAINED_EARNINGS = 'sofp.retained_earnings';

    public function __construct(private StatementBuilder $builder)
    {
    }

    /**
     * @param array<string, array>   $aggregated  LineAggregator::aggregate()
     * @param ?array<string, Figure> $comparative every line's comparative figure (retained earnings already final); null: none
     */
    public function build(LineCatalogue $catalogue, array $aggregated, Figure $netProfit, LayoutProfile $profile, ?array $comparative = null): array
    {
        $structure = $catalogue->statement('financial_position');
        $lines     = [];
        foreach (array_filter($structure, fn ($i) => $i['type'] === 'line') as $item) {
            $lines[$item['key']] = $aggregated[$item['key']]['closing'] ?? new Figure(0.0, 'trial_balance');
        }
        // Pre-closing: the year's result isn't in retained earnings yet. Post-closing with the P&L from movements, it is.
        if (! $profile->postClosing && ! $profile->plFromMovement) {
            $balance = $lines[self::RETAINED_EARNINGS];
            $lines[self::RETAINED_EARNINGS] = Figure::sum([$balance, $netProfit],
                [...$balance->refs, ['type' => 'line', 'key' => 'is.net_profit']]);
        }

        return $this->builder->build($structure, $lines, array_map(fn ($a) => $a['rows'], $aggregated), $comparative);
    }
}
