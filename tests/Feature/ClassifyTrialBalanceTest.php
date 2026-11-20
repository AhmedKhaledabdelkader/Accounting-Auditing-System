<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Endpoint contract, the requests sent to Anthropic, and the split between AI confidence and PHP checks. */
class ClassifyTrialBalanceTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->useModel('test-model');
    }

    private function classifyOne(array $account, array $label)
    {
        $this->fakeLlm($this->standardStructure(), [$label]);

        return $this->postJson(self::URL, ['file' => $this->trialBalance([$account])]);
    }

    public function test_classifies_accounts_and_returns_meta(): void
    {
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق', 95),
            $this->account(3, 'موردون', 90),
        ]);

        $file = $this->trialBalance([
            ['110101', 'الصندوق الرئيسي', 5000, 0, 12000, 9000, 8000, 0],
            ['210101', 'مورد - شركة الأمل', 0, 3000, 1000, 4000, 0, 6000],
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.needs_review', 0)
            ->assertJsonPath('meta.avg_confidence', 92.5)
            ->assertJsonPath('meta.skipped_rows', 0)
            ->assertJsonPath('meta.balance', ['opening' => 2000, 'period' => 0, 'closing' => 2000])   // debit − credit; a partial file
            ->assertJsonPath('data.0.account_number', '110101')
            ->assertJsonPath('data.0.account_name', 'الصندوق الرئيسي')
            ->assertJsonPath('data.0.financial_statement', 'قائمة المركز المالي')
            ->assertJsonPath('data.0.main_class', 'النقد ومافي حكمه')
            ->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق')
            ->assertJsonPath('data.0.opening_debit', 5000)
            ->assertJsonPath('data.0.period_credit', 9000)
            ->assertJsonPath('data.0.closing_debit', 8000)
            ->assertJsonPath('data.0.classification_confidence', 95)
            ->assertJsonPath('data.0.classification_reason', 'الاسم واضح')
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.source_note', null)
            ->assertJsonPath('data.0.confidence', 95)
            ->assertJsonPath('data.0.confidence_label', '95%')
            ->assertJsonPath('data.0.needs_review', false)
            ->assertJsonPath('data.0.note', '')
            ->assertJsonPath('data.1.main_class', 'الذمم الدائنة التجارية');
    }

    public function test_response_contains_every_documented_field(): void
    {
        $this->classifyOne(['110101', 'الصندوق', 0, 0, 100, 0, 100, 0], $this->account(2, 'نقدية بالصندوق'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'account_number', 'account_name', 'financial_statement', 'main_class', 'sub_class',
                    'opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit',
                    'classification_confidence', 'classification_reason', 'data_flags', 'source_note', 'needs_review',
                    'confidence', 'confidence_label', 'note',
                ]],
                'meta' => ['total', 'needs_review', 'avg_confidence', 'skipped_rows', 'warnings', 'info', 'other_sheets', 'adjustments'],
            ]);
    }

    public function test_structure_request_sends_sheet_names_notes_and_other_sheets(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        $this->classifyOne(['110101', 'الصندوق', 0, 0, 100, 0, 100, 0], $this->account(2, 'نقدية بالصندوق'))->assertOk();

        Http::assertSent(function (Request $r) {
            if (! $this->isStructureCall($r)) {
                return false;
            }
            $schema = $this->toolSchema($r)['properties'];

            return $r->url() === 'https://api.anthropic.com/v1/messages'
                && $r->hasHeader('x-api-key', 'test-key')
                && $r->hasHeader('anthropic-version', '2023-06-01')
                && $r['model'] === 'test-model'
                && $r['tool_choice'] === ['type' => 'tool', 'name' => 'submit_structure']
                && $schema['sheet']['enum'] === [1]
                && $schema['columns']['properties']['account_name']['enum'] === ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']
                && isset($schema['columns']['properties']['notes'])
                && $schema['other_sheets']['items']['properties']['type']['enum'] === ['adjustments', 'subsidiary_ledger', 'notes', 'other']
                && str_contains($this->systemText($r), 'return it as notes even if it has no header')
                && str_contains($this->userText($r), '### Sheet 1: Sheet1')
                && str_contains($this->userText($r), 'R2 | A: 110101 | B: الصندوق');
        });
    }

    public function test_classification_request_prompt_schema_and_cached_prefix(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        $this->classifyOne(['110101', 'الصندوق', 0, 0, 100, 0, 100, 0], $this->account(2, 'نقدية بالصندوق'))->assertOk();

        Http::assertSent(function (Request $r) {
            if ($this->isStructureCall($r)) {
                return false;
            }
            $item  = $this->toolSchema($r)['properties']['rows']['items'];
            $rules = $this->systemText($r, 0);

            return $r['tool_choice'] === ['type' => 'tool', 'name' => 'submit_rows']
                && ! isset($item['properties']['row']['enum'])                       // keeps tools identical across batches
                && $item['properties']['row_type']['enum'] === ['account', 'parent', 'total', 'section', 'other']
                && count($item['properties']['sub_class']['enum']) === 158
                && array_keys($item['properties']) === ['row', 'row_type', 'sub_class', 'confidence', 'reason']
                && str_contains($rules, 'Egyptian Arabic')
                && str_contains($rules, 'Infer the numbering scheme from the file itself')
                && str_contains($rules, 'do NOT consider balances when scoring confidence')
                && str_contains($rules, '"عُهد العاملين"')
                && ! str_contains($rules, '1 = أصول')                                // first-digit rule removed
                && $r['system'][1]['cache_control'] === ['type' => 'ephemeral']
                && str_contains($this->fileBlock($r), 'B = account name / description')
                && str_contains($this->fileBlock($r), "Full chart of accounts, for context only (row | number | name | file classification):\nR2 | 110101 | الصندوق")
                && str_contains($this->userText($r), "Rows to classify:\nR2 | A: 110101");
        });
        Http::assertSentCount(2);
    }

    public function test_low_classification_confidence_needs_review_with_reason(): void
    {
        $this->classifyOne(['120101', 'حساب عام', 0, 0, 100, 0, 100, 0], $this->account(2, 'مدينون متنوعون', 65, 'الاسم مش واضح'))
            ->assertOk()
            ->assertJsonPath('data.0.classification_confidence', 65)
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.0.note', 'الاسم مش واضح')
            ->assertJsonPath('meta.needs_review', 1);
    }

    public function test_confidence_70_is_not_reviewed_and_hides_reason(): void
    {
        $this->classifyOne(['110101', 'الصندوق', 0, 0, 100, 0, 100, 0], $this->account(2, 'نقدية بالصندوق', 70, 'ممكن يكون حاجة تانية'))
            ->assertOk()
            ->assertJsonPath('data.0.needs_review', false)
            ->assertJsonPath('data.0.classification_reason', 'ممكن يكون حاجة تانية')
            ->assertJsonPath('data.0.note', '');
    }

    public function test_non_arabic_reason_is_replaced_with_egyptian_fallback(): void
    {
        $this->classifyOne(['120101', 'General account', 0, 0, 100, 0, 100, 0], $this->account(2, 'مدينون متنوعون', 60, 'Name is ambiguous'))
            ->assertOk()
            ->assertJsonPath('data.0.classification_reason', 'السبب غير واضح، يحتاج إلى مراجعة')
            ->assertJsonPath('data.0.note', 'السبب غير واضح، يحتاج إلى مراجعة');
    }

    public function test_missing_reason_uses_egyptian_fallback(): void
    {
        $label = ['row' => 2, 'row_type' => 'account', 'sub_class' => 'مدينون متنوعون', 'confidence' => 60];

        $this->classifyOne(['120101', 'حساب', 0, 0, 100, 0, 100, 0], $label)
            ->assertOk()
            ->assertJsonPath('data.0.note', 'السبب غير واضح، يحتاج إلى مراجعة');
    }

    public function test_an_account_zero_in_every_column_is_left_out_and_never_sent(): void
    {
        $asked = [];
        $this->fakeLlm($this->standardStructure(), function ($call, array $requested) use (&$asked) {
            array_push($asked, ...$requested);

            return [$this->account(3, 'نقدية بالصندوق', 92)];
        });
        $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['110109', 'صندوق فرع مغلق', 0, 0, 0, 0, 0, 0],
            ['110101', 'الصندوق', 0, 0, 0.004, 0, 100, 0],
        ])])->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.row', 3)
            ->assertJsonPath('meta.info.2', '1 صف رصيدها صفر في الافتتاحي والحركة والختامي (حسابات صفرية أو عناوين)، وقد أُزيلت من التبويب (الصف 2 في الملف).');
        $this->assertSame([3], $asked);
    }

    /* ---------- 1a reconciliation ---------- */

    public function test_closing_higher_than_computed_is_flagged_with_signed_difference(): void
    {
        // expected = 10,340 + (411,756.57 − 423,270.57) = −1,174 ; actual = 0 → +1,174
        $this->classifyOne(['110101010', 'النقد في الصندوق', 10340, 0, 411756.57, 423270.57, 0, 0], $this->account(2, 'نقدية بالصندوق'))
            ->assertOk()
            ->assertJsonPath('data.0.data_flags', [[
                'type' => 'reconciliation', 'difference' => 1174, 'message' => 'الرصيد الختامي أعلى من المحسوب بمقدار 1,174.00',
                'message_text' => 'The closing balance is higher than calculated by 1,174.00',
            ]])
            ->assertJsonPath('data.0.classification_confidence', 95)
            ->assertJsonPath('data.0.needs_review', true);
    }

    public function test_closing_lower_than_computed_is_flagged_with_negative_difference(): void
    {
        $this->classifyOne(['110101510', 'بنك الرياض', 347432.06, 0, 2333318.26, 2678992.46, 6.66, 0], $this->account(2, 'نقدية لدى البنوك'))
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'reconciliation')
            ->assertJsonPath('data.0.data_flags.0.difference', -1751.2)
            ->assertJsonPath('data.0.data_flags.0.message', 'الرصيد الختامي أقل من المحسوب بمقدار 1,751.20');
    }

    public function test_difference_within_one_cent_is_not_flagged(): void
    {
        $this->classifyOne(['1101', 'بنك', 0, 0, 3347481.51, 3347481.512, 0, 0.002], $this->account(2, 'نقدية لدى البنوك'))
            ->assertOk()
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.needs_review', false);
    }

    public function test_reconciliation_with_net_columns(): void
    {
        $file = $this->xlsxUpload([
            ['Code', 'Name', 'Opening', 'Movement', 'Closing'],
            ['1101', 'Cash', 100, -30, 90],
        ]);
        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'opening_net' => 'C', 'period_net' => 'D', 'closing_net' => 'E'],
        ], [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'reconciliation')
            ->assertJsonPath('data.0.data_flags.0.difference', 20)
            ->assertJsonPath('data.0.data_flags.0.message', 'الرصيد الختامي أعلى من المحسوب بمقدار 20.00');
    }

    public function test_no_reconciliation_when_a_group_is_missing(): void
    {
        $file = $this->xlsxUpload([['رقم', 'اسم', 'مدين', 'دائن'], ['1101', 'الصندوق', 100, 0]]);
        $this->fakeLlm([
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => ['account_number' => 'A', 'account_name' => 'B', 'closing_debit' => 'C', 'closing_credit' => 'D'],
        ], [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])->assertOk()->assertJsonPath('data.0.data_flags', []);
    }

    /* ---------- 1b abnormal balance side ---------- */

    public function test_credit_closing_on_debit_account_is_flagged_and_caps_confidence(): void
    {
        $this->classifyOne(['110101', 'الصندوق', 0, 0, 0, 500, 0, 500], $this->account(2, 'نقدية بالصندوق'))
            ->assertOk()
            ->assertJsonPath('data.0.data_flags', [[
                'type' => 'cash_credit', 'difference' => -500, 'message' => 'صندوق برصيد ختامي دائن 500.00: لا ينبغي أن يكون رصيد الصندوق دائنًا، وغالبًا هو قيد خاطئ. راجعه.',
                'message_text' => 'Cash box with a credit closing balance of 500.00: a cash box can’t be in credit, most likely a wrong entry. Review it.',
            ]])
            ->assertJsonPath('data.0.classification_confidence', 60)   // config confidence_caps.abnormal_balance
            ->assertJsonPath('data.0.needs_review', true);
    }

    public function test_debit_closing_on_credit_account_is_flagged(): void
    {
        $this->classifyOne(['210101', 'ورقة دفع', 0, 0, 500, 0, 500, 0], $this->account(2, 'أوراق دفع'))   // not a side pair: no flip
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'abnormal_closing')
            ->assertJsonPath('data.0.data_flags.0.difference', 500)
            ->assertJsonPath('data.0.data_flags.0.message', 'رصيد ختامي مدين 500.00 على حساب طبيعته دائنة');
    }

    public function test_opening_and_closing_are_both_checked(): void
    {
        $this->classifyOne(['110101012', 'مدين- طارق', 0, 2876.9, 0, 0, 0, 2876.9], $this->account(2, 'أوراق قبض'))   // no counterpart: flagged, not flipped
            ->assertOk()
            ->assertJsonPath('data.0.data_flags', [
                ['type' => 'abnormal_opening', 'difference' => -2876.9, 'message' => 'رصيد افتتاحي دائن 2,876.90 على حساب طبيعته مدينة',
                    'message_text' => 'Opening balance credit 2,876.90 on an account whose normal balance is debit'],
                ['type' => 'abnormal_closing', 'difference' => -2876.9, 'message' => 'رصيد ختامي دائن 2,876.90 على حساب طبيعته مدينة',
                    'message_text' => 'Closing balance credit 2,876.90 on an account whose normal balance is debit'],
            ]);
    }

    public function test_retained_earnings_in_debit_are_accumulated_losses_not_flagged(): void
    {
        $this->classifyOne(['3301', 'الأرباح المرحلة', 15500, 0, 0, 0, 15500, 0], $this->account(2, 'أرباح محتجزة'))
            ->assertOk()->assertJsonPath('data.0.data_flags', []);
    }

    public function test_abnormal_amount_at_or_below_materiality_is_not_flagged(): void
    {
        $this->classifyOne(['1101', 'بنك', 0, 0, 0, 1, 0, 1], $this->account(2, 'نقدية لدى البنوك'))
            ->assertOk()->assertJsonPath('data.0.data_flags', []);

        $this->classifyOne(['1101', 'بنك', 0, 0, 0, 1.01, 0, 1.01], $this->account(2, 'نقدية لدى البنوك'))
            ->assertOk()->assertJsonPath('data.0.data_flags.0.type', 'cash_credit');
    }

    public function test_materiality_threshold_is_configurable(): void
    {
        config(['classification.materiality_threshold' => 100]);

        $this->classifyOne(['110101', 'الصندوق', 0, 0, 0, 50, 0, 50], $this->account(2, 'نقدية بالصندوق'))
            ->assertOk()->assertJsonPath('data.0.data_flags', []);
    }

    public function test_contra_account_credit_balance_is_normal(): void
    {
        $this->classifyOne(['150901', 'مجمع إهلاك السيارات', 0, 900, 0, 100, 0, 1000], $this->account(2, 'مجمع إهلاك الأصول الثابتة'))
            ->assertOk()
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.needs_review', false);
    }

    public function test_account_number_prefix_no_longer_affects_the_result(): void
    {
        // Starts with 2 but named and classified as cash: no hard-coded digit rule; one account shows no numbering scheme
        // (prefix_mismatch needs the file's own scheme, see CacheSafetyTest).
        $this->classifyOne(['210101', 'الصندوق', 0, 0, 100, 0, 100, 0], $this->account(2, 'نقدية بالصندوق'))
            ->assertOk()
            ->assertJsonPath('data.0.classification_confidence', 95)
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.needs_review', false);
    }

    public function test_income_statement_expense_is_classified(): void
    {
        $this->classifyOne(['610101', 'إيجار المستودع', 0, 0, 1200, 0, 1200, 0], $this->account(2, 'إيجارات', 92))
            ->assertOk()
            ->assertJsonPath('data.0.financial_statement', 'قائمة الدخل')
            ->assertJsonPath('data.0.main_class', 'المصروفات الإدارية والعمومية')
            ->assertJsonPath('data.0.needs_review', false);
    }

    public function test_account_names_are_returned_exactly_as_uploaded(): void
    {
        $this->classifyOne(['٢١٠١٠١', 'Main Cash Box', 0, 0, 100, 0, 100, 0], $this->account(2, 'نقدية بالصندوق'))
            ->assertOk()
            ->assertJsonPath('data.0.account_number', '٢١٠١٠١')
            ->assertJsonPath('data.0.account_name', 'Main Cash Box');
    }

    public function test_warm_call_and_batches_share_an_identical_cached_prefix_and_tools(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        config(['classification.batch_size' => 2, 'classification.context_rows' => 1]);

        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'نقدية بالصندوق'),
            $this->account(3, 'نقدية لدى البنوك'),
            $this->account(4, 'رأس المال'),
        ]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['110201', 'البنك الأهلي', 0, 0, 100, 0, 100, 0],
            ['310101', 'رأس المال', 0, 0, 0, 200, 0, 200],
        ])])
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.2.sub_class', 'رأس المال');

        $warm = Http::recorded(fn (Request $r) => $this->isWarmCall($r))->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(1, $warm);   // writes the cache once, before the batches
        $this->assertSame([], $this->requestedRows($warm[0]));

        $calls = Http::recorded(fn (Request $r) => ! $this->isStructureCall($r) && ! $this->isWarmCall($r))->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(2, $calls);
        foreach ($calls as $call) {
            $this->assertSame($warm[0]['system'], $call['system']);
            $this->assertSame($warm[0]['tools'], $call['tools']);
        }
        $this->assertStringContainsString('R4 | 310101 | رأس المال', $this->fileBlock($calls[0]));   // full chart in batch 1
        $this->assertSame([2, 3], $this->requestedRows($calls[0]));
        $this->assertSame([4], $this->requestedRows($calls[1]));
        $this->assertStringContainsString("Context rows only, do not classify:\nR3 |", $calls[1]['messages'][0]['content']);
    }

    public function test_ai_provider_error_returns_502(): void
    {
        Sleep::fake();
        Http::fake([$this->aiUrl() => Http::response(['error' => 'overloaded'], 500)]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 100, 0, 100, 0]])])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI provider error, please retry.']);
    }

    public function test_empty_file_returns_422_without_calling_ai(): void
    {
        $this->postJson(self::URL, ['file' => $this->xlsxUpload([])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No account rows found in the file.');

        Http::assertNothingSent();
    }

    public function test_file_with_no_account_rows_returns_422(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->rowOfType(2, 'total')]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['', 'الإجمالي', 0, 0, 100, 100, 0, 0]])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No account rows found in the file.');
    }

    public function test_accepts_csv_upload(): void
    {
        $this->fakeLlm($this->standardStructure(['columns' => [
            'opening_debit' => null, 'opening_credit' => null, 'closing_debit' => null, 'closing_credit' => null,
            'period_debit' => 'C', 'period_credit' => 'D',
        ]]), [$this->account(2, 'نقدية بالصندوق')]);

        $csv = "رقم الحساب,اسم الحساب,مدين الحركة,دائن الحركة\n110101,الصندوق,100,0\n";

        $this->postJson(self::URL, ['file' => $this->csvUpload($csv)])
            ->assertOk()
            ->assertJsonPath('data.0.period_debit', 100)
            ->assertJsonPath('data.0.closing_debit', 100);
    }
}
