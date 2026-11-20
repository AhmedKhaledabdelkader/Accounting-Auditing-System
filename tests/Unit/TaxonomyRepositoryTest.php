<?php

namespace Tests\Unit;

use App\Repositories\Eloquents\TaxonomyRepository;
use Tests\TestCase;

/**
 * The taxonomy as seeded (config list; the tables hold the same until the taxonomy page changes them, TaxonomyPageTest) —
 * these tests audit every entry. Without a database the repository reads the config list.
 */
class TaxonomyRepositoryTest extends TestCase
{
    private TaxonomyRepository $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxonomy = new TaxonomyRepository;
    }

    public function test_sub_classes_are_unique_and_complete(): void
    {
        $subs = $this->taxonomy->subClasses();

        $this->assertCount(158, $subs);   // 145 + periodic opening/closing stock + «مخصصات أخرى» (2) + 5 for the statements (v3) + 4 of the accountant's (2026-10-19)
        $this->assertSame($subs, array_values(array_unique($subs)));
    }

    public function test_every_entry_has_statement_main_class_and_valid_normal_side(): void
    {
        foreach ($this->taxonomy->entries() as $e) {
            $this->assertSame(['sub_class', 'main_class', 'statement', 'normal_side'], array_keys($e));
            $this->assertContains($e['statement'], ['قائمة المركز المالي', 'قائمة الدخل'], $e['sub_class']);
            $this->assertContains($e['normal_side'], ['debit', 'credit'], $e['sub_class']);
        }
    }

    public function test_every_main_class_has_a_family(): void
    {
        foreach (config('classification.taxonomy') as $mains) {
            foreach (array_keys($mains) as $main) {
                $this->assertNotNull($this->taxonomy->familyOf($main), "No family for main class: {$main}");
            }
        }
    }

    public function test_balance_sheet_main_classes_map_to_balance_sheet_families(): void
    {
        foreach (array_keys(config('classification.taxonomy.قائمة المركز المالي')) as $main) {
            $this->assertContains($this->taxonomy->familyOf($main), ['أصول', 'التزامات', 'حقوق الملكية'], $main);
        }
    }

    /** Non-contra entries follow their family; the listed contra entries are the only exceptions. */
    public function test_normal_side_matches_family_except_contra_accounts(): void
    {
        $contra = [
            'مخصص ديون مشكوك في تحصيلها', 'مخصص هبوط أسعار المخزون', 'مجمع إهلاك الأصول الثابتة',
            'مجمع إهلاك الأصول غير الملموسة', 'مردودات ومسموحات المبيعات', 'خصم مسموح به للعملاء',
            'خصم مكتسب', 'مردودات المشتريات', 'مخزون آخر المدة', 'مجمع إطفاء أصول أخرى',
        ];
        $familySide = ['أصول' => 'debit', 'تكلفة' => 'debit', 'مصروفات' => 'debit',
            'التزامات' => 'credit', 'حقوق الملكية' => 'credit', 'إيرادات' => 'credit'];

        foreach ($this->taxonomy->entries() as $e) {
            $side = $familySide[$this->taxonomy->familyOf($e['main_class'])];
            $expected = in_array($e['sub_class'], $contra, true) ? ($side === 'debit' ? 'credit' : 'debit') : $side;
            $this->assertSame($expected, $e['normal_side'], $e['sub_class']);
        }
    }

    public function test_audit_fixes(): void
    {
        $this->assertSame(['قائمة المركز المالي', 'الذمم المدينة التجارية', 'debit'], $this->taxonomy->find('أوراق قبض'));
        $this->assertSame(['قائمة المركز المالي', 'الذمم الدائنة التجارية', 'credit'], $this->taxonomy->find('أوراق دفع'));
        $this->assertSame(['قائمة المركز المالي', 'استثمارات', 'debit'], $this->taxonomy->find('استثمارات قصيرة الأجل'));
        $this->assertSame(['قائمة الدخل', 'تكلفة الإيرادات', 'credit'], $this->taxonomy->find('خصم مكتسب'));
        $this->assertSame(['قائمة الدخل', 'إيرادات النشاط', 'debit'], $this->taxonomy->find('خصم مسموح به للعملاء'));
    }

    public function test_new_entries(): void
    {
        $this->assertSame(['قائمة المركز المالي', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'debit'], $this->taxonomy->find('عُهد العاملين'));
        $this->assertNotNull($this->taxonomy->find('سلف العاملين'));
        $this->assertSame(['قائمة المركز المالي', 'المخزون', 'credit'], $this->taxonomy->find('مخصص هبوط أسعار المخزون'));
        $this->assertSame('التزامات', $this->taxonomy->familyOf($this->taxonomy->find('بطاقات ائتمان دائنة')[1]));
    }

    public function test_added_expense_categories(): void
    {
        foreach (['مصروف مكافأة نهاية الخدمة', 'مصروفات تعبئة وتغليف', 'إهلاك الأصول الثابتة', 'أدوات سلامة ودفاع مدني', 'غرامات ومخالفات', 'سكن العاملين'] as $sub) {
            [$statement, $main, $side] = $this->taxonomy->find($sub);
            $this->assertSame('قائمة الدخل', $statement, $sub);
            $this->assertSame('مصروفات', $this->taxonomy->familyOf($main), $sub);
            $this->assertSame('debit', $side, $sub);
        }
        // The expense is not the liability / the stock.
        $this->assertNotSame($this->taxonomy->find('مصروف مكافأة نهاية الخدمة')[1], $this->taxonomy->find('مخصص نهاية الخدمة')[1]);
        $this->assertNotSame($this->taxonomy->find('مصروفات تعبئة وتغليف')[1], $this->taxonomy->find('مخزون مواد تعبئة وتغليف')[1]);
    }

    public function test_main_classes_list(): void
    {
        $mains = $this->taxonomy->mainClasses();
        $this->assertSame($mains, array_values(array_unique($mains)));
        $this->assertContains('الممتلكات و الالات و المعدات', $mains);
        $this->assertCount(36, $mains);   // the expense natures are sub classes of their function's main class (admin, selling, finance, depreciation, other)
    }

    public function test_contra_accounts_sit_under_their_related_asset(): void
    {
        $this->assertSame('الممتلكات و الالات و المعدات', $this->taxonomy->find('مجمع إهلاك الأصول الثابتة')[1]);
        $this->assertSame('اصول غير ملموسة', $this->taxonomy->find('مجمع إهلاك الأصول غير الملموسة')[1]);
        $this->assertSame('الذمم المدينة التجارية', $this->taxonomy->find('مخصص ديون مشكوك في تحصيلها')[1]);
        $this->assertSame('المخزون', $this->taxonomy->find('مخصص هبوط أسعار المخزون')[1]);
        foreach (['مجمع إهلاك الأصول الثابتة', 'مخصص ديون مشكوك في تحصيلها', 'مخصص هبوط أسعار المخزون'] as $sub) {
            $this->assertSame('credit', $this->taxonomy->normalSide($sub));
        }
    }

    public function test_find_and_normal_side_for_unknown(): void
    {
        $this->assertSame(['قائمة الدخل', 'المصروفات الإدارية والعمومية', 'debit'], $this->taxonomy->find('إيجارات'));
        $this->assertNull($this->taxonomy->find('غير موجود'));
        $this->assertNull($this->taxonomy->normalSide('غير موجود'));
    }

    public function test_periodic_stock_and_other_provisions(): void
    {
        // Periodic inventory: opening and closing stock reduce or add to cost of sales, not the balance sheet.
        $this->assertSame(['قائمة الدخل', 'تكلفة الإيرادات', 'debit'], $this->taxonomy->find('مخزون أول المدة'));
        $this->assertSame(['قائمة الدخل', 'تكلفة الإيرادات', 'credit'], $this->taxonomy->find('مخزون آخر المدة'));
        // A warranty provision is a liability, not an inventory write-down.
        $this->assertSame(['قائمة المركز المالي', 'مخصصات أخرى', 'credit'], $this->taxonomy->find('مخصص ضمان'));
        $this->assertSame(['قائمة المركز المالي', 'مخصصات أخرى', 'credit'], $this->taxonomy->find('مخصصات أخرى'));
        $this->assertSame('التزامات', $this->taxonomy->familyOf('مخصصات أخرى'));
    }

    public function test_statement_sub_classes(): void
    {
        // Version 3: classes the statements present on their own lines (investment property, associates, dividends
        // payable, contract liabilities, impairment reversals), under existing main classes.
        $this->assertSame(['قائمة المركز المالي', 'استثمارات', 'debit'], $this->taxonomy->find('استثمارات عقارية'));
        $this->assertSame(['قائمة المركز المالي', 'استثمارات', 'debit'], $this->taxonomy->find('استثمارات في شركات زميلة'));
        $this->assertSame(['قائمة المركز المالي', 'مصروفات مستحقةو ارصدة دائنة اخرى', 'credit'], $this->taxonomy->find('توزيعات أرباح مستحقة'));
        $this->assertSame(['قائمة المركز المالي', 'مصروفات مستحقةو ارصدة دائنة اخرى', 'credit'], $this->taxonomy->find('إيرادات مؤجلة'));
        $this->assertSame(['قائمة الدخل', 'إيرادات أخرى', 'credit'], $this->taxonomy->find('رد خسائر الانخفاض'));
        $this->assertSame('3', config('classification.cache.taxonomy_version'));
    }

    public function test_current_names_resolve_to_themselves_and_unknown_ones_to_nothing(): void
    {
        $this->assertSame('عملاء', $this->taxonomy->resolveSub('عملاء'));
        $this->assertSame('المخزون', $this->taxonomy->resolveMain('المخزون'));
        $this->assertNull($this->taxonomy->resolveSub('غير موجود'));
        $this->assertNull($this->taxonomy->resolveSub(null));
        $this->assertNull($this->taxonomy->lineKeyOf('عملاء'));   // a built-in sub class follows the config rules
    }

    public function test_family_of_known_main_classes(): void
    {
        $this->assertSame('أصول', $this->taxonomy->familyOf('المخزون'));
        $this->assertSame('التزامات', $this->taxonomy->familyOf('الذمم الدائنة التجارية'));
        $this->assertSame('حقوق الملكية', $this->taxonomy->familyOf('رأس المال'));
        $this->assertSame('إيرادات', $this->taxonomy->familyOf('إيرادات النشاط'));
        $this->assertSame('تكلفة', $this->taxonomy->familyOf('تكلفة الإيرادات'));
        $this->assertSame('مصروفات', $this->taxonomy->familyOf('المصروفات البيعية والتوزيعية'));
        $this->assertNull($this->taxonomy->familyOf('غير موجود'));
    }
}
