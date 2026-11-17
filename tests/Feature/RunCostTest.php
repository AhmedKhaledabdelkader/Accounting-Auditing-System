<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * The cost of a run from the provider's usage fields, and the estimated savings of the classification cache.
 * Anthropic's usage fields (cache writes); Gemini's dated prices: GeminiDriverTest.
 */
class RunCostTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    /** USD per million tokens. */
    private const PRICES = ['input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.20, 'output' => 10.00];

    /** Structure detection call: 1000 × $2 + 200 × $10 = $0.004 */
    private const STRUCTURE_USAGE = ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];

    /** One row call: 500 × $2 + 2000 × $2.50 + 4000 × $0.20 + 300 × $10 = $0.0098 */
    private const ROW_USAGE = ['input_tokens' => 500, 'output_tokens' => 300, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 4000];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->useProvider('anthropic');
        config(['classification.prices.test-model' => [['from' => null] + self::PRICES]]);
        $this->useModel('test-model');
    }

    /**
     * Every response carries its usage. $rowAnswer(int $call, list<int> $requested): array{0: int, 1: list<array>}
     * gives the status and the labels of each row call (call 1, 2, … including retries).
     */
    private function fake(\Closure $rowAnswer): void
    {
        $calls = 0;
        Http::fake(['api.anthropic.com/*' => function (Request $r) use ($rowAnswer, &$calls) {
            if ($this->isStructureCall($r)) {
                return Http::response(['usage' => self::STRUCTURE_USAGE] + $this->toolResponse('submit_structure', $this->standardStructure()));
            }
            [$status, $labels] = $rowAnswer(++$calls, $this->requestedRows($r));

            return $status === 200
                ? Http::response(['usage' => self::ROW_USAGE] + $this->toolResponse('submit_rows', ['rows' => $labels]))
                : Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal error']], $status);
        }]);
    }

    private function upload(array $accounts): TestResponse
    {
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($accounts)])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done');
    }

    private function labels(array $requested): array
    {
        return array_map(fn ($n) => $this->account($n, 'نقدية بالصندوق'), $requested);
    }

    public function test_run_cost_is_calculated_from_the_usage_fields(): void
    {
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);

        $run = $this->upload([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])
            ->assertJsonPath('cost.input_tokens', 1500)
            ->assertJsonPath('cost.output_tokens', 500)
            ->assertJsonPath('cost.cache_write_tokens', 2000)
            ->assertJsonPath('cost.cache_read_tokens', 4000)
            ->assertJsonPath('cost.llm_requests', 2)        // structure + one batch
            ->json('cost.cost_usd');

        $this->assertEqualsWithDelta(0.004 + 0.0098, $run, 1e-9);
    }

    public function test_retries_are_included_in_the_cost(): void
    {
        Sleep::fake();
        // Call 1: a 500 (retried by the HTTP client). Call 2: answers only R2. Call 3: the retry of the missing R3.
        $this->fake(fn ($call, $requested) => match ($call) {
            1       => [500, []],
            2       => [200, $this->labels([2])],
            default => [200, $this->labels($requested)],
        });

        $r = $this->upload([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0], ['1102', 'العهد', 0, 0, 1, 0, 1, 0]])
            ->assertJsonPath('result.meta.unclassified.rows', 0)
            ->assertJsonPath('cost.llm_requests', 4)        // structure + failed attempt + answer + missing-rows retry
            ->assertJsonPath('cost.output_tokens', 200 + 2 * 300);   // the failed attempt is not billed

        $this->assertEqualsWithDelta(0.004 + 2 * 0.0098, $r->json('cost.cost_usd'), 1e-9);
    }

    public function test_estimated_savings_are_zero_when_nothing_came_from_the_cache(): void
    {
        config(['classification.cache.enabled' => true]);
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);

        $this->upload([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])
            ->assertJsonPath('cache.rows_from_cache', 0)
            ->assertJsonPath('cache.estimated_savings_usd', 0)
            ->assertJsonPath('cache.savings_is_estimate', true)
            ->assertJsonPath('cache.savings_basis', 'none');
    }

    public function test_savings_of_a_fully_cached_run_use_the_cost_per_row_of_recent_runs(): void
    {
        config(['classification.cache.enabled' => true]);
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);
        $accounts = [['1101', 'الصندوق', 0, 0, 1, 0, 1, 0], ['1102', 'العهد', 0, 0, 1, 0, 1, 0]];

        $first = $this->upload($accounts);
        $perRow = 0.0098 / 2;   // one batch for 2 rows; structure detection is not counted (the cache doesn't save it)
        $this->assertEqualsWithDelta($perRow, (float) ClassificationRun::find($first->json('id'))->cost_per_row_usd, 1e-9);

        $second = $this->upload($accounts)
            ->assertJsonPath('cache.rows_from_cache', 2)
            ->assertJsonPath('cache.rows_sent_to_llm', 0)
            ->assertJsonPath('cache.savings_basis', 'recent_runs')
            ->assertJsonPath('cost.llm_requests', 1);       // structure only
        $this->assertEqualsWithDelta(2 * $perRow, $second->json('cache.estimated_savings_usd'), 1e-9);
        $this->assertEqualsWithDelta(0.004, $second->json('cost.cost_usd'), 1e-9);
    }

    public function test_unknown_model_price_gives_no_cost_instead_of_a_guess(): void
    {
        $this->useModel('unpriced-model');
        $this->fake(fn ($call, $requested) => [200, $this->labels($requested)]);

        $this->upload([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])
            ->assertJsonPath('cost.cost_usd', null)
            ->assertJsonPath('cost.output_tokens', 500);
    }
}
