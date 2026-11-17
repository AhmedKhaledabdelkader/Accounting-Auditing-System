<?php

namespace Tests\Feature;

use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use RuntimeException;
use Tests\TestCase;

/** Queue settings that would let a long run be picked up by a second worker. */
class QueueSafetyTest extends TestCase
{
    public function test_configured_retry_after_is_above_the_job_timeout(): void
    {
        $this->assertGreaterThan(\App\Jobs\ClassifyTrialBalance::TIMEOUT, (require config_path('queue.php'))['connections']['database']['retry_after']);
    }

    public function test_worker_refuses_to_start_when_retry_after_is_not_above_the_job_timeout(): void
    {
        config(['queue.connections.database.retry_after' => 90]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has retry_after = 90s, not above the classification job timeout (1800s)');

        event(new WorkerStarting('database', 'default', new WorkerOptions));
    }

    public function test_worker_starts_with_a_safe_retry_after(): void
    {
        config(['queue.connections.database.retry_after' => 1900]);

        event(new WorkerStarting('database', 'default', new WorkerOptions));
        event(new WorkerStarting('sync', 'default', new WorkerOptions));   // no retry_after: nothing to check

        $this->addToAssertionCount(1);
    }
}
