<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** The file's own classification columns, duplicate account numbers, and row-keyed matching. */
class ExistingClassificationAndDuplicatesTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    /** رقم | الاسم | بنود الميزانية | إيضاحات | مدين | دائن (closing only) */
    private const HEADER = ['رقم الحساب', 'اسم الحساب', 'بنود الميزانية', 'إيضاحات الميزانية', 'مدين', 'دائن'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function structure(): array
    {
        return [
            'sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2,
            'columns' => [
                'account_number' => 'A', 'account_name' => 'B',
                'existing_classification' => 'C', 'existing_note_classification' => 'D',
                'closing_debit' => 'E', 'closing_credit' => 'F',
            ],
        ];
    }

    /** Claude's per-value mapping of column C: X1 = PP&E, X2 = G&A expenses (several mains). */
    private function existingMap(): array
    {
        return [
            ['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']],
            ['existing_id' => 2, 'main_classes' => ['إيجارات', 'مصروفات متنوعة', 'اجور و رواتب و مافي حكمها']],
        ];
    }

    private function fakeWithMap(array $labels, array $map = null): void
    {
        $map ??= $this->existingMap();
        Http::fake([$this->aiUrl() => function (Request $r) use ($labels, $map) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', $this->structure()));
            }
            $requested = $this->requestedRows($r);
            return Http::response($this->toolResponse('submit_rows', [
                'rows'                   => array_values(array_filter($labels, fn ($l) => in_array($l['row'], $requested, true))),
                'existing_class_matches' => $map,
            ]));
        }]);
    }

    public function test_existing_classification_is_returned_and_agreement_is_not_flagged(): void
    {
        $file = $this->xlsxUpload([self::HEADER, ['112001', 'سيارات', 'ممتلكات والالات ومعدات', 'سيارات ووسائل نقل', 45000, 0]]);
        $this->fakeWithMap([$this->account(2, 'سيارات ووسائل نقل')]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.existing_classification', 'ممتلكات والالات ومعدات')
            ->assertJsonPath('data.0.existing_note_classification', 'سيارات ووسائل نقل')
            ->assertJsonPath('data.0.existing_main_classes', ['الممتلكات و الالات و المعدات'])
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.0.needs_review', false);
    }

    public function test_main_class_outside_the_file_classification_is_a_mismatch(): void
    {
        $file = $this->xlsxUpload([self::HEADER, ['322001', 'مصروف إهلاك', 'المصروفات العمومية والإدارية', 'استهلاك', 500, 0]]);
        $this->fakeWithMap([$this->account(2, 'مخزون بضاعة')]);   // balance-sheet class for a G&A expense

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'classification_mismatch')
            ->assertJsonPath('data.0.data_flags.0.message', 'تصنيف الملف «المصروفات العمومية والإدارية» غير متوافق مع التصنيف المقترح «المخزون»')
            ->assertJsonPath('data.0.classification_confidence', 95)
            ->assertJsonPath('data.0.needs_review', true);
    }

    public function test_broad_file_line_covers_several_main_classes(): void
    {
        $file = $this->xlsxUpload([self::HEADER, ['33001', 'إيجار', 'المصروفات العمومية والإدارية', '', 100, 0]]);
        $this->fakeWithMap([$this->account(2, 'إيجارات')], [
            ['existing_id' => 1, 'main_classes' => ['إيجارات', 'مصروفات متنوعة', 'اجور و رواتب و مافي حكمها']],   // only value in this file
        ]);

        $this->postJson(self::URL, ['file' => $file])->assertOk()->assertJsonPath('data.0.data_flags', []);
    }

    public function test_existing_values_are_sent_once_in_the_cached_prefix_and_mapped_in_the_matching_call(): void
    {
        $file = $this->xlsxUpload([
            self::HEADER,
            ['112001', 'سيارات', 'ممتلكات والالات ومعدات', '', 1, 0],
            ['33001', 'إيجار', 'المصروفات العمومية والإدارية', '', 1, 0],
            ['112002', 'معدات', 'ممتلكات والالات ومعدات', '', 1, 0],
        ]);
        $this->fakeWithMap([$this->account(2, 'آلات ومعدات'), $this->account(3, 'إيجارات'), $this->account(4, 'آلات ومعدات')]);

        $this->postJson(self::URL, ['file' => $file])->assertOk();

        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r)
            && str_contains($this->fileBlock($r), "Distinct existing classification values (id | value):\nX1 | ممتلكات والالات ومعدات\nX2 | المصروفات العمومية والإدارية")
            && str_contains($this->fileBlock($r), 'R2 | 112001 | سيارات | ممتلكات والالات ومعدات')
            && str_contains($this->systemText($r, 0), 'treat it as strong evidence')
            && str_contains($this->userText($r), 'No rows to classify. Return only existing_class_matches for existing classification values X1, X2.')
            && $this->toolSchema($r)['properties']['existing_class_matches']['items']['properties']['main_classes']['items']['enum'] !== []);
    }

    public function test_missing_existing_map_is_retried(): void
    {
        $calls = 0;
        Http::fake([$this->aiUrl() => function (Request $r) use (&$calls) {
            if ($this->isStructureCall($r)) {
                return Http::response($this->toolResponse('submit_structure', $this->structure()));
            }
            if (! $this->isMatchCall($r)) {
                return Http::response($this->toolResponse('submit_rows', ['rows' => [$this->account(2, 'مخزون بضاعة')]]));
            }
            $calls++;
            return Http::response($this->toolResponse('submit_rows', [
                'rows'                   => [],
                'existing_class_matches' => $calls === 1 ? [] : [['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']]],
            ]));
        }]);

        $this->postJson(self::URL, ['file' => $this->xlsxUpload([self::HEADER, ['1', 'سيارات', 'ممتلكات والالات ومعدات', '', 1, 0]])])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags.0.type', 'classification_mismatch');

        $this->assertSame(2, $calls);
    }

    public function test_existing_values_still_unmapped_after_the_retry_warn(): void
    {
        $this->fakeWithMap([$this->account(2, 'مخزون بضاعة')], []);

        $this->postJson(self::URL, ['file' => $this->xlsxUpload([self::HEADER, ['1', 'سيارات', 'ممتلكات والالات ومعدات', '', 1, 0]])])
            ->assertOk()
            ->assertJsonPath('data.0.data_flags', [])   // no comparison was possible
            ->assertJsonPath('meta.warnings', ['تعذّرت مقارنة تصنيف الملف بالتصنيف المقترح لـ 1 قيمة، لذا لا توجد تنبيهات عدم توافق لهذه الحسابات. حاول مرة أخرى.']);
    }

    public function test_matching_call_is_first_in_line_after_the_warm_call(): void
    {
        $this->useProvider('anthropic');   // Anthropic's wire format
        config(['classification.batch_size' => 1]);
        $this->fakeWithMap([$this->account(2, 'آلات ومعدات'), $this->account(3, 'إيجارات')]);

        $this->postJson(self::URL, ['file' => $this->xlsxUpload([
            self::HEADER,
            ['112001', 'سيارات', 'ممتلكات والالات ومعدات', '', 1, 0],
            ['33001', 'إيجار', 'المصروفات العمومية والإدارية', '', 1, 0],
        ])])->assertOk()->assertJsonPath('meta.warnings', []);

        $order = Http::recorded()->map(fn ($pair) => match (true) {
            $this->isStructureCall($pair[0]) => 'structure',
            $this->isWarmCall($pair[0])      => 'warm',
            $this->isMatchCall($pair[0])     => 'matches',
            default                          => 'R'.implode(',', $this->requestedRows($pair[0])),
        })->all();
        $this->assertSame(['structure', 'warm', 'matches', 'R2', 'R3'], $order);
    }

    public function test_file_without_a_classification_column_sends_no_matching_call(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['1101', 'الصندوق', 0, 0, 1, 0, 1, 0]])])->assertOk();

        Http::assertNotSent(fn (Request $r) => $this->isMatchCall($r));
    }

    public function test_same_number_with_different_file_classifications_is_a_conflict(): void
    {
        $file = $this->xlsxUpload([
            self::HEADER,
            ['119006', 'مجمع اهلاك البرامج', 'ممتلكات والالات ومعدات', '', 0, 10],
            ['119006', 'مجمع اهلاك البرامج', 'أصول غير ملموسة', '', 0, 10],
        ]);
        $this->fakeWithMap([$this->account(2, 'مجمع إهلاك الأصول الثابتة'), $this->account(3, 'مجمع إهلاك الأصول الثابتة')], [
            ['existing_id' => 1, 'main_classes' => ['الممتلكات و الالات و المعدات']],
            ['existing_id' => 2, 'main_classes' => ['اصول غير ملموسة']],
        ]);

        $response = $this->postJson(self::URL, ['file' => $file])->assertOk();

        foreach ([0, 1] as $i) {
            $types = array_column($response->json("data.{$i}.data_flags"), 'type');
            $this->assertContains('existing_classification_conflict', $types);
            $this->assertTrue($response->json("data.{$i}.needs_review"));
        }
        $conflict = collect($response->json('data.0.data_flags'))->firstWhere('type', 'existing_classification_conflict');
        $this->assertStringContainsString('«ممتلكات والالات ومعدات» (الصف 2 في الملف)، «أصول غير ملموسة» (الصف 3 في الملف)', $conflict['message']);
        // R3's file line (intangibles) also disagrees with the chosen PP&E class.
        $this->assertContains('classification_mismatch', array_column($response->json('data.1.data_flags'), 'type'));
        $this->assertNotContains('duplicate_account_number', array_column($response->json('data.0.data_flags'), 'type'));   // same name
    }

    /* ---------- duplicate account numbers ---------- */

    public function test_same_number_different_names_is_a_low_priority_row_flag_and_one_file_warning(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'عُهد العاملين'), $this->account(3, 'عُهد العاملين')]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['1401015', 'خالد البركاني', 0, 0, 10, 0, 10, 0],
            ['1401015', 'اديب عبدالواسع', 0, 0, 10, 0, 10, 0],
        ])])->assertOk();

        foreach ([0, 1] as $i) {
            $response->assertJsonPath("data.{$i}.data_flags.0.type", 'duplicate_account_number')
                ->assertJsonPath("data.{$i}.data_flags.0.severity", 'info')
                ->assertJsonPath("data.{$i}.data_flags.0.message", 'رقم الحساب هذا متكرر بأسماء مختلفة: «خالد البركاني» (الصف 2 في الملف)، «اديب عبدالواسع» (الصف 3 في الملف)')
                ->assertJsonPath("data.{$i}.note", 'رقم الحساب هذا متكرر بأسماء مختلفة: «خالد البركاني» (الصف 2 في الملف)، «اديب عبدالواسع» (الصف 3 في الملف)')
                ->assertJsonPath("data.{$i}.needs_review", false);   // not on its own: normal with sub-accounts or branches
        }
        $response->assertJsonPath('meta.needs_review', 0);
        $this->assertContains('يوجد 1 رقم حساب متكرر بأسماء مختلفة (قد يكون طبيعيًا إن كان الملف يحتوي على فروع أو حسابات فرعية بالرقم نفسه)، '
            .'ولم تُوضع هذه الصفوف للمراجعة بسبب ذلك وحده: 1401015 (2 أسماء)', $response->json('meta.warnings'));
    }

    public function test_same_number_same_name_is_one_info_message_and_no_row_flag(): void
    {
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'إهلاك الأصول الثابتة'), $this->account(3, 'إهلاك الأصول الثابتة'), $this->account(4, 'إهلاك الأصول الثابتة'),
        ]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['322001', 'مصروف إهلاك  الاصول الثابتة', 0, 0, 10, 0, 10, 0],
            ['322001', 'مصروف إهلاك الاصول الثابتة', 0, 0, 20, 0, 20, 0],   // whitespace differences don't count
            ['322001', 'مصروف إهلاك الاصول الثابتة', 0, 0, 30, 0, 30, 0],
        ])])->assertOk()->assertJsonPath('meta.needs_review', 0);

        $this->assertContains('يوجد 1 رقم حساب متكرر بالاسم نفسه في أكثر من صف، وغالبًا هو ميزان مجمّع لأكثر من فرع: 322001 ×3', $response->json('meta.info'));
    }

    public function test_every_row_keeps_its_own_values_when_numbers_repeat(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية بالصندوق')]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['1101', 'الصندوق', 0, 0, 10, 0, 10, 0],
            ['1101', 'الصندوق', 0, 0, 20, 0, 25, 0],   // reconciliation +5 only on this row
        ])])
            ->assertOk()
            ->assertJsonPath('data.0.row', 2)
            ->assertJsonPath('data.1.row', 3)
            ->assertJsonPath('data.0.data_flags', [])
            ->assertJsonPath('data.1.data_flags.0.difference', 5);
    }

    public function test_numbered_adjustment_on_a_repeated_number_goes_to_claude_and_matches_one_row(): void
    {
        $file = $this->workbook([
            'TB'  => [self::FULL_HEADER, ['1101', 'الصندوق فرع 1', 0, 0, 10, 0, 10, 0], ['1101', 'الصندوق فرع 2', 0, 0, 10, 0, 15, 0]],
            'ADJ' => [['الحساب', 'رقم', 'مدين', 'دائن'], ['الصندوق فرع 2', '1101', 5, 0]],
        ]);
        $this->fakeLlm($this->standardStructure(['other_sheets' => [[
            'sheet' => 2, 'type' => 'adjustments', 'description' => '', 'first_data_row' => 2,
            'columns' => ['account_name' => 'A', 'account_number' => 'B', 'debit' => 'C', 'credit' => 'D'],
        ]]]), [$this->account(2, 'نقدية بالصندوق'), $this->account(3, 'نقدية بالصندوق')], [['adjustment_row' => 1, 'account_row' => 3]]);

        $this->postJson(self::URL, ['file' => $file])
            ->assertOk()
            ->assertJsonPath('meta.adjustments.0.account_row', 3)
            ->assertJsonPath('data.1.data_flags.0.message', 'هذا الفرق مطابق لقيد تسوية بمبلغ 5.00 (ورقة ADJ)');

        // Ambiguous number → sent to Claude with the rows.
        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r) && str_contains($this->fileBlock($r), 'A1 | ADJ R2 | الصندوق فرع 2'));
    }
}
