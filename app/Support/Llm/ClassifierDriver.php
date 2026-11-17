<?php

namespace App\Support\Llm;

use GuzzleHttp\Promise\PromiseInterface;

/**
 * One AI provider (Strategy). The prompts, schemas, the missing-only retry, caching and cost are provider-neutral and
 * stay above the drivers; a driver translates a ToolCall into its provider's API, sends it through LlmTransport, and
 * translates the answer back. Everything provider-specific lives in the driver: wire format, schema conversion,
 * retry delays and fatal errors, answers without a tool call, thinking. The one driver in use is chosen in
 * AppServiceProvider from CLASSIFIER_PROVIDER; nothing else knows which provider runs.
 */
interface ClassifierDriver
{
    /**
     * Sends one forced tool call (a warm-up only where the provider caches explicitly; elsewhere it resolves at once,
     * nothing sent). Resolves to a ToolAnswer; rejects with a ProviderException (fatal = no retry can fix it: stop the
     * run). A missing API key rejects before anything is sent.
     *
     * @return PromiseInterface<ToolAnswer>
     */
    public function classify(ToolCall $call): PromiseInterface;
}
