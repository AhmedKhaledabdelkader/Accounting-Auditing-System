<?php

namespace App\Support\Llm;

/**
 * Tokens used by the AI calls of one run, summed over every successful response (retries included, failed attempts
 * are not billed). A singleton: reset at the start of each file; a run that paused for confirmation is seeded with
 * what its detection already used.
 *
 * Besides the totals: every attempt sent (failed ones included), and the usage per kind of call
 * (structure | warm | rows | matching), so the cost of classifying rows can be told apart (LlmCost).
 */
class UsageMeter
{
    public const EMPTY = ['requests' => 0, 'input' => 0, 'cache_write' => 0, 'cached' => 0, 'output' => 0];

    private array $totals = self::EMPTY;

    private int $attempts = 0;

    /** @var array<string, array> kind => same shape as EMPTY */
    private array $kinds = [];

    /** @param  array{input?: int, cache_write?: int, cached?: int, output?: int}  $usage  one response, from ToolAnswer::$usage */
    public function add(array $usage, string $kind = 'other'): void
    {
        $this->kinds[$kind] ??= self::EMPTY;
        foreach ([&$this->totals, &$this->kinds[$kind]] as &$sum) {
            $sum['requests']++;
            foreach (['input', 'cache_write', 'cached', 'output'] as $k) {
                $sum[$k] += (int) ($usage[$k] ?? 0);
            }
        }
    }

    /** One request sent to the provider (each retry is one more). */
    public function attempt(): void
    {
        $this->attempts++;
    }

    /** @param  array  $seed  a snapshot(), or older totals() (attempts then default to the billed requests) */
    public function reset(array $seed = []): void
    {
        $this->totals   = array_intersect_key($seed, self::EMPTY) + self::EMPTY;
        $this->attempts = (int) ($seed['attempts'] ?? $this->totals['requests']);
        $this->kinds    = array_map(fn ($k) => array_intersect_key((array) $k, self::EMPTY) + self::EMPTY, (array) ($seed['kinds'] ?? []));
    }

    /** @return array{requests: int, input: int, cache_write: int, cached: int, output: int} */
    public function totals(): array
    {
        return $this->totals;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /** @return array<string, array> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /** Everything, to store on a paused run and reset() from when it resumes. */
    public function snapshot(): array
    {
        return $this->totals + ['attempts' => $this->attempts, 'kinds' => $this->kinds];
    }
}
