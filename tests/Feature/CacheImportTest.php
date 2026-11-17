<?php

namespace Tests\Feature;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationImportMapping;
use App\Models\ClassificationImportRow;
use App\Models\ClassificationNameLayerEntry;
use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;
use App\Services\ClassificationCache;
use App\Support\ClassificationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTaxonomyMapper;
use Tests\Support\PageConfig;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Importing accountant-labelled trial balances: distinct values mapped once, junk skipped, review before apply, priorities. */
class CacheImportTest extends TestCase
{
    use PageConfig;
    use RefreshDatabase;
    use TrialBalanceFiles;

    private const HEADER = ['رقم الحساب ', 'الميزانية', 'الإيضاحات', 'اسم الحساب ', 'أول مدين  ', 'أول دائن  ', 'حركة مدينة  ', 'حركة دائنة ',
        'تسوية مدين  ', 'تسوية دائن ', 'أخر مدين  ', 'أخر دائن ', 'اسم الشركة'];

    private FakeTaxonomyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        config(['classification.rules_enabled' => true]);   // the accountant's rules apply here, as in a run
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['classification.cache.enabled' => true]);
        $this->mapper = new FakeTaxonomyMapper;
        $this->app->instance(TaxonomyMapperRepositoryInterface::class, $this->mapper);
    }

    /** [code, budget, note, name, closing debit, closing credit, company] → a full row of the file. */
    private function row(?string $code, ?string $budget, ?string $note, ?string $name, float $debit, float $credit, string $company): array
    {
        return [$code, $budget, $note, $name, 0, 0, 0, 0, null, null, $debit, $credit, $company];
    }

    /** Uploads through the site (the queue runs synchronously in tests) and returns the import report. */
    private function upload(array $rows): array
    {
        $id = $this->post('/api/v1/classification-cache/import', ['file' => $this->xlsxUpload([self::HEADER, ...$rows])], ['Accept' => 'application/json'])
            ->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classification-cache/imports/{$id}")->assertOk()->assertJsonPath('status', 'pending_review')->json('report');
    }

    /** The accountant approves every mapping of the import as it is. */
    private function approveAll(int $id): void
    {
        $this->postJson("/api/v1/classification-cache/imports/{$id}/mappings/approve", ['ids' => ClassificationImportMapping::where('import_id', $id)->pluck('id')->all()])->assertOk();
    }

    private function subOf(int $excelRow): ?string
    {
        return ClassificationImportRow::where('row', $excelRow)->value('sub_class');
    }

    public function test_spelling_variants_of_the_budget_item_map_to_the_same_class(): void
    {
        $this->mapper->budget = ['دائنون تجاريون' => 'الذمم الدائنة التجارية'];
        $this->mapper->notes  = ['موردين' => 'موردون'];

        $report = $this->upload([
            $this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 1'),           // R2
            $this->row('2102', 'الذمم الدائنة التجارية', 'موردين', 'مورد ب', 0, 10, 'شركة 2'),     // R3: same after normalization
            $this->row('2103', 'الذمم  الدائنـة التجاريّة', 'موردين', 'مورد ج', 0, 10, 'شركة 3'),  // R4: spaces, tatweel, shadda
            $this->row('2104', 'دائنون تجاريون', 'موردين', 'مورد د', 0, 10, 'شركة 4'),             // R5: a synonym → the LLM
        ]);

        foreach ([2, 3, 4, 5] as $r) {
            $this->assertSame('موردون', $this->subOf($r), "R{$r}");
        }
        // Mapped once per distinct value: the three spellings are one mapping, matched to the taxonomy without the LLM.
        $budget = ClassificationImportMapping::where('kind', 'budget')->get();
        $this->assertCount(2, $budget);
        $this->assertSame(['taxonomy', 'llm'], $budget->pluck('method')->all());
        $this->assertSame([['دائنون تجاريون']], $this->mapper->budgetCalls);
        // One note pair (main class, «موردين») for all four rows: one LLM item.
        $this->assertSame(1, ClassificationImportMapping::where('kind', 'note')->count());
        $this->assertCount(1, $this->mapper->noteCalls);
        $this->assertSame(4, $report['budget_mapping']['distinct_values']);
        $this->assertSame(4, $report['rows']['mapped']);
    }

    public function test_junk_and_header_rows_are_skipped_and_a_shifted_block_is_recovered(): void
    {
        $this->mapper->notes = ['النقد في البنوك' => 'نقدية لدى البنوك', 'النقد في الصندوق' => 'نقدية بالصندوق'];
        $this->mapper->budget = ['النقد وما في حكمه' => 'النقد ومافي حكمه'];   // «وما في» ≠ «ومافي» after normalization: the LLM
        $report = $this->upload([
            $this->row('1101', 'النقد وما في حكمه', 'النقد في البنوك', 'بنك الراجحي', 10, 0, 'شركة 1'),    // R2 ok
            ['رقم الحساب', 'مستوى القوائم', 'الإيضاحات', 'اسم الحساب'],                                     // R3 repeated header
            $this->row('1102', 'Accounts Receivable', null, 'عميل', 10, 0, 'شركة 1'),                         // R4 الميزانية = account name
            $this->row('1103', 'المخزون', null, null, 10, 0, 'شركة 1'),                                        // R5 no name
            $this->row(null, null, null, 'الإجمالي', 30, 0, 'شركة 1'),                                         // R6 no الميزانية
            // R7: one company's block with its own header, every column moved right; its company column is unlabelled.
            ['م', 'Account', 'بنود الميزانية  ', 'إيضاحات  الميزانية  ', 'اسم الحساب ', 'أول مدين  ', 'أول دائن  ', 'حركة مدينة  ', 'حركة دائنة ', 'أخر مدين  ', 'أخر دائن ', null, 'شركة 2'],
            [1, ' Petty Cash', 'النقد ومافي حكمه', 'النقد في الصندوق', 'صندوق', 0, 0, 0, 0, 5, 0, null, 'شركة 2'],   // R8 recovered
        ]);

        $skipped = ClassificationImportRow::whereNotNull('skip_reason')->pluck('skip_reason', 'row')->all();
        $this->assertSame([3 => 'header_row', 4 => 'budget_not_arabic', 5 => 'empty_name', 6 => 'empty_budget', 7 => 'header_row'], $skipped);
        $this->assertSame('نقدية لدى البنوك', $this->subOf(2));

        $shifted = ClassificationImportRow::where('row', 8)->first();
        $this->assertSame(['صندوق', 'النقد ومافي حكمه', 'النقد في الصندوق', 'debit', 'شركة 2'],
            [$shifted->account_name, $shifted->budget_value, $shifted->note_value, $shifted->side, $shifted->company]);
        $this->assertSame('نقدية بالصندوق', $shifted->sub_class);
        $this->assertSame(2, $report['rows']['mapped']);
        $this->assertSame(1, $report['skipped']['budget_not_arabic']['rows']);
    }

    public function test_a_name_classified_differently_across_companies_is_not_used(): void
    {
        $this->mapper->notes = ['العملاء' => 'عملاء', 'دفعات مقدمة' => 'دفعات مقدمة من العملاء', 'ايجار' => 'إيجارات', 'سكن' => 'سكن العاملين', 'متنوعة' => 'مصروفات متنوعة'];
        $rows = [];
        foreach (['شركة 1', 'شركة 2'] as $c) {
            // «العملاء» is a receivable when debit and a customer advance when credit: one conflict by name, none by name + side.
            $rows[] = $this->row(null, 'ذمم مدينة تجارية', 'العملاء', 'العملاء', 10, 0, $c);
            $rows[] = $this->row(null, 'مصروفات مستحقة وارصدة دائنة أخرى', 'دفعات مقدمة', 'العملاء', 0, 10, $c);
        }
        // «إيجار المستودع»: 2 companies say rent, 1 says housing (66% < 80%): never used.
        foreach (['شركة 1' => 'ايجار', 'شركة 2' => 'ايجار', 'شركة 3' => 'سكن'] as $c => $note) {
            $rows[] = $this->row(null, 'المصروفات العمومية والإدارية', $note, 'إيجار المستودع', 10, 0, $c);
        }
        // «أخرى»: every company agrees, but a generic name is never used by name.
        foreach (['شركة 1', 'شركة 2', 'شركة 3'] as $c) {
            $rows[] = $this->row(null, 'المصروفات العمومية والإدارية', 'متنوعة', 'أخرى', 10, 0, $c);
        }
        $this->mapper->budget = ['المصروفات العمومية والإدارية' => 'route', 'مصروفات مستحقة وارصدة دائنة أخرى' => 'مصروفات مستحقةو ارصدة دائنة اخرى'];

        $id = $this->upload($rows)['import_id'];
        $this->approveAll($id);
        $report = $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk()->json('report');

        $this->assertSame(2, $report['name_layer']['conflicts_before']);   // «العملاء» by name, «إيجار المستودع»
        $this->assertSame(1, $report['name_layer']['conflicts_after']);    // only «إيجار المستودع»
        $this->assertSame(['العملاء|debit' => 'عملاء', 'العملاء|credit' => 'دفعات مقدمة من العملاء'],
            ClassificationNameLayerEntry::get()->mapWithKeys(fn ($e) => [$e->normalized_name.'|'.$e->side => $e->sub_class])->all());

        // A new file: the layer answers «العملاء» by side, and never «إيجار المستودع» or «أخرى».
        $extracted = array_map(fn ($a) => ['account' => ['account_number' => '9', 'account_name' => $a[0], 'closing_debit' => $a[1], 'closing_credit' => $a[2]], 'existing' => null],
            [2 => ['العملاء', 50, 0], 3 => ['العملاء', 0, 50], 4 => ['إيجار المستودع', 50, 0], 5 => ['أخرى', 50, 0]]);
        $found = app(ClassificationCache::class)->lookup($extracted);
        $this->assertSame([2 => 'عملاء', 3 => 'دفعات مقدمة من العملاء'], array_map(fn ($l) => $l['sub_class'], $found['labels']));
        $this->assertSame([2 => ClassificationCache::NAME_LAYER, 3 => ClassificationCache::NAME_LAYER], $found['sources']);
    }

    public function test_an_imported_entry_never_overwrites_a_manual_correction_or_an_ai_answer(): void
    {
        $cash = fn (string $name) => ClassificationKey::make('1101', $name, 'النقد ومافي حكمه', null);
        $cache = app(ClassificationCache::class);
        $cache->correct($cash('صندوق'), '1101', 'صندوق', 'النقد ومافي حكمه', 'نقدية لدى البنوك');
        ClassificationCacheEntry::create([
            'key_hash' => ClassificationKey::hash($cash('بنك')), 'name_hash' => ClassificationKey::nameHash('بنك'), 'normalized_key' => $cash('بنك'),
            'original_name' => 'بنك', 'account_code' => '1101', 'budget_item' => 'النقد ومافي حكمه', 'row_type' => 'account',
            'main_class' => 'النقد ومافي حكمه', 'sub_class' => 'نقدية بالصندوق', 'source' => ClassificationCache::AI, 'confidence' => 95,
            'taxonomy_version' => ClassificationCache::version(),
        ]);
        $this->mapper->notes = ['صندوق' => 'نقدية بالصندوق', 'بنوك' => 'نقدية لدى البنوك'];

        $id = $this->upload([
            $this->row('1101', 'النقد ومافي حكمه', 'صندوق', 'صندوق', 10, 0, 'شركة 1'),   // R2: the manual correction says بنوك
            $this->row('1101', 'النقد ومافي حكمه', 'بنوك', 'بنك', 10, 0, 'شركة 1'),       // R3: the AI said صندوق
            $this->row('1102', 'النقد ومافي حكمه', 'بنوك', 'بنك الأهلي', 10, 0, 'شركة 1'), // R4: new key
        ])['import_id'];

        // Nothing reaches the cache before apply.
        $this->assertSame(0, ClassificationCacheEntry::where('source', ClassificationCache::IMPORTED)->count());

        $this->approveAll($id);
        $applied = $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk()->json('applied');

        $this->assertSame(['cache_entries' => 1, 'key_conflicts' => 0, 'kept_existing' => 2, 'pending_review' => 0], array_diff_key($applied, ['name_layer' => 0]));
        $entries = ClassificationCacheEntry::get()->keyBy('original_name');
        $this->assertSame([ClassificationCache::MANUAL, 'نقدية لدى البنوك'], [$entries['صندوق']->source, $entries['صندوق']->sub_class]);
        $this->assertSame([ClassificationCache::AI, 'نقدية بالصندوق'], [$entries['بنك']->source, $entries['بنك']->sub_class]);
        $this->assertSame([ClassificationCache::IMPORTED, 'نقدية لدى البنوك'], [$entries['بنك الأهلي']->source, $entries['بنك الأهلي']->sub_class]);
        $this->assertSame(['cache_has_entry', 'cache_has_entry', null], ClassificationImportRow::orderBy('row')->pluck('skip_reason')->all());
    }

    public function test_apply_writes_approved_rows_only_and_can_run_again_in_stages(): void
    {
        $this->mapper->notes = ['حق استخدام مباني' => 'مباني وإنشاءات'];
        $id = $this->upload([
            $this->row('1301', 'أصول حق الاستخدام', 'حق استخدام مباني', 'حق استخدام المعرض', 10, 0, 'شركة 1'),   // R2: flagged override + an LLM note
            $this->row('2401', 'مخصص نهاية الخدمة', null, 'مكافأة نهاية الخدمة', 0, 10, 'شركة 1'),             // R3: exact taxonomy, one sub-class
        ])['import_id'];

        // Only R3 is approved (decided by the taxonomy alone): the first «تطبيق» writes it, R2 stays pending.
        $applied = $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk()->json('applied');
        $this->assertSame([1, 1], [$applied['cache_entries'], $applied['pending_review']]);
        $this->assertSame(['مكافأة نهاية الخدمة'], ClassificationCacheEntry::pluck('original_name')->all());
        $this->assertSame(100, ClassificationCacheEntry::sole()->confidence);

        // The accountant approves the rest later; the next «تطبيق» adds it.
        $flagged = ClassificationImportMapping::whereNotNull('flag')->sole();
        $this->patchJson("/api/v1/classification-cache/imports/mappings/{$flagged->id}", [])->assertOk();
        $this->assertSame(100, $flagged->fresh()->confidence);   // approved as is
        $this->approveAll($id);
        $applied = $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk()->json('applied');
        $this->assertSame([2, 0], [$applied['cache_entries'], $applied['pending_review']]);
        $this->assertSame('مباني وإنشاءات', ClassificationCacheEntry::where('original_name', 'حق استخدام المعرض')->value('sub_class'));
        $this->assertSame(2, ClassificationCacheEntry::where('import_id', $id)->count());
    }

    public function test_the_accountant_can_correct_a_mapping_and_the_rows_follow(): void
    {
        $this->mapper->budget = ['نتائج الأعمال' => 'skip'];
        $id = $this->upload([$this->row('3101', 'نتائج الأعمال', null, 'أرباح العام', 0, 10, 'شركة 1')])['import_id'];
        $this->assertNull($this->subOf(2));

        $budget = ClassificationImportMapping::where('kind', 'budget')->sole();
        $this->mapper->notes = ['أرباح العام' => 'أرباح أو خسائر العام الحالي'];
        $report = $this->patchJson("/api/v1/classification-cache/imports/mappings/{$budget->id}", ['decision' => 'map', 'main_class' => 'الارباح المبقاه'])
            ->assertOk()->json('report');

        $this->assertSame('أرباح أو خسائر العام الحالي', $this->subOf(2));
        // The budget mapping is the accountant's; the row also needs its LLM note approved before «تطبيق» writes it.
        $this->assertSame([0, 1], [$report['rows']['approved'], $report['rows']['pending_review']]);
        $this->assertSame(['map'], ClassificationImportMapping::where('kind', 'note')->pluck('decision')->all());
        $this->patchJson("/api/v1/classification-cache/imports/mappings/{$budget->id}", ['decision' => 'map', 'main_class' => 'غير موجود'])->assertUnprocessable();
    }

    public function test_a_note_mapped_outside_the_budget_main_class_is_flagged_for_review(): void
    {
        // Advances to suppliers listed under receivables: the taxonomy puts them under prepaid expenses and other debit balances.
        $this->mapper->notes = ['موردين دفعات مقدمه' => 'دفعات مقدمة للموردين', 'العملاء' => 'عملاء'];
        $id = $this->upload([
            $this->row('1201', 'الذمم المدينة التجارية', 'موردين دفعات مقدمه', 'مورد أ - دفعة', 10, 0, 'شركة 1'),
            $this->row('1202', 'الذمم المدينة التجارية', 'العملاء', 'عميل أ', 10, 0, 'شركة 1'),
        ])['import_id'];

        $this->assertSame(['دفعات مقدمة للموردين', 'عملاء'], [$this->subOf(2), $this->subOf(3)]);
        $flagged = ClassificationImportMapping::whereNotNull('flag')->sole();
        $this->assertSame('موردين دفعات مقدمه', $flagged->value);
        $this->assertSame('الذمم المدينة التجارية', $this->mapper->noteCalls[0][0]['hint_main']);
        $this->assertFalse($flagged->reviewed);   // never approved on its own
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $this->assertSame(['عميل أ'], ClassificationCacheEntry::pluck('original_name')->all());   // exact sub-class name: approved

        $this->approveAll($id);
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $this->assertSame(2, ClassificationCacheEntry::count());
    }

    public function test_the_cost_of_revenue_policy_switches_without_asking_the_llm_again(): void
    {
        $this->mapper->notes = ['ايجارات' => ['إيجارات', 'تكلفة الخدمات المقدمة'], 'بضاعة' => ['تكلفة البضاعة المباعة', 'تكلفة البضاعة المباعة']];
        $id = $this->upload([
            $this->row('5101', 'تكلفة الايرادات', 'ايجارات', 'إيجار موقع المشروع', 10, 0, 'شركة 1'),   // R2
            $this->row('5102', 'تكاليف المبيعات', 'بضاعة', 'تكلفة بضاعة', 10, 0, 'شركة 1'),             // R3
        ])['import_id'];

        // Default: by function, kept as a cost of revenue (no review flag: the policy decides, not the main class).
        $this->assertSame(['تكلفة الخدمات المقدمة', 'تكلفة البضاعة المباعة'], [$this->subOf(2), $this->subOf(3)]);
        $this->assertSame(0, ClassificationImportMapping::whereNotNull('flag')->count());
        $this->approveAll($id);
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $decision = $this->getJson("/api/v1/classification-cache/imports/{$id}")->json('report.open_decisions.0');
        $this->assertSame(['cost_of_revenue', 'function', 2, 1], [$decision['policy'], $decision['mode'], $decision['rows'], $decision['rows_changing_if_switched_to_nature']]);

        // Switch to by nature: config + re-apply, no LLM call.
        $calls = count($this->mapper->noteCalls) + count($this->mapper->budgetCalls);
        config(['classification.import.policies.cost_of_revenue.mode' => 'nature']);
        $this->artisan('classification:import', ['--reapply' => $id])->assertSuccessful();

        $this->assertSame(['إيجارات', 'تكلفة البضاعة المباعة'], [$this->subOf(2), $this->subOf(3)]);
        $this->assertSame('إيجارات', ClassificationCacheEntry::where('original_name', 'إيجار موقع المشروع')->value('sub_class'));
        $this->assertSame($calls, count($this->mapper->noteCalls) + count($this->mapper->budgetCalls));
    }

    /** Imports and applies rows ([code, budget, note, name, debit, credit]) with every mapping approved. */
    private function importAndApply(array $rows): void
    {
        $id = $this->upload(array_map(fn ($r) => $this->row(...[...$r, 'شركة 1']), $rows))['import_id'];
        $this->approveAll($id);
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
    }

    /** A client's trial balance (no الميزانية column): [code, name, closing debit, closing credit] per row. */
    private function classifyClientFile(array $accounts, array &$asked): \Illuminate\Testing\TestResponse
    {
        $this->fakeLlm($this->standardStructure(), function ($call, array $requested) use (&$asked) {
            array_push($asked, ...$requested);

            return array_map(fn ($n) => $this->account($n, 'نقدية لدى البنوك', 95), $requested);
        });
        $rows = array_map(fn ($a) => [$a[0], $a[1], 0, 0, $a[2], $a[3], $a[2], $a[3]], $accounts);
        $id   = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($rows)])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done');
    }

    public function test_an_imported_label_is_reused_by_code_and_name_when_the_client_file_has_no_budget_column(): void
    {
        $this->mapper->notes = ['صندوق' => 'نقدية بالصندوق'];
        $this->importAndApply([['1101', 'النقد ومافي حكمه', 'صندوق', 'الصنـدوق الرئيسي', 10, 0]]);
        $entry = ClassificationCacheEntry::sole();
        $this->assertSame(sha1('code:1101|name:الصندوق الرئيسي'), $entry->base_hash);

        // Spelled differently (diacritics, hamza), and no budget item: the full key misses, code + name finds it.
        $asked = [];
        $run = $this->classifyClientFile([['1101', 'الصَّندوق الرئيسى', 50, 0], ['1102', 'بنك الأهلي', 20, 0]], $asked);
        $this->assertSame([3], $asked);
        $row = collect($run->json('result.data'))->firstWhere('row', 2);
        $this->assertSame(['نقدية بالصندوق', 100], [$row['sub_class'], $row['classification_confidence']]);
        $this->assertStringStartsWith(ClassificationCache::IMPORTED_CODE_PREFIX, $row['classification_reason']);
        $this->assertSame(1, $entry->fresh()->hit_count);
    }

    public function test_the_code_and_name_fallback_needs_one_clear_answer_and_only_uses_imported_entries(): void
    {
        $this->mapper->notes = ['صندوق' => 'نقدية بالصندوق', 'بنوك' => 'نقدية لدى البنوك'];
        $this->mapper->budget = ['نقدية' => 'النقد ومافي حكمه'];
        // The same code + name under two budget items, with two different sub-classes: no clear answer.
        $this->importAndApply([
            ['1101', 'النقد ومافي حكمه', 'صندوق', 'خزينة', 10, 0],
            ['1101', 'نقدية', 'بنوك', 'خزينة', 10, 0],
        ]);
        $this->assertSame(2, ClassificationCacheEntry::where('source', ClassificationCache::IMPORTED)->count());

        // An AI answer with a budget item is never used by code + name alone.
        $key = ClassificationKey::make('1201', 'عهدة', 'بند', null);
        ClassificationCacheEntry::create(['key_hash' => ClassificationKey::hash($key), 'base_hash' => ClassificationKey::baseHash($key),
            'name_hash' => ClassificationKey::nameHash('عهدة'), 'normalized_key' => $key, 'original_name' => 'عهدة', 'row_type' => 'account',
            'sub_class' => 'عُهد العاملين', 'source' => ClassificationCache::AI, 'confidence' => 99, 'taxonomy_version' => ClassificationCache::version()]);

        $asked = [];
        $this->classifyClientFile([['1101', 'خزينة', 50, 0], ['1201', 'عهدة', 20, 0]], $asked);
        sort($asked);
        $this->assertSame([2, 3], $asked);
    }

    public function test_apply_stores_the_base_class_so_both_sides_of_one_account_are_not_a_conflict(): void
    {
        // An employee account with a debit balance in one company and a credit balance in another (side rule): one kind.
        $this->importAndApply([
            ['1301', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'عهد موظفين', 'عهدة سامي', 10, 0],
            ['1301', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'عهد موظفين', 'عهدة سامي', 0, 10],
        ]);
        $this->assertSame([['عُهد العاملين']], ClassificationCacheEntry::get(['sub_class'])->map(fn ($e) => [$e->sub_class])->all());
        $this->assertSame([], ClassificationImportRow::whereNotNull('skip_reason')->pluck('skip_reason')->all());

        // A client row with a credit balance gets the member of its own side.
        $asked = [];
        $row = collect($this->classifyClientFile([['1301', 'عهدة سامي', 0, 40]], $asked)->json('result.data'))->first();
        $this->assertSame(['عُهد وسُلف دائنه', []], [$row['sub_class'], $asked]);   // the «عهدة» rule's class for a credit
    }

    public function test_a_base_side_row_decides_the_member_for_the_other_side_rows_of_its_account(): void
    {
        // Side rule «سلف موظفين»: debit → سلف العاملين, credit → أمانات العاملين. The name has no «سلف», so the name rule
        // alone would turn the credit row into عُهد العاملين; the debit row is the accountant's own pick.
        $this->importAndApply([
            ['1302', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'سلف موظفين', 'خالد محمود', 10, 0],
            ['1302', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'سلف موظفين', 'خالد محمود', 0, 10],
        ]);
        $this->assertSame(['سلف العاملين'], ClassificationCacheEntry::pluck('sub_class')->all());
        $this->assertSame([], ClassificationImportRow::whereNotNull('skip_reason')->pluck('skip_reason')->all());
    }

    public function test_approve_all_approves_every_unreviewed_mapping_matching_the_filters_on_every_page(): void
    {
        $this->mapper->budget = ['بند أ' => 'النقد ومافي حكمه', 'بند ب' => 'النقد ومافي حكمه', 'بند ج' => 'النقد ومافي حكمه'];
        $this->mapper->notes  = ['صندوق' => 'نقدية بالصندوق', 'بنوك' => 'نقدية لدى البنوك'];
        $id = $this->upload([
            $this->row('1101', 'بند أ', 'صندوق', 'صندوق', 10, 0, 'شركة 1'),
            $this->row('1102', 'بند ب', 'بنوك', 'بنك', 10, 0, 'شركة 1'),
            $this->row('1103', 'بند ج', 'بنوك', 'بنك مصر', 10, 0, 'شركة 1'),
        ])['import_id'];
        $unreviewed = fn (string $kind) => ClassificationImportMapping::where('import_id', $id)->where('kind', $kind)->where('reviewed', false)->count();
        $this->assertSame(3, $unreviewed('budget'));
        $notes = $unreviewed('note');
        $this->assertGreaterThan(0, $notes);
        $approve = fn (array $body) => $this->postJson("/api/v1/classification-cache/imports/{$id}/mappings/approve", $body);

        // The search filter narrows it, as on the list.
        $approve(['all' => true, 'kind' => 'budget', 'q' => 'بند أ'])->assertOk()->assertJsonPath('approved', 1);
        $this->assertSame(2, $unreviewed('budget'));

        // The rest of the tab, whatever the list's page size; the other tab is untouched.
        $this->getJson("/api/v1/classification-cache/imports/{$id}/mappings?kind=budget&unreviewed=1&per_page=1")->assertJsonPath('total', 2);
        $approve(['all' => true, 'kind' => 'budget'])->assertOk()->assertJsonPath('approved', 2);
        $this->assertSame([0, $notes], [$unreviewed('budget'), $unreviewed('note')]);
        $this->assertSame(100, ClassificationImportMapping::where('import_id', $id)->where('kind', 'budget')->min('confidence'));   // approved as is

        // Nothing left: 0, not an error. Picked ids still work; neither ids nor all is a validation error.
        $approve(['all' => true, 'kind' => 'budget'])->assertOk()->assertJsonPath('approved', 0);
        $approve(['ids' => [ClassificationImportMapping::where('import_id', $id)->where('kind', 'note')->value('id')]])->assertOk()->assertJsonPath('approved', 1);
        $approve([])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $approve(['ids' => []])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $approve(['all' => false])->assertUnprocessable()->assertJsonValidationErrors('ids');   // false = use ids
        $approve(['all' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('all');
        $approve(['all' => true, 'kind' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('kind');

        // The pages confirm in the page (partials/ask-dialog), never with a browser popup.
        foreach (["/classification-cache/imports/{$id}/review", '/classification-cache'] as $url) {
            $html = $this->get($url)->assertOk()->assertSee('<dialog class="ask" id="ask"', false)->getContent();
            $this->assertDoesNotMatchRegularExpression('/\b(confirm|alert|prompt)\(/', $html);
        }
        $this->get("/classification-cache/imports/{$id}/review")->assertSee('id="approveAll"', false)->assertSee('id="nPending"', false)->assertSee('id="otherTab"', false);
    }

    public function test_ready_counts_only_approved_rows_not_written_yet(): void
    {
        $this->mapper->notes = ['صندوق' => 'نقدية بالصندوق', 'بنوك' => 'نقدية لدى البنوك'];
        $id = $this->upload([
            $this->row('1101', 'النقد ومافي حكمه', 'صندوق', 'صندوق', 10, 0, 'شركة 1'),
            $this->row('1102', 'النقد ومافي حكمه', 'بنوك', 'بنك', 10, 0, 'شركة 1'),
            $this->row('1103', 'النقد ومافي حكمه', 'بنوك', 'بنك مصر', 10, 0, 'شركة 1'),
        ])['import_id'];
        $rows = fn () => $this->getJson("/api/v1/classification-cache/imports/{$id}")->assertOk()->json('report.rows');
        // By key: MySQL's JSON column doesn't keep the key order.
        $counts = fn () => array_map(fn ($k) => $rows()[$k], ['approved', 'ready', 'written', 'cache_entries']);
        $this->approveAll($id);
        $this->assertSame([3, 3, 0, 0], $counts());

        // After «تطبيق» nothing is waiting; «approved» stays cumulative.
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $this->assertSame([3, 0, 3, 3], $counts());

        // A mapping changed after «تطبيق»: its rows wait for the next one.
        $bank = ClassificationImportMapping::where('import_id', $id)->where('kind', 'note')->where('value', 'بنوك')->value('id');
        $this->patchJson("/api/v1/classification-cache/imports/mappings/{$bank}", ['decision' => 'map', 'sub_class' => 'نقدية بالصندوق'])->assertOk();
        $this->assertSame([2, 1], [$rows()['ready'], $rows()['written']]);
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $this->assertSame(0, $rows()['ready']);

        // A report saved before these counters existed is rebuilt once when read.
        $import = \App\Models\ClassificationImport::find($id);
        $old = $import->report;
        unset($old['rows']['ready'], $old['rows']['written']);
        $import->update(['report' => $old]);
        $this->assertSame([0, 3], [$rows()['ready'], $rows()['written']]);
    }

    public function test_the_review_page_renders_with_the_import_api(): void
    {
        $id = $this->upload([$this->row('2401', 'مخصص نهاية الخدمة', null, 'مكافأة نهاية الخدمة', 0, 10, 'شركة 1')])['import_id'];

        $html = $this->get("/classification-cache/imports/{$id}/review")->assertOk()->assertSee('مراجعة ربط الملف المستورد')->getContent();

        // The config the script reads (dataset): versioned URLs, and JSON that parses after the browser decodes it once.
        $config = $this->appConfig($html);
        $this->assertSame(["/api/v1/classification-cache/imports/{$id}", '/api/v1/classification-cache/imports/mappings/'], [$config['api'], $config['review-url']]);
        $this->assertSame(app(\App\Repositories\Contracts\TaxonomyRepositoryInterface::class)->map(), json_decode($config['taxonomy'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(config('classification.import.policies'), json_decode($config['policies'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('const TAXONOMY = JSON.parse(CONFIG.taxonomy);', $html);

        // Every call the script makes, built from those URLs as the script builds them.
        $this->getJson($config['api'])->assertOk()->assertJsonPath('status', 'pending_review');
        $mapping = $this->getJson("{$config['api']}/mappings?kind=budget")->assertOk()
            ->assertJsonPath('data.0.samples.0.name', 'مكافأة نهاية الخدمة')->assertJsonPath('data.0.active_sub', 'مخصص نهاية الخدمة')->json('data.0.id');
        $this->patchJson($config['review-url'].$mapping, [])->assertOk();   // «✓ اعتماد» sends {}
        $this->postJson("{$config['api']}/mappings/approve", ['ids' => [$mapping]])->assertOk();
        $this->postJson("{$config['api']}/apply")->assertOk();
        $this->get('/classification-cache/imports/999/review')->assertNotFound();
    }
}
