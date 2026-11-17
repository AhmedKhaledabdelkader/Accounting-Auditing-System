<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Moving an account between the income statement and the balance sheet is a high-priority flag; each result says which AI made it and what it used. */
class StatementAndUsageTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    /** رقم | الاسم | بنود الميزانية | مدين | دائن (closing only) */
    private const HEADER = ['رقم الحساب', 'اسم الحساب', 'بنود الميزانية', 'مدين', 'دائن'];

    private const WITH_FILE_CLASS = [
        'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
        'columns' => ['account_number' => 'A', 'account_name' => 'B', 'existing_classification' => 'C', 'closing_debit' => 'D', 'closing_credit' => 'E'],
    ];

    private const USAGE = ['input_tokens' => 100, 'cache_creation_input_tokens' => 20, 'cache_read_input_tokens' => 300, 'output_tokens' => 10];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->useProvider('anthropic');   // Anthropic's usage fields
        $this->useModel('claude-haiku-4-5');
    }

    /** Every answer carries the same usage; the file's value X1 is mapped to G&A expense mains. */
    private function fake(array $structure, array $labels, array $existingMap = []): void
    {
        Http::fake(['api.anthropic.com/*' => function (Request $r) use ($structure, $labels, $existingMap) {
            $body = $this->isStructureCall($r)
                ? $this->toolResponse('submit_structure', $structure)
                : $this->toolResponse('submit_rows', [
                    'rows'                   => array_values(array_filter($labels, fn ($l) => in_array($l['row'], $this->requestedRows($r), true))),
                    'existing_class_matches' => $existingMap,
                ]);

            return Http::response(['usage' => self::USAGE] + $body);
        }]);
    }

    public function test_statement_different_from_the_file_classification_is_a_high_priority_flag_shown_first(): void
    {
        $this->fake(self::WITH_FILE_CLASS, [$this->account(2, 'مخصص نهاية الخدمة', 80, 'مخصص')],
            [['existing_id' => 1, 'main_classes' => ['اجور و رواتب و مافي حكمها', 'إيجارات']]]);

        $response = $this->postJson(self::URL, ['file' => $this->xlsxUpload([self::HEADER,
            ['323008', 'مكافأة نهاية خدمة', 'المصروفات العمومية والإدارية', 15874, 0],
        ])])->assertOk();

        $message = '⛔ تصنيف الملف «المصروفات العمومية والإدارية» يشير إلى أن هذا الحساب في قائمة الدخل، '
            .'والتصنيف المقترح وضعه في قائمة المركز المالي («مخصص نهاية الخدمة»). وهذا يغيّر صافي الربح، راجعه أولًا.';
        $response->assertJsonPath('data.0.needs_review', true);
        $flags = $response->json('data.0.data_flags');
        $this->assertSame(['statement_mismatch', 'high', $message], [$flags[1]['type'], $flags[1]['severity'], $flags[1]['message']]);
        $this->assertNotContains('classification_mismatch', array_column($flags, 'type'));   // replaced, not repeated
        // Before the other notes; the wrong-side balance caps the confidence at 60, so the reason is shown too.
        $this->assertStringStartsWith($message.' | مخصص | رصيد ختامي مدين', $response->json('data.0.note'));
        $this->assertStringStartsWith('⛔ 1 حساب وضعها التصنيف المقترح في قائمة غير التي', $response->json('meta.warnings.0'));
        $this->assertStringEndsWith('«مكافأة نهاية خدمة» (الصف 2 في الملف)', $response->json('meta.warnings.0'));
    }

    public function test_expense_named_accounts_on_the_balance_sheet_are_flagged_unless_prepaid_or_accrued(): void
    {
        $this->fake($this->standardStructure(), [
            $this->account(2, 'مخصص نهاية الخدمة'),
            $this->account(3, 'مصروفات مدفوعة مقدماً'),
            $this->account(4, 'رواتب وأجور مستحقة'),
            $this->account(5, 'مصروف مكافأة نهاية الخدمة'),
        ]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['323008', 'مصروف مكافأة نهاية خدمة', 0, 0, 0, 50, 0, 50],
            ['140201', 'مصروف ايجار مقدم', 0, 0, 90, 0, 90, 0],
            ['221501', 'مصروفات مستحقة رواتب', 0, 0, 0, 70, 0, 70],
            ['323009', 'مصاريف مكافأة نهاية الخدمة', 0, 0, 40, 0, 40, 0],   // on the income statement: fine
        ])])->assertOk();

        $types = array_map(fn ($r) => array_column($r['data_flags'], 'type'), $response->json('data'));
        $this->assertSame([['statement_mismatch'], [], [], []], $types);
        $response->assertJsonPath('data.0.data_flags.0.message',
            '⛔ اسم الحساب يدل على أنه مصروف (قائمة الدخل)، والتصنيف المقترح وضعه في قائمة المركز المالي («مخصص نهاية الخدمة»). وهذا يغيّر صافي الربح، راجعه أولًا.');
    }

    public function test_both_checks_on_one_row_give_one_flag(): void
    {
        $this->fake(self::WITH_FILE_CLASS, [$this->account(2, 'مخصص نهاية الخدمة')],
            [['existing_id' => 1, 'main_classes' => ['اجور و رواتب و مافي حكمها']]]);

        $flags = $this->postJson(self::URL, ['file' => $this->xlsxUpload([self::HEADER,
            ['323008', 'مصروف مكافأة نهاية خدمة', 'المصروفات العمومية والإدارية', 0, 50],
        ])])->assertOk()->json('data.0.data_flags');

        $this->assertSame(['statement_mismatch'], array_column($flags, 'type'));
        $this->assertStringContainsString('تصنيف الملف', $flags[0]['message']);   // the file's classification is the stronger evidence
    }

    public function test_prompt_has_the_expense_and_end_of_service_rules(): void
    {
        $this->fake($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);
        $this->postJson(self::URL, ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 10, 0, 10, 0]])])->assertOk();

        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r)
            && str_contains($this->systemText($r, 0), 'Account names starting with "مصروف" / "مصاريف" / "مصروفات" are income-statement expenses')
            && str_contains($this->systemText($r, 0), '"مصروف مكافأة نهاية الخدمة" (the period\'s expense, income statement) is not "مخصص نهاية الخدمة"'));
    }

    public function test_result_and_summary_log_record_provider_model_and_summed_usage(): void
    {
        config(['classification.log_channel' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $this->fake($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 10, 0, 10, 0]])])->assertOk();

        $usage = ['requests' => 2, 'input' => 200, 'cache_write' => 40, 'cached' => 600, 'output' => 20];   // structure + one batch
        $response->assertJsonPath('meta.provider', 'anthropic')
            ->assertJsonPath('meta.model', 'claude-haiku-4-5')
            ->assertJsonPath('meta.usage', $usage);
        $summary = collect($handler->getRecords())->firstWhere('message', 'classification_summary');
        $this->assertSame(['anthropic', 'claude-haiku-4-5', $usage, 1], [$summary->context['provider'], $summary->context['model'], $summary->context['usage'], $summary->context['rows']]);
    }

    public function test_usage_is_counted_per_file_not_summed_across_requests(): void
    {
        $this->fake($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);
        $file = fn () => $this->trialBalance([['110101', 'الصندوق', 0, 0, 10, 0, 10, 0]]);

        $this->postJson(self::URL, ['file' => $file()])->assertJsonPath('meta.usage.requests', 2);
        $this->postJson(self::URL, ['file' => $file()])->assertJsonPath('meta.usage.requests', 2);
    }

    public function test_a_run_that_paused_for_confirmation_keeps_its_detection_usage(): void
    {
        Storage::fake('local');
        // Wrong mapping on both structure attempts → the run pauses after 2 calls.
        $this->fake($this->standardStructure(['columns' => ['closing_debit' => 'B']]), array_map(fn ($r) => $this->account($r, 'نقدية بالصندوق'), range(2, 9)));
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance(array_map(fn ($i) => ['110'.$i, "صندوق {$i}", 0, 0, 10, 0, 10, 0], range(1, 8)))])->json('id');
        $this->assertSame(2, ClassificationRun::find($id)->usage['requests']);

        $s = $this->standardStructure();
        $this->postJson("/api/v1/classify/jobs/{$id}/structure", ['header_rows' => $s['header_rows'], 'first_data_row' => $s['first_data_row'], 'columns' => $s['columns']])->assertStatus(202);

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')
            ->assertJsonPath('result.meta.usage.requests', 3)   // 2 detection calls before the pause + 1 batch after
            ->assertJsonPath('result.meta.usage.output', 30);
    }

    public function test_default_models_when_the_model_variables_are_unset(): void
    {
        $names = ['CLASSIFIER_ANTHROPIC_MODEL', 'CLASSIFIER_GEMINI_MODEL'];
        $saved = array_map(fn ($n) => [$_ENV[$n] ?? null, $_SERVER[$n] ?? null, getenv($n)], $names);
        foreach ($names as $n) {
            unset($_ENV[$n], $_SERVER[$n]);
            putenv($n);
        }
        try {
            $config = require config_path('classification.php');   // as on a server without the model variables
        } finally {
            foreach ($names as $i => $n) {
                [$env, $server, $put] = $saved[$i];
                $env !== null && $_ENV[$n] = $env;
                $server !== null && $_SERVER[$n] = $server;
                $put !== false && putenv("{$n}={$put}");
            }
        }

        $this->assertSame('claude-sonnet-5', $config['providers']['anthropic']['model']);
        $this->assertSame('gemini-3.8-flash', $config['providers']['gemini']['model']);
    }
}
