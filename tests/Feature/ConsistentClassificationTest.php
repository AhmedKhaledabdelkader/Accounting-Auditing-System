<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** One account (number + name) gets one classification; a missing confidence is said, not shown as a silent 0. */
class ConsistentClassificationTest extends TestCase
{
    use TrialBalanceFiles;

    private const URL = '/api/v1/classify/trial-balance';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->useModel('test-model');
    }

    public function test_same_account_labelled_differently_across_batches_is_unified_on_the_majority_and_flagged(): void
    {
        config(['classification.batch_size' => 1]);   // every row in its own batch
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'مجمع إهلاك الأصول غير الملموسة', 85, 'برامج'),
            $this->account(3, 'مجمع إهلاك الأصول الثابتة', 80, 'مجمع إهلاك'),
            $this->account(4, 'مجمع إهلاك الأصول الثابتة', 90, 'مجمع إهلاك أصول'),
        ]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['119006', 'مجمع اهلاك البرامج', 0, 0, 0, 10, 0, 10],
            ['119006', 'مجمع اهلاك البرامج', 0, 0, 0, 20, 0, 20],
            ['119006', 'مجمع اهلاك البرامج', 0, 0, 0, 30, 0, 30],
        ])])->assertOk()->assertJsonPath('meta.needs_review', 3);

        $message = 'صُنّف هذا الحساب بأكثر من تصنيف («مجمع إهلاك الأصول غير الملموسة» (الصف 2 في الملف) · «مجمع إهلاك الأصول الثابتة» (الصفوف 3، 4 في الملف))، '
            .'وتم توحيده على «مجمع إهلاك الأصول الثابتة»';
        foreach ([0, 1, 2] as $i) {
            $response->assertJsonPath("data.{$i}.sub_class", 'مجمع إهلاك الأصول الثابتة')
                ->assertJsonPath("data.{$i}.main_class", 'الممتلكات و الالات و المعدات')
                ->assertJsonPath("data.{$i}.classification_confidence", 90)          // the winner's (tie on votes → higher confidence)
                ->assertJsonPath("data.{$i}.classification_reason", 'مجمع إهلاك أصول')
                ->assertJsonPath("data.{$i}.data_flags.0.type", 'inconsistent_classification')
                ->assertJsonPath("data.{$i}.data_flags.0.message", $message)
                ->assertJsonPath("data.{$i}.needs_review", true);
        }
    }

    public function test_disagreement_in_one_batch_ties_go_to_the_higher_confidence(): void
    {
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'إيجارات', 70),
            $this->account(3, 'إيجار المقر الإداري', 90),
        ]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['323002', 'ايجار المحل', 0, 0, 100, 0, 100, 0],
            ['323002', 'ايجار المحل', 0, 0, 200, 0, 200, 0],
        ])])->assertOk();

        foreach ([0, 1] as $i) {
            $response->assertJsonPath("data.{$i}.sub_class", 'إيجار المقر الإداري')
                ->assertJsonPath("data.{$i}.classification_confidence", 90)
                ->assertJsonPath("data.{$i}.data_flags.0.type", 'inconsistent_classification')
                ->assertJsonPath("data.{$i}.needs_review", true);
        }
    }

    public function test_labels_that_follow_the_balance_side_are_kept(): void
    {
        config(['classification.batch_size' => 1]);
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'موردون'),
            $this->account(3, 'موردون'),
            $this->account(4, 'دفعات مقدمة للموردين'),
        ]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['221134', 'مجموعة السنبلة', 0, 0, 0, 10, 0, 10],     // credit: supplier
            ['221134', 'مجموعة السنبلة', 0, 0, 0, 50, 0, 50],     // credit: supplier
            ['221134', 'مجموعة السنبلة', 0, 0, 80, 0, 80, 0],     // debit: advance payment
        ])])->assertOk()->assertJsonPath('meta.needs_review', 0);

        $this->assertSame(['موردون', 'موردون', 'دفعات مقدمة للموردين'], array_column($response->json('data'), 'sub_class'));
        $this->assertNotContains('inconsistent_classification', array_column(array_merge(...array_column($response->json('data'), 'data_flags')), 'type'));
    }

    public function test_opposite_side_labels_without_balances_to_explain_them_are_unified(): void
    {
        config(['classification.batch_size' => 1]);
        $this->fakeLlm($this->standardStructure(), [
            $this->account(2, 'موردون', 90),
            $this->account(3, 'دفعات مقدمة للموردين', 85),
            $this->account(4, 'موردون', 80),
        ]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            // Moved but closed at zero: no side (all zero would leave the rows out).
            ['221340', 'شركة الوفاق', 0, 0, 40, 40, 0, 0],
            ['221340', 'شركة الوفاق', 0, 0, 40, 40, 0, 0],
            ['221340', 'شركة الوفاق', 0, 0, 40, 40, 0, 0],
        ])])->assertOk()->assertJsonPath('meta.needs_review', 3);

        $this->assertSame(['موردون', 'موردون', 'موردون'], array_column($response->json('data'), 'sub_class'));
        $response->assertJsonPath('data.1.classification_confidence', 90);
    }

    public function test_same_number_with_different_names_is_not_unified(): void
    {
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'عُهد العاملين'), $this->account(3, 'سلف العاملين')]);

        $response = $this->postJson(self::URL, ['file' => $this->trialBalance([
            ['1401015', 'خالد البركاني', 0, 0, 10, 0, 10, 0],
            ['1401015', 'اديب عبدالواسع', 0, 0, 10, 0, 10, 0],
        ])])->assertOk();

        $this->assertSame(['عُهد العاملين', 'سلف العاملين'], array_column($response->json('data'), 'sub_class'));
    }

    public function test_confidence_is_required_and_a_missing_one_is_explained(): void
    {
        $this->fakeLlm($this->standardStructure(), [
            ['row' => 2, 'row_type' => 'account', 'sub_class' => 'عُهد العاملين', 'reason' => 'عهدة موظف'],   // no confidence
        ]);

        $this->postJson(self::URL, ['file' => $this->trialBalance([['1401019', 'محمد الزمر كاشير', 0, 0, 10, 0, 10, 0]])])
            ->assertOk()
            ->assertJsonPath('data.0.sub_class', 'عُهد العاملين')
            ->assertJsonPath('data.0.classification_confidence', 0)
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.0.note', 'لم يعطِ النظام درجة ثقة لهذا التصنيف')
            ->assertJsonMissingPath('data.0.confidence_missing');   // internal only

        Http::assertSent(fn (Request $r) => ! $this->isStructureCall($r) && ! $this->isWarmCall($r)
            && in_array('confidence', $this->toolSchema($r)['properties']['rows']['items']['required'], true));
    }
}
