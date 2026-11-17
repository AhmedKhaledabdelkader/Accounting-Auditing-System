<?php

namespace App\Support\Llm;

/** The answer to a ToolCall, provider-neutral. */
final class ToolAnswer
{
    /**
     * @param  array  $input  the arguments of the forced tool call; [] when the answer has none (a gap: the caller retries
     *                        what is missing)
     * @param  array{input: int, cache_write: int, cached: int, output: int}|null  $usage  billed tokens: input = uncached
     *                        input (cache writes excluded), cache_write = input written to the cache, cached = input read
     *                        from the cache, output = output including thinking; null = nothing was sent
     * @param  string|null  $noToolCall  why the answer has no tool call (the provider's finish/stop reason); null = it has one
     */
    public function __construct(
        public readonly array $input,
        public readonly ?array $usage,
        public readonly ?string $noToolCall = null,
    ) {}

    /** A warm-up the provider does not need: nothing sent, nothing billed. */
    public static function notSent(): self
    {
        return new self([], null);
    }
}
