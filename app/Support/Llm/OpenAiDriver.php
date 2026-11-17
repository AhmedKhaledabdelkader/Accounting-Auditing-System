<?php

namespace App\Support\Llm;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * OpenAI Responses API (POST /v1/responses) with a forced function call (tool_choice = the function). OpenAI caches repeated
 * prefixes implicitly, so a warm-up sends nothing. Nothing is stored on OpenAI's side (store: false): the documents are the clients'.
 * Docs: https://platform.openai.com/docs/api-reference/responses, /guides/function-calling.
 */
class OpenAiDriver implements ClassifierDriver
{
    /** @param  array{api_key: ?string, model: string, base_url: ?string, reasoning_effort: ?string}  $config  config('classification.providers.openai') */
    public function __construct(private LlmTransport $transport, private array $config) {}

    public function classify(ToolCall $call): PromiseInterface
    {
        if (! $this->config['api_key']) {
            return Create::rejectionFor(ProviderException::missingKey('OpenAI', 'OPENAI_API_KEY'));
        }
        if ($call->warmUp) {
            return Create::promiseFor(ToolAnswer::notSent());
        }

        return $this->transport->post(
            rtrim($this->config['base_url'] ?: 'https://api.openai.com/v1', '/').'/responses',
            ['Authorization' => 'Bearer '.$this->config['api_key']],
            $this->body($call),
            $this->retryDelay(...),
        )->then(fn (Response $r) => $this->answer($r), fn (Throwable $e) => Create::rejectionFor($this->error($e)));
    }

    private function body(ToolCall $call): array
    {
        $tool = $call->tool;
        $mime = $call->mime ?? 'application/pdf';
        $data = $call->pdf === null ? null : 'data:'.$mime.';base64,'.base64_encode($call->pdf);

        return [
            'model'        => $this->config['model'],
            'instructions' => implode("\n\n", array_map(fn ($p) => $p['text'], $call->system)),
            // A PDF or an image goes before the text.
            'input'        => [['role' => 'user', 'content' => [
                ...($data === null ? [] : [str_starts_with($mime, 'image/')
                    ? ['type' => 'input_image', 'image_url' => $data]
                    : ['type' => 'input_file', 'filename' => 'document.pdf', 'file_data' => $data]]),
                ['type' => 'input_text', 'text' => $call->content],
            ]]],
            // strict = false: our JSON Schemas (type arrays with null, enums, minimum/maximum) are not limited to strict mode's subset.
            'tools'        => [['type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['schema'], 'strict' => false]],
            'tool_choice'  => ['type' => 'function', 'name' => $tool['name']],
            // OpenAI counts the output limit against the per-minute token limit before the answer exists: capped, not 16000.
            'max_output_tokens' => min($call->maxTokens, (int) ($this->config['max_output_tokens'] ?? 8000)),
            'store'        => false,
        ] + ($this->config['reasoning_effort'] ? ['reasoning' => ['effort' => $this->config['reasoning_effort']]] : []);
    }

    /** No function call (an incomplete answer, a refusal…) is a gap, not an error: the caller retries what is missing. */
    private function answer(Response $r): ToolAnswer
    {
        $call = collect($r->json('output') ?? [])->firstWhere('type', 'function_call');
        $args = is_string($call['arguments'] ?? null) ? json_decode($call['arguments'], true) : null;
        $cached = (int) $r->json('usage.input_tokens_details.cached_tokens', 0);   // included in input_tokens

        return new ToolAnswer(is_array($args) ? $args : [], [
            'input'       => (int) $r->json('usage.input_tokens', 0) - $cached,
            'cache_write' => 0,
            'cached'      => $cached,
            'output'      => (int) $r->json('usage.output_tokens', 0),   // includes the reasoning tokens
        ], is_array($args) ? null : (string) ($r->json('incomplete_details.reason') ?? $r->json('status') ?? 'NO_TOOL_CALL'));
    }

    /** Timeouts, connection errors, 5xx and rate limits are retried (after Retry-After when given); other 4xx are fatal. */
    private function retryDelay(Throwable $e): ?int
    {
        if (! $e instanceof RequestException) {
            return LlmTransport::DEFAULT_DELAY_MS;
        }
        $status = $e->response->status();

        return match (true) {
            ! LlmTransport::retryable($status), $this->noQuota($e->response), $this->tooLarge($e->response) => null,
            default => LlmTransport::delay($e->response->header('retry-after') ?: self::resetSeconds($e->response->header('x-ratelimit-reset-tokens'))),
        };
    }

    /** «Request too large … tokens per min»: the request alone is above the account's per-minute limit, so no wait can fix it. */
    private function tooLarge(Response $r): bool
    {
        return $r->status() === 429 && str_contains((string) $r->json('error.message'), 'Request too large');
    }

    /** Seconds from OpenAI's reset header ("6s", "1m2s", "250ms"); null when absent. */
    private static function resetSeconds(?string $v): ?float
    {
        if (! $v || ! preg_match('/^(?:(\d+)m(?!s))?(?:([\d.]+)s)?(?:([\d.]+)ms)?$/', $v, $m)) {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 60 + (float) ($m[2] ?? 0) + (float) ($m[3] ?? 0) / 1000;
    }

    /** A 429 that says the quota or the credit is used up (not a per-minute limit): waiting does not help. */
    private function noQuota(Response $r): bool
    {
        return $r->status() === 429 && in_array($r->json('error.code'), ['insufficient_quota', 'billing_hard_limit_reached'], true);
    }

    private function error(Throwable $e): ProviderException
    {
        $fatal = $this->retryDelay($e) === null;

        return ProviderException::from($e, $fatal, $fatal ? match (true) {
            $e->response->status() === 401 => 'OpenAI API key is invalid. Check OPENAI_API_KEY.',
            $this->tooLarge($e->response)  => 'The request is larger than the per-minute token limit of this OpenAI account ('.($e->response->json('error.message') ? preg_replace('/^.*?Limit/s', 'Limit', (string) $e->response->json('error.message')) : '').'). Raise the limit in the OpenAI dashboard, lower CLASSIFIER_BATCH_SIZE, or switch CLASSIFIER_PROVIDER back to gemini.',
            $this->noQuota($e->response)   => 'OpenAI credit or quota is used up. Add credit in the OpenAI dashboard, then retry.',
            $e->response->status() === 404 => "OpenAI model {$this->config['model']} was not found for this key. Check CLASSIFIER_OPENAI_MODEL.",
            default                        => null,
        } : null);
    }
}
