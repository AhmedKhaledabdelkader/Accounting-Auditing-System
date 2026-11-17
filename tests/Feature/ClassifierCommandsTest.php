<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeWire;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** classifier:probe and classifier:compare, against faked providers. */
class ClassifierCommandsTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Answers every probe call validly, in the wire format of the request. */
    private function fakeValidAnswers(string $provider): void
    {
        Http::fake([FakeWire::URLS[$provider] => function (Request $r) use ($provider) {
            $text = $this->userText($r);
            $input = match (FakeWire::toolName($r->data())) {
                'submit_structure'       => $this->standardStructure(),
                'submit_budget_mappings' => ['mappings' => array_map(fn ($id) => ['id' => $id, 'decision' => 'skip', 'main_class' => null, 'confidence' => 90, 'reason' => 'x'], [0, 1, 2])],
                'submit_note_mappings'   => ['mappings' => array_map(fn ($id) => ['id' => $id, 'sub_class' => null, 'function_sub_class' => null, 'confidence' => 0, 'reason' => 'x'], [0, 1])],
                default                  => str_contains($text, 'Rows to classify')
                    ? ['rows' => array_map(fn ($row) => $this->account($row, 'نقدية بالصندوق'), [2, 3, 4, 5])]
                    : ['rows' => [], 'adjustment_matches' => [['adjustment_row' => 1, 'account_row' => 2]],
                        'existing_class_matches' => array_map(fn ($id) => ['existing_id' => $id, 'main_classes' => ['النقد ومافي حكمه']], [1, 2, 3])],
            };

            return Http::response(FakeWire::answer($provider, FakeWire::toolName($r->data()), $input));
        }]);
    }

    public function test_probe_reports_every_call_accepted(): void
    {
        $this->fakeValidAnswers('gemini');

        $this->artisan('classifier:probe', ['--provider' => 'gemini'])
            ->expectsOutputToContain('Provider: gemini, model: gemini-3.8-flash')
            ->expectsOutputToContain('note mappings (null inside enums)')
            ->assertExitCode(0);

        Http::assertSentCount(5);
    }

    public function test_probe_fails_on_a_rejected_schema(): void
    {
        Http::fake([FakeWire::URLS['gemini'] => Http::response(FakeWire::error('gemini', 400, 'Invalid schema'), 400)]);

        $this->artisan('classifier:probe', ['--provider' => 'gemini'])
            ->expectsOutputToContain('AI provider rejected the request: Invalid schema')
            ->assertExitCode(1);
    }

    public function test_compare_reports_the_agreement_and_the_classes_they_disagree_on(): void
    {
        $answer = fn (string $provider, array $classes) => function (Request $r) use ($provider, $classes) {
            $input = $this->isStructureCall($r) ? $this->standardStructure()
                : ['rows' => array_map(fn ($row) => $this->account($row, $classes[$row]), $this->requestedRows($r))];

            return Http::response(FakeWire::answer($provider, FakeWire::toolName($r->data()), $input));
        };
        Http::fake([
            FakeWire::URLS['anthropic'] => $answer('anthropic', [2 => 'نقدية بالصندوق', 3 => 'نقدية لدى البنوك', 4 => 'عملاء', 5 => 'عملاء']),
            FakeWire::URLS['gemini']    => $answer('gemini', [2 => 'نقدية بالصندوق', 3 => 'نقدية لدى البنوك', 4 => 'أوراق قبض', 5 => 'عملاء']),
        ]);
        $file = $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0], ['1102', 'البنك', 0, 0, 1, 0, 1, 0],
            ['1201', 'حساب أ', 0, 0, 1, 0, 1, 0], ['1202', 'حساب ب', 0, 0, 1, 0, 1, 0]]);
        $report = storage_path('framework/testing/compare.json');
        @mkdir(dirname($report), 0777, true);

        $this->artisan('classifier:compare', ['file' => $file->getRealPath(), '--report' => $report])
            ->expectsOutputToContain('Sub-class agreement: 75% of 4 rows (main class: 100%).')
            ->assertExitCode(0);

        $json = json_decode(file_get_contents($report), true);
        $this->assertSame(['rows' => 1, 'became' => ['أوراق قبض' => 1]], $json['disagreements_by_reference_class']['عملاء']);
        $this->assertSame(4, $json['disagreements'][0]['row']);
        @unlink($report);
    }
}
