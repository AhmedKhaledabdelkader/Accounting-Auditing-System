<?php

namespace App\Support\Llm;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Google Gemini API: generateContent with a forced function call (mode ANY). Gemini caches repeated prefixes
 * implicitly, so a warm-up sends nothing. Docs: https://ai.google.dev/gemini-api/docs/function-calling,
 * /structured-output (schema subset), /thinking, /rate-limits.
 */
class GeminiDriver implements ClassifierDriver
{
    /** @param  array{api_key: ?string, model: string, base_url: ?string, thinking_level: ?string}  $config  config('classification.providers.gemini') */
    public function __construct(private LlmTransport $transport, private array $config) {}

    public function classify(ToolCall $call): PromiseInterface
    {
        if (! $this->config['api_key']) {
            return Create::rejectionFor(ProviderException::missingKey('Gemini', 'GEMINI_API_KEY'));
        }
        if ($call->warmUp) {
            return Create::promiseFor(ToolAnswer::notSent());
        }

        return $this->transport->post(
            rtrim($this->config['base_url'] ?: 'https://generativelanguage.googleapis.com/v1beta', '/').'/models/'.$this->config['model'].':generateContent',
            ['x-goog-api-key' => $this->config['api_key']],
            $this->body($call),
            $this->retryDelay(...),
        )->then(fn (Response $r) => $this->answer($r), fn (Throwable $e) => Create::rejectionFor($this->error($e)));
    }

    private function body(ToolCall $call): array
    {
        $tool = $call->tool;

        return [
            'systemInstruction' => ['parts' => array_map(fn ($p) => ['text' => $p['text']], $call->system)],
            // A PDF goes inline before the text.
            'contents'          => [['role' => 'user', 'parts' => [
                ...($call->pdf === null ? [] : [['inlineData' => ['mimeType' => $call->mime ?? 'application/pdf', 'data' => base64_encode($call->pdf)]]]),
                ['text' => $call->content],
            ]]],
            // parametersJsonSchema takes JSON Schema (type arrays, integer enums, minimum/maximum), unlike `parameters`.
            'tools'             => [['functionDeclarations' => [[
                'name' => $tool['name'], 'description' => $tool['description'], 'parametersJsonSchema' => self::schema($tool['schema']),
            ]]]],
            'toolConfig'        => ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => [$tool['name']]]],
            // Thinking tokens count against maxOutputTokens: a capped level keeps the reasoning from crowding out the answer.
            'generationConfig'  => ['maxOutputTokens' => $call->maxTokens]
                + ($this->config['thinking_level'] ? ['thinkingConfig' => ['thinkingLevel' => $this->config['thinking_level']]] : []),
        ];
    }

    /**
     * Our JSON Schema → what Gemini's documented subset accepts. A null inside an enum is not documented (only "null" in
     * a type array is): such a property becomes anyOf [the enum without null, {type: null}], which documents the same
     * values. Everything else (type arrays with null, integer enums, minimum/maximum, large enums) is documented: kept.
     */
    public static function schema(array $schema): array
    {
        if (isset($schema['enum']) && in_array(null, $schema['enum'], true)) {
            $types = array_values(array_diff((array) ($schema['type'] ?? []), ['null']));
            $values = array_values(array_filter($schema['enum'], fn ($v) => $v !== null));

            return array_intersect_key($schema, array_flip(['description', 'title'])) + ['anyOf' => [
                array_filter(['type' => count($types) === 1 ? $types[0] : ($types ?: null), 'enum' => $values], fn ($v) => $v !== null),
                ['type' => 'null'],
            ]];
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $schema['properties'][$name] = self::schema($property);
        }
        if (isset($schema['items'])) {
            $schema['items'] = self::schema($schema['items']);
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
            if (isset($schema[$key])) {
                $schema[$key] = array_map(self::schema(...), $schema[$key]);
            }
        }

        return $schema;
    }

    /**
     * No function call (MALFORMED_FUNCTION_CALL, SAFETY, MAX_TOKENS, a blocked prompt…) is a gap, not an error: the
     * caller retries what is missing.
     */
    private function answer(Response $r): ToolAnswer
    {
        $call = collect($r->json('candidates.0.content.parts') ?? [])->pluck('functionCall')->filter()->first();
        $cached = (int) $r->json('usageMetadata.cachedContentTokenCount', 0);   // included in promptTokenCount

        return new ToolAnswer(is_array($call['args'] ?? null) ? $call['args'] : [], [
            'input'       => (int) $r->json('usageMetadata.promptTokenCount', 0) - $cached,
            'cache_write' => 0,
            'cached'      => $cached,
            'output'      => (int) $r->json('usageMetadata.candidatesTokenCount', 0) + (int) $r->json('usageMetadata.thoughtsTokenCount', 0),
        ], $call ? null : (string) ($r->json('candidates.0.finishReason')
            ?? ($r->json('promptFeedback.blockReason') ? 'PROMPT_BLOCKED_'.$r->json('promptFeedback.blockReason') : 'NO_CANDIDATE')));
    }

    /** 503 UNAVAILABLE ("high demand", seen often on the free tier): a spike, so the retry waits longer than the default. */
    public const OVERLOADED_DELAY_MS = 10_000;

    /**
     * Timeouts, connection errors, 5xx and per-minute 429s are retried (after the body's RetryInfo.retryDelay, else the
     * Retry-After header; a 503 after OVERLOADED_DELAY_MS); a used-up daily quota and other 4xx are fatal.
     */
    private function retryDelay(Throwable $e): ?int
    {
        if (! $e instanceof RequestException) {
            return LlmTransport::DEFAULT_DELAY_MS;
        }
        $status = $e->response->status();

        return match (true) {
            ! LlmTransport::retryable($status), self::dailyQuota($e->response) => null,
            $status === 429 => LlmTransport::delay(self::bodyRetryDelay($e->response) ?? $e->response->header('retry-after')),
            $status === 503 => is_numeric($e->response->header('retry-after')) ? LlmTransport::delay($e->response->header('retry-after')) : self::OVERLOADED_DELAY_MS,
            default         => LlmTransport::DEFAULT_DELAY_MS,
        };
    }

    /** Seconds from a google.rpc.RetryInfo detail ("13s", "0.5s"); null when absent. */
    private static function bodyRetryDelay(Response $r): ?float
    {
        foreach ((array) $r->json('error.details') as $detail) {
            if (str_ends_with($detail['@type'] ?? '', 'google.rpc.RetryInfo') && preg_match('/^([\d.]+)s$/', (string) ($detail['retryDelay'] ?? ''), $m)) {
                return (float) $m[1];
            }
        }
        return null;
    }

    /** A 429 whose google.rpc.QuotaFailure names a per-day quota: nothing works again before it resets. */
    private static function dailyQuota(Response $r): bool
    {
        if ($r->status() !== 429) {
            return false;
        }
        foreach ((array) $r->json('error.details') as $detail) {
            foreach ($detail['violations'] ?? [] as $v) {
                if (str_contains(($v['quotaId'] ?? '').($v['quotaMetric'] ?? ''), 'PerDay')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function error(Throwable $e): ProviderException
    {
        $fatal = $this->retryDelay($e) === null;

        return ProviderException::from($e, $fatal, $fatal ? match (true) {
            str_contains($e->response->body(), 'API_KEY_INVALID') || $e->response->status() === 401 => 'Gemini API key is invalid. Check GEMINI_API_KEY.',
            self::dailyQuota($e->response) => "Gemini daily quota for {$this->config['model']} is used up. It resets at midnight Pacific time; retry then, or raise the quota in Google AI Studio.",
            $e->response->status() === 402 => 'Gemini prepaid credit is used up. Add credit in Google AI Studio, then retry.',
            default                        => null,
        } : null);
    }
}
