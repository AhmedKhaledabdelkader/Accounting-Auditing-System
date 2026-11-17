<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Sections 2 and 3: remarks column, other sheets, adjustments matching. */
class AdjustmentsAndNotesTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Cash with a +100 reconciliation difference, plus an adjustments sheet. */
    private function workbookWithAdjustments(array $adjustmentRows): \Illuminate\Http\UploadedFile
    {
        return $this->workbook([
            'TB'  => [self::FULL_HEADER, ['1101', 'الصندوق', 0, 0, 500, 100, 500, 0]],
            'ADJ' => [['الحساب', 'رقم', 'مدين', 'دائن'], ...$adjustmentRows],
        ]);
    }

    private function structureWithAdjustments(array $columns): array
    {
        return $this->standardStructure(['other_sheets' => [[
            'sheet' => 2, 'type' => 'adjustments', 'description' => 'قيود', 'first_data_row' => 2, 'columns' => $columns,
        ]]]);
    }

    public function test_adjustment_lines_with_account_numbers_are_matched_in_php(): void
    {
        $file = $this->workbookWithAdjustments([['الصندوق', '1101', 100, 0], ['حساب برا الميزان', '9999', 0, 100]]);
        $this->fakeLlm($this->structureWithAdjustments(['account_name' => 'A', 'account_number' => 'B', 'debit' => 'C', 'credit' => 'D']),
            [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.message', 'هذا الفرق مطابق لقيد تسوية بمبلغ 100.00 (ورقة ADJ)')
            ->assertJsonPath('data.0.data_flags.0.difference', 100)
            ->assertJsonPath('data.0.data_flags.0.matched_adjustment', ['sheet' => 'ADJ', 'amount' => 100])
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('meta.adjustments.0.account_row', 2)
            ->assertJsonPath('meta.adjustments.1.account_number', '9999')
            ->assertJsonPath('meta.adjustments.1.account_row', null)
            ->assertJsonPath('meta.warnings', []);

        // Numbered lines never go to Claude.
        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r) && ! str_contains($this->fileBlock($r), 'Adjustment lines'));
    }

    public function test_unmatched_difference_keeps_message_and_warns_about_adjustments(): void
    {
        $file = $this->workbookWithAdjustments([['الصندوق', '1101', 40, 0]]);
        $this->fakeLlm($this->structureWithAdjustments(['account_name' => 'A', 'account_number' => 'B', 'debit' => 'C', 'credit' => 'D']),
            [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.message', 'الرصيد الختامي أعلى من المحسوب بمقدار 100.00')
            ->assertJsonPath('meta.warnings', ['يوجد في الملف ورقة قيود تسوية، وقد تكون الأرصدة الختامية متضمنة لها. راجع ذلك.']);
    }

    public function test_no_adjustments_sheet_means_no_adjustment_warning(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 500, 100, 500, 0]])])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'reconciliation')
            ->assertJsonPath('meta.warnings', [])
            ->assertJsonPath('meta.adjustments', []);
    }

    public function test_missing_adjustment_matches_trigger_a_retry(): void
    {
        $file = $this->workbookWithAdjustments([['الصندوق', null, 100, 0]]);
        $calls = 0;
        // First answer has no matches, the retry has them.
        Http::fake([$this->aiUrl() => function (Request $r) use (&$calls) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', $this->structureWithAdjustments(['account_name' => 'A', 'debit' => 'C', 'credit' => 'D'])));
            }
            if (! $this->isMatchCall($r)) {
                return Http::response($this->toolResponse('submit_rows', ['rows' => [$this->account(2, 'نقدية بالصندوق')]]));
            }
            $calls++;
            return Http::response($this->toolResponse('submit_rows', [
                'rows'               => [],
                'adjustment_matches' => $calls === 1 ? [] : [['adjustment_row' => 1, 'account_row' => 2]],
            ]));
        }]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.message', 'هذا الفرق مطابق لقيد تسوية بمبلغ 100.00 (ورقة ADJ)')
            ->assertJsonPath('meta.warnings', []);

        $this->assertSame(2, $calls);   // the matching call, then its retry
    }

    /** Name-only adjustment line: needs the matching call. $matching answers it: Closure(int $call): response. */
    private function fakeWithMatching(\Closure $matching): void
    {
        $calls = 0;
        Http::fake([$this->aiUrl() => function (Request $r) use ($matching, &$calls) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', $this->structureWithAdjustments(['account_name' => 'A', 'debit' => 'C', 'credit' => 'D'])));
            }
            if ($this->isMatchCall($r)) {
                return $matching(++$calls);
            }
            return Http::response($this->toolResponse('submit_rows', ['rows' => [$this->account(2, 'نقدية بالصندوق')]]));
        }]);
    }

    private const NOT_MATCHED = 'تعذّرت مطابقة 1 سطر من قيود التسوية مع حسابات الميزان، لذا فالفروق التي قد تنتج عنها غير مفسَّرة. حاول مرة أخرى.';

    public function test_matching_call_failing_every_attempt_warns_and_keeps_the_rows(): void
    {
        $this->fakeWithMatching(fn () => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529));

        $this->postJson(self::URL, ['file' => $this->workbookWithAdjustments([['الصندوق', null, 100, 0]])])
            ->assertOk()
            ->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق')
            ->assertJsonPath('meta.adjustments.0.account_row', null)
            ->assertJsonPath('meta.warnings', ['يوجد في الملف ورقة قيود تسوية، وقد تكون الأرصدة الختامية متضمنة لها. راجع ذلك.', self::NOT_MATCHED]);

        $this->assertCount(3, Http::recorded(fn (Request $r) => $this->isMatchCall($r)));   // 1 attempt + 2 retries
    }

    public function test_matches_still_missing_after_the_retry_are_not_silent(): void
    {
        $this->fakeWithMatching(fn () => Http::response($this->toolResponse('submit_rows', ['rows' => [], 'adjustment_matches' => []])));

        $this->postJson(self::URL, ['file' => $this->workbookWithAdjustments([['الصندوق', null, 100, 0]])])
            ->assertOk()
            ->assertJsonPath('meta.warnings.1', self::NOT_MATCHED);

        $this->assertCount(2, Http::recorded(fn (Request $r) => $this->isMatchCall($r)));   // the call and its missing-only retry
    }

    public function test_matching_call_fatal_error_aborts_the_file(): void
    {
        $this->fakeWithMatching(fn () => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'Your credit balance is too low to access the Anthropic API.']], 400));

        $this->postJson(self::URL, ['file' => $this->workbookWithAdjustments([['الصندوق', null, 100, 0]])])
            ->assertStatus(502);

        $this->assertCount(1, Http::recorded(fn (Request $r) => $this->isMatchCall($r)));   // fatal: not retried
    }

    public function test_lines_matched_by_account_number_send_no_matching_call(): void
    {
        $this->fakeLlm($this->structureWithAdjustments(['account_name' => 'A', 'account_number' => 'B', 'debit' => 'C', 'credit' => 'D']),
            [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->workbookWithAdjustments([['الصندوق', '1101', 100, 0]])])->assertOk();

        Http::assertNotSent(fn (Request $r) => $this->isMatchCall($r));
        Http::assertNotSent(fn (Request $r) => $this->isWarmCall($r));   // one batch, nothing else: no cache to share
    }

    public function test_match_to_unknown_row_is_rejected(): void
    {
        $file = $this->workbookWithAdjustments([['الصندوق', null, 100, 0]]);
        $this->fakeLlm($this->structureWithAdjustments(['account_name' => 'A', 'debit' => 'C', 'credit' => 'D']),
            [$this->account(2, 'نقدية بالصندوق')],
            [['adjustment_row' => 1, 'account_row' => 55]]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.adjustments.0.account_row', null)
            ->assertJsonPath('data.0.data_flags.0.message', 'الرصيد الختامي أعلى من المحسوب بمقدار 100.00');
    }

    public function test_source_note_is_returned_and_forces_review(): void
    {
        $file = $this->xlsxUpload([
            [...self::FULL_HEADER, 'ملاحظات'],
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0, 'غير مطابق مع التحليلي'],
        ]);
        $this->fakeLlm($this->standardStructure(['columns' => ['notes' => 'I']]), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.source_note', 'غير مطابق مع التحليلي')
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.0.note', 'ملاحظة في الملف: غير مطابق مع التحليلي');
    }

    public function test_headerless_remarks_column_is_kept_even_if_the_ai_misses_it(): void
    {
        $file = $this->xlsxUpload([
            self::FULL_HEADER,
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['1102', 'البنك', 0, 0, 100, 0, 100, 0, 'راجع كشف البنك'],
        ]);
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية لدى البنوك')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.source_note', null)
            ->assertJsonPath('data.1.source_note', 'راجع كشف البنك')
            ->assertJsonPath('data.1.needs_review', true);
    }

    public function test_note_on_a_skipped_row_is_not_dropped(): void
    {
        $file = $this->xlsxUpload([
            [...self::FULL_HEADER, 'ملاحظات'],
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],
            ['', 'الإجمالي', 0, 0, 100, 0, 100, 0, 'الإجمالي من النظام'],
        ]);
        $this->fakeLlm($this->standardStructure(['columns' => ['notes' => 'I']]), [
            $this->account(2, 'نقدية بالصندوق'), $this->rowOfType(3, 'total'),
        ]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.warnings', ['ملاحظة في الملف على الصف 3 (هذا الصف ليس حسابًا): الإجمالي من النظام']);
    }

    public function test_other_sheets_are_reported_with_names_and_types(): void
    {
        $file = $this->workbook(['TB' => [self::FULL_HEADER, ['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]], 'Ledger' => [['x']]]);
        $this->fakeLlm($this->standardStructure(['other_sheets' => [
            ['sheet' => 2, 'type' => 'subsidiary_ledger', 'description' => 'دفتر مساعد'],
        ]]), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.other_sheets', [['sheet' => 2, 'name' => 'Ledger', 'type' => 'subsidiary_ledger', 'description' => 'دفتر مساعد']]);
    }
}
