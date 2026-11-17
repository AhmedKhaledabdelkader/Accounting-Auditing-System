<?php

namespace Tests\Feature;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping;
use App\Models\ClassificationNameLayerEntry;
use App\Models\ClassificationRun;
use App\Models\Role;
use App\Models\TaxonomyMainClass;
use App\Models\TaxonomySubClass;
use App\Models\User;
use App\Repositories\Eloquents\AccountClassifierRepository;
use App\Repositories\Eloquents\TaxonomyMapperRepository;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use App\Repositories\Eloquents\TaxonomyRepository;
use App\Services\ClassificationCache;
use App\Services\Statements\CurrentYearStatements;
use App\Support\ClassificationKey;
use App\Support\Taxonomy\TaxonomyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * The taxonomy page (docs/16-taxonomy.md): the categories from their tables, adding a main and a sub class (with its
 * statement line), renaming (everything that stores or names it follows; old runs still build statements), deleting only
 * what nothing uses, and the permissions.
 */
class TaxonomyPageTest extends TestCase
{
    use ClassifiedRows;
    use RefreshDatabase;

    private const SOFP = 'قائمة المركز المالي';

    private function taxonomy(): TaxonomyRepository
    {
        return app(TaxonomyRepository::class);
    }

    private function main(string $name): TaxonomyMainClass
    {
        return TaxonomyMainClass::where('name', $name)->firstOrFail();
    }

    private function sub(string $name): TaxonomySubClass
    {
        return TaxonomySubClass::where('name', $name)->firstOrFail();
    }

    /** The statements of a run with these rows (a first year, no OCI, zakat confirmed zero). */
    private function build(array $rows): array
    {
        $run = (new ClassificationRun)->forceFill(['status' => 'done', 'result' => $this->runResult($rows)]);

        return app(CurrentYearStatements::class)->build($run, 'sa-ifrs-smes-3',
            ['inputs' => ['oci.has_items' => false, 'zakat.zero_reason' => 'لا وعاء زكوي'], 'prior' => ['source' => 'first_year']]);
    }

    private function line(array $built, int $row): string
    {
        return (string) array_key_first($built['mapping']['rows'][$row]['lines']['closing']);
    }

    /** A row as a run stores it, under any name (an old run keeps the names it was classified with). */
    private function storedRow(int $row, string $main, string $sub, float $closing): array
    {
        return array_merge($this->account($row, 'نقدية بالصندوق', ['closing' => $closing]), ['main_class' => $main, 'sub_class' => $sub]);
    }

    private function storeRun(array $rows): ClassificationRun
    {
        return ClassificationRun::create(['status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows)]);
    }

    private function memory(string $sub, string $name = 'حساب'): ClassificationCacheEntry
    {
        $key = ClassificationKey::hash("code:1|name:{$name}");

        return ClassificationCacheEntry::forceCreate(['key_hash' => $key, 'name_hash' => sha1($name), 'normalized_key' => "code:1|name:{$name}",
            'original_name' => $name, 'row_type' => 'account', 'main_class' => $this->taxonomy()->find($sub)[1], 'sub_class' => $sub,
            'source' => 'manual_correction', 'confidence' => 100, 'taxonomy_version' => '3']);
    }

    private function user(array $permissions, string $locale = 'ar'): User
    {
        $role = Role::create(['name_ar' => 'محاسب', 'name_en' => 'Accountant']);
        $role->syncPermissions($permissions);

        return User::create(['name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE, 'locale' => $locale]);
    }

    public function test_the_tables_hold_the_config_taxonomy(): void
    {
        $this->assertTrue($this->taxonomy()->fromDatabase());
        $config = [];
        foreach (config('classification.taxonomy') as $statement => $mains) {
            foreach ($mains as $main => $subs) {
                foreach ($subs as $sub => $side) {
                    $config[$sub] = [$statement, $main, $side];
                }
                $this->assertSame(TaxonomyRepository::FAMILIES[TaxonomyRepository::configFamily($statement, $main)], $this->taxonomy()->familyOf($main));
            }
        }
        $this->assertSame($config, $this->taxonomy()->map());
        $this->assertCount(72, $this->taxonomy()->mainClasses());
    }

    public function test_the_page_renders_in_arabic_and_in_english_without_codes(): void
    {
        $html = $this->get('/taxonomy')->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee(['دليل التصنيفات', self::SOFP, 'قائمة الدخل', 'النقد ومافي حكمه', 'نقدية بالصندوق', 'حسب القواعد المضمّنة', 'مدين', 'دائن', 'تصنيف رئيسي جديد'])
            ->getContent();
        $this->assertStringContainsString('href="/taxonomy"', $html);   // the side menu's link

        $user = $this->admin();
        $user->locale = 'en';
        $en = $this->actingAs($user)->get('/taxonomy')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee(['Classification taxonomy', 'By the built-in rules', 'Debit', 'Credit', 'New main class', 'Assets', 'نقدية بالصندوق'])
            ->getContent();

        // What the reader sees (text, placeholders) has no code: no line key, no family code, no raw side.
        foreach ([$html, $en] as $page) {
            $text = preg_replace('/<script\b.*?<\/script>|<style\b.*?<\/style>|<[^>]+>/s', ' ', $page);
            $this->assertDoesNotMatchRegularExpression('/\b(sofp|is)\.[a-z_]+|\b(debit|credit|assets|liabilities|equity|expenses|revenue)\b/', $text);
        }
    }

    public function test_both_languages_have_the_same_keys(): void
    {
        foreach (['taxonomy_page', 'permissions'] as $file) {
            $this->assertSame(array_keys(Arr::dot(require lang_path("ar/{$file}.php"))), array_keys(Arr::dot(require lang_path("en/{$file}.php"))), $file);
        }
    }

    public function test_a_new_main_and_sub_class_reach_the_ai_and_the_statements_on_the_chosen_line(): void
    {
        $this->post('/taxonomy/main-classes', ['statement' => self::SOFP, 'name' => 'أصول بيولوجية', 'family' => 'assets'])
            ->assertRedirect('/taxonomy?tab=0')->assertSessionHasNoErrors();
        $main = $this->main('أصول بيولوجية');
        $this->assertNull($main->origin_name);
        $this->post("/taxonomy/main-classes/{$main->id}/sub-classes", ['name' => 'نباتات منتجة', 'normal_side' => 'debit', 'line_key' => 'sofp.prepayments_other_receivables'])
            ->assertSessionHasNoErrors();

        $this->assertSame([self::SOFP, 'أصول بيولوجية', 'debit'], $this->taxonomy()->find('نباتات منتجة'));
        $this->assertSame('أصول', $this->taxonomy()->familyOf('أصول بيولوجية'));
        $this->assertContains('نباتات منتجة', $this->taxonomy()->subClasses());

        // The AI's allowed list and main classes.
        $repo = app(AccountClassifierRepository::class);
        $call = (new ReflectionMethod($repo, 'classifyPrompt'))->invoke($repo, ['columns' => []], [], [], [], [], null);
        $this->assertContains('نباتات منتجة', $call->tool['schema']['properties']['rows']['items']['properties']['sub_class']['enum']);

        // A report puts the account on the chosen line, and its note lists it by its sub class.
        $built = $this->build([
            $this->account(2, 'نباتات منتجة', ['closing' => 400]),
            $this->account(3, 'رأس المال', ['closing' => -400]),
        ]);
        $this->assertSame('sofp.prepayments_other_receivables', $this->line($built, 2));
        $this->assertSame([], $built['mapping']['unmapped']);
        $this->assertContains('نباتات منتجة', array_column($built['breakdowns']['other_receivables']['rows'], 'key'));

        $this->get('/taxonomy')->assertSee(['أصول بيولوجية', 'نباتات منتجة', 'مصاريف مقدمة وأرصدة مدينة أخرى']);
    }

    public function test_a_new_sub_class_needs_a_line_of_its_statement_and_a_unique_name(): void
    {
        $main = $this->main('إيرادات أخرى');
        $this->post("/taxonomy/main-classes/{$main->id}/sub-classes", ['name' => 'إيراد جديد', 'normal_side' => 'credit'])->assertSessionHasErrors('line_key');
        $this->post("/taxonomy/main-classes/{$main->id}/sub-classes", ['name' => 'إيراد جديد', 'normal_side' => 'credit', 'line_key' => 'sofp.cash'])->assertSessionHasErrors('line_key');
        $this->post("/taxonomy/main-classes/{$main->id}/sub-classes", ['name' => 'عملاء', 'normal_side' => 'credit', 'line_key' => 'is.other_income'])->assertSessionHasErrors('name');
        $this->post('/taxonomy/main-classes', ['statement' => 'قائمة الدخل', 'name' => 'جديد', 'family' => 'assets'])->assertSessionHasErrors('family');

        $errors = session('errors')->getBag('default');
        $this->assertSame('القيمة المختارة في المجموعة غير صحيحة.', $errors->first('family'));
        $user = $this->admin();
        $user->locale = 'en';
        $this->actingAs($user)->post("/taxonomy/main-classes/{$main->id}/sub-classes", ['name' => 'x', 'normal_side' => 'credit'])
            ->assertSessionHasErrors(['line_key' => 'The statement line field is required.']);
    }

    public function test_a_renamed_sub_class_follows_everywhere_and_old_runs_still_build(): void
    {
        $memory = $this->memory('استثمارات عقارية');
        $import = ClassificationImport::create(['file_name' => 'a.xlsx', 'status' => ClassificationImport::APPLIED]);
        $mapping = ClassificationImportMapping::create(['import_id' => $import->id, 'kind' => 'note', 'value' => 'عقار', 'normalized' => 'عقار',
            'parent' => 'استثمارات', 'decision' => 'map', 'main_class' => 'استثمارات', 'sub_class' => 'استثمارات عقارية', 'method' => 'llm', 'confidence' => 90, 'rows' => 1]);
        $layer = ClassificationNameLayerEntry::create(['name_hash' => sha1('عقار'), 'side' => 'debit', 'normalized_name' => 'عقار', 'original_name' => 'عقار',
            'sub_class' => 'استثمارات عقارية', 'companies' => 2, 'share' => 100, 'origin' => 'rows']);
        $old = $this->storeRun([$this->storedRow(2, 'استثمارات', 'استثمارات عقارية', 300), $this->account(3, 'رأس المال', ['closing' => -300])]);

        $sub = $this->sub('استثمارات عقارية');
        $this->put("/taxonomy/sub-classes/{$sub->id}", ['name' => 'عقارات استثمارية محتفظ بها', 'normal_side' => 'debit'])->assertSessionHasNoErrors();

        $new = 'عقارات استثمارية محتفظ بها';
        $this->assertNull($this->taxonomy()->find('استثمارات عقارية'));
        $this->assertSame([self::SOFP, 'استثمارات', 'debit'], $this->taxonomy()->find($new));
        $this->assertSame($new, $this->taxonomy()->resolveSub('استثمارات عقارية'));
        $this->assertSame($new, $memory->fresh()->sub_class);
        $this->assertSame($new, $mapping->fresh()->sub_class);
        $this->assertSame($new, $layer->fresh()->sub_class);
        $this->assertSame('sofp.investment_property', config('financial_statements.templates.sa-ifrs-smes-3.sub_classes')[$new]);

        // An account of the renamed class still lands on its line; the old run (stored with the old name) too.
        $this->assertSame('sofp.investment_property', $this->line($this->build([$this->account(2, $new, ['closing' => 300]), $this->account(3, 'رأس المال', ['closing' => -300])]), 2));
        $this->assertSame('استثمارات عقارية', $old->fresh()->result['data'][0]['sub_class']);   // the record keeps its name
        $this->assertSame('sofp.investment_property', $this->line(app(CurrentYearStatements::class)->build($old->fresh(), 'sa-ifrs-smes-3',
            ['inputs' => ['oci.has_items' => false, 'zakat.zero_reason' => 'لا وعاء زكوي'], 'prior' => ['source' => 'first_year']]), 2));

        // The AI's hints name it by its new name; the page shows the former one.
        $this->assertStringContainsString('"'.$new.'"', app(AccountClassifierRepository::class)->classifyRules());
        $this->assertStringNotContainsString('"استثمارات عقارية"', app(AccountClassifierRepository::class)->classifyRules());
        $this->get('/taxonomy')->assertSee(['أسماء سابقة: استثمارات عقارية', $new]);

        // Back to its own former name: allowed; another category's name or former name: refused.
        $this->put("/taxonomy/sub-classes/{$sub->id}", ['name' => 'عملاء', 'normal_side' => 'debit'])->assertSessionHasErrors('name');
        $this->put("/taxonomy/sub-classes/{$sub->id}", ['name' => 'استثمارات عقارية', 'normal_side' => 'debit'])->assertSessionHasNoErrors();
        $this->assertSame('استثمارات عقارية', $memory->fresh()->sub_class);
        $other = $this->sub('شهرة');
        $this->put("/taxonomy/sub-classes/{$other->id}", ['name' => $new, 'normal_side' => 'debit'])->assertSessionHasErrors('name');
    }

    public function test_a_renamed_main_class_keeps_its_rules_and_its_old_runs(): void
    {
        $memory = $this->memory('نقدية لدى البنوك');
        $old = $this->storeRun([$this->storedRow(2, 'النقد ومافي حكمه', 'نقدية لدى البنوك', -50), $this->account(3, 'رأس المال', ['closing' => 50])]);
        $main = $this->main('النقد ومافي حكمه');
        $this->put("/taxonomy/main-classes/{$main->id}", ['name' => 'النقدية وما في حكمها', 'family' => 'liabilities'])->assertSessionHasNoErrors();

        $this->assertSame('النقدية وما في حكمها', $memory->fresh()->main_class);
        $this->assertSame('assets', $main->fresh()->family);   // a built-in main class keeps its family
        $this->assertSame([self::SOFP, 'النقدية وما في حكمها', 'debit'], $this->taxonomy()->find('نقدية لدى البنوك'));

        // The main-class rule (cash) and the side rule (a credit balance is an overdraft) follow the new name.
        $built = $this->build([$this->account(2, 'نقدية لدى البنوك', ['closing' => 70]), $this->account(4, 'نقدية بالصندوق', ['closing' => -20]), $this->account(3, 'رأس المال', ['closing' => -50])]);
        $this->assertSame('sofp.cash', $this->line($built, 2));
        $this->assertSame('sofp.bank_overdraft', $this->line($built, 4));
        $this->assertSame('sofp.bank_overdraft', $this->line(app(CurrentYearStatements::class)->build($old->fresh(), 'sa-ifrs-smes-3',
            ['inputs' => ['oci.has_items' => false, 'zakat.zero_reason' => 'لا وعاء زكوي'], 'prior' => ['source' => 'first_year']]), 2));
        $this->assertSame('النقدية وما في حكمها', $this->taxonomy()->resolveMain('النقد ومافي حكمه'));

        // A main class and a sub class may share a name: renaming the main one leaves the sub one's rules alone.
        $rent = $this->main('إيجارات');
        $this->put("/taxonomy/main-classes/{$rent->id}", ['name' => 'مصروفات الإيجار'])->assertSessionHasNoErrors();
        $rules = config('financial_statements.templates.sa-ifrs-smes-3');
        $this->assertSame(['function' => 'general_admin'], $rules['main_classes']['مصروفات الإيجار']);
        $this->assertArrayNotHasKey('إيجارات', $rules['main_classes']);
        $this->assertSame(['قائمة الدخل', 'مصروفات الإيجار', 'debit'], $this->taxonomy()->find('إيجارات'));
    }

    public function test_the_import_mappers_examples_use_the_current_names(): void
    {
        $this->put('/taxonomy/sub-classes/'.$this->sub('إيجارات')->id, ['name' => 'إيجار', 'normal_side' => 'debit'])->assertSessionHasNoErrors();
        $driver = new class implements ClassifierDriver
        {
            public ?ToolCall $call = null;

            public function classify(ToolCall $call): PromiseInterface
            {
                $this->call = $call;

                return Create::promiseFor(new ToolAnswer(['mappings' => []], null));
            }

        };
        $this->app->instance(ClassifierDriver::class, $driver);
        app(TaxonomyMapperRepository::class)->mapNotes([1 => ['budget' => 'x', 'note' => 'y', 'names' => ['z'], 'hint_main' => null]], $this->taxonomy()->map());

        $this->assertStringContainsString('rent → إيجار,', $driver->call->system[0]['text']);
        $this->assertStringContainsString('- إيجارات: إيجار | إيجار المقر الإداري', $driver->call->system[0]['text']);   // the tree: the main class kept its name
    }

    public function test_a_renamed_side_pair_member_still_flips(): void
    {
        $sub = $this->sub('موردون');
        $this->put("/taxonomy/sub-classes/{$sub->id}", ['name' => 'موردون تجاريون', 'normal_side' => 'credit'])->assertSessionHasNoErrors();

        $this->assertSame(['موردون تجاريون'], config('classification.side_pairs.suppliers.credit'));
        $this->assertSame('موردون تجاريون', ClassificationCache::baseClass('دفعات مقدمة للموردين', 'مورد'));
        $this->assertSame('موردون تجاريون', ClassificationCache::baseClass('موردون تجاريون', 'مورد'));

        // Rules keyed by sub-class names follow too (the employees' pair's by_name).
        $advance = $this->sub('سلف العاملين');
        $this->put("/taxonomy/sub-classes/{$advance->id}", ['name' => 'سلف الموظفين', 'normal_side' => 'debit'])->assertSessionHasNoErrors();
        $this->assertSame(['سلف الموظفين'], array_keys(config('classification.side_pairs.employees.by_name.debit')));
        $this->assertContains('سلف الموظفين', config('classification.side_pairs.employees.debit'));
    }

    public function test_a_delete_is_refused_while_anything_uses_the_category(): void
    {
        $main = $this->main('اطراف ذات علاقة مدينة');
        $this->post("/taxonomy/main-classes/{$main->id}/sub-classes", ['name' => 'مستحق من شركة شقيقة', 'normal_side' => 'debit', 'line_key' => 'sofp.due_from_related_parties']);
        $added = $this->sub('مستحق من شركة شقيقة');
        $refused = fn (TaxonomySubClass|TaxonomyMainClass $c, string $use) => $this
            ->delete($c instanceof TaxonomySubClass ? "/taxonomy/sub-classes/{$c->id}" : "/taxonomy/main-classes/{$c->id}")
            ->assertSessionHasErrors(['delete' => __('taxonomy_page.in_use_'.($c instanceof TaxonomySubClass ? 'sub' : 'main'), ['name' => $c->name, 'uses' => $use])]);

        $entry = $this->memory('مستحق من شركة شقيقة');
        $refused($added, 'ذاكرة التصنيف (1 إدخال)');
        $entry->delete();

        $layer = ClassificationNameLayerEntry::create(['name_hash' => sha1('ش'), 'side' => 'debit', 'normalized_name' => 'ش', 'original_name' => 'ش',
            'sub_class' => 'مستحق من شركة شقيقة', 'companies' => 2, 'share' => 100, 'origin' => 'rows']);
        $refused($added, 'طبقة الأسماء في الذاكرة (1 اسم)');
        $layer->delete();

        $import = ClassificationImport::create(['file_name' => 'a.xlsx', 'status' => ClassificationImport::APPLIED]);
        $mapping = ClassificationImportMapping::create(['import_id' => $import->id, 'kind' => 'note', 'value' => 'ش', 'normalized' => 'ش',
            'parent' => 'x', 'decision' => 'map', 'function_sub_class' => 'مستحق من شركة شقيقة', 'method' => 'llm', 'confidence' => 90, 'rows' => 1]);
        $refused($added, 'ربط ملفات المحاسبين المستوردة (1)');
        $mapping->delete();

        // A run classified with it, even under a former name.
        $this->put("/taxonomy/sub-classes/{$added->id}", ['name' => 'مستحق من شركة زميلة', 'normal_side' => 'debit', 'line_key' => 'sofp.due_from_related_parties']);
        $run = $this->storeRun([$this->storedRow(2, 'اطراف ذات علاقة مدينة', 'مستحق من شركة شقيقة', 10)]);
        $refused($added->fresh(), 'نتائج تصنيف سابقة (1 ملف)');
        $run->delete();

        // A rule names it (config, the AI's hints); a main class with sub classes.
        $refused($this->sub('استثمارات عقارية'), 'قواعد سطور القوائم المالية، قواعد استيراد ملفات المحاسبين، تعليمات النظام');
        $refused($this->sub('مكيفات'), 'جداول حركة الإيضاحات');
        $refused($this->sub('عملاء'), 'قواعد التبويب (1 قاعدة)، التصنيفات التي تنعكس حسب اتجاه الرصيد');
        $refused($main, 'تصنيفات فرعية تحته (2)، قواعد سطور القوائم المالية، قواعد الإيضاحات');

        // Unused: deleted, then its empty main class too.
        $this->delete("/taxonomy/sub-classes/{$added->id}")->assertSessionHasNoErrors();
        $this->assertNull($this->taxonomy()->find('مستحق من شركة زميلة'));
        $this->assertNull($this->taxonomy()->resolveSub('مستحق من شركة شقيقة'));   // its former names went with it
        $this->post('/taxonomy/main-classes', ['statement' => self::SOFP, 'name' => 'مؤقت', 'family' => 'equity']);
        $this->delete('/taxonomy/main-classes/'.$this->main('مؤقت')->id)->assertSessionHasNoErrors();
        $this->assertSame(0, TaxonomyMainClass::where('name', 'مؤقت')->count());

        // A built-in sub class nothing uses can go too.
        $this->delete('/taxonomy/sub-classes/'.$this->sub('نقدية بالصندوق')->id)->assertSessionHasNoErrors();
        $this->assertNull($this->taxonomy()->find('نقدية بالصندوق'));
    }

    public function test_view_only_sees_the_page_but_cannot_change_it(): void
    {
        $this->actingAs($this->user(['taxonomy.view']));
        $this->get('/taxonomy')->assertOk()->assertSee(['النقد ومافي حكمه', 'صلاحيتك العرض فقط.'])->assertDontSee('تصنيف رئيسي جديد');
        $main = $this->main('المخزون');
        $this->post('/taxonomy/main-classes', ['statement' => self::SOFP, 'name' => 'x', 'family' => 'assets'])->assertForbidden();
        $this->put("/taxonomy/main-classes/{$main->id}", ['name' => 'x'])->assertForbidden();
        $this->delete("/taxonomy/main-classes/{$main->id}")->assertForbidden();
        $this->post("/taxonomy/main-classes/{$main->id}/sub-classes", [])->assertForbidden();
        $sub = $this->sub('مخزون بضاعة');
        $this->put("/taxonomy/sub-classes/{$sub->id}", ['name' => 'x', 'normal_side' => 'debit'])->assertForbidden();
        $this->delete("/taxonomy/sub-classes/{$sub->id}")->assertForbidden();
        $this->assertSame('مخزون بضاعة', $sub->fresh()->name);
    }

    public function test_without_the_view_permission_the_page_is_forbidden_and_not_in_the_menu(): void
    {
        $this->actingAs($this->user(['reports.view']));
        $this->get('/taxonomy')->assertForbidden();
        $this->get('/financial-reports')->assertOk()->assertDontSee('href="/taxonomy"', false);
    }

    public function test_the_rules_are_untouched_until_something_changes(): void
    {
        $before = [config('financial_statements'), config('classification')];
        app(TaxonomyRules::class)->apply(reload: true);
        $this->assertSame($before, [config('financial_statements'), config('classification')]);
        $this->assertSame([], app(TaxonomyRules::class)->usesOf('sub', 'نقدية بالصندوق', 'نقدية بالصندوق'));
        $this->assertSame(['statement_rules', 'import_rules'], app(TaxonomyRules::class)->usesOf('main', 'تكلفة البضاعة المباعة', 'تكلفة البضاعة المباعة'));
        $this->assertSame(1, DB::table('taxonomy_main_classes')->where('name', 'تكلفة البضاعة المباعة')->count());
    }
}
