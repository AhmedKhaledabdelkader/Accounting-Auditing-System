<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A database that still has the old expense classes (one main class per nature) is regrouped by function without losing anything. */
class ExpenseGroupingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function oldStructure(): void
    {
        DB::table('classification_cache')->delete();
        DB::table('taxonomy_aliases')->delete();
        DB::table('taxonomy_sub_classes')->delete();
        DB::table('taxonomy_main_classes')->delete();
        $now = now();
        foreach ([['تكلفة البضاعة المباعة', 'cost', ['تكلفة البضاعة المباعة', 'تكلفة المشتريات']], ['إيجارات', 'expenses', ['إيجارات', 'إيجار المقر الإداري']], ['كهرباء ومياه وغاز', 'expenses', ['كهرباء ومياه وغاز']],
            ['دعاية وإعلان', 'expenses', ['دعاية وإعلان']], ['فوائد قروض', 'expenses', ['فوائد قروض']], ['مصروفات تمويلية', 'expenses', ['خسائر فروق عملة']],
            ['إهلاك المباني', 'expenses', ['إهلاك المباني']], ['مصروف الزكاة الشرعية', 'expenses', ['مصروف الزكاة الشرعية']]] as $i => [$main, $family, $subs]) {
            $id = DB::table('taxonomy_main_classes')->insertGetId(['statement' => 'قائمة الدخل', 'name' => $main, 'family' => $family, 'sort' => $i * 10, 'origin_name' => $main, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($subs as $sub) {
                DB::table('taxonomy_sub_classes')->insert(['main_class_id' => $id, 'name' => $sub, 'normal_side' => 'debit', 'sort' => 1, 'origin_name' => $sub, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        DB::table('taxonomy_aliases')->insert(['kind' => 'main', 'name' => 'إيجار قديم', 'main_class_id' => DB::table('taxonomy_main_classes')->where('name', 'إيجارات')->value('id'), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('classification_cache')->insert(['key_hash' => sha1('a'), 'name_hash' => sha1('a'), 'normalized_key' => 'a', 'original_name' => 'إيجار مكتب', 'row_type' => 'account', 'main_class' => 'إيجارات',
            'sub_class' => 'إيجارات', 'source' => 'ai', 'confidence' => 95, 'taxonomy_version' => '3', 'created_at' => $now, 'updated_at' => $now]);
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_11_19_000001_group_income_statement_expenses.php'))->up();
    }

    public function test_the_old_mains_become_the_sub_classes_homes_and_nothing_is_lost(): void
    {
        $this->oldStructure();

        $this->migrate();

        $mains = DB::table('taxonomy_main_classes')->orderBy('sort')->pluck('family', 'name')->all();
        $this->assertSame(['تكلفة الإيرادات' => 'cost', 'المصروفات الإدارية والعمومية' => 'expenses', 'المصروفات البيعية والتوزيعية' => 'expenses', 'تكاليف التمويل' => 'expenses',
            'استهلاك ممتلكات وآلات ومعدات' => 'expenses', 'مصروف الزكاة الشرعية' => 'expenses'], $mains);
        $of = fn (string $sub) => DB::table('taxonomy_sub_classes')->join('taxonomy_main_classes', 'taxonomy_main_classes.id', '=', 'main_class_id')->where('taxonomy_sub_classes.name', $sub)->value('taxonomy_main_classes.name');
        $this->assertSame('المصروفات الإدارية والعمومية', $of('إيجار المقر الإداري'));
        $this->assertSame('المصروفات الإدارية والعمومية', $of('كهرباء ومياه وغاز'));
        $this->assertSame('المصروفات البيعية والتوزيعية', $of('دعاية وإعلان'));
        $this->assertSame('تكاليف التمويل', $of('فوائد قروض'));
        $this->assertSame('تكاليف التمويل', $of('خسائر فروق عملة'));              // from the old «مصروفات تمويلية»
        $this->assertSame('استهلاك ممتلكات وآلات ومعدات', $of('إهلاك المباني'));
        $this->assertSame('تكلفة الإيرادات', $of('تكلفة المشتريات'));                // the cost main class was renamed, it kept its subs
        $this->assertSame('مصروف الزكاة الشرعية', $of('مصروف الزكاة الشرعية'));      // a line of its own: left as it is
        $this->assertSame(10, DB::table('taxonomy_sub_classes')->count());          // every sub class is still there

        // The old names still resolve (to the main class they went to), the old alias moved with its main class, the memory follows.
        $alias = fn (string $name) => DB::table('taxonomy_aliases')->join('taxonomy_main_classes', 'taxonomy_main_classes.id', '=', 'taxonomy_aliases.main_class_id')->where('kind', 'main')->where('taxonomy_aliases.name', $name)->value('taxonomy_main_classes.name');
        $this->assertSame('المصروفات الإدارية والعمومية', $alias('إيجارات'));
        $this->assertSame('المصروفات الإدارية والعمومية', $alias('إيجار قديم'));
        $this->assertSame('تكلفة الإيرادات', $alias('تكلفة البضاعة المباعة'));
        $this->assertSame('المصروفات الإدارية والعمومية', DB::table('classification_cache')->value('main_class'));
        $this->assertSame('المصروفات الإدارية والعمومية', app(\App\Repositories\Eloquents\TaxonomyRepository::class)->resolveMain('إيجارات'));
    }

    public function test_it_is_a_no_op_on_a_database_that_already_has_the_new_structure_and_runs_once(): void
    {
        $before = DB::table('taxonomy_main_classes')->count();
        $this->migrate();   // seeded from the current config: nothing to do
        $this->assertSame($before, DB::table('taxonomy_main_classes')->count());

        $this->oldStructure();
        $this->migrate();
        $after = [DB::table('taxonomy_main_classes')->count(), DB::table('taxonomy_sub_classes')->count(), DB::table('taxonomy_aliases')->count()];
        $this->migrate();
        $this->assertSame($after, [DB::table('taxonomy_main_classes')->count(), DB::table('taxonomy_sub_classes')->count(), DB::table('taxonomy_aliases')->count()]);
    }
}
