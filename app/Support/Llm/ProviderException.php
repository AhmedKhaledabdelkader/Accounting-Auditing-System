<?php

namespace App\Support\Llm;

use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/** An AI provider failure, with the message the user sees. fatal = no retry can fix it (bad key, no credit…): stop the run. */
class ProviderException extends Exception
{
    public function __construct(string $message, public readonly bool $fatal, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param  string|null  $message  the driver's explanation of a fatal error; null = the provider's own message */
    public static function from(Throwable $e, bool $fatal, ?string $message = null): self
    {
        if (! $fatal) {
            // The user sees a generic message; the log keeps what the provider really said (429 per-minute, 503 overloaded, timeout…).
            Log::warning('ai provider transient error', [
                'status' => $e instanceof RequestException ? $e->response->status() : null,
                'body' => $e instanceof RequestException ? mb_substr($e->response->body(), 0, 500) : $e->getMessage(),
            ]);

            return new self('AI provider error, please retry.', false, $e);
        }

        return new self($message ?? 'AI provider rejected the request: '.($e instanceof RequestException
            ? ($e->response->json('error.message') ?? 'status '.$e->response->status())
            : $e->getMessage()), true, $e);
    }

    public static function missingKey(string $provider, string $envKey): self
    {
        return new self("{$provider} API key is missing. Set {$envKey} in .env, then run php artisan queue:restart.", true);
    }
}
