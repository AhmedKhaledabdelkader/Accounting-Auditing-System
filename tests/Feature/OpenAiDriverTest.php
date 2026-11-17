<?php

namespace Tests\Feature;

use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\OpenAiDriver;
use App\Support\Llm\ProviderException;
use App\Support\Llm\ToolCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** OpenAI's driver: the Responses API request (forced function, nothing stored, the PDF inline) and its answer. */
class OpenAiDriverTest extends TestCase
{
    private function toolCall(?string $pdf = null): ToolCall
    {
        return new ToolCall([['text' => 'sys A'], ['text' => 'sys B']], ['name' => 'submit', 'description' => 'd', 'schema' => ['type' => 'object']], 'hello', 500, false, $pdf);
    }

    private function useOpenAi(?string $key = 'sk-test'): void
    {
        config(['classification.provider' => 'openai', 'classification.providers.openai.api_key' => $key, 'classification.providers.openai.model' => 'gpt-6-astra']);
        $this->app->forgetInstance(ClassifierDriver::class);
    }

    public function test_the_provider_setting_picks_the_driver(): void
    {
        $this->useOpenAi();

        $this->assertInstanceOf(OpenAiDriver::class, app(ClassifierDriver::class));
    }

    public function test_request_forces_the_function_stores_nothing_and_the_answer_is_read(): void
    {
        $this->useOpenAi();
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'reasoning'], ['type' => 'function_call', 'name' => 'submit', 'arguments' => '{"rows":[1,2]}']],
            'usage' => ['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 40], 'output_tokens' => 30],
        ])]);

        $answer = app(ClassifierDriver::class)->classify($this->toolCall('%PDF-1.4'))->wait();

        $this->assertSame(['rows' => [1, 2]], $answer->input);
        $this->assertSame(['input' => 60, 'cache_write' => 0, 'cached' => 40, 'output' => 30], $answer->usage);
        $this->assertNull($answer->noToolCall);
        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->hasHeader('Authorization', 'Bearer sk-test') && $b['model'] === 'gpt-6-astra' && $b['store'] === false
                && $b['tool_choice'] === ['type' => 'function', 'name' => 'submit'] && $b['instructions'] === "sys A\n\nsys B"
                && $b['max_output_tokens'] === 500 && $b['input'][0]['content'][0]['type'] === 'input_file'
                && str_starts_with($b['input'][0]['content'][0]['file_data'], 'data:application/pdf;base64,')
                && $b['input'][0]['content'][1] === ['type' => 'input_text', 'text' => 'hello'];
        });
    }

    public function test_an_answer_without_a_function_call_is_a_gap_and_a_warm_up_sends_nothing(): void
    {
        $this->useOpenAi();
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [], 'usage' => []])]);

        $answer = app(ClassifierDriver::class)->classify($this->toolCall())->wait();
        $this->assertSame([], $answer->input);
        $this->assertSame('max_output_tokens', $answer->noToolCall);

        $warm = app(ClassifierDriver::class)->classify(new ToolCall([['text' => 's']], ['name' => 'n', 'description' => 'd', 'schema' => []], 'c', 10, true))->wait();
        $this->assertNull($warm->usage);
        Http::assertSentCount(1);
    }

    public function test_a_missing_key_is_fatal_before_anything_is_sent(): void
    {
        $this->useOpenAi(null);
        Http::preventStrayRequests();

        try {
            app(ClassifierDriver::class)->classify($this->toolCall())->wait();
            $this->fail('expected a ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->fatal);
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }
    }
}
