<?php

namespace App\Support\Llm;

use App\Models\ClassificationRun;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** The USD cost of a run's AI calls, from the provider's usage and config('classification.prices'). Never estimated from text. */
final class LlmCost
{
    /** Calls the classification cache saves when it answers a row: the batches (and their retries) and the cache warm-up. */
    private const ROW_KINDS = ['rows', 'warm'];

    /**
     * The model's price in effect on $at (UTC day; null = today): the entry with the latest `from` not after it.
     *
     * @return array{input: float, cache_write: float, cache_read: float, output: float}|null  per million tokens; null = unknown model
     */
    public static function prices(?string $model, ?DateTimeInterface $at = null): ?array
    {
        $day   = Carbon::instance($at ?? now())->utc()->toDateString();
        $price = null;
        foreach ((array) (config('classification.prices')[$model] ?? null) as $entry) {   // not a dot path: model names have dots
            if (is_array($entry) && ($entry['from'] ?? null) <= $day && ($price === null || ($entry['from'] ?? '') >= ($price['from'] ?? ''))) {
                $price = $entry;
            }
        }
        if ($price === null) {
            return null;
        }

        return array_map('floatval', array_intersect_key($price, array_flip(['input', 'cache_write', 'cache_read', 'output']))
            + ['input' => 0, 'cache_write' => 0, 'cache_read' => 0, 'output' => 0]);
    }

    /** @param  array{input?: int, cache_write?: int, cached?: int, output?: int}  $usage */
    public static function usd(array $usage, ?string $model, ?DateTimeInterface $at = null): ?float
    {
        $p = self::prices($model, $at);
        if ($p === null) {
            return null;
        }

        return (($usage['input'] ?? 0) * $p['input'] + ($usage['cache_write'] ?? 0) * $p['cache_write']
            + ($usage['cached'] ?? 0) * $p['cache_read'] + ($usage['output'] ?? 0) * $p['output']) / 1_000_000;
    }

    /**
     * The run's columns: tokens, requests, actual cost, and the ESTIMATED savings of the rows the cache answered.
     * Savings = cost per row sent × rows from cache, where the cost per row only counts the calls the cache avoids
     * (row batches and the warm-up, not structure detection or budget-item matching). A run that sent no rows uses the
     * average of recent runs of the same model. Priced as of $startedAt (the run's start; null = now).
     */
    public static function forRun(UsageMeter $meter, ?string $model, ?DateTimeInterface $startedAt, int $rowsSent, int $rowsFromCache): array
    {
        $totals  = $meter->totals();
        $cost    = self::usd($totals, $model, $startedAt);
        $rowCost = self::usd(array_reduce(array_intersect_key($meter->kinds(), array_flip(self::ROW_KINDS)), function ($sum, $k) {
            foreach ($k as $field => $value) {
                $sum[$field] = ($sum[$field] ?? 0) + $value;
            }
            return $sum;
        }, []), $model, $startedAt);
        $perRow = $rowsSent > 0 && $rowCost !== null ? $rowCost / $rowsSent : null;

        [$perRowBasis, $basis] = match (true) {
            $rowsFromCache === 0 => [null, 'none'],
            $perRow !== null     => [$perRow, 'this_run'],
            default              => [$recent = self::recentCostPerRow($model), $recent === null ? 'unknown' : 'recent_runs'],
        };

        return [
            'input_tokens'          => $totals['input'],
            'output_tokens'         => $totals['output'],
            'cache_read_tokens'     => $totals['cached'],
            'cache_write_tokens'    => $totals['cache_write'],
            'llm_requests'          => $meter->attempts(),
            'cost_usd'              => $cost === null ? null : round($cost, 6),
            'cost_per_row_usd'      => $perRow === null ? null : round($perRow, 8),
            'estimated_savings_usd' => match ($basis) {
                'none'    => 0.0,
                'unknown' => null,
                default   => round($perRowBasis * $rowsFromCache, 6),
            },
            'savings_basis'         => $basis,
        ];
    }

    /** Average cost per row sent, weighted by rows, over the last finished runs of the model that sent rows. */
    private static function recentCostPerRow(?string $model): ?float
    {
        $runs = ClassificationRun::where('status', 'done')->where('model', $model)->whereNotNull('cost_per_row_usd')->where('rows_sent_to_llm', '>', 0)
            ->latest()->limit((int) config('classification.savings_recent_runs', 20))->get(['cost_per_row_usd', 'rows_sent_to_llm']);
        $rows = $runs->sum('rows_sent_to_llm');

        return $rows > 0 ? $runs->sum(fn ($r) => (float) $r->cost_per_row_usd * $r->rows_sent_to_llm) / $rows : null;
    }
}
