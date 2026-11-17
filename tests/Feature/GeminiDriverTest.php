<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Support\Llm\GeminiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\FakeWire;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Gemini's own behaviour end to end: its request, schema conversion, rate limits and quotas, gaps, thinking, prices. */
class GeminiDriverTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    private const FILE = [['110101', 'الصندوق', 0, 0, 100, 0, 100, 0], ['110201', 'البنك', 0, 0, 50, 0, 50, 0]];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->useProvider('gemini');
    }

    private function classify(array $accounts = self::FILE)
    {
        return $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance($accounts)]);
    }

    /** Structure → standard; row calls answered by $rows(int $call, list<int> $requested): array{0: int, 1: array} (status, body). */
    private function fake(\Closure $rows): void
    {
        $calls = 0;
        Http::fake([$this->aiUrl() => function (Request $r) use ($rows, &$calls) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', $this->standardStructure()));
            }
            [$status, $body] = $rows(++$calls, $this->requestedRows($r));

            return Http::response($body, $status);
        }]);
    }

    private function labels(array $requested): array
    {
        return FakeWire::answer('gemini', 'submit_rows', ['rows' => array_map(fn ($row) => $this->account($row, 'نقدية بالصندوق'), $requested)]);
    }

    public function test_request_forces_the_tool_caps_thinking_and_keeps_the_full_sub_class_enum(): void
    {
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);

        $this->classify()->assertOk()->assertJsonPath('meta.provider', 'gemini')->assertJsonPath('meta.model', 'gemini-3.8-flash');

        Http::assertSent(function (Request $r) {
            if ($this->isStructureCall($r)) {
                return false;
            }
            $schema = $r['tools'][0]['functionDeclarations'][0]['parametersJsonSchema'];

            return $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
                && $r['toolConfig']['functionCallingConfig'] === ['mode' => 'ANY', 'allowedFunctionNames' => ['submit_rows']]
                && $r['generationConfig'] === ['maxOutputTokens' => 16000, 'thinkingConfig' => ['thinkingLevel' => 'low']]
                && count($schema['properties']['rows']['items']['properties']['sub_class']['enum']) === 158
                && $schema['properties']['rows']['items']['properties']['confidence'] === ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]
                && ! isset($r['tools'][0]['functionDeclarations'][0]['parameters']);
        });
        Http::assertSentCount(2);   // structure + one batch: no warm-up call (implicit caching)
    }

    public function test_an_empty_thinking_level_leaves_the_models_default(): void
    {
        config(['classification.providers.gemini.thinking_level' => null]);
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);

        $this->classify()->assertOk();

        Http::assertNotSent(fn (Request $r) => isset($r['generationConfig']['thinkingConfig']));
    }

    public function test_null_inside_an_enum_becomes_any_of_the_values_or_null_everything_else_is_kept(): void
    {
        $this->assertSame([
            'type'       => 'object',
            'properties' => [
                'sub_class' => ['description' => 'or null', 'anyOf' => [['type' => 'string', 'enum' => ['عملاء', 'موردون']], ['type' => 'null']]],
                'main'      => ['type' => ['string', 'null'], 'description' => 'nullable, no enum'],
                'sheet'     => ['type' => 'integer', 'enum' => [1, 2]],
                'list'      => ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string', 'enum' => ['a']], ['type' => 'null']]]],
            ],
            'required'   => ['sub_class'],
        ], GeminiDriver::schema([
            'type'       => 'object',
            'properties' => [
                'sub_class' => ['type' => ['string', 'null'], 'enum' => ['عملاء', 'موردون', null], 'description' => 'or null'],
                'main'      => ['type' => ['string', 'null'], 'description' => 'nullable, no enum'],
                'sheet'     => ['type' => 'integer', 'enum' => [1, 2]],
                'list'      => ['type' => 'array', 'items' => ['type' => ['string', 'null'], 'enum' => ['a', null]]],
            ],
            'required'   => ['sub_class'],
        ]));
    }

    public function test_answers_without_a_function_call_are_gaps_retried_for_the_missing_rows_only(): void
    {
        $log = new TestHandler;
        Log::extend('test-gemini', fn () => new Logger('test', [$log]));
        config(['logging.channels.test-gemini' => ['driver' => 'test-gemini'], 'classification.log_channel' => 'test-gemini']);
        $asked = [];
        $this->fake(function ($call, $requested) use (&$asked) {
            $asked[] = $requested;

            return $call === 1
                ? [200, ['candidates' => [['content' => ['parts' => [['text' => 'call: submit_rows(...']]], 'finishReason' => 'MALFORMED_FUNCTION_CALL']],
                    'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 30]]]
                : [200, $this->labels($requested)];
        });

        $this->classify()->assertOk()->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق')->assertJsonPath('data.1.sub_class', 'نقدية بالصندوق')
            ->assertJsonPath('meta.unclassified.rows', 0);

        $this->assertSame([[2, 3], [2, 3]], $asked);   // the whole batch was missing: retried once
        $warning = collect($log->getRecords())->firstWhere('message', 'llm_no_tool_call');
        $this->assertSame(['kind' => 'rows', 'tool' => 'submit_rows', 'reason' => 'MALFORMED_FUNCTION_CALL'], $warning->context);
    }

    public function test_a_blocked_or_thinking_heavy_answer_is_a_gap_and_its_thinking_is_billed_as_output(): void
    {
        $this->fake(fn ($call, $requested) => match ($call) {
            // The reasoning used the whole output budget: no function call left.
            1       => [200, ['candidates' => [['content' => ['parts' => []], 'finishReason' => 'MAX_TOKENS']],
                'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 0, 'thoughtsTokenCount' => 15990]]],
            default => [200, $this->labels($requested)],
        });

        $response = $this->classify()->assertOk()->assertJsonPath('meta.unclassified.rows', 0);

        $this->assertSame(20 + 15990 + 20, $response->json('meta.usage.output'));   // structure + the thinking-only answer + the retry
    }

    public function test_a_blocked_prompt_with_no_candidate_is_a_gap(): void
    {
        $this->fake(fn ($call, $requested) => $call === 1
            ? [200, ['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 50]]]
            : [200, $this->labels($requested)]);

        $this->classify()->assertOk()->assertJsonPath('meta.unclassified.rows', 0);
    }

    public function test_a_used_up_daily_quota_stops_the_run_at_once_with_a_clear_message(): void
    {
        $this->fake(fn () => [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota.', 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [[
                'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_paid_tier_requests',
                'quotaId'     => 'GenerateRequestsPerDayPerProjectPerModel',
            ]]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '50s'],
        ]]]]);

        $this->classify()->assertStatus(502)->assertJsonPath('message',
            'Gemini daily quota for gemini-3.8-flash is used up. It resets at midnight Pacific time; retry then, or raise the quota in Google AI Studio.');

        Http::assertSentCount(2);   // structure + one batch, not retried
    }

    public function test_a_per_minute_limit_waits_for_the_body_retry_delay(): void
    {
        $this->fake(fn ($call, $requested) => $call === 1
            ? [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded', 'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel']]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '7.5s'],
            ]]]]
            : [200, $this->labels($requested)]);

        $this->classify()->assertOk()->assertJsonPath('meta.unclassified.rows', 0);

        Http::assertSentCount(3);
    }

    public function test_high_demand_503_waits_10s_before_its_retry(): void
    {
        Http::allowStrayRequests();   // the scripted handler below answers, nothing leaves the process
        $delays = [];
        $attempt = 0;
        $handler = function (\Psr\Http\Message\RequestInterface $request, array $options) use (&$delays, &$attempt) {
            $delays[] = $options['delay'] ?? null;
            [$status, $body] = ++$attempt === 1
                // As sent by the live API on the free tier (2026-10-08).
                ? [503, ['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand. Spikes in demand are usually temporary. Please try again later.', 'status' => 'UNAVAILABLE']]]
                : [200, FakeWire::answer('gemini', 'submit_rows', ['rows' => []])];

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
        };
        $this->app->instance(\App\Support\Llm\LlmTransport::class, new \App\Support\Llm\LlmTransport(app(\App\Support\Heartbeat::class), app(\App\Support\Llm\UsageMeter::class), $handler));

        $answer = app(\App\Support\Llm\ClassifierDriver::class)->classify(new \App\Support\Llm\ToolCall([['text' => 'rules']],
            ['name' => 'submit_rows', 'description' => 'x', 'schema' => ['type' => 'object']], 'Rows to classify:'))->wait();

        $this->assertSame(['rows' => []], $answer->input);
        $this->assertSame([null, GeminiDriver::OVERLOADED_DELAY_MS], $delays);
    }

    /** @return iterable<array{0: int, 1: array, 2: string}> */
    public static function fatalErrors(): iterable
    {
        yield 'invalid key' => [400, ['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com']]]],
            'Gemini API key is invalid. Check GEMINI_API_KEY.'];
        yield 'unauthenticated' => [401, ['error' => ['code' => 401, 'message' => 'Unauthenticated', 'status' => 'UNAUTHENTICATED']], 'Gemini API key is invalid. Check GEMINI_API_KEY.'];
        yield 'no prepaid credit' => [402, ['error' => ['code' => 402, 'message' => 'Prepay balance depleted', 'status' => 'FAILED_PRECONDITION']],
            'Gemini prepaid credit is used up. Add credit in Google AI Studio, then retry.'];
        yield 'schema rejected' => [400, ['error' => ['code' => 400, 'message' => 'Invalid JSON payload: schema too complex', 'status' => 'INVALID_ARGUMENT']],
            'AI provider rejected the request: Invalid JSON payload: schema too complex'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fatalErrors')]
    public function test_fatal_errors_are_not_retried_and_explain_themselves(int $status, array $body, string $message): void
    {
        Http::fake([$this->aiUrl() => Http::response($body, $status)]);

        $this->classify()->assertStatus(502)->assertJsonPath('message', $message);

        Http::assertSentCount(1);
    }

    /** @return iterable<array{0: string, 1: float}> run start (UTC), cost of 1,000 input + 20 output tokens per call (2 calls) */
    public static function priceDays(): iterable
    {
        yield 'until 2026-12-31' => ['2026-12-31 23:59:00', 2 * (100 * 0.75 + 20 * 3.75) / 1_000_000];
        yield 'from 2027-01-01'  => ['2027-01-01 00:00:00', 2 * (100 * 1.50 + 20 * 7.50) / 1_000_000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('priceDays')]
    public function test_a_run_is_priced_at_the_price_in_effect_when_it_started(string $start, float $cost): void
    {
        Storage::fake('local');
        Carbon::setTestNow($start);
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance(self::FILE)])->assertStatus(202)->json('id');

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')
            ->assertJsonPath('provider', 'gemini')->assertJsonPath('model', 'gemini-3.8-flash')
            ->assertJsonPath('cost.input_tokens', 200)->assertJsonPath('cost.output_tokens', 40);
        $this->assertEqualsWithDelta($cost, ClassificationRun::find($id)->cost_usd, 1e-9);
        $this->assertSame($start, ClassificationRun::find($id)->started_at->utc()->format('Y-m-d H:i:s'));
    }
}
