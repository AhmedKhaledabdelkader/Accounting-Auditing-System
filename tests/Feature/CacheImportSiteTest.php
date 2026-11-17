<?php

namespace Tests\Feature;

use App\Jobs\ProcessClassificationImport;
use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping;
use App\Models\ClassificationImportRow;
use App\Models\ClassificationNameLayerEntry;
use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;
use App\Services\CacheImport;
use App\Services\ClassificationCache;
use App\Support\ClassificationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\FakeTaxonomyMapper;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** The import as a site feature: queued upload, reuse of earlier mappings, duplicate files, side rules, export / import. */
class CacheImportSiteTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    private const HEADER = ['رقم الحساب ', 'الميزانية', 'الإيضاحات', 'اسم الحساب ', 'أول مدين  ', 'أول دائن  ', 'حركة مدينة  ', 'حركة دائنة ',
        'تسوية مدين  ', 'تسوية دائن ', 'أخر مدين  ', 'أخر دائن ', 'اسم الشركة'];

    private FakeTaxonomyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['classification.cache.enabled' => true]);
        $this->mapper = new FakeTaxonomyMapper;
        $this->app->instance(TaxonomyMapperRepositoryInterface::class, $this->mapper);
    }

    private function row(?string $code, ?string $budget, ?string $note, ?string $name, float $debit, float $credit, string $company): array
    {
        return [$code, $budget, $note, $name, 0, 0, 0, 0, null, null, $debit, $credit, $company];
    }

    private function file(array $rows): UploadedFile
    {
        return $this->xlsxUpload([self::HEADER, ...$rows]);
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        // As the page sends it: its messages in Arabic.
        return $this->post('/api/v1/classification-cache/import', ['file' => $file] + $extra, ['Accept' => 'application/json', 'Accept-Language' => 'ar']);
    }

    private function approveAll(int $id): void
    {
        $this->postJson("/api/v1/classification-cache/imports/{$id}/mappings/approve", ['ids' => ClassificationImportMapping::where('import_id', $id)->pluck('id')->all()])->assertOk();
    }

    public function test_an_upload_from_the_page_runs_the_whole_import_in_the_queue(): void
    {
        Queue::fake();
        $this->mapper->notes = ['موردين' => 'موردون'];

        $job = $this->upload($this->file([$this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 1')]))
            ->assertStatus(202)->assertJsonPath('status', 'queued')->json();
        Queue::assertPushed(ProcessClassificationImport::class, fn ($j) => $j->importId === $job['id']);
        $import = ClassificationImport::find($job['id']);
        Storage::disk('local')->assertExists($import->file_path);
        $this->assertSame(0, ClassificationImportRow::count());   // nothing read before the worker runs

        // The worker: reading → الميزانية → الإيضاحات → ready for review; the upload is deleted.
        (new ProcessClassificationImport($job['id']))->handle(app(CacheImport::class));
        $status = $this->getJson($job['status_url'])->assertOk()->json();
        $this->assertSame(['pending_review', null, 1, 1], [$status['status'], $status['stage'], $status['progress']['done'], $status['progress']['total']]);
        $this->assertSame(1, $status['report']['rows']['mapped']);
        Storage::disk('local')->assertMissing($import->file_path);
        $this->assertSame('/classification-cache/imports/'.$job['id'].'/review', $status['review_url']);

        $this->getJson('/api/v1/classification-cache/imports')->assertOk()->assertJsonPath('0.id', $job['id'])->assertJsonPath('0.rows.total', 1);
        $this->get('/classification-cache')->assertOk()->assertSee('ذاكرة التصنيف')->assertSee('استيراد ملف مصنف');
        $this->get('/classifier')->assertSee('href="/classification-cache"', false);
    }

    public function test_a_failed_import_shows_an_arabic_error(): void
    {
        Queue::fake();
        $job = $this->upload($this->file([$this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 1')]))->json();
        Http::fake(['*' => Http::response(['error' => ['message' => 'credit balance is too low']], 400)]);
        $this->app->forgetInstance(TaxonomyMapperRepositoryInterface::class);   // the real mapper, against the faked provider
        $this->app->bind(TaxonomyMapperRepositoryInterface::class, \App\Repositories\Eloquents\TaxonomyMapperRepository::class);

        (new ProcessClassificationImport($job['id']))->handle(app(CacheImport::class));

        $status = $this->getJson($job['status_url'])->json();
        $this->assertSame('failed', $status['status']);
        $this->assertStringStartsWith('خطأ من النظام', $status['error']);
    }

    public function test_a_second_upload_reuses_earlier_mappings_without_llm_calls(): void
    {
        $this->mapper->budget = ['دائنون تجاريون' => 'الذمم الدائنة التجارية'];
        $this->mapper->notes  = ['موردين' => 'موردون', 'دفعات' => 'دفعات مقدمة من العملاء'];
        $first = $this->upload($this->file([
            $this->row('2101', 'دائنون تجاريون', 'موردين', 'مورد أ', 0, 10, 'شركة 1'),
            $this->row('2102', 'دائنون تجاريون', 'دفعات', 'عميل دفعة', 0, 10, 'شركة 1'),
        ]))->assertStatus(202)->json('id');
        // The accountant approves «موردين» only; «دفعات» stays unreviewed.
        $this->postJson("/api/v1/classification-cache/imports/{$first}/mappings/approve", ['ids' => ClassificationImportMapping::where('import_id', $first)
            ->whereIn('value', ['دائنون تجاريون', 'موردين'])->pluck('id')->all()])->assertOk();
        $calls = [count($this->mapper->budgetCalls), count($this->mapper->noteCalls)];

        $second = $this->upload($this->file([
            $this->row('9001', 'دائنون تجاريون', 'موردين', 'مورد آخر', 0, 50, 'شركة 2'),
            $this->row('9002', 'دائنون تجاريون', 'دفعات', 'عميل آخر', 0, 50, 'شركة 2'),
        ]))->assertStatus(202)->json('id');

        $this->assertSame($calls, [count($this->mapper->budgetCalls), count($this->mapper->noteCalls)]);   // no LLM call
        $m = ClassificationImportMapping::where('import_id', $second)->get()->keyBy('value');
        $this->assertSame(['reused', true], [$m['دائنون تجاريون']->method, $m['دائنون تجاريون']->reviewed]);
        $this->assertSame(['reused', true, 'موردون'], [$m['موردين']->method, $m['موردين']->reviewed, $m['موردين']->sub_class]);
        $this->assertSame(['llm', false, 'دفعات مقدمة من العملاء'], [$m['دفعات']->method, $m['دفعات']->reviewed, $m['دفعات']->sub_class]);   // copied, still to review
        $this->assertSame([true, false], ClassificationImportRow::where('import_id', $second)->orderBy('row')->pluck('approved')->all());
    }

    public function test_the_same_file_uploaded_twice_is_flagged(): void
    {
        $this->mapper->notes = ['موردين' => 'موردون'];
        $file = $this->file([$this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 1')]);
        $copy = fn () => new UploadedFile($file->getPathname(), 'نسخة.xlsx', null, null, true);
        $first = $this->upload($copy())->assertStatus(202)->json('id');

        $this->upload($copy())->assertStatus(409)->assertJsonPath('duplicate_of.id', $first)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'سبق رفعه'))->etc());
        $this->upload($copy(), ['force' => '1'])->assertStatus(202);
        $this->assertSame(2, ClassificationImport::count());

        // A failed import is no duplicate: the same file can be uploaded again to retry.
        ClassificationImport::query()->update(['status' => ClassificationImport::FAILED]);
        $this->upload($copy())->assertStatus(202);
    }

    public function test_a_file_without_the_required_columns_gets_a_clear_arabic_error(): void
    {
        $noBudget = $this->xlsxUpload([['رقم الحساب', 'اسم الحساب', 'الإيضاحات', 'أخر مدين', 'أخر دائن'], ['1101', 'الصندوق', 'نقدية', 10, 0]]);
        $this->upload($noBudget)->assertUnprocessable()
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, '«الميزانية»') && str_contains($m, 'غير موجود'))->etc());

        $noHeader = $this->xlsxUpload([['أ', 'ب'], [1, 2]]);
        $this->upload($noHeader)->assertUnprocessable()
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'صف العناوين'))->etc());

        $this->assertSame(0, ClassificationImport::count());   // nothing queued
    }

    public function test_employee_advances_are_classified_by_balance_side(): void
    {
        $budget = 'مصروفات مستحقةو ارصدة دائنة اخرى';   // matches the taxonomy: approved without review
        // [code, note, name, opening debit, opening credit, period debit, period credit, closing debit, closing credit]
        $rows = [
            ['1501', 'عهد وسلف موظفين', 'عهدة أحمد', 0, 0, 500, 0, 500, 0],        // R2 closing debit
            ['1502', 'عهد وسلف موظفين', 'أمانة محمد', 0, 0, 0, 300, 0, 300],       // R3 closing credit
            ['1503', 'ذمم موظفين', 'سلفة علي', 0, 0, 200, 0, 200, 0],              // R4 closing debit
            ['1504', 'عهد وسلف موظفين', 'أمانة مردودة', 0, 400, 400, 0, 0, 0],     // R5 closing zero → opening credit
            ['1505', 'عهد وسلف موظفين', 'عهدة سُوّيت', 0, 0, 900, 0, 0, 0],         // R6 closing and opening zero → movement debit
            ['1506', 'عهد وسلف موظفين', 'عهدة مقفولة', 0, 0, 0, 0, 0, 0],          // R7 all zero → not imported
        ];
        $id = $this->upload($this->file(array_map(fn ($r) => [$r[0], $budget, $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], null, null, $r[7], $r[8], 'شركة 1'], $rows)))->json('id');

        $rows = ClassificationImportRow::where('import_id', $id)->orderBy('row')->get();
        $this->assertSame(['عُهد العاملين', 'أمانات العاملين', 'سلف العاملين', 'أمانات العاملين', 'عُهد العاملين', null], $rows->pluck('sub_class')->all());
        $this->assertSame([null, null, null, null, null, 'no_side'], $rows->pluck('skip_reason')->all());
        $this->assertSame([true, true, true, true, true, false], $rows->pluck('approved')->all());
        $this->assertSame(['side_rule'], ClassificationImportMapping::where('kind', 'note')->pluck('method')->unique()->values()->all());
        $this->assertSame([], $this->mapper->noteCalls);   // decided by config, no LLM

        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $this->assertNull(ClassificationCacheEntry::where('original_name', 'عهدة مقفولة')->first());   // never cached without a side
        $this->assertSame(5, ClassificationCacheEntry::count());
    }

    public function test_apply_removes_only_its_own_imported_entries(): void
    {
        // In the cache already: a manual correction, an AI answer, and another import's entry.
        $cache = app(ClassificationCache::class);
        $make  = fn (string $code, string $name) => ClassificationKey::make($code, $name, 'ذمم دائنة تجارية', null);
        $cache->correct($make('2101', 'مورد أ'), '2101', 'مورد أ', 'ذمم دائنة تجارية', 'أوراق دفع');
        $entry = fn (string $code, string $name, string $source, ?int $import) => ClassificationCacheEntry::create([
            'key_hash' => ClassificationKey::hash($make($code, $name)), 'name_hash' => ClassificationKey::nameHash($name), 'normalized_key' => $make($code, $name),
            'original_name' => $name, 'account_code' => $code, 'budget_item' => 'ذمم دائنة تجارية', 'row_type' => 'account', 'main_class' => 'الذمم الدائنة التجارية',
            'sub_class' => 'أوراق دفع', 'source' => $source, 'confidence' => 95, 'taxonomy_version' => ClassificationCache::version(), 'import_id' => $import,
        ]);
        $entry('2102', 'مورد ب', ClassificationCache::AI, null);
        $entry('2109', 'مورد قديم', ClassificationCache::IMPORTED, 999);

        $this->mapper->notes = ['موردين' => 'موردون'];
        $id = $this->upload($this->file([
            $this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 1'),   // manual correction: kept
            $this->row('2102', 'ذمم دائنة تجارية', 'موردين', 'مورد ب', 0, 10, 'شركة 1'),   // AI answer: kept
            $this->row('2103', 'ذمم دائنة تجارية', 'موردين', 'مورد ج', 0, 10, 'شركة 1'),   // new
        ]))->json('id');
        $this->approveAll($id);
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
        $this->assertSame(1, ClassificationCacheEntry::where('import_id', $id)->count());

        // The accountant then skips the note: the re-apply removes this import's entry, and nothing else.
        $note = ClassificationImportMapping::where('import_id', $id)->where('kind', 'note')->sole();
        $this->patchJson("/api/v1/classification-cache/imports/mappings/{$note->id}", ['decision' => 'skip'])->assertOk();
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();

        $left = ClassificationCacheEntry::orderBy('original_name')->get()->map(fn ($e) => [$e->original_name, $e->source, $e->sub_class])->all();
        $this->assertSame([
            ['مورد أ', ClassificationCache::MANUAL, 'أوراق دفع'],
            ['مورد ب', ClassificationCache::AI, 'أوراق دفع'],
            ['مورد قديم', ClassificationCache::IMPORTED, 'أوراق دفع'],
        ], $left);
    }

    /* ---------- export / import ---------- */

    /** A manual correction, an AI answer and an applied import in the cache. */
    private function seedCache(): void
    {
        $cache = app(ClassificationCache::class);
        $cache->correct(ClassificationKey::make('0110', 'الصندوق', 'النقد ومافي حكمه', null), '0110', 'الصندوق', 'النقد ومافي حكمه', 'نقدية بالصندوق');
        $key = ClassificationKey::make('1102', 'بنك الراجحي', null, null);
        ClassificationCacheEntry::create([
            'key_hash' => ClassificationKey::hash($key), 'name_hash' => ClassificationKey::nameHash('بنك الراجحي'), 'normalized_key' => $key,
            'original_name' => 'بنك الراجحي', 'account_code' => '1102', 'row_type' => 'account', 'main_class' => 'النقد ومافي حكمه',
            'sub_class' => 'نقدية لدى البنوك', 'source' => ClassificationCache::AI, 'confidence' => 95, 'reason' => 'بنك', 'taxonomy_version' => ClassificationCache::version(),
        ]);
        $this->mapper->notes = ['موردين' => 'موردون'];
        $id = $this->upload($this->file([
            $this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 1'),
            $this->row('2101', 'ذمم دائنة تجارية', 'موردين', 'مورد أ', 0, 10, 'شركة 2'),
        ]))->json('id');
        $this->approveAll($id);
        $this->postJson("/api/v1/classification-cache/imports/{$id}/apply")->assertOk();
    }

    public function test_a_json_export_imported_into_an_empty_database_gives_the_same_entries(): void
    {
        $this->seedCache();
        $fields = ['key_hash', 'normalized_key', 'original_name', 'account_code', 'budget_item', 'row_type', 'main_class', 'sub_class', 'source', 'confidence', 'reason', 'taxonomy_version'];
        $before = ClassificationCacheEntry::orderBy('key_hash')->get()->map(fn ($e) => $e->only($fields))->all();
        $this->assertCount(3, $before);

        $json = $this->get('/api/v1/classification-cache/export.json')->assertOk()->streamedContent();
        $data = json_decode($json, true);
        $this->assertSame(['classification-cache-export', 1, ClassificationCache::version()], [$data['format'], $data['format_version'], $data['taxonomy_version']]);
        $this->assertSame(['ذمم دائنه تجاريه'], array_column($data['budget_mappings'], 'normalized'));
        $this->assertSame(['موردون'], array_column($data['note_mappings'], 'sub_class'));

        // Another instance: empty.
        foreach (['classification_cache', 'classification_import_rows', 'classification_import_mappings', 'classification_imports', 'classification_name_layer'] as $t) {
            \DB::table($t)->delete();
        }
        $path = tempnam(sys_get_temp_dir(), 'exp').'.json';
        file_put_contents($path, $json);
        $report = $this->post('/api/v1/classification-cache/import-export', ['file' => new UploadedFile($path, 'export.json', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->json();

        $this->assertSame([3, 0, 0], [$report['entries']['added'], $report['entries']['updated'], $report['entries']['skipped_unknown_sub_class_count']]);
        $this->assertSame($before, ClassificationCacheEntry::orderBy('key_hash')->get()->map(fn ($e) => $e->only($fields))->all());
        // The mappings came too: the next upload of the same values asks the LLM nothing.
        $calls = count($this->mapper->noteCalls) + count($this->mapper->budgetCalls);
        $this->upload($this->file([$this->row('7001', 'ذمم دائنة تجارية', 'موردين', 'مورد جديد', 0, 10, 'شركة 9')]))->assertStatus(202);
        $this->assertSame($calls, count($this->mapper->noteCalls) + count($this->mapper->budgetCalls));
    }

    public function test_an_export_keeps_side_locks_and_base_hash_and_converts_an_old_resolved_class_to_its_base(): void
    {
        $cache = app(ClassificationCache::class);
        $key   = fn (string $code, string $name) => ClassificationKey::make($code, $name, 'ذمم دائنة تجارية', null);
        $cache->correct($key('2101', 'مورد المعدات'), '2101', 'مورد المعدات', 'ذمم دائنة تجارية', 'دفعات مقدمة للموردين', 'credit');   // locked
        $json = json_decode($this->get('/api/v1/classification-cache/export.json')->assertOk()->streamedContent(), true);
        $this->assertSame([true, 'credit'], [$json['cache_entries'][0]['side_locked'], $json['cache_entries'][0]['side']]);

        // An export from before base classes: an AI entry holding the side-resolved member, no side_locked.
        $old = $json['cache_entries'][0];
        $json['cache_entries'][] = ['key_hash' => ClassificationKey::hash($k = $key('2102', 'مورد الخامات')), 'normalized_key' => $k, 'original_name' => 'مورد الخامات',
            'row_type' => 'account', 'sub_class' => 'دفعات مقدمة للموردين', 'source' => ClassificationCache::AI, 'confidence' => 95,
            'taxonomy_version' => ClassificationCache::version()] + array_diff_key($old, array_flip(['side', 'side_locked']));
        \DB::table('classification_cache')->delete();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.json';
        file_put_contents($path, json_encode($json));
        $this->post('/api/v1/classification-cache/import-export', ['file' => new UploadedFile($path, 'x.json', null, null, true)], ['Accept' => 'application/json'])->assertOk();

        $entries = ClassificationCacheEntry::get()->keyBy('original_name');
        $this->assertSame(['دفعات مقدمة للموردين', true], [$entries['مورد المعدات']->sub_class, $entries['مورد المعدات']->side_locked]);
        $this->assertSame(['موردون', false], [$entries['مورد الخامات']->sub_class, $entries['مورد الخامات']->side_locked]);
        $this->assertSame(ClassificationKey::baseHash($k), $entries['مورد الخامات']->base_hash);
    }

    public function test_importing_an_export_never_overwrites_a_manual_correction_and_skips_unknown_sub_classes(): void
    {
        $key = ClassificationKey::make('0110', 'الصندوق', 'النقد ومافي حكمه', null);
        app(ClassificationCache::class)->correct($key, '0110', 'الصندوق', 'النقد ومافي حكمه', 'نقدية بالصندوق');
        $entry = fn (string $k, string $name, ?string $sub, string $source) => [
            'key_hash' => ClassificationKey::hash($k), 'name_hash' => ClassificationKey::nameHash($name), 'normalized_key' => $k, 'original_name' => $name,
            'account_code' => null, 'budget_item' => null, 'row_type' => 'account', 'main_class' => null, 'sub_class' => $sub, 'source' => $source,
            'confidence' => 99, 'reason' => null, 'taxonomy_version' => ClassificationCache::version(), 'hit_count' => 0,
            'created_at' => now()->addDay()->toIso8601String(), 'updated_at' => now()->addDay()->toIso8601String(),
        ];
        $data = ['format' => 'classification-cache-export', 'format_version' => 1, 'taxonomy_version' => '1', 'cache_entries' => [
            $entry($key, 'الصندوق', 'نقدية لدى البنوك', ClassificationCache::MANUAL),               // newer manual: still never replaces ours
            $entry('name:حساب غريب|parent:', 'حساب غريب', 'تصنيف مش موجود', ClassificationCache::AI),
        ], 'budget_mappings' => [], 'note_mappings' => [], 'name_layer' => [
            ['side' => 'debit', 'normalized_name' => 'الصندوق', 'original_name' => 'الصندوق', 'sub_class' => 'نقدية بالصندوق', 'companies' => 3, 'share' => 100],
        ]];
        $path = tempnam(sys_get_temp_dir(), 'exp').'.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));

        $report = $this->post('/api/v1/classification-cache/import-export', ['file' => new UploadedFile($path, 'x.json', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->json();

        $this->assertSame([0, 1, 1], [$report['entries']['added'], $report['entries']['kept_manual'], $report['entries']['skipped_unknown_sub_class_count']]);
        $this->assertSame('تصنيف مش موجود', $report['entries']['skipped_unknown_sub_class'][0]['sub_class']);
        $this->assertSame('نقدية بالصندوق', ClassificationCacheEntry::sole()->sub_class);
        $this->assertSame('export', ClassificationNameLayerEntry::sole()->origin);
        // A rebuild from local rows keeps what the export brought.
        app(CacheImport::class)->rebuildNameLayer();
        $this->assertSame(1, ClassificationNameLayerEntry::count());

        file_put_contents($path, json_encode(['format' => 'شيء تاني']));
        $this->post('/api/v1/classification-cache/import-export', ['file' => new UploadedFile($path, 'x.json', null, null, true)], ['Accept' => 'application/json', 'Accept-Language' => 'ar'])
            ->assertUnprocessable()->assertJsonPath('message', 'هذا الملف ليس ملف تصدير لذاكرة التصنيف.');
    }

    public function test_the_excel_export_opens_with_arabic_columns_and_filters(): void
    {
        $this->seedCache();

        $response = $this->get('/api/v1/classification-cache/export.xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);
        $book  = IOFactory::load($path);
        $sheet = $book->getSheetByName('ذاكرة التصنيف');

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame(['اسم الحساب', 'كود الحساب', 'بند الميزانية', 'القائمة المالية', 'التصنيف الرئيسي', 'التصنيف الفرعي', 'المصدر', 'الثقة', 'مرات الاستخدام', 'آخر تعديل'],
            $sheet->rangeToArray('A1:J1')[0]);
        $rows = collect($sheet->rangeToArray('A2:J4'))->keyBy(0);
        $this->assertSame(['0110', 'قائمة المركز المالي', 'النقد ومافي حكمه', 'نقدية بالصندوق', 'تصحيح يدوي'],
            [$rows['الصندوق'][1], $rows['الصندوق'][3], $rows['الصندوق'][4], $rows['الصندوق'][5], $rows['الصندوق'][6]]);   // the code keeps its leading zero
        $this->assertSame('مستورد من ملفات المحاسبين', $rows['مورد أ'][6]);
        $this->assertNotNull($book->getSheetByName('طبقة الأسماء'));

        // Filter: manual corrections only.
        $response = $this->get('/api/v1/classification-cache/export.xlsx?source=manual_correction')->assertOk();
        copy($response->baseResponse->getFile()->getPathname(), $path);
        $this->assertSame(2, IOFactory::load($path)->getSheetByName('ذاكرة التصنيف')->getHighestDataRow());
        $this->getJson('/api/v1/classification-cache/export.json?from=2026-13-01')->assertUnprocessable();
    }

    public function test_both_pages_render_in_english_for_an_english_user(): void
    {
        $user = $this->admin();
        $user->locale = 'en';
        $this->actingAs($user);
        $import = ClassificationImport::create(['file_name' => 'ملف المحاسب.xlsx', 'status' => ClassificationImport::PENDING, 'kind' => ClassificationImport::FILE]);

        $this->get('/classification-cache')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)->assertSee('<title>Classification memory</title>', false)->assertSee('Import a classified file');
        $this->get("/classification-cache/imports/{$import->id}/review")->assertOk()
            ->assertSee('dir="ltr"', false)->assertSee("<title>Review of the imported file&#039;s mappings</title>", false)
            ->assertSee('«ملف المحاسب.xlsx»: approve how statement line items')   // the file name is data: as uploaded
            ->assertSee('Statement line items');

        // Every string exists in both languages (a missing English one would fall back to Arabic).
        $this->assertSame(array_keys(Arr::dot(Lang::get('cache_pages', [], 'ar'))), array_keys(Arr::dot(Lang::get('cache_pages', [], 'en'))));
    }
}
