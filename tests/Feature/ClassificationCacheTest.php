<?php

namespace Tests\Feature;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationRun;
use App\Services\ClassificationCache;
use App\Support\ClassificationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** The classification cache: rows seen before skip the AI; manual corrections win; a version bump invalidates. */
class ClassificationCacheTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    /** @var list<list<int>> rows the AI was asked to classify, per call */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['classification.rules_enabled' => true]);   // the accountant's rules apply here, as in a run
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['classification.cache.enabled' => true]);
    }

    /** Two generic «أخرى» rows under different parents (no code, carrying their child's balance), and a coded account. */
    private function file()
    {
        return $this->trialBalance([
            ['', 'المصروفات البيعية', 0, 0, 10, 0, 10, 0],      // R2 parent
            ['', 'أخرى', 0, 0, 10, 0, 10, 0],                    // R3 → expenses
            ['1101', 'الصندوق', 0, 0, 100, 0, 100, 0],           // R4
            ['', 'الإيرادات الأخرى', 0, 0, 0, 10, 0, 10],        // R5 parent
            ['', 'أخرى', 0, 0, 0, 10, 0, 10],                    // R6 → revenue
        ]);
    }

    private function fakeAi(string $cash = 'نقدية بالصندوق', int $confidence = 95): void
    {
        $labels = [
            $this->rowOfType(2, 'parent'),
            $this->account(3, 'مصروفات متنوعة', $confidence),
            $this->account(4, $cash, $confidence),
            $this->rowOfType(5, 'parent'),
            $this->account(6, 'إيرادات أخرى', $confidence),
        ];
        $this->fakeLlm($this->standardStructure(), function ($call, array $requested) use ($labels) {
            if ($requested) {
                $this->sent[] = $requested;
            }
            return $labels;
        });
    }

    private function upload(): TestResponse
    {
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->file()])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done');
    }

    /** Rows sent to the AI since the last call. */
    private function sentRows(): array
    {
        $rows = array_merge(...$this->sent ?: [[]]);
        $this->sent = [];
        sort($rows);
        return $rows;
    }

    public function test_same_file_twice_sends_no_known_rows_to_the_ai(): void
    {
        $this->fakeAi();
        $first = $this->upload()->assertJsonPath('cache.rows_from_cache', 0)->assertJsonPath('cache.rows_sent_to_llm', 5);
        $this->assertSame([2, 3, 4, 5, 6], $this->sentRows());
        $this->assertSame(5, ClassificationCacheEntry::count());

        $second = $this->upload()->assertJsonPath('cache.rows_from_cache', 5)->assertJsonPath('cache.rows_sent_to_llm', 0);
        $this->assertSame([], $this->sentRows());

        // Same classes as the AI gave; the two «أخرى» rows kept apart by their parent.
        $classes = fn (TestResponse $r) => array_column($r->json('result.data'), 'sub_class', 'row');
        $this->assertSame([3 => 'مصروفات متنوعة', 4 => 'نقدية بالصندوق', 6 => 'إيرادات أخرى'], $classes($second));
        $this->assertSame($classes($first), $classes($second));
        // The AI's own reason is kept, marked as coming from the cache.
        $this->assertSame('من ذاكرة التصنيف (إجابة سابقة للنظام): الاسم واضح', $second->json('result.data.1.classification_reason'));

        $run = ClassificationRun::find($second->json('id'));
        $this->assertSame([5, 0], [$run->rows_from_cache, $run->rows_sent_to_llm]);
        $this->assertSame([1], ClassificationCacheEntry::pluck('hit_count')->unique()->values()->all());
    }

    public function test_a_different_file_reuses_the_answers_for_the_same_accounts(): void
    {
        // The AI answers by account code, whatever row or layout the account is on; it records what it is asked.
        $byCode = ['1101' => 'نقدية بالصندوق', '1102' => 'نقدية لدى البنوك', '2101' => 'موردون', '4101' => 'مبيعات البضائع', '5101' => 'إيجارات'];
        $layout = $this->standardStructure();
        $asked  = [];   // account codes the AI was asked about
        $codeOf = [];   // row => code, of the file being classified
        $this->fakeLlm(function () use (&$layout) {
            return $layout;
        }, function ($call, array $requested) use ($byCode, &$asked, &$codeOf) {
            array_push($asked, ...array_map(fn ($n) => $codeOf[$n], $requested));

            return array_map(fn ($n) => $this->account($n, $byCode[$codeOf[$n]], 95), $requested);
        });
        $classify = function (array $sheet, array $codes) use (&$codeOf) {
            $codeOf = $codes;
            $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->xlsxUpload($sheet)])->assertStatus(202)->json('id');

            return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done');
        };

        // File 1 (client A, January): four accounts.
        $first = $classify([self::FULL_HEADER,
            ['1101', 'الصندوق', 0, 0, 500, 0, 500, 0],
            ['1102', 'بنك الأهلي', 0, 0, 900, 0, 900, 0],
            ['2101', 'مورد الخامات', 0, 0, 0, 300, 0, 300],
            ['4101', 'مبيعات محلية', 0, 0, 0, 1100, 0, 1100],
        ], [2 => '1101', 3 => '1102', 4 => '2101', 5 => '4101']);
        sort($asked);
        $this->assertSame(['1101', '1102', '2101', '4101'], $asked);
        $this->assertSame(4, ClassificationCacheEntry::count());

        // File 2 (February): another layout (name first, two title rows), other order and balances, spelling variants
        // (أ/ا, ى/ي, tatweel), Arabic digits, and one new account.
        $layout = $this->standardStructure(['header_rows' => [3], 'first_data_row' => 4, 'columns' => [
            'account_name' => 'A', 'account_number' => 'B', 'opening_debit' => 'C', 'opening_credit' => 'D',
            'period_debit' => 'E', 'period_credit' => 'F', 'closing_debit' => 'G', 'closing_credit' => 'H',
        ]]);
        $asked = [];
        $second = $classify([
            ['شركة العميل - ميزان فبراير'], [''],
            ['اسم الحساب', 'رقم الحساب', 'مدين اول المدة', 'دائن اول المدة', 'مدين الحركة', 'دائن الحركة', 'مدين اخر المدة', 'دائن اخر المدة'],
            ['إيجار المعرض', '5101', 0, 0, 200, 0, 200, 0],
            ['مبيعات محليه', '٤١٠١', 0, 1100, 0, 700, 0, 1800],
            ['بنك الاهلى', '1102', 900, 0, 50, 0, 950, 0],
            ['الصنـدوق', '1101', 500, 0, 0, 100, 400, 0],
            ['مورد الخامات', '2101', 0, 300, 300, 0, 0, 0],
        ], [4 => '5101', 5 => '4101', 6 => '1102', 7 => '1101', 8 => '2101']);

        $this->assertSame(['5101'], $asked);   // only the new account went to the AI
        $second->assertJsonPath('cache.rows_from_cache', 4)->assertJsonPath('cache.rows_sent_to_llm', 1);
        $classes = fn (TestResponse $r) => collect($r->json('result.data'))->mapWithKeys(fn ($row) => [\App\Services\AmountParser::asciiDigits($row['account_number']) => $row['sub_class']])->sortKeys()->all();
        $this->assertSame(['1101' => 'نقدية بالصندوق', '1102' => 'نقدية لدى البنوك', '2101' => 'موردون', '4101' => 'مبيعات البضائع', '5101' => 'إيجارات'], $classes($second));
        $this->assertSame(array_diff_key($classes($second), ['5101' => 0]), $classes($first));
        // The supplier is the «مورد» rule's; the rest come from the cache.
        $this->assertSame('rule', collect($second->json('result.data'))->firstWhere('account_number', '2101')['classification_source']);
        foreach (collect($second->json('result.data'))->whereNotIn('account_number', ['5101', '2101']) as $row) {
            $this->assertStringStartsWith(\App\Services\ClassificationCache::CACHED_PREFIX, $row['classification_reason']);
        }
        // The new account is remembered too: a third file asks the AI nothing.
        $this->assertSame(5, ClassificationCacheEntry::count());
        $asked  = [];
        $layout = $this->standardStructure();
        $classify([self::FULL_HEADER, ['5101', 'ايجار المعرض', 0, 0, 10, 0, 10, 0], ['1101', 'الصندوق', 0, 0, 10, 0, 10, 0]], [2 => '5101', 3 => '1101']);
        $this->assertSame([], $asked);
    }

    public function test_saved_corrections_come_back_in_later_files_and_unsaved_ones_change_nothing(): void
    {
        $this->fakeAi();
        $first = $this->upload();
        $this->sentRows();
        $classes = fn (TestResponse $r) => array_column($r->json('result.data'), 'sub_class', 'row');

        // Not saved (the page only): nothing reaches the run or the cache.
        $this->assertSame([3 => 'مصروفات متنوعة', 4 => 'نقدية بالصندوق', 6 => 'إيرادات أخرى'], $classes($this->getJson("/api/v1/classify/jobs/{$first->json('id')}")));
        $this->assertSame(0, ClassificationCacheEntry::where('source', 'manual_correction')->count());

        // «حفظ التعديلات»: both rows at once.
        $saved = $this->postJson("/api/v1/classify/jobs/{$first->json('id')}/corrections", ['corrections' => [
            ['row' => 4, 'sub_class' => 'نقدية لدى البنوك'], ['row' => 3, 'sub_class' => 'إيجارات'],
        ]])->assertOk()->assertJsonPath('corrected', 2)->assertJsonPath('remembered', 2)->assertJsonPath('not_remembered', []);
        $this->assertSame([4 => 'نقدية لدى البنوك', 3 => 'إيجارات'], array_column($saved->json('rows'), 'sub_class', 'row'));
        $this->assertSame(['النقد ومافي حكمه', 100, 'تصحيح يدوي'], [$saved->json('rows.0.main_class'), $saved->json('rows.0.classification_confidence'), $saved->json('rows.0.classification_reason')]);
        // The run keeps them (reopening it shows them).
        $this->assertSame([3 => 'إيجارات', 4 => 'نقدية لدى البنوك', 6 => 'إيرادات أخرى'], $classes($this->getJson("/api/v1/classify/jobs/{$first->json('id')}")));

        // The same accounts in a later file: the saved classes, without asking the AI.
        $second = $this->upload();
        $this->assertSame([], $this->sentRows());
        $this->assertSame([3 => 'إيجارات', 4 => 'نقدية لدى البنوك', 6 => 'إيرادات أخرى'], $classes($second));
        $this->assertSame(ClassificationCache::MANUAL_REASON, collect($second->json('result.data'))->firstWhere('row', 4)['classification_reason']);
    }

    public function test_saving_corrections_is_all_or_nothing_and_validated(): void
    {
        $this->fakeAi();
        $id = $this->upload()->json('id');
        $save = fn (array $corrections) => $this->postJson("/api/v1/classify/jobs/{$id}/corrections", ['corrections' => $corrections]);

        $save([['row' => 4, 'sub_class' => 'مش موجود']])->assertUnprocessable()->assertJsonValidationErrors('corrections.0.sub_class');
        $save([['row' => 4, 'sub_class' => 'إيجارات'], ['row' => 4, 'sub_class' => 'إيجارات']])->assertUnprocessable()->assertJsonValidationErrors('corrections.0.row');
        $this->postJson("/api/v1/classify/jobs/{$id}/corrections", [])->assertUnprocessable()->assertJsonValidationErrors('corrections');
        // An unknown row: nothing of the request is saved.
        $save([['row' => 4, 'sub_class' => 'إيجارات'], ['row' => 99, 'sub_class' => 'إيجارات']])->assertNotFound()->assertJsonPath('rows', [99]);
        $this->assertSame('نقدية بالصندوق', collect($this->getJson("/api/v1/classify/jobs/{$id}")->json('result.data'))->firstWhere('row', 4)['sub_class']);
        $this->assertSame(0, ClassificationCacheEntry::where('source', 'manual_correction')->count());

        $this->postJson('/api/v1/classify/jobs/nope/corrections', ['corrections' => [['row' => 4, 'sub_class' => 'إيجارات']]])->assertNotFound();
        ClassificationRun::whereKey($id)->update(['status' => 'failed']);
        $save([['row' => 4, 'sub_class' => 'إيجارات']])->assertStatus(409);
    }

    public function test_the_classifier_page_can_edit_any_row_and_save(): void
    {
        $html = $this->get('/classifier')->assertOk()->getContent();
        foreach (['id="saveEdits"', 'id="undoEdits"', '<dialog class="ask" id="ask"', 'data-taxonomy=', '/corrections`'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(confirm|alert|prompt)\(/', $html);
    }

    public function test_manual_correction_overrides_the_ai_answer(): void
    {
        $this->fakeAi();
        $first = $this->upload();

        $this->patchJson("/api/v1/classify/jobs/{$first->json('id')}/rows/4", ['sub_class' => 'نقدية لدى البنوك'])
            ->assertOk()
            ->assertJsonPath('row.sub_class', 'نقدية لدى البنوك')
            ->assertJsonPath('row.classification_confidence', 100)
            ->assertJsonPath('cache_entry.source', 'manual_correction');
        $this->getJson("/api/v1/classify/jobs/{$first->json('id')}")->assertJsonPath('result.data.1.sub_class', 'نقدية لدى البنوك');

        $this->sentRows();
        $second = $this->upload();
        $this->assertSame([], $this->sentRows());
        $second->assertJsonPath('result.data.1.sub_class', 'نقدية لدى البنوك')
            ->assertJsonPath('result.data.1.classification_confidence', 100)
            ->assertJsonPath('result.data.1.classification_reason', 'تصحيح يدوي محفوظ من مراجعة سابقة');

        // A correction survives a version bump, while the AI answers around it are asked again and never overwrite it.
        config(['classification.cache.taxonomy_version' => '99']);
        $third = $this->upload();
        $this->assertSame([2, 3, 5, 6], $this->sentRows());
        $third->assertJsonPath('result.data.1.sub_class', 'نقدية لدى البنوك');
        $this->assertSame('manual_correction', ClassificationCacheEntry::where('account_code', '1101')->value('source'));
    }

    public function test_changing_the_taxonomy_version_is_a_cache_miss(): void
    {
        $this->fakeAi();
        $this->upload();
        $this->sentRows();

        config(['classification.cache.taxonomy_version' => '99']);
        $this->upload()->assertJsonPath('cache.rows_from_cache', 0)->assertJsonPath('cache.rows_sent_to_llm', 5);
        $this->assertSame([2, 3, 4, 5, 6], $this->sentRows());
        $this->assertSame(['99'], ClassificationCacheEntry::pluck('taxonomy_version')->unique()->values()->all());
    }

    public function test_low_confidence_answers_are_not_stored(): void
    {
        $this->fakeAi(confidence: 80);
        $this->upload();
        $this->sentRows();

        $this->upload();
        // Only R2 (a parent: no confidence needed) was reused. R4 is new, so it may be a parent: the generic rows
        // after it (R5, R6) can't know theirs and go to the AI too, rather than risk another section's answer.
        $this->assertSame([3, 4, 5, 6], $this->sentRows());
    }

    public function test_deleted_entry_goes_back_to_the_ai(): void
    {
        $this->fakeAi();
        $this->upload();
        $this->sentRows();

        $id = ClassificationCacheEntry::where('account_code', '1101')->value('id');
        $this->deleteJson("/api/v1/classification-cache/entries/{$id}")->assertNoContent();
        $this->deleteJson("/api/v1/classification-cache/entries/{$id}")->assertNotFound();

        $this->upload();
        $this->assertSame([4, 5, 6], $this->sentRows());   // R5, R6: generic rows below an unknown row (see above)
    }

    public function test_a_row_sent_to_the_ai_still_gets_its_cached_parent_as_context(): void
    {
        config(['classification.context_rows' => 0]);   // no neighbours: only the parent context is left
        $this->fakeAi();
        $this->upload();
        ClassificationCacheEntry::where('normalized_key', 'like', 'name:اخري|parent:المصروفات%')->delete();   // R3 forgotten

        $this->sentRows();
        $this->upload();
        $this->assertSame([3, 5, 6], $this->sentRows());   // R5, R6: generic rows below the now-unknown R3
        Http::assertSent(fn (Request $r) => $this->requestedRows($r) === [3, 5, 6]
            && str_contains($this->userText($r), "Context rows only, do not classify:\nR2 | ")
            && str_contains($this->userText($r), 'المصروفات البيعية'));
    }

    public function test_spelling_variants_produce_the_same_key(): void
    {
        $key = fn ($code, $name, $budget = null, $parent = null) => ClassificationKey::make($code, $name, $budget, $parent);

        $this->assertSame('code:5101|name:مصروفات اداريه|budget:', $key('5101', 'مصروفات إدارية'));
        foreach (['  مصروفات   إدارية ', 'مَصْرُوفَات إداريّة', 'مصـــروفات اداريه', 'مصروفات أدارية'] as $variant) {
            $this->assertSame($key('5101', 'مصروفات إدارية'), $key('5101', $variant), $variant);
        }
        $this->assertSame($key('5101', 'مستشفى'), $key('٥١٠١', 'مستشفي'));
        $this->assertSame($key('1', 'أ', 'بنود إدارية'), $key('1', 'ا', 'بنود اداريه'));

        // Generic names: the parent is part of the key, and without a known parent there is no key.
        $this->assertNotSame($key(null, 'أخرى', null, 'المصروفات البيعية'), $key(null, 'أخرى', null, 'الإيرادات الأخرى'));
        $this->assertSame($key(null, 'أخرى', null, 'المصروفات البيعية'), $key('', 'اخري', '', 'المصروفات  البيعيه'));
        $this->assertNull($key(null, 'أخرى', null, null));
        $this->assertNull($key('1101', '  '));
    }

    public function test_explain_returns_every_section_from_code_and_config(): void
    {
        config(['classification.cache.min_confidence' => 85, 'classification.cache.taxonomy_version' => '7']);

        $r = $this->getJson('/api/v1/classification-cache/explain')->assertOk()
            ->assertJsonStructure(['enabled', 'steps', 'after_lookup', 'normalization_rules', 'normalization_example', 'key' => ['fields', 'why', 'examples'],
                'stored_fields', 'thresholds' => ['cache_min_confidence', 'taxonomy_version', 'description'], 'not_cached'])
            ->assertJsonPath('thresholds.cache_min_confidence', 85)
            ->assertJsonPath('thresholds.taxonomy_version', '7')
            ->assertJsonPath('steps.0.source', 'manual_correction')
            ->assertJsonPath('normalization_example.after', 'مصروفات اداريه');

        $this->assertStringContainsString('85', $r->json('steps.1.description'));
        $this->assertSame(array_keys(ClassificationKey::RULES), array_slice(array_column($r->json('normalization_rules'), 'rule'), 0, count(ClassificationKey::RULES)));
        foreach ($r->json('normalization_rules') as $rule) {
            $this->assertNotSame($rule['before'], $rule['after'], $rule['rule']);   // every example shows its rule at work
        }
        // Every column of the table is described, so the list can't go stale.
        $this->assertSame(Schema::getColumnListing('classification_cache'), array_column($r->json('stored_fields'), 'field'));
        $this->assertNotContains(null, array_column($r->json('stored_fields'), 'description'));
    }

    public function test_entries_are_listed_with_pagination(): void
    {
        $this->fakeAi();
        $this->upload();

        $this->getJson('/api/v1/classification-cache/entries?per_page=2')->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'original_name', 'normalized_key', 'main_class', 'sub_class', 'source', 'confidence', 'in_use', 'hit_count']]]);

        $this->getJson('/api/v1/classification-cache/entries?q='.urlencode('أُخرى'))->assertOk()->assertJsonPath('total', 3);   // normalized «اخري»: both «أخرى» rows and «الإيرادات الأخرى»

        $this->getJson('/api/v1/classification-cache/entries?q=1101')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق')
            ->assertJsonPath('data.0.in_use', true);
    }

    public function test_entries_are_reclassified_and_deleted_from_the_memory_page(): void
    {
        $this->fakeAi();
        $this->upload();
        $this->get('/classification-memory')->assertOk()->assertSee('الحسابات المحفوظة');

        $cash = $this->getJson('/api/v1/classification-cache/entries?q=1101')->json('data.0.id');
        $others = array_column($this->getJson('/api/v1/classification-cache/entries?q='.urlencode('أخرى'))->json('data'), 'id');
        // Several at once: manual corrections from now on, the main class from the taxonomy.
        $this->postJson('/api/v1/classification-cache/entries/reclassify', ['ids' => [$cash], 'sub_class' => 'نقدية لدى البنوك'])->assertOk()
            ->assertJsonPath('data.0.sub_class', 'نقدية لدى البنوك')->assertJsonPath('data.0.source', 'manual_correction')
            ->assertJsonPath('data.0.confidence', 100)->assertJsonPath('data.0.in_use', true);
        $this->getJson('/api/v1/classification-cache/entries?sub_class='.urlencode('نقدية لدى البنوك'))->assertJsonPath('total', 1);
        // A side-pair member is kept as the account's kind (its base side).
        $this->postJson('/api/v1/classification-cache/entries/reclassify', ['ids' => [$cash], 'sub_class' => 'موردون'])->assertOk();
        $this->assertSame('موردون', ClassificationCacheEntry::find($cash)->sub_class);
        $this->postJson('/api/v1/classification-cache/entries/reclassify', ['ids' => [$cash, 999999], 'sub_class' => 'موردون'])->assertNotFound();
        $this->postJson('/api/v1/classification-cache/entries/reclassify', ['ids' => [$cash], 'sub_class' => 'غير موجود'])->assertUnprocessable();

        // Added by hand: a manual correction; the same account again replaces it; a pair member is stored as its kind.
        $this->postJson('/api/v1/classification-cache/entries', ['account_code' => '2105', 'account_name' => 'ايجارات مستحقة', 'sub_class' => 'مصروفات مستحقة'])
            ->assertCreated()->assertJsonPath('replaced', false)->assertJsonPath('data.source', 'manual_correction')
            ->assertJsonPath('data.sub_class', 'مصروفات مدفوعة مقدماً')->assertJsonPath('data.normalized_key', 'code:2105|name:'.ClassificationKey::name('ايجارات مستحقة').'|budget:');
        $this->postJson('/api/v1/classification-cache/entries', ['account_code' => '2105', 'account_name' => 'ايجارات مستحقة', 'sub_class' => 'أرصدة دائنة أخرى'])
            ->assertCreated()->assertJsonPath('replaced', true);
        // A generic name is known by its parent.
        $this->postJson('/api/v1/classification-cache/entries', ['account_name' => 'متنوعة', 'parent' => 'المصروفات البيعية', 'sub_class' => 'موردون'])
            ->assertCreated()->assertJsonPath('data.normalized_key', 'name:'.ClassificationKey::name('متنوعة').'|parent:'.ClassificationKey::name('المصروفات البيعية'));
        $this->postJson('/api/v1/classification-cache/entries', ['account_name' => ' ', 'sub_class' => 'موردون'])->assertUnprocessable()
            ->assertJsonPath('errors.account_name.0', 'The account name field is required.');   // the API's default language

        $this->postJson('/api/v1/classification-cache/entries/delete', ['ids' => $others])->assertOk()->assertJsonPath('deleted', count($others));
        $this->assertSame(0, ClassificationCacheEntry::whereIn('id', $others)->count());
    }

    /**
     * A class the user chose answers the doubts about the old one (the account number's family, the rule, the balance's
     * side for the old class), while the balance is checked again against the class chosen: a wrong side stays flagged.
     */
    public function test_a_chosen_class_drops_the_old_doubts_and_checks_the_balance_again(): void
    {
        $suppliers = array_map(fn ($n) => ["20{$n}", "شركة النور {$n}", 0, 0, 0, 100, 0, 100], range(1, 6));
        $file = $this->trialBalance([...$suppliers,
            ['2101', 'شركة الضياء', 0, 0, 50, 0, 50, 0],       // R8: a debit balance among the suppliers' numbers
            ['2102', 'شركة الهدى', 0, 0, 0, 70, 0, 70],     // R9: a credit balance
        ]);
        $this->fakeLlm($this->standardStructure(), fn () => [
            ...array_map(fn ($r) => $this->account($r, 'موردون'), range(2, 7)),
            $this->account(8, 'مدينون متنوعون'), $this->account(9, 'موردون'),
        ]);
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $file])->assertStatus(202)->json('id');
        $row = fn (int $n) => collect($this->getJson("/api/v1/classify/jobs/{$id}")->json('result.data'))->firstWhere('row', $n);
        $types = fn (array $r) => array_column($r['data_flags'], 'type');

        // The number's family doubts the debtor among the suppliers' numbers.
        $this->assertContains('prefix_mismatch', $types($row(8)));
        $this->assertTrue($row(8)['needs_review']);

        // Confirmed as it is: the doubt is answered, the debit balance fits the class: nothing left to review.
        $this->postJson("/api/v1/classify/jobs/{$id}/corrections", ['corrections' => [['row' => 8, 'sub_class' => 'مدينون متنوعون']]])->assertOk();
        $this->assertSame([], $types($row(8)));
        $this->assertFalse($row(8)['needs_review']);

        // A credit balance moved to a debit class: the balance is on the chosen class's wrong side, still flagged.
        $this->postJson("/api/v1/classify/jobs/{$id}/corrections", ['corrections' => [['row' => 9, 'sub_class' => 'مدينون متنوعون']]])->assertOk();
        $this->assertContains('abnormal_closing', $types($row(9)));
        $this->assertTrue($row(9)['needs_review']);
    }

    /**
     * Rows corrected before the re-check (their old doubts still on them): confirming the class as it is, through the
     * same save, re-checks them; the counter is the server's; a new row given a class is checked against it too.
     */
    public function test_a_row_corrected_before_is_confirmed_as_it_is_and_the_counter_follows(): void
    {
        $stale = ['classification_source' => 'manual_correction', 'classification_confidence' => 100, 'confidence' => 100, 'needs_review' => true,
            'data_flags' => [['type' => 'prefix_mismatch', 'difference' => null, 'message' => 'رقم من نوع تاني']], 'note' => 'سبب قديم | رقم من نوع تاني'];
        $rows = [
            $this->row(2, 'مدينون متنوعون', 50, $stale),     // debit: the doubt is all there is
            $this->row(3, 'مدينون متنوعون', -70, $stale),    // credit: on the class's wrong side
            // The file's own note (its notes column) is no doubt about the class: kept, and the row still to review.
            $this->row(4, 'مدينون متنوعون', 10, ['source_note' => 'مراجعة المدقق'] + $stale),
        ];
        $run = ClassificationRun::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx',
            'result' => ['data' => $rows, 'meta' => ['groups' => ['opening', 'closing'], 'needs_review' => 3]]]);

        $saved = $this->postJson("/api/v1/classify/jobs/{$run->id}/corrections", ['corrections' => [
            ['row' => 2, 'sub_class' => 'مدينون متنوعون'], ['row' => 3, 'sub_class' => 'مدينون متنوعون'], ['row' => 4, 'sub_class' => 'مدينون متنوعون']]])->assertOk();
        $this->assertSame([[2, false, []], [3, true, ['abnormal_opening', 'abnormal_closing']], [4, true, []]],
            array_map(fn ($r) => [$r['row'], $r['needs_review'], array_column($r['data_flags'], 'type')], $saved->json('rows')));
        // The note follows: the resolved doubt's words gone; a still-valid warning and the file's note kept.
        $notes = array_column($saved->json('rows'), 'note', 'row');
        $this->assertSame('', $notes[2]);
        $this->assertSame('رصيد افتتاحي دائن 70.00 على حساب طبيعته مدينة | رصيد ختامي دائن 70.00 على حساب طبيعته مدينة', $notes[3]);
        $this->assertStringContainsString('مراجعة المدقق', $notes[4]);
        $this->assertStringNotContainsString('رقم من نوع تاني', implode(' ', $notes));
        // Reopened: kept, the class unchanged, the counter the rows'.
        $result = $this->getJson("/api/v1/classify/jobs/{$run->id}")->json('result');
        $this->assertSame([2, array_fill(0, 3, 'مدينون متنوعون')], [$result['meta']['needs_review'], array_column($result['data'], 'sub_class')]);
        $this->assertSame($notes, array_column($result['data'], 'note', 'row'));
        $this->assertSame([50.0, 70.0], [(float) $result['data'][0]['closing_debit'], (float) $result['data'][1]['closing_credit']]);

        // A row added with a class its balance contradicts: flagged at once.
        $added = $this->postJson("/api/v1/classify/jobs/{$run->id}/rows", ['account_name' => 'مدين جديد', 'closing_credit' => 30, 'sub_class' => 'مدينون متنوعون'])->assertCreated();
        $this->assertSame([true, ['abnormal_closing']], [$added->json('row.needs_review'), array_column($added->json('row.data_flags'), 'type')]);
    }

    /**
     * Rows confirmed before a confirmation re-derived the note: listed, then (with --apply) only their notes rewritten
     * from their present flags; a still-valid warning kept; nothing else changes; a second run changes nothing.
     */
    public function test_stale_notes_of_rows_confirmed_before_are_rewritten_on_request_only(): void
    {
        $manual = ['classification_source' => 'manual_correction', 'classification_confidence' => 100, 'confidence' => 100];
        $wrongSide = ['type' => 'abnormal_closing', 'difference' => -70.0, 'message' => 'رصيد ختامي دائن 70.00 على حساب طبيعته مدينة'];
        $rows = [
            $this->row(2, 'مدينون متنوعون', 50, $manual + ['needs_review' => false, 'data_flags' => [], 'note' => 'رصيد مدين على حساب طبيعته دائنة']),
            $this->row(3, 'مدينون متنوعون', -70, $manual + ['needs_review' => true, 'data_flags' => [$wrongSide], 'note' => 'سبب قديم | '.$wrongSide['message']]),
            $this->row(4, 'مدينون متنوعون', 10, ['classification_source' => 'ai', 'classification_confidence' => 95, 'needs_review' => false, 'data_flags' => [], 'note' => 'ملاحظة الـ AI']),
            $this->row(5, 'مدينون متنوعون', 10, $manual + ['needs_review' => true, 'data_flags' => [], 'note' => 'سبب قديم']),
        ];
        $run = ClassificationRun::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx',
            'result' => ['data' => $rows, 'meta' => ['groups' => ['opening', 'closing'], 'needs_review' => 2]]]);
        $before = $run->fresh()->result;

        $this->artisan('classification:refresh-review-notes', ['runs' => [$run->id]])->assertSuccessful()
            ->expectsOutputToContain('2 note(s) to rewrite (dry run');
        $this->assertSame($before, $run->fresh()->result);

        $this->artisan('classification:refresh-review-notes', ['runs' => [$run->id], '--apply' => true])->assertSuccessful()
            ->expectsOutputToContain('2 note(s) rewritten.');
        $after = $run->fresh()->result;
        $this->assertSame(['', $wrongSide['message'], 'ملاحظة الـ AI', 'سبب قديم'], array_column($after['data'], 'note'));
        // Only the notes: class, balances, flags, review state and the counter as they were.
        $strip = fn (array $result) => array_map(fn ($r) => array_diff_key($r, ['note' => 1, 'note_phrase' => 1]), $result['data']);
        $this->assertSame($strip($before), $strip($after));
        $this->assertSame($before['meta'], $after['meta']);

        $this->artisan('classification:refresh-review-notes', ['runs' => [$run->id], '--apply' => true])->expectsOutputToContain('0 note(s) rewritten.');
    }

    public function test_with_warnings_only_just_the_warnings_of_flags_gone_are_dropped(): void
    {
        $manual = ['classification_source' => 'manual_correction', 'classification_confidence' => 100, 'confidence' => 100];
        $flag = fn (string $key, array $params) => ['key' => "classification_messages.flags.{$key}", 'params' => $params];
        $abnormal = $flag('abnormal', ['group' => 'ختامي', 'side' => 'دائن', 'amount' => '70.00', 'expected' => 'مدينة']);
        $prefix = $flag('prefix_family', ['digit' => '2', 'count' => 5, 'total' => 5, 'expected' => 'التزامات', 'family' => 'أصول']);
        $rule = $flag('rule_review', ['rule' => 'مخصص', 'side' => 'مدين']);
        $ai = ['text' => ['ar' => 'رصيد مدين لموظف']];
        $file = ['key' => 'classification_messages.note.source_note', 'params' => ['note' => 'من الملف']];
        $noted = function (array $parts) {
            $p = \App\Support\Phrase::fromArray(['join' => $parts, 'sep' => ' | ']);

            return ['note' => $p->ar(), 'note_phrase' => $p->toArray()];
        };
        $wrongSide = ['type' => 'abnormal_closing', 'difference' => -70.0, 'message' => 'x', 'message_phrase' => $abnormal];
        $rows = [
            // The AI's explanation kept; the account number's doubt, resolved, dropped.
            $this->row(2, 'مدينون متنوعون', 50, $manual + ['needs_review' => false, 'data_flags' => []] + $noted([$ai, $prefix])),
            // The rule's doubt dropped; the wrong-side warning its flag still gives kept.
            $this->row(3, 'مدينون متنوعون', -70, $manual + ['needs_review' => true, 'data_flags' => [$wrongSide]] + $noted([$rule, $abnormal])),
            // The file's note and the AI's explanation kept around a warning gone.
            $this->row(4, 'مدينون متنوعون', 10, $manual + ['needs_review' => true, 'data_flags' => [], 'source_note' => 'من الملف'] + $noted([$ai, $abnormal, $file])),
            // Not corrected by the user: never touched.
            $this->row(5, 'مدينون متنوعون', 10, ['classification_source' => 'ai', 'classification_confidence' => 95, 'needs_review' => false, 'data_flags' => []] + $noted([$prefix])),
            // Only an explanation: nothing to drop.
            $this->row(6, 'مدينون متنوعون', 10, $manual + ['needs_review' => false, 'data_flags' => []] + $noted([$ai])),
        ];
        $run = ClassificationRun::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx',
            'result' => ['data' => $rows, 'meta' => ['groups' => ['opening', 'closing'], 'needs_review' => 2]]]);
        $before = $run->fresh()->result;
        $args = ['runs' => [$run->id], '--warnings-only' => true];

        $this->artisan('classification:refresh-review-notes', $args)->assertSuccessful()
            ->expectsOutputToContain('dropped: «قاعدة «مخصص»: الرصيد مدين، وهذا يحتاج إلى مراجعة.»')
            ->expectsOutputToContain('3 note(s) to rewrite (dry run');
        $this->assertSame($before, $run->fresh()->result);

        $this->artisan('classification:refresh-review-notes', $args + ['--apply' => true])->assertSuccessful()->expectsOutputToContain('3 note(s) rewritten.');
        $after = $run->fresh()->result;
        $this->assertSame([
            'رصيد مدين لموظف',
            'رصيد ختامي دائن 70.00 على حساب طبيعته مدينة',
            'رصيد مدين لموظف | ملاحظة في الملف: من الملف',
            $before['data'][3]['note'],
            'رصيد مدين لموظف',
        ], array_column($after['data'], 'note'));
        $this->assertSame([$abnormal], $after['data'][1]['note_phrase']['join']);
        // Only the notes: class, balances, flags, review state and the counter as they were.
        $strip = fn (array $result) => array_map(fn ($r) => array_diff_key($r, ['note' => 1, 'note_phrase' => 1]), $result['data']);
        $this->assertSame($strip($before), $strip($after));
        $this->assertSame($before['meta'], $after['meta']);

        $this->artisan('classification:refresh-review-notes', $args + ['--apply' => true])->expectsOutputToContain('0 note(s) rewritten.');
        $this->assertSame($after, $run->fresh()->result);
    }

    private function row(int $n, string $sub, float $balance, array $extra): array
    {
        [$statement, $main] = app(\App\Repositories\Contracts\TaxonomyRepositoryInterface::class)->find($sub);

        return $extra + ['row' => $n, 'account_number' => "20{$n}", 'account_name' => "شركة {$n}", 'financial_statement' => $statement, 'main_class' => $main,
            'sub_class' => $sub, 'opening_debit' => max($balance, 0), 'opening_credit' => max(-$balance, 0), 'period_debit' => 0, 'period_credit' => 0,
            'adjustment_debit' => 0, 'adjustment_credit' => 0, 'closing_debit' => max($balance, 0), 'closing_credit' => max(-$balance, 0),
            'classification_reason' => 'تصحيح يدوي', 'source_note' => null, 'existing_classification' => null, 'cache_key' => null, 'note' => ''];
    }
}
