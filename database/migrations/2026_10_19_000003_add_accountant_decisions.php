<?php

use App\Repositories\Eloquents\TaxonomyRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The accountant's answers of 2026-10-19 (docs/17-classification-rules.md). The taxonomy's new classes (already in
// config('classification.taxonomy'), so a fresh install has them from the taxonomy's migration): «أصول أخرى» with its
// accumulated amortisation (sofp.other_assets), «عُهد وسُلف العاملين», and «عُهد وسُلف دائنه» (added in the app before:
// now built in). Then the rules: the classes decided, «تسهيلات موردين» dropped (not a kind of its own), the partners'
// current account left out of «مدير / شريك».
return new class extends Migration
{
    private const SOFP = 'قائمة المركز المالي';

    public function up(): void
    {
        $now = now();
        $main = fn (string $origin) => DB::table('taxonomy_main_classes')->where('origin_name', $origin)->value('id');
        $sub = function (string $name, string $side, ?int $mainId) use ($now) {
            $existing = DB::table('taxonomy_sub_classes')->where('name', $name)->orWhere('origin_name', $name)->first();
            if ($existing) {
                // Added in the app before: built in from now on, its line from its main class's rule.
                DB::table('taxonomy_sub_classes')->where('id', $existing->id)->whereNull('origin_name')->update(['origin_name' => $name, 'line_key' => null, 'updated_at' => $now]);

                return;
            }
            if ($mainId !== null) {
                DB::table('taxonomy_sub_classes')->insert(['main_class_id' => $mainId, 'name' => $name, 'normal_side' => $side,
                    'sort' => (int) DB::table('taxonomy_sub_classes')->max('sort') + 1, 'origin_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        };

        if (DB::getSchemaBuilder()->hasTable('taxonomy_main_classes')) {
            if (! $main('أصول أخرى')) {
                DB::table('taxonomy_main_classes')->insert(['statement' => self::SOFP, 'name' => 'أصول أخرى',
                    'family' => TaxonomyRepository::configFamily(self::SOFP, 'أصول أخرى'), 'sort' => (int) DB::table('taxonomy_main_classes')->max('sort') + 1,
                    'origin_name' => 'أصول أخرى', 'created_at' => $now, 'updated_at' => $now]);
            }
            $sub('أصول أخرى', 'debit', $main('أصول أخرى'));
            $sub('مجمع إطفاء أصول أخرى', 'credit', $main('أصول أخرى'));
            $sub('عُهد وسُلف العاملين', 'debit', $main('مصروفات مدفوعة مقدما و ارصدة مدينة اخرى'));
            $sub('عُهد وسُلف دائنه', 'credit', $main('مصروفات مستحقةو ارصدة دائنة اخرى'));
        }

        $rule = fn (string $name, array $values) => DB::table('classification_rules')->where('name', $name)
            ->update(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $values) + ['updated_at' => $now]);
        DB::table('classification_rules')->where('name', 'تسهيلات موردين')->delete();
        $rule('عهدة', ['debit_class' => 'عُهد وسُلف العاملين', 'credit_class' => 'عُهد وسُلف دائنه',
            'ai_classes' => ['عُهد العاملين', 'عُهد وسُلف العاملين']]);
        $rule('موظف (حساب شخصي)', ['debit_class' => 'عُهد وسُلف العاملين',
            'ai_classes' => ['سلف العاملين', 'عُهد العاملين', 'أمانات العاملين', 'عُهد وسُلف العاملين', 'عُهد وسُلف دائنه', 'رواتب وأجور مستحقة']]);
        $rule('جاري التأمينات الاجتماعية', ['debit_class' => 'حسابات مدينة لدى الجهات الحكومية', 'review_side' => 'none']);
        // Other assets on the wrong side are flagged, not moved: they have no counterpart.
        $rule('مصروفات ما قبل التشغيل', ['debit_class' => 'أصول أخرى', 'review_side' => 'credit']);
        $rule('مجمع إطفاء ما قبل التشغيل', ['credit_class' => 'مجمع إطفاء أصول أخرى', 'review_side' => 'debit']);
        $rule('مدير / شريك', ['exclude_words' => ['جاري الشريك', 'جاري الشركاء', 'جاري شريك', 'جاري شركاء']]);
    }

    public function down(): void
    {
        // Data decisions: not undone.
    }
};
