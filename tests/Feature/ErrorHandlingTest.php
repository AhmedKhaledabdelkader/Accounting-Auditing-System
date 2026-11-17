<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_missing_file_returns_422_with_validation_errors(): void
    {
        $this->postJson(self::URL)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'The file field is required.']);
    }

    public function test_missing_file_returns_json_422_even_without_accept_header(): void
    {
        $this->post(self::URL)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors('file');
    }

    public function test_non_file_value_returns_422(): void
    {
        $this->postJson(self::URL, ['file' => 'not-a-file'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_wrong_file_type_returns_422(): void
    {
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_file_over_10mb_returns_422(): void
    {
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->create('big.xlsx', 10241)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_wrong_http_method_returns_405_not_500(): void
    {
        $this->getJson(self::URL)->assertStatus(405);
    }

    public function test_unknown_route_returns_json_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertExactJson(['status' => 'error', 'message' => 'Route not found']);
    }

    public function test_unexpected_exception_returns_json_500(): void
    {
        Route::get('/api/_boom', fn () => throw new RuntimeException('boom'));

        $this->getJson('/api/_boom')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => 'boom']);
    }
}
