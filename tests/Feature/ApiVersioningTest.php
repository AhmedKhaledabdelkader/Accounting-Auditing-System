<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every API endpoint lives under /api/v1; the unversioned paths are gone. */
class ApiVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_is_served_under_v1_only(): void
    {
        $this->getJson('/api/v1/classification-cache/explain')->assertOk();
        $this->getJson('/api/v1/classify/jobs/does-not-exist')->assertNotFound();

        $this->getJson('/api/classification-cache/explain')->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Route not found']);
        $this->postJson('/api/classify/jobs')->assertNotFound();
    }

    public function test_urls_returned_by_the_api_are_versioned(): void
    {
        $this->assertSame('/api/v1/classify/jobs/x', route('classify.jobs.show', 'x', false));
        $this->assertSame('/api/v1/classify/jobs/x/structure', route('classify.jobs.structure', 'x', false));
    }
}
