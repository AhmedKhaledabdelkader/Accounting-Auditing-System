<?php

namespace App\Support\Llm;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/** Anthropic Messages API (Claude), with explicit prompt caching. */
class AnthropicDriver implements ClassifierDriver
{
    /** @param  array{api_key: ?string, model: string, base_url: ?string}  $config  config('classification.providers.anthropic') */
    public function __construct(private LlmTransport $transport, private array $config) {}

    public function classify(ToolCall $call): PromiseInterface
    {
        if (! $this->config['api_key']) {
            return Create::rejectionFor(ProviderException::missingKey('Anthropic', 'ANTHROPIC_API_KEY'));
        }

        return $this->transport->post(
            rtrim($this->config['base_url'] ?: 'https://api.anthropic.com/v1', '/').'/messages',
            ['x-api-key' => $this->config['api_key'], 'anthropic-version' => '2023-06-01'],
            $this->body($call),
            $this->retryDelay(...),
        )->then(fn (Response $r) => $this->answer($r), fn (Throwable $e) => Create::rejectionFor($this->error($e)));
    }

    private function body(ToolCall $call): array
    {
        // A single uncached part goes as plain text; otherwise content blocks, the cached prefix ending at the marked part.
        $blocks = array_map(fn ($p) => ['type' => 'text', 'text' => $p['text']]
            + (($p['cache'] ?? false) ? ['cache_control' => ['type' => 'ephemeral']] : []), $call->system);
        $tool = $call->tool;

        return [
            'model'       => $this->config['model'],
            'max_tokens'  => $call->warmUp ? 1 : $call->maxTokens,   // a warm-up only writes the cache: its answer is ignored
            'system'      => count($blocks) === 1 && ! isset($blocks[0]['cache_control']) ? $blocks[0]['text'] : $blocks,
            'tools'       => [['name' => $tool['name'], 'description' => $tool['description']] + (($tool['strict'] ?? false)
                ? ['input_schema' => self::strictSchema($tool['schema']), 'strict' => true]
                : ['input_schema' => $tool['schema']])],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
            // A PDF goes as a document block before the text (each page read as text and as an image).
            'messages'    => [['role' => 'user', 'content' => $call->pdf === null ? $call->content : [
                ($call->mime ?? 'application/pdf') === 'application/pdf'
                    ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($call->pdf)]]
                    : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $call->mime, 'data' => base64_encode($call->pdf)]],
                ['type' => 'text', 'text' => $call->content],
            ]]],
        ];
    }

    /** Strict tool use: every object closed (additionalProperties: false), numeric/length bounds dropped (unsupported). */
    private static function strictSchema(array $schema): array
    {
        unset($schema['minimum'], $schema['maximum'], $schema['multipleOf'], $schema['minLength'], $schema['maxLength']);
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $schema['properties'][$name] = self::strictSchema($property);
        }
        if (isset($schema['items'])) {
            $schema['items'] = self::strictSchema($schema['items']);
        }
        return $schema;
    }

    private function answer(Response $r): ToolAnswer
    {
        $use = collect($r->json('content'))->firstWhere('type', 'tool_use');

        return new ToolAnswer(is_array($use['input'] ?? null) ? $use['input'] : [], [
            'input'       => (int) $r->json('usage.input_tokens', 0),
            'cache_write' => (int) $r->json('usage.cache_creation_input_tokens', 0),
            'cached'      => (int) $r->json('usage.cache_read_input_tokens', 0),
            'output'      => (int) $r->json('usage.output_tokens', 0),
        ], $use ? null : (string) ($r->json('stop_reason') ?? 'no_tool_use'));
    }

    /** Timeouts, connection errors, 429 (after its Retry-After) and 5xx are retried; other 4xx are fatal. */
    private function retryDelay(Throwable $e): ?int
    {
        if (! $e instanceof RequestException) {
            return LlmTransport::DEFAULT_DELAY_MS;
        }
        $status = $e->response->status();

        return match (true) {
            ! LlmTransport::retryable($status) => null,
            $status === 429                    => LlmTransport::delay($e->response->header('retry-after')),
            default                            => LlmTransport::DEFAULT_DELAY_MS,
        };
    }

    private function error(Throwable $e): ProviderException
    {
        $fatal = $this->retryDelay($e) === null;

        return ProviderException::from($e, $fatal, $fatal ? match (true) {
            str_contains($e->response->body(), 'credit balance') => 'Anthropic API credit balance is too low. Add credits, then retry.',
            $e->response->status() === 401                      => 'Anthropic API key is invalid. Check ANTHROPIC_API_KEY.',
            default                                             => null,
        } : null);
    }
}
