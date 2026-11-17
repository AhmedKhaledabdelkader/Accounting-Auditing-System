<?php

namespace Tests\Feature;

use App\Support\Heartbeat;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\LlmTransport;
use App\Support\Llm\ProviderException;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use App\Support\Llm\UsageMeter;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeWire;
use Tests\TestCase;

/**
 * What every ClassifierDriver must do, run against each driver with faked responses in its own wire format. Adding a
 * provider: add it to providers() and FakeWire.
 */
class ClassifierDriverContractTest extends TestCase
{
    /** @var list<array{body: array, headers: array, delay: mixed}> every request the scripted handler received */
    private array $sent = [];

    public static function providers(): iterable
    {
        yield 'anthropic' => ['anthropic'];
        yield 'gemini'    => ['gemini'];
    }

    /** The driver bound for $provider, its HTTP answered by $reply(int $attempt, array $body): array{0: int, 1: array, 2?: array}. */
    private function driver(string $provider, \Closure $reply): ClassifierDriver
    {
        $this->useProvider($provider);
        $handler = function (RequestInterface $request, array $options) use ($reply) {
            $body = json_decode((string) $request->getBody(), true);
            $this->sent[] = ['body' => $body, 'headers' => $request->getHeaders(), 'delay' => $options['delay'] ?? null];
            [$status, $json, $headers] = $reply(count($this->sent), $body) + [2 => []];

            return Create::promiseFor(new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($json)));
        };
        $this->app->instance(LlmTransport::class, new LlmTransport(app(Heartbeat::class), app(UsageMeter::class), $handler));

        return app(ClassifierDriver::class);
    }

    private function toolCall(bool $warmUp = false): ToolCall
    {
        return new ToolCall([['text' => 'rules'], ['text' => 'file block', 'cache' => true]], [
            'name' => 'submit_rows', 'description' => 'Submit rows', 'strict' => true,
            'schema' => ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'row' => ['type' => 'integer'], 'sub_class' => ['type' => 'string', 'enum' => ['نقدية بالصندوق', 'عملاء']],
            ]]]], 'required' => ['rows']],
        ], "Rows to classify:\nR2 | A: الصندوق", 16000, $warmUp);
    }

    private function rejection(ClassifierDriver $driver, ToolCall $call): ProviderException
    {
        try {
            $driver->classify($call)->wait();
        } catch (ProviderException $e) {
            return $e;
        }
        $this->fail('Expected a ProviderException.');
    }

    #[DataProvider('providers')]
    public function test_a_pdf_goes_before_the_user_message(string $provider): void
    {
        $driver = $this->driver($provider, fn () => [200, FakeWire::answer($provider, 'submit_rows', ['rows' => []])]);
        $call = new ToolCall([['text' => 'rules']], $this->toolCall()->tool, 'Find the statements.', 4000, false, '%PDF-1.4 fake');

        $driver->classify($call)->wait();

        $body = $this->sent[0]['body'];
        $this->assertSame('%PDF-1.4 fake', FakeWire::pdf($body));
        $this->assertSame('Find the statements.', FakeWire::userText($body));
        // The document first, the text after it.
        $parts = $provider === 'gemini' ? $body['contents'][0]['parts'] : $body['messages'][0]['content'];
        $this->assertSame([true, false], array_map(fn ($p) => isset($p['inlineData']) || ($p['type'] ?? null) === 'document', $parts));
        // Without a PDF the message is as it always was.
        $driver->classify($this->toolCall())->wait();
        $this->assertNull(FakeWire::pdf($this->sent[1]['body']));
    }

    #[DataProvider('providers')]
    public function test_sends_the_forced_tool_call_and_returns_its_input_and_usage(string $provider): void
    {
        $rows   = [['row' => 2, 'sub_class' => 'نقدية بالصندوق']];
        $driver = $this->driver($provider, fn () => [200, FakeWire::answer($provider, 'submit_rows', ['rows' => $rows], ['input' => 70, 'cached' => 30, 'output' => 20])]);

        $answer = $driver->classify($this->toolCall())->wait();

        $this->assertInstanceOf(ToolAnswer::class, $answer);
        $this->assertSame(['rows' => $rows], $answer->input);
        $this->assertSame(['input' => 70, 'cache_write' => 0, 'cached' => 30, 'output' => 20], $answer->usage);
        $this->assertNull($answer->noToolCall);
        $body = $this->sent[0]['body'];
        $this->assertSame('submit_rows', FakeWire::toolName($body));
        $this->assertSame("Rows to classify:\nR2 | A: الصندوق", FakeWire::userText($body));
        $this->assertSame(['rules', 'file block'], FakeWire::systemParts($body));
        $this->assertSame(['نقدية بالصندوق', 'عملاء'], FakeWire::schema($body)['properties']['rows']['items']['properties']['sub_class']['enum']);
        $this->assertStringContainsString('test-key', json_encode($this->sent[0]['headers']));
    }

    #[DataProvider('providers')]
    public function test_an_answer_without_a_tool_call_is_a_gap_with_its_reason(string $provider): void
    {
        $body = $provider === 'gemini'
            ? ['candidates' => [['content' => ['parts' => [['text' => 'I cannot']]], 'finishReason' => 'MALFORMED_FUNCTION_CALL']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]]
            : ['content' => [['type' => 'text', 'text' => 'I cannot']], 'stop_reason' => 'max_tokens', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];

        $answer = $this->driver($provider, fn () => [200, $body])->classify($this->toolCall())->wait();

        $this->assertSame([], $answer->input);
        $this->assertSame($provider === 'gemini' ? 'MALFORMED_FUNCTION_CALL' : 'max_tokens', $answer->noToolCall);
        $this->assertSame(5, $answer->usage['output']);   // billed all the same
    }

    #[DataProvider('providers')]
    public function test_a_missing_key_rejects_before_anything_is_sent(string $provider): void
    {
        $driver = $this->driver($provider, fn () => $this->fail('Nothing may be sent.'));
        config(["classification.providers.{$provider}.api_key" => null]);
        $driver = app(ClassifierDriver::class);   // built with the missing key

        $e = $this->rejection($driver, $this->toolCall());

        $this->assertTrue($e->fatal);
        $this->assertStringContainsString('API key is missing', $e->getMessage());
        $this->assertSame([], $this->sent);
    }

    #[DataProvider('providers')]
    public function test_server_errors_are_retried_twice_then_fail_without_stopping_the_run(string $provider): void
    {
        $e = $this->rejection($this->driver($provider, fn () => [500, FakeWire::error($provider, 500)]), $this->toolCall());

        $this->assertFalse($e->fatal);
        $this->assertSame('AI provider error, please retry.', $e->getMessage());
        $this->assertCount(3, $this->sent);
        $this->assertSame([null, 1500, 1500], array_column($this->sent, 'delay'));
    }

    #[DataProvider('providers')]
    public function test_a_retry_that_succeeds_returns_the_answer(string $provider): void
    {
        $answer = $this->driver($provider, fn (int $attempt) => $attempt === 1
            ? [503, FakeWire::error($provider, 503)]
            : [200, FakeWire::answer($provider, 'submit_rows', ['rows' => []])])->classify($this->toolCall())->wait();

        $this->assertSame(['rows' => []], $answer->input);
        $this->assertCount(2, $this->sent);
    }

    #[DataProvider('providers')]
    public function test_a_rejected_request_is_fatal_and_not_retried(string $provider): void
    {
        $e = $this->rejection($this->driver($provider, fn () => [400, FakeWire::error($provider, 400, 'schema too large')]), $this->toolCall());

        $this->assertTrue($e->fatal);
        $this->assertSame('AI provider rejected the request: schema too large', $e->getMessage());
        $this->assertCount(1, $this->sent);
    }

    #[DataProvider('providers')]
    public function test_a_rate_limit_is_retried_after_the_providers_delay_capped_at_60s(string $provider): void
    {
        $limited = $provider === 'gemini'
            ? [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded', 'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '3s']]]]]
            : [429, FakeWire::error($provider, 429), ['retry-after' => '3']];
        $answer = $this->driver($provider, fn (int $attempt) => match ($attempt) {
            1       => $limited,
            2       => [429, FakeWire::error($provider, 429), ['retry-after' => '600']],
            default => [200, FakeWire::answer($provider, 'submit_rows', ['rows' => []])],
        })->classify($this->toolCall())->wait();

        $this->assertSame(['rows' => []], $answer->input);
        $this->assertSame([null, 3000, 60000], array_column($this->sent, 'delay'));
    }

    #[DataProvider('providers')]
    public function test_a_warm_up_writes_the_cache_only_where_the_provider_needs_it(string $provider): void
    {
        $answer = $this->driver($provider, fn () => [200, FakeWire::answer($provider, 'submit_rows', ['rows' => []])])->classify($this->toolCall(warmUp: true))->wait();

        if ($provider === 'anthropic') {   // explicit prompt caching: a 1-token call writes the prefix
            $this->assertCount(1, $this->sent);
            $this->assertSame(1, $this->sent[0]['body']['max_tokens']);
            $this->assertNotNull($answer->usage);
        } else {                           // implicit caching: nothing to send, nothing billed
            $this->assertSame([], $this->sent);
            $this->assertNull($answer->usage);
        }
    }
}
