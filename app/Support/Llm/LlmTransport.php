<?php

namespace App\Support\Llm;

use App\Support\Heartbeat;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP side every driver shares: one Guzzle handler for all requests (waiting on any one moves them all forward,
 * which the rolling pool relies on), the heartbeat, attempt counting, the timeout and up to 2 retries. What a failure
 * means (retry after how long, or fatal) is the driver's call.
 */
class LlmTransport
{
    public const ATTEMPTS = 3;

    public const TIMEOUT = 120;

    public const DEFAULT_DELAY_MS = 1500;

    public const MAX_DELAY_MS = 60_000;

    /** @param  callable|null  $handler  Guzzle handler (tests); null = the default curl handler */
    public function __construct(private Heartbeat $heartbeat, private UsageMeter $meter, private mixed $handler = null) {}

    /**
     * @param  Closure(Throwable): ?int  $retryDelay  milliseconds before the next attempt; null = no retry can fix it
     * @return PromiseInterface<Response>  the successful response; rejects with the last failure
     */
    public function post(string $url, array $headers, array $body, Closure $retryDelay): PromiseInterface
    {
        $this->handler ??= Utils::chooseHandler();

        $promise = Http::setHandler($this->handler)->async()
            ->withHeaders($headers)
            // Every attempt (retries after a Retry-After wait included) and every response keeps the run's heartbeat fresh.
            // Each attempt is also counted, failed ones included (llm_requests).
            ->beforeSending(function () {
                $this->meter->attempt();
                $this->heartbeat->beat();
            })
            ->withResponseMiddleware(function ($response) {
                $this->heartbeat->beat();
                return $response;
            })
            ->timeout(self::TIMEOUT)
            ->retry(self::ATTEMPTS, fn (int $attempt, Throwable $e) => $retryDelay($e) ?? 0, fn (Throwable $e) => $retryDelay($e) !== null)
            ->post($url, $body);

        return ($promise instanceof LazyPromise ? $promise->buildPromise() : $promise)
            ->then(fn (Response|Throwable $r) => match (true) {
                $r instanceof Throwable => Create::rejectionFor($r),
                $r->failed()            => Create::rejectionFor($r->toException()),
                default                 => $r,
            });
    }

    /** Whether a status can succeed on a second try: everything but 4xx, except timeouts, conflicts and rate limits. */
    public static function retryable(int $status): bool
    {
        return $status < 400 || $status >= 500 || in_array($status, [408, 409, 429], true);
    }

    /** Milliseconds to wait for a provider-given delay in seconds (capped at 60 s); not given → the default 1.5 s. */
    public static function delay(mixed $seconds): int
    {
        return is_numeric($seconds) ? (int) min(self::MAX_DELAY_MS, max(0, (float) $seconds * 1000)) : self::DEFAULT_DELAY_MS;
    }
}
