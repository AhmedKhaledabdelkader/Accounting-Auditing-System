<?php

namespace Tests\Feature;

use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPdf\Checks;
use App\Services\Statements\PriorPdf\CompanyFacts;
use App\Services\Statements\PriorPdf\EquityBlock;
use App\Services\Statements\PriorPdf\FileFacts;
use App\Services\Statements\PriorPdf\PageExtractor;
use App\Services\Statements\PriorPdf\Partners;
use App\Services\Statements\PriorPdf\PriorPdfIssues;
use App\Services\Statements\PriorPdf\PriorPdfReader;
use App\Services\Statements\PriorPdf\RelatedParties;
use App\Services\Statements\PriorPdf\Tables;
use App\Services\Statements\PriorPdf\Wording;
use App\Services\Statements\PriorPpe;
use App\Services\Statements\ZakatBase;
use Tests\TestCase;

/**
 * Last year's PDF of a company reporting under the full IFRS, laid out otherwise again (P4, Step D): Arabic-Indic digits
 * everywhere, a balance sheet with a third column (the start of a restated year), «كما في <date>» balances instead of
 * «الرصيد», the changes in equity's rows naming the year before, rates printed as ranges, the partners by year with
 * their shares in their names, the related parties printed sideways, a zakat note with its movement only, a
 * first-time adoption note. A made-up company, made-up figures; the AI's answers made up.
 */
class PriorPdfFullIfrsLayoutTest extends TestCase
{
    private static function page(int $n, string $kind, array $blocks): array
    {
        return ['page' => $n, 'kind' => $kind, 'rotation' => 0, 'printed_page' => null, 'blocks' => PageExtractor::blocks(['blocks' => $blocks])];
    }

    private static function h(string $text, ?string $number = null): array
    {
        return ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
    }

    private static function p(string $text): array
    {
        return ['type' => 'paragraph', 'number' => null, 'text' => $text, 'columns' => [], 'rows' => []];
    }

    private static function t(array $headers, array $rows, ?string $title = null): array
    {
        return ['type' => 'table', 'number' => null, 'text' => $title, 'columns' => array_map(fn ($h) => ['header' => $h], $headers),
            'rows' => array_map(fn ($r) => ['label' => $r[0], 'note' => $r[2] ?? null, 'cells' => $r[1]], $rows)];
    }

    private static function doc(): array
    {
        return PriorPdfReader::process([
            1 => self::page(1, 'financial_position', [self::h('قائمة المركز المالي المنفصلة'), self::t(['٢٠٢٤/١٢/٣١م', '٢٠٢٣/١٢/٣١م', "(إيضاح ٥)\n٢٠٢٣/٠١/٠١م"], [
                ['الموجودات', [null, null, null]], ['ممتلكات ومعدات', ['١٠٠,٠٠٠', '٨٠,٠٠٠', '٦٠,٠٠٠'], '٤'], ['نقد وما في حكمه', ['٥٠,٠٠٠', '٤٠,٠٠٠', '٣٠,٠٠٠']],
                ['إجمالي الموجودات', ['١٥٠,٠٠٠', '١٢٠,٠٠٠', '٩٠,٠٠٠']],
                ['حقوق الملكية', [null, null, null]], ['رأس المال', ['١٠٠,٠٠٠', '١٠٠,٠٠٠', '١٠٠,٠٠٠'], '٦'], ['أرباح مبقاة', ['٣٠,٠٠٠', '١٠,٠٠٠', '(١٠,٠٠٠)']], ['إجمالي حقوق الملكية', ['١٣٠,٠٠٠', '١١٠,٠٠٠', '٩٠,٠٠٠']],
                ['المطلوبات', [null, null, null]], ['مطلوب إلى أطراف ذات علاقة', ['١٥,٠٠٠', '٦,٠٠٠', '-'], '٧'], ['زكاة مستحقة', ['٥,٠٠٠', '٤,٠٠٠', '-'], '٨'],
                ['إجمالي المطلوبات', ['٢٠,٠٠٠', '١٠,٠٠٠', '-']],
                ['إجمالي حقوق الملكية والمطلوبات', ['١٥٠,٠٠٠', '١٢٠,٠٠٠', '٩٠,٠٠٠']],
            ])]),
            2 => self::page(2, 'cash_flows', [self::h('قائمة التدفقات النقدية المنفصلة'), self::t(['٢٠٢٤', '٢٠٢٣'], [
                ['الربح قبل الزكاة', ['٢٠,٠٠٠', '٢٠,٠٠٠']], ['شراء ممتلكات ومعدات', ['(٢٠,٠٠٠)', '(٢٠,٠٠٠)']], ['مطلوب إلى أطراف ذات علاقة', ['١٠,٠٠٠', '١٠,٠٠٠']],
                ['صافي الزيادة في النقد', ['١٠,٠٠٠', '١٠,٠٠٠']], ['النقد في بداية السنة', ['٤٠,٠٠٠', '٣٠,٠٠٠']], ['النقد في نهاية السنة', ['٥٠,٠٠٠', '٤٠,٠٠٠']],
                ['معاملات غير نقدية', [null, null]], ['استثمار مقابل مستحق لطرف ذي علاقة', ['٥,٠٠٠', '-']],
            ])]),
            3 => self::page(3, 'changes_in_equity', [self::h('قائمة التغيرات في حقوق الملكية المنفصلة'), self::t(['رأس المال', 'أرباح مبقاة', 'إجمالي'], [
                ['كما في 1 يناير 2022', ['100,000', '(10,000)', '90,000']], ['الربح بعد الزكاة', ['-', '20,000', '20,000']], ['كما في 31 ديسمبر 2022', ['100,000', '10,000', '110,000']],
                ['الربح بعد الزكاة', ['-', '25,000', '25,000']], ['الخسارة الشاملة الأخرى للسنة', ['-', '(5,000)', '(5,000)']], ['كما في 31 ديسمبر 2023', ['100,000', '30,000', '130,000']],
            ])]),
            4 => self::page(4, 'notes', [
                self::h('الوضع القانوني', '1'), self::p('إن شركة الأفق للتجارة ("الشركة")، هي شركة ذات مسؤولية محدودة تعمل بموجب السجل التجاري رقم ١٠١٠١٢٣٤٥٦.'),
                self::h('بيان الالتزام', '2'), self::p('تم إعداد القوائم المالية المنفصلة وفقًا للمعايير الدولية للتقرير المالي المعتمدة في المملكة العربية السعودية.'),
                self::h('ملخص السياسات المحاسبية', '3'), self::p('يتم احتساب الاستهلاك بطريقة القسط الثابت بالنسب التالية:'),
                self::t([''], [['تحسينات على مباني مستأجرة', ['٪20 - ٪10']], ['أجهزة حاسب آلي', ['٪25']]]),
                self::h('ممتلكات ومعدات', '4'), self::t(['تحسينات على مباني مستأجرة', 'أجهزة حاسب آلي', 'إجمالي'], [
                    ['تكلفة', [null, null, null]], ['كما في ١ يناير ٢٠٢٤', ['١٠٠,٠٠٠', '٥٠,٠٠٠', '١٥٠,٠٠٠']], ['اضافات', ['٣٠,٠٠٠', '١٠,٠٠٠', '٤٠,٠٠٠']],
                    ['كما في ٣١ ديسمبر ٢٠٢٤', ['١٣٠,٠٠٠', '٦٠,٠٠٠', '١٩٠,٠٠٠']],
                    ['استهلاك متراكم', [null, null, null]], ['كما في ١ يناير ٢٠٢٤', ['٤٠,٠٠٠', '٣٠,٠٠٠', '٧٠,٠٠٠']], ['استهلاك للسنة', ['١٠,٠٠٠', '١٠,٠٠٠', '٢٠,٠٠٠']],
                    ['كما في ٣١ ديسمبر ٢٠٢٤', ['٥٠,٠٠٠', '٤٠,٠٠٠', '٩٠,٠٠٠']],
                    ['القيمة الدفترية', [null, null, null]], ['كما في ٣١ ديسمبر ٢٠٢٤', ['٨٠,٠٠٠', '٢٠,٠٠٠', '١٠٠,٠٠٠']], ['كما في ٣١ ديسمبر ٢٠٢٣', ['٦٠,٠٠٠', '٢٠,٠٠٠', '٨٠,٠٠٠']],
                ]),
                self::h('تبني المعايير الدولية للتقرير المالي لأول مرة', '5'), self::p('أعدت الشركة قوائمها حتى السنة المنتهية في 2023 وفقًا للمعيار الدولي للتقرير المالي للمنشآت الصغيرة والمتوسطة الحجم.'),
                self::h('رأس المال', '6'), self::p('يبلغ رأس مال الشركة ١٠٠,٠٠٠ ريال سعودي ويتكون من ١٠,٠٠٠ حصة بقيمة ١٠ ريالات سعودية للحصة الواحدة.'),
                self::t(['٢٠٢٣ / المبلغ', '٢٠٢٣ / النسبة', '٢٠٢٤ / المبلغ', '٢٠٢٤ / النسبة'], [
                    ['شركة النخبة القابضة (٩,٠٠٠ حصة)', ['-', '-', '٩٠,٠٠٠', '%٩٠']], ['سالم عبدالله الحربي (١,٠٠٠ حصة) سعودي', ['-', '-', '١٠,٠٠٠', '٪١٠']],
                    ['فهد ناصر القحطاني (٥,٠٠٠ حصة) سعودي', ['٥٠,٠٠٠', '%٥٠', '-', '-']], ['ماجد ناصر القحطاني (٥,٠٠٠ حصة) سعودي', ['٥٠,٠٠٠', '%٥٠', '-', '-']],
                    ['', ['١٠٠,٠٠٠', '%١٠٠', '١٠٠,٠٠٠', '%١٠٠']],
                ]),
                self::h('معاملات مع الأطراف ذات علاقة', '7'), self::h('مطلوب الى أطراف ذات علاقة', '7.1'),
                self::t(['شركة النخبة القابضة', 'شركة رواسي للتقنية', 'كما في 1 يناير', 'صافي أرصدة المطلوب الى أطراف ذات علاقة'], [
                    ['كما في 31 ديسمبر 2023', ['5,000', '5,000', '-', '10,000']], ['كما في 31 ديسمبر 2024', ['12,000', '8,000', '-', '20,000']], ['2024', ['7,000', '3,000', null, null]],
                    ['طبيعة المعاملات', ['تمويل', 'تشغيل', null, null]], ['العلاقة', ['شريك', 'شقيقة', null, null]],
                ]),
                self::h('الزكاة', '8'), self::t(['٣١ ديسمبر ٢٠٢٤', '٣١ ديسمبر ٢٠٢٣'], [
                    ['الرصيد الافتتاحي', ['٣,٠٠٠', '٢,٠٠٠']], ['مخصصات محملة للسنة', ['٤,٠٠٠', '٣,٠٠٠']], ['دفعات خلال السنة', ['(٣,٠٠٠)', '(٢,٠٠٠)']], ['', ['٤,٠٠٠', '٣,٠٠٠']],
                ]),
            ]),
        ]);
    }

    private static function file(): array
    {
        return ['kind' => 'pdf', 'document' => self::doc()];
    }

    public function test_a_balance_sheet_with_a_third_column_and_arabic_indic_digits_is_read_and_checked(): void
    {
        $doc = self::doc();
        $sofp = $doc['statements']['financial_position'];

        // The first of January stands for the year before's end: ranked last, the two years in use unharmed.
        $this->assertSame(['roles' => ['current', 'comparative', null], 'printed' => [2024, 2023, 2022], 'by' => 'years'], $sofp['roles']);
        $this->assertSame(['current' => 2024, 'comparative' => 2023], array_intersect_key($doc['years'], ['current' => 1, 'comparative' => 1]));
        $this->assertEquals([100000, 80000, 60000], array_column($sofp['rows'][1]['cells'], 'amount'));
        // The checks hold on the digits as read; a third column is no year typo.
        $failing = array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok'] && in_array($c['code'], ['statement_total', 'balance', 'cash_roll'], true)));
        $this->assertSame([], $failing);
        $this->assertSame([], array_filter($doc['flags'], fn ($f) => $f['code'] === 'year_typo' && ($f['page'] ?? 0) === 1));
    }

    public function test_a_cash_flow_ends_on_its_closing_cash_whatever_is_printed_after_it(): void
    {
        $rows = self::doc()['statements']['cash_flows']['rows'];

        $this->assertSame(5, Checks::cashEnd(array_values(array_filter($rows, fn ($r) => $r['kind'] !== 'heading'))));
        $this->assertSame([], array_values(array_filter(Checks::statement('cash_flows', $rows), fn ($c) => ! $c['ok'])));
    }

    public function test_a_total_misread_in_one_of_three_columns_is_still_a_total_and_said(): void
    {
        $cell = fn (?string $raw) => PageExtractor::cell($raw);
        $row = fn (string $label, array $cells) => ['label' => $label, 'note' => null, 'cells' => array_map($cell, $cells)];
        $rows = Tables::totals([$row('أ', ['10', '20', '30']), $row('ب', ['5', '5', '5']), $row('الإجمالي', ['15', '28', '35'])]);
        $this->assertSame('total', $rows[2]['kind']);
        $this->assertSame([1 => 25.0], $rows[2]['off']);

        // Two columns: both must add. Dashes under dashes say nothing (two rows with the same figures are no total).
        $this->assertSame('line', Tables::totals([$row('أ', ['10', '20']), $row('ب', ['5', '5']), $row('الإجمالي', ['15', '28'])])[2]['kind']);
        $this->assertSame('line', Tables::totals([$row('أ', ['-', '-', '25,000']), $row('ب', ['-', '-', '12,500'])])[1]['kind']);
    }

    public function test_cells_of_percentages_ranges_and_words_with_digits_are_not_unread_amounts(): void
    {
        $this->assertSame(['range', null], [PageExtractor::cell('٪20 - ٪10')['status'], PageExtractor::cell('٪20 - ٪10')['amount']]);
        $this->assertSame(['percent', 2.5], [PageExtractor::cell('٪٢,٥')['status'], PageExtractor::cell('٪٢,٥')['amount']]);
        $this->assertSame(['percent', 90.0], [PageExtractor::cell('%٩٠')['status'], PageExtractor::cell('%٩٠')['amount']]);
        $this->assertSame('text', PageExtractor::cell('المعيار الدولي للتقرير المالي (17) عقود التأمين')['status']);
        $this->assertSame(['ok', 450000.0], [PageExtractor::cell('٤٥٠,٠٠٠')['status'], PageExtractor::cell('٤٥٠,٠٠٠')['amount']]);
        $this->assertSame('unread', PageExtractor::cell('0..,...')['status']);
        $this->assertSame([], array_filter(self::doc()['checks'], fn ($c) => $c['code'] === 'unread'));
    }

    public function test_ppe_balances_by_date_and_rates_printed_as_ranges(): void
    {
        $categories = PriorPpe::categories(self::file());

        $this->assertSame(['تحسينات على مباني مستأجرة', 'أجهزة حاسب آلي'], array_column($categories, 'label'));
        // A range of rates: the life from 100 / the higher to 100 / the lower; a single rate: its life.
        $this->assertSame([[10.0, 20.0], [5.0, 10.0], null, null], [$categories[0]['rate_range'], $categories[0]['life_range'], $categories[0]['rate'], $categories[0]['life']]);
        $this->assertSame([25.0, 4.0, null], [$categories[1]['rate'], $categories[1]['life'], $categories[1]['life_range']]);
        $this->assertEquals(['cost_opening' => 100000, 'additions' => 30000, 'cost_closing' => 130000, 'acc_opening' => 40000, 'depreciation' => 10000, 'acc_closing' => 50000,
            'nbv_closing' => 80000, 'nbv_opening' => 60000], array_intersect_key($categories[0]['last'], array_flip(['cost_opening', 'additions', 'cost_closing', 'acc_opening',
            'depreciation', 'acc_closing', 'nbv_closing', 'nbv_opening'])));
        // The notes' useful-life table prints a range as such.
        $years = new \ReflectionMethod(\App\Services\Statements\NotesBuilder::class, 'years');
        $this->assertSame(['5 - 10', '4', null], [$years->invoke(null, null, [5.0, 10.0]), $years->invoke(null, 4.0, null), $years->invoke(null, null, null)]);
        // Balances side by side under «القيمة الدفترية» don't roll: no movement gap.
        $this->assertSame([], array_values(array_filter(self::doc()['checks'], fn ($c) => $c['code'] === 'movement' && ! $c['ok'])));
        $this->assertTrue(Tables::isBalance('كما في ١ يناير ٢٠٢٤'));
        $this->assertFalse(Tables::isBalance('اضافات'));
    }

    public function test_the_partners_are_the_latest_years_with_their_shares_in_their_names(): void
    {
        $partners = Partners::read(self::file());

        $this->assertEquals([['شركة النخبة القابضة', 9000, 10, 90000], ['سالم عبدالله الحربي', 1000, 10, 10000]], array_map(fn ($r) => array_values($r), $partners['rows']));
        $this->assertSame([2024, 'read', true], [$partners['year'], $partners['status'], $partners['sum_ok']]);
        $this->assertSame(['فهد ناصر القحطاني', 'ماجد ناصر القحطاني'], $partners['former']);
    }

    public function test_related_parties_printed_sideways(): void
    {
        $parties = RelatedParties::read(self::file())['parties'];

        $this->assertSame([['شركة النخبة القابضة', 'شريك', 'تمويل', 7000.0, 12000.0, 'due_to'], ['شركة رواسي للتقنية', 'شقيقة', 'تشغيل', 3000.0, 8000.0, 'due_to']],
            array_map(fn ($p) => [$p['name'], $p['relationship'], $p['nature'], $p['volume_py'], $p['balance_py'], $p['side']], $parties));
    }

    public function test_the_company_the_framework_and_what_the_accountant_should_know(): void
    {
        $doc = self::doc();
        $facts = CompanyFacts::of($doc);

        // The name without the sentence around it; the framework from the statement of compliance, not the transition note.
        $this->assertSame(['شركة الأفق للتجارة', '1010123456', 'ifrs'], [$facts['name_ar']['value'], $facts['cr_number']['value'], $facts['framework']['value']]);
        $flags = array_column(FileFacts::flags(self::file()), null, 'code');
        $this->assertSame(['framework_full_ifrs', 'year_typo_rows', 'transition_note', 'partners_changed'], array_keys($flags));
        $this->assertSame(['changes_in_equity', [2023, 2022], [2024, 2023]], [$flags['year_typo_rows']['statement'], $flags['year_typo_rows']['printed'], $flags['year_typo_rows']['used']]);
        $this->assertSame('5', $flags['transition_note']['note']);
        // Each said to the accountant in words, with its page.
        app()->setLocale('ar');
        $issues = array_column(PriorPdfIssues::of(['flags' => array_values($flags)] + $doc), 'message', 'code');
        $this->assertStringContainsString('نموذج المنشآت الصغيرة ومتوسطة الحجم', $issues['framework_full_ifrs']);
        $this->assertStringContainsString('لن يُنقل إلى السنة الجديدة', $issues['transition_note']);
        $this->assertStringContainsString('فهد ناصر القحطاني', $issues['partners_changed']);
    }

    public function test_the_changes_in_equity_by_their_dated_rows_and_wording(): void
    {
        $block = EquityBlock::of(self::doc(), new LineCatalogue);

        // The block closing on the latest date (its year printed one behind), its rows placed by their words.
        $this->assertEquals(['opening' => ['share_capital' => 100000, 'retained_earnings' => 10000], 'net_profit' => ['retained_earnings' => 25000],
            'oci' => ['retained_earnings' => -5000]], $block['rows']);
        $this->assertSame([], $block['unplaced_rows']);
    }

    public function test_a_zakat_note_with_its_movement_only_has_no_base_to_carry(): void
    {
        $this->assertNull(ZakatBase::of(self::file(), [], [], [], [], new LineCatalogue));
        $zakat = collect(self::doc()['sections'])->firstWhere('printed', '8');
        $this->assertTrue(self::doc()['tables'][$zakat['tables'][0]]['movement']);
    }

    public function test_names_are_never_corrected_and_figures_are_no_wording(): void
    {
        $doc = self::doc();
        $wording = Wording::check($doc, ['رواسب' => true, 'تقنيه' => true], [], [], Wording::names(self::file()));

        $this->assertSame([], array_values(array_filter($wording['corrections'], fn ($c) => str_contains($c['title'], 'رواسي'))));
        $this->assertSame([], array_values(array_filter($wording['flags'], fn ($f) => $f['code'] === 'wording_differs' && preg_match('/\d|[٠-٩]/u', $f['printed']))));
        $this->assertContains('شركة رواسي للتقنية', Wording::names(self::file()));
    }
}
