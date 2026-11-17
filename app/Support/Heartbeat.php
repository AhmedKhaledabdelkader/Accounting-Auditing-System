<?php

namespace App\Support;

use Closure;

/**
 * Keeps a running job's heartbeat fresh during long waits (AI calls, retry delays). The job switches it on with a
 * callback; the HTTP layer calls beat() before every attempt and after every response. Beats are throttled and never
 * throw: a lost lease is noticed by the job at its next stage.
 */
class Heartbeat
{
    private ?Closure $callback = null;
    private float $last = 0.0;

    public function __construct(private int $everySeconds = 15) {}

    public function start(Closure $callback): void
    {
        $this->callback = $callback;
        $this->last     = microtime(true);
    }

    public function stop(): void
    {
        $this->callback = null;
    }

    public function beat(): void
    {
        if ($this->callback === null || microtime(true) - $this->last < $this->everySeconds) {
            return;
        }
        $this->last = microtime(true);
        ($this->callback)();
    }
}
