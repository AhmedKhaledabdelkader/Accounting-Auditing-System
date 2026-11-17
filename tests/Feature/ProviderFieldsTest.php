<?php

namespace Tests\Feature;

use App\Models\ClassificationImport;
use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeWire;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** `provider` and `model` on job status and imports: what the run or the mapping used, null when nothing was used (yet). */
class ProviderFieldsTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    private const IMPORT_HEADER = ['رقم الحساب', 'الميزانية', 'الإيضاحات', 'اسم الحساب', 'أول مدين', 'أول دائن', 'حركة مدينة', 'حركة دائنة',
        'أخر مدين', 'أخر دائن', 'اسم الشركة'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_a_finished_run_shows_the_provider_and_model_it_ran_with(): void
    {
        $this->useModel('claude-sonnet-5');
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 10, 0, 10, 0]])])->assertStatus(202)->json('id');

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')
            ->assertJsonPath('provider', $this->provider())
            ->assertJsonPath('model', 'claude-sonnet-5')
            ->assertJsonPath('result.meta.provider', $this->provider())   // unchanged
            ->assertJsonPath('result.meta.model', 'claude-sonnet-5');
    }

    public function test_a_queued_run_and_a_run_from_before_these_fields_show_null(): void
    {
        Queue::fake();
        $queued = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 10, 0, 10, 0]])])->assertStatus(202)
            ->assertJsonPath('provider', null)->assertJsonPath('model', null)->json('id');
        $this->getJson("/api/v1/classify/jobs/{$queued}")->assertJsonPath('provider', null)->assertJsonPath('model', null);

        $old = (string) Str::uuid();
        ClassificationRun::forceCreate(['id' => $old, 'status' => 'done', 'original_name' => 'old.xlsx', 'result' => ['data' => [], 'meta' => []]]);
        $this->getJson("/api/v1/classify/jobs/{$old}")->assertOk()
            ->assertJsonPath('status', 'done')->assertJsonPath('provider', null)->assertJsonPath('model', null);
    }

    private function import(array $rows): array
    {
        $file = $this->xlsxUpload([self::IMPORT_HEADER, ...array_map(fn ($r) => [...$r, 'شركة 1'], $rows)]);

        return $this->post('/api/v1/classification-cache/import', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(202)->json();
    }

    public function test_an_import_that_needed_no_ai_shows_null(): void
    {
        $id = $this->import([['1101', 'النقد ومافي حكمه', 'نقدية بالصندوق', 'الصندوق', 0, 0, 0, 0, 10, 0]])['id'];

        Http::assertNothingSent();
        $this->getJson("/api/v1/classification-cache/imports/{$id}")->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('provider', null)->assertJsonPath('model', null);
        $this->getJson('/api/v1/classification-cache/imports')->assertJsonPath('0.provider', null)->assertJsonPath('0.model', null);
    }

    public function test_an_import_mapped_by_the_ai_shows_its_provider_and_model(): void
    {
        $this->useModel('test-model');
        Http::fake([$this->aiUrl() => function (Request $r) {
            $ids = array_map('intval', preg_match_all('/^(\d+): /m', $this->userText($r), $m) ? $m[1] : []);
            $mappings = FakeWire::toolName($r->data()) === 'submit_budget_mappings'
                ? array_map(fn ($id) => ['id' => $id, 'decision' => 'map', 'main_class' => 'النقد ومافي حكمه', 'confidence' => 95, 'reason' => 'واضح'], $ids)
                : array_map(fn ($id) => ['id' => $id, 'sub_class' => 'نقدية بالصندوق', 'function_sub_class' => null, 'confidence' => 95, 'reason' => 'واضح'],
                    array_map('intval', preg_match_all('/^### (\d+)$/m', $this->userText($r), $n) ? $n[1] : []));

            return Http::response($this->toolResponse(FakeWire::toolName($r->data()), ['mappings' => $mappings]));
        }]);

        $id = $this->import([['1101', 'نقديات الشركة', 'خزينة رئيسية', 'الصندوق', 0, 0, 0, 0, 10, 0]])['id'];

        Http::assertSent(fn (Request $r) => FakeWire::toolName($r->data()) === 'submit_budget_mappings');
        $this->getJson("/api/v1/classification-cache/imports/{$id}")->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('provider', $this->provider())->assertJsonPath('model', 'test-model');
        $this->assertSame([$this->provider(), 'test-model'], [ClassificationImport::find($id)->provider, ClassificationImport::find($id)->model]);
    }
}
