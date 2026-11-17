<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Tool answers that don't match the schema (lists sent as JSON strings, malformed items) never fail the run. */
class MalformedToolOutputTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    /** رقم | الاسم | بنود الميزانية | مدين | دائن (closing only) */
    private const HEADER = ['رقم الحساب', 'اسم الحساب', 'بنود الميزانية', 'مدين', 'دائن'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function structure(): array
    {
        return [
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'existing_classification' => 'C', 'closing_debit' => 'D', 'closing_credit' => 'E'],
        ];
    }

    /** @param  mixed  $rows  rows of every classification call; $matches existing_class_matches of the matching call */
    private function fake(mixed $rows, mixed $matches): void
    {
        Http::fake([$this->aiUrl() => function (Request $r) use ($rows, $matches) {
            return Http::response(match (true) {
                $this->isStructureCall($r) => $this->toolResponse('submit_structure', $this->structure()),
                $this->isMatchCall($r)     => $this->toolResponse('submit_rows', ['rows' => [], 'existing_class_matches' => $matches]),
                default                    => $this->toolResponse('submit_rows', ['rows' => $rows]),
            });
        }]);
    }

    /** Submits the file as a background run and returns the polled run. */
    private function classifyRun(array $rows): \Illuminate\Testing\TestResponse
    {
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->xlsxUpload([self::HEADER, ...$rows])])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('error', null);
    }

    public function test_existing_class_matches_sent_as_a_json_string_is_decoded(): void
    {
        $this->fake([$this->account(2, 'مخزون بضاعة')], json_encode([
            ['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']],
        ], JSON_UNESCAPED_UNICODE));

        $this->classifyRun([['112001', 'سيارات', 'ممتلكات والالات ومعدات', 1, 0]])
            ->assertJsonPath('result.data.0.existing_main_classes', ['الممتلكات و الالات و المعدات'])
            ->assertJsonPath('result.data.0.data_flags.0.type', 'classification_mismatch')
            ->assertJsonPath('result.meta.warnings', []);
    }

    public function test_malformed_items_are_skipped_and_the_rest_are_kept(): void
    {
        $this->fake([$this->account(2, 'مخزون بضاعة'), $this->account(3, 'إيجارات')], [
            'not an object',
            ['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']],
            ['existing_id' => 2],   // no main_classes, every time: X2 stays unmapped
        ]);

        $this->classifyRun([
            ['112001', 'سيارات', 'ممتلكات والالات ومعدات', 1, 0],
            ['33001', 'إيجار', 'المصروفات العمومية والإدارية', 1, 0],
        ])
            ->assertJsonPath('result.data.0.existing_main_classes', ['الممتلكات و الالات و المعدات'])
            ->assertJsonPath('result.data.1.existing_main_classes', null)
            ->assertJsonPath('result.meta.warnings', ['تعذّرت مقارنة تصنيف الملف بالتصنيف المقترح لـ 1 قيمة، لذا لا توجد تنبيهات عدم توافق لهذه الحسابات. حاول مرة أخرى.']);
    }

    public function test_rows_sent_as_a_json_string_are_decoded(): void
    {
        $this->fake(json_encode([$this->account(2, 'آلات ومعدات')], JSON_UNESCAPED_UNICODE), [
            ['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']],
        ]);

        $this->classifyRun([['112001', 'سيارات', 'ممتلكات والالات ومعدات', 1, 0]])
            ->assertJsonPath('result.data.0.sub_class', 'آلات ومعدات')
            ->assertJsonPath('result.data.0.data_flags', []);
    }

    public function test_classification_tool_is_strict(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        $this->fake([$this->account(2, 'آلات ومعدات')], [['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']]]);

        $this->classifyRun([['112001', 'سيارات', 'ممتلكات والالات ومعدات', 1, 0]]);

        Http::assertSent(function (Request $r) {
            if ($this->isStructureCall($r)) {
                return false;
            }
            $tool   = $r['tools'][0];
            $schema = $tool['input_schema'];

            return $tool['strict'] === true
                && $schema['additionalProperties'] === false
                && $schema['properties']['rows']['items']['additionalProperties'] === false
                && $schema['properties']['existing_class_matches']['items']['additionalProperties'] === false
                && ! isset($schema['properties']['rows']['items']['properties']['confidence']['minimum']);
        });
    }
}
