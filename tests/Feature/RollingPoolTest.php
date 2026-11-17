<?php

namespace Tests\Feature;

use App\Repositories\Eloquents\AccountClassifierRepository;
use App\Support\Heartbeat;
use App\Support\Llm\LlmTransport;
use App\Support\Llm\ProviderException;
use App\Support\Llm\UsageMeter;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The repository's rolling pool, driven by a scripted Guzzle handler: each request finishes only when it is waited on.
 * Through the Anthropic driver (its wire format and Retry-After); Gemini's retry rules: GeminiDriverTest.
 */
class RollingPoolTest extends TestCase
{
    /** @var list<string> "start R2", "done R2", … in the order they happened */
    private array $events = [];

    /** @var list<array{rows: list<int>, delay: mixed}> every request the handler received */
    private array $requests = [];

    /** @param  \Closure(list<int> $rows, int $attempt): array{0: int, 1: array, 2?: array}  $reply  status, JSON body, headers */
    private function repository(\Closure $reply): AccountClassifierRepository
    {
        $attempts = [];
        $handler  = function (RequestInterface $request, array $options) use ($reply, &$attempts) {
            $content = json_decode((string) $request->getBody(), true)['messages'][0]['content'];
            preg_match_all('/^R(\d+) /m', str_contains($content, 'Rows to classify:') ? substr($content, strpos($content, 'Rows to classify:')) : '', $m);
            $rows = array_map('intval', $m[1]);
            $name = 'R'.($rows[0] ?? '-');
            $this->events[]   = "start {$name}";
            $this->requests[] = ['rows' => $rows, 'delay' => $options['delay'] ?? null];
            $attempt = $attempts[$name] = ($attempts[$name] ?? 0) + 1;

            $promise = new Promise(function () use (&$promise, $reply, $rows, $name, $attempt) {
                $this->events[] = "done {$name}";
                [$status, $body, $headers] = $reply($rows, $attempt) + [2 => []];
                $promise->resolve(new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body)));
            });

            return $promise;
        };

        $this->useProvider('anthropic');
        $this->app->instance(LlmTransport::class, new LlmTransport(app(Heartbeat::class), app(UsageMeter::class), $handler));

        return app(AccountClassifierRepository::class);
    }

    /** @param  list<int>  $rows */
    private function labels(array $rows): array
    {
        return [200, ['content' => [['type' => 'tool_use', 'name' => 'submit_rows', 'input' => ['rows' => array_map(
            fn ($r) => ['row' => $r, 'row_type' => 'account', 'sub_class' => 'نقدية بالصندوق', 'confidence' => 95, 'reason' => 'واضح'],
            $rows
        )]]]]];
    }

    /** @param  list<list<int>>  $batches */
    private function batches(array $batches): array
    {
        $structure = ['columns' => ['account_number' => 'A', 'account_name' => 'B']];

        return array_map(fn ($rows) => [
            $structure, [], array_fill_keys($rows, ['A' => '1101', 'B' => 'صندوق']), [], ['chart' => []], null,
        ], $batches);
    }

    public function test_a_freed_slot_starts_the_next_batch_without_waiting_for_the_others(): void
    {
        $answers = [];
        $this->repository(fn ($rows) => $this->labels($rows))->classifyRowsRolling(
            $this->batches([[2], [3], [4], [5]]), 2,
            function ($key, $answer) use (&$answers) { $answers[$key] = $answer; return null; }
        );

        $this->assertCount(4, $answers);
        $this->assertSame(['start R2', 'start R3'], array_slice($this->events, 0, 2));   // never more than 2 in flight
        $this->assertLessThan(array_search('done R3', $this->events), array_search('start R4', $this->events));   // R4 did not wait for R3
    }

    public function test_matching_call_counts_toward_the_limit_and_starts_first(): void
    {
        $requests = ['matches' => [['columns' => []], [], [], [], ['chart' => []], ['adjustments' => [1], 'existing' => []]]]
            + $this->batches([[2], [3], [4], [5], [6]]);

        $this->repository(fn ($rows) => $this->labels($rows))->classifyRowsRolling($requests, 2, fn () => null);

        $inFlight = 0;
        $peak     = 0;
        foreach ($this->events as $event) {
            $inFlight += str_starts_with($event, 'start') ? 1 : -1;
            $peak      = max($peak, $inFlight);
        }
        $this->assertSame(2, $peak);
        $this->assertSame('start R-', $this->events[0]);   // the matching call (no rows) went out first
        $this->assertCount(6, $this->requests);
    }

    public function test_missing_only_retry_is_sent_in_the_same_slot_with_only_the_missing_rows(): void
    {
        $calls = [];
        $this->repository(fn ($rows, $attempt) => $this->labels($rows === [2, 3] ? [2] : $rows))->classifyRowsRolling(
            $this->batches([[2, 3], [4]]), 1,
            function ($key, $answer, $isRetry) use (&$calls) {
                $calls[] = [$key, $isRetry, array_column($answer['rows'], 'row')];
                return $key === 0 && ! $isRetry ? $this->batches([[3]])[0] : null;
            }
        );

        $this->assertSame([[0, false, [2]], [0, true, [3]], [1, false, [4]]], $calls);
        $this->assertSame([[2, 3], [3], [4]], array_column($this->requests, 'rows'));   // the retry ran before batch 2 took the slot
    }

    public function test_429_waits_for_retry_after_and_server_errors_fall_back_to_1_5s(): void
    {
        $this->repository(fn ($rows, $attempt) => match ($attempt) {
            1 => [429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error']], ['retry-after' => '7']],
            2 => [529, ['type' => 'error', 'error' => ['type' => 'overloaded_error']]],
            default => $this->labels($rows),
        })->classifyRowsRolling($this->batches([[2]]), 4, fn () => null);

        $this->assertSame([null, 7000, 1500], array_column($this->requests, 'delay'));
    }

    public function test_request_failing_every_attempt_comes_back_as_an_error_after_2_retries(): void
    {
        $answers = [];
        $this->repository(fn () => [500, ['type' => 'error']])->classifyRowsRolling(
            $this->batches([[2]]), 4,
            function ($key, $answer) use (&$answers) { $answers[] = $answer; return null; }
        );

        $this->assertCount(3, $this->requests);
        $this->assertInstanceOf(ProviderException::class, $answers[0]);
        $this->assertFalse($answers[0]->fatal);
        $this->assertInstanceOf(RequestException::class, $answers[0]->getPrevious());
    }

    public function test_fatal_error_aborts_the_run_and_later_answers_are_dropped(): void
    {
        $answered = [];
        $repository = $this->repository(fn ($rows) => $rows === [3]
            ? [400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']]]
            : $this->labels($rows));

        try {
            $repository->classifyRowsRolling($this->batches([[2], [3], [4], [5], [6]]), 2, function ($key, $answer) use (&$answered) {
                if ($answer instanceof ProviderException && $answer->fatal) {
                    throw $answer;
                }
                $answered[] = $key;
                return null;
            });
            $this->fail('The fatal error was swallowed.');
        } catch (ProviderException $e) {
            $this->assertSame('Anthropic API credit balance is too low. Add credits, then retry.', $e->getMessage());
            $this->assertSame(400, $e->getPrevious()->response->status());
        }

        $this->assertCount(1, array_filter($this->requests, fn ($r) => $r['rows'] === [3]));   // fatal: not retried
        $this->assertNotContains(4, $answered);   // nothing handled after the abort
        $this->assertNotContains('start R6', $this->events);   // and no new batch started
    }
}
