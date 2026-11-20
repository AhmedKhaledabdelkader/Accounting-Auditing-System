<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The income statement's expense classes by FUNCTION (the accountant's tabs): تكلفة الإيرادات / المصروفات الإدارية والعمومية / المصروفات البيعية
 * والتوزيعية / استهلاك ممتلكات وآلات ومعدات / تكاليف التمويل / مصروفات أخرى. Until now each nature (rent, electricity…) was a main class of its own
 * with one sub class of the same name, so the two columns repeated each other. The old main classes become sub classes' homes:
 *   - every sub class moves to its function's main class (its own name, side, line and rules are untouched: the function each old main had in
 *     config/financial_statements.php `main_classes` is the function of the new one, so no statement figure changes);
 *   - the cost main class is renamed (it keeps its sub classes);
 *   - an old main name stays as a former name (taxonomy_aliases) of the main class it went to, so runs and files that carry it still resolve;
 *   - what is stored for reuse under an old main name (the classification memory, accountant-import mappings) follows.
 * A database seeded from the current config (a new one) already has the new structure: nothing to do. Not reversible.
 */
return new class extends Migration
{
    private const COST = 'تكلفة الإيرادات';
    private const ADMIN = 'المصروفات الإدارية والعمومية';
    private const SELLING = 'المصروفات البيعية والتوزيعية';
    private const OTHER = 'مصروفات أخرى';
    private const FINANCE = 'تكاليف التمويل';
    private const DEPR = 'استهلاك ممتلكات وآلات ومعدات';

    /** @return array<string, string> old main class → the main class it joins */
    private function moves(): array
    {
        $moves = [];
        foreach (['اجور و رواتب و مافي حكمها', 'سكن العاملين', 'مصروفات تعبئة وتغليف', 'مصروفات السلامة', 'تأمينات اجتماعية', 'مكافآت وحوافز العاملين', 'إيجارات',
            'كهرباء ومياه وغاز', 'اتصالات وإنترنت', 'صيانة وإصلاحات', 'وقود ومحروقات', 'نقل وانتقالات', 'مهمات وأدوات مكتبية', 'سفر وإقامة', 'تدريب وتأهيل',
            'مصروفات بريد وشحن', 'مصروفات تأمين', 'كهرباء ومياه المقر الإداري', 'أتعاب مهنية واستشارات', 'أتعاب مراجعة ومحاسبة', 'مصروفات قانونية',
            'قرطاسية ومطبوعات', 'ضيافة واستقبال', 'اشتراكات وبرامج', 'مصروفات بنكية', 'رسوم وتراخيص'] as $m) {
            $moves[$m] = self::ADMIN;
        }
        foreach (['دعاية وإعلان', 'تسويق ومبيعات', 'عمولات بيع'] as $m) {
            $moves[$m] = self::SELLING;
        }
        foreach (['غرامات ومخالفات', 'مصروفات متنوعة'] as $m) {
            $moves[$m] = self::OTHER;
        }
        foreach (['فوائد قروض', 'فوائد تسهيلات ائتمانية', 'عمولات تمويلية', 'مصروفات تمويلية'] as $m) {
            $moves[$m] = self::FINANCE;
        }
        foreach (['إهلاك الأصول الثابتة', 'إهلاك المباني', 'إهلاك الآلات والمعدات', 'إهلاك السيارات', 'إهلاك الأثاث والتجهيزات', 'إهلاك أجهزة الكمبيوتر'] as $m) {
            $moves[$m] = self::DEPR;
        }

        return $moves;
    }

    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_main_classes') || DB::table('taxonomy_main_classes')->where('name', self::ADMIN)->exists()) {
            return;
        }
        $now = now();
        DB::transaction(function () use ($now) {
            $statement = 'قائمة الدخل';
            // The cost main class: renamed in place.
            $cost = DB::table('taxonomy_main_classes')->where('name', 'تكلفة البضاعة المباعة')->where('statement', $statement)->first();
            if ($cost) {
                DB::table('taxonomy_main_classes')->where('id', $cost->id)->update(['name' => self::COST, 'origin_name' => self::COST, 'updated_at' => $now]);
                $this->alias('main', 'تكلفة البضاعة المباعة', $cost->id, $now);
                DB::table('classification_cache')->where('main_class', 'تكلفة البضاعة المباعة')->update(['main_class' => self::COST]);
                $this->mappings('تكلفة البضاعة المباعة', self::COST);
            }
            $ids = [];
            foreach ($this->moves() as $old => $new) {
                $oldRow = DB::table('taxonomy_main_classes')->where('name', $old)->where('statement', $statement)->first();
                if (! $oldRow) {
                    continue;
                }
                $ids[$new] ??= DB::table('taxonomy_main_classes')->where('name', $new)->value('id') ?? DB::table('taxonomy_main_classes')->insertGetId([
                    'statement' => $statement, 'name' => $new, 'family' => 'expenses', 'sort' => $oldRow->sort, 'origin_name' => $new, 'created_at' => $now, 'updated_at' => $now]);
                $subs = DB::table('taxonomy_sub_classes')->where('main_class_id', $oldRow->id)->pluck('name')->all();
                DB::table('taxonomy_sub_classes')->where('main_class_id', $oldRow->id)->update(['main_class_id' => $ids[$new], 'updated_at' => $now]);
                // What the memory and the imports hold under the old main name follows its sub classes.
                $subs && DB::table('classification_cache')->where('main_class', $old)->whereIn('sub_class', $subs)->update(['main_class' => $new]);
                DB::table('classification_cache')->where('main_class', $old)->update(['main_class' => $new]);
                $this->mappings($old, $new);
                // The old name, and every former name it already carried, now belong to the new main class.
                DB::table('taxonomy_aliases')->where('main_class_id', $oldRow->id)->update(['main_class_id' => $ids[$new], 'updated_at' => $now]);
                $this->alias('main', $old, $ids[$new], $now);
                DB::table('taxonomy_main_classes')->where('id', $oldRow->id)->delete();
            }
        });
    }

    private function alias(string $kind, string $name, int $mainId, $now): void
    {
        $exists = DB::table('taxonomy_aliases')->where('kind', $kind)->where('name', $name)->exists();
        $exists ? DB::table('taxonomy_aliases')->where('kind', $kind)->where('name', $name)->update(['main_class_id' => $mainId, 'updated_at' => $now])
            : DB::table('taxonomy_aliases')->insert(['kind' => $kind, 'name' => $name, 'main_class_id' => $mainId, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function mappings(string $old, string $new): void
    {
        if (Schema::hasTable('classification_import_mappings')) {
            DB::table('classification_import_mappings')->where('main_class', $old)->update(['main_class' => $new]);
            DB::table('classification_import_mappings')->where('parent', $old)->update(['parent' => $new]);
        }
    }

    public function down(): void
    {
        // Not reversible: the old names stay as former names of the new main classes.
    }
};
