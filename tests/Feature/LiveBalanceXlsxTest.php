<?php

namespace Tests\Feature;

use Tests\Support\BalanceXlsxExpectations;
use Tests\TestCase;

/**
 * balance.xlsx against the real API of CLASSIFIER_PROVIDER (costs money, takes ~1–2 minutes).
 * Skipped unless RUN_LIVE_TESTS=1:  RUN_LIVE_TESTS=1 [CLASSIFIER_PROVIDER=gemini] php artisan test --filter=LiveBalanceXlsxTest
 */
class LiveBalanceXlsxTest extends TestCase
{
    use BalanceXlsxExpectations;

    public function test_balance_xlsx_with_the_real_provider(): void
    {
        $var = strtoupper($this->provider()).'_API_KEY';
        $key = in_array(env($var), [null, '', '<need-to-change>'], true) ? null : env($var);
        if (! env('RUN_LIVE_TESTS') || ! $key) {
            $this->markTestSkipped("Set RUN_LIVE_TESTS=1 and {$var} to run against the real API.");
        }
        config(["classification.providers.{$this->provider()}.api_key" => $key]);   // TestCase sets fake keys
        set_time_limit(600);

        $response = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->balanceXlsx()]);

        file_put_contents(storage_path("logs/live-balance-xlsx-{$this->provider()}.json"), json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertBalanceXlsxResults($response);
    }
}
