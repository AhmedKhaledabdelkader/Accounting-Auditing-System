<?php

namespace Tests\Feature;

use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorNoteItems;
use App\Services\Statements\PriorPdf\CompanyFacts;
use App\Services\Statements\PriorPdf\EquityBlock;
use App\Services\Statements\PriorPdf\PageExtractor;
use App\Services\Statements\PriorPdf\Partners;
use App\Services\Statements\PriorPdf\PriorPdfReader;
use App\Services\Statements\PriorPdf\Totals;
use App\Services\Statements\PriorPdf\Wording;
use App\Services\Statements\PriorPpe;
use Tests\TestCase;

/**
 * Last year's PDF laid out otherwise than the reference file (P4: a generic product): notes in another order and
 * numbering, the company note not first, sub-notes numbered with a slash, a note without a comparative column, a
 * related-parties note, a share value of its own, PPE categories printed as rows on a portrait page (any number of
 * them, the cost and the depreciation as one table or two), a total misprinted, the changes in equity as two tables in
 * different column orders. The AI's answers are made up; PHP must place each by what it reads, never by position.
 */
class PriorPdfLayoutsTest extends TestCase
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

    /** @param string $ppe2023 the balance sheet's PPE at the end of 2023 */
    private static function balanceSheet(string $ppe2023 = '290,000'): array
    {
        $assets2023 = number_format((float) str_replace(',', '', $ppe2023) + 40000);

        return self::page(1, 'financial_position', [self::h('قائمة المركز المالي'), self::t(['31 ديسمبر 2024م', '31 ديسمبر 2023م'], [
            ['ممتلكات ومعدات', ['300,000', $ppe2023], '9'], ['نقد لدى البنوك', ['50,000', '40,000'], '7'], ['إجمالي الأصول', ['350,000', $assets2023]],
            ['رأس المال', ['200,000', '200,000'], '11'], ['مستحق لأطراف ذات علاقة', ['150,000', number_format((float) str_replace(',', '', $assets2023) - 200000)], '8'],
            ['إجمالي المطلوبات وحقوق الملكية', ['350,000', $assets2023]],
        ])]);
    }

    /**
     * The PPE note printed with its categories as rows: three categories, the depreciation total misprinted by 1.
     *
     * @param array<string, array<int, string>> $set a row's printed cells changed: label → column → cell
     */
    private static function ppeAsRows(array $set = []): array
    {
        $rows = [
            'أراضي' => ['100,000', '-', '100,000', '-', '-', '-', '100,000', '100,000'],
            'معدات ثقيلة' => ['200,000', '50,000', '250,000', '60,000', '25,000', '85,000', '165,000', '140,000'],
            'سيارات' => ['80,000', '-', '80,000', '30,000', '15,000', '45,000', '35,000', '50,000'],
            'الإجمالي' => ['380,000', '50,000', '430,000', '90,000', '40,000', '130,001', '300,000', '290,000'],
        ];
        foreach ($set as $label => $cells) {
            $rows[$label] = array_replace($rows[$label], $cells);
        }

        return [self::h('ممتلكات ومعدات', '9'), self::t([
            'التكلفة / الرصيد في 1 يناير 2024', 'إضافات', 'الرصيد في 31 ديسمبر 2024',
            'مجمع الإهلاك / الرصيد في 1 يناير 2024', 'إهلاك السنة', 'الرصيد في 31 ديسمبر 2024',
            'صافي القيمة الدفترية في 31 ديسمبر 2024', 'صافي القيمة الدفترية في 31 ديسمبر 2023',
        ], array_map(fn ($label, $cells) => [$label, $cells], array_keys($rows), $rows))];
    }

    /** Each year end's balances where a printed total differs: [year, status, its options (cost, accumulated, net), the one used]. */
    private static function yearEnds(array $doc, array $choices = []): array
    {
        return array_map(fn ($g) => [$g['year'], $g['status'], array_map(fn ($o) => [$o['cost'], $o['accumulated'], $o['nbv']], $g['options']), Totals::option($g, $choices)],
            $doc['total_groups']);
    }

    /** Notes in their own order and numbering: the company note third, sub-notes «4/1», note 9 before note 7. */
    private static function notes(array $ppe): array
    {
        return self::page(2, 'notes', [
            self::h('شركة النخيل للمقاولات'), self::h('إيضاحات حول القوائم المالية'),
            self::h('نبذة عن الشركة', '3'),
            self::p('شركة النخيل للمقاولات شركة ذات مسؤولية محدودة مسجلة بموجب السجل التجاري رقم 1010999999 الصادر من مدينة أبها بتاريخ 12/ 4/ 1442 هـ.'),
            self::h('السياسات المحاسبية الهامة', '4'), self::h('العرف المحاسبي', '4/1'), self::p('تعد القوائم المالية وفقاً لمبدأ التكلفة التاريخية.'),
            self::h('الممتلكات والمعدات', '4/2'), self::p('تستهلك الممتلكات والمعدات بطريقة القسط الثابت بالنسب التالية:'), self::t(['النسبة %'], [['أراضي', ['-']], ['معدات ثقيلة', ['15']], ['سيارات', ['25']]]),
            ...$ppe,
            // This year's column only, its header the currency.
            self::h('النقد لدى البنوك', '7'), self::t(['ريال سعودي'], [['نقد بالصندوق', ['20,000']], ['نقد لدى البنوك', ['30,000']], ['', ['50,000']]]),
            self::h('أطراف ذات علاقة', '8'), self::p('تتمثل المعاملات مع الأطراف ذات العلاقة في تمويل من شركة شقيقة.'),
            self::t(['2024م', '2023م'], [['شركة الواحة (شركة شقيقة)', ['150,000', '130,000']]]),
            // A share value of 1,000: the partners' table is read as printed, no value assumed.
            self::h('رأس المال', '11'), self::p('يبلغ رأس مال الشركة 200,000 ريال سعودي مقسم إلى 200 حصة قيمة كل حصة 1,000 ريال.'),
            self::t(['عدد الحصص', 'قيمة الحصة', 'المبلغ'], [['سالم عبدالله', ['120', '1,000', '120,000']], ['هند خالد', ['80', '1,000', '80,000']], ['الإجمالي', ['200', '-', '200,000']]]),
        ]);
    }

    public function test_notes_in_another_order_and_numbering_are_linked_by_number_title_and_amount(): void
    {
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows())]);
        $printed = fn (int $row) => $doc['sections'][collect($doc['links'])->firstWhere('row', $row)['section']]['printed'];

        // Each line to its own note, whatever the order the notes are printed in.
        $this->assertSame(['9', '7', '11', '8'], [$printed(0), $printed(1), $printed(3), $printed(4)]);
        // «4/1» and «4/2» inside note 4 are its parts 4.1 and 4.2.
        $parts = array_values(array_filter($doc['sections'], fn ($s) => in_array($s['printed'], ['4/1', '4/2'], true)));
        $this->assertSame([['4.1', '4'], ['4.2', '4']], array_map(fn ($s) => [$s['number'], $doc['sections'][$s['parent']]['number']], $parts));
        // The company from its note, found by its title (it is note 3 here).
        $facts = array_map(fn ($f) => $f['value'], CompanyFacts::of($doc));
        $this->assertSame(['شركة النخيل للمقاولات', 'llc', '1010999999', 'أبها'], [$facts['name_ar'], $facts['legal_form'], $facts['cr_number'], $facts['city']]);
        // Every note ties to its line: the related parties' note too, and the cash note only in the year it prints.
        $this->assertSame([], array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok'])));
    }

    public function test_a_notes_tables_printed_with_other_years_are_one_flag(): void
    {
        // Two tables of one note headed 2023/2022 in 2024's statements: one flag for the note, its two tables in it.
        $note = self::page(2, 'notes', [self::h('المخزون', '6'), self::t(['2023م', '2022م'], [['مواد خام', ['10', '8']], ['', ['10', '8']]]),
            self::h('حركة مخصص المخزون'), self::t(['2023م', '2022م'], [['الرصيد', ['1', '1']]])]);
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => $note]);

        $typos = array_values(array_filter($doc['flags'], fn ($f) => $f['code'] === 'year_typo'));
        $this->assertSame([['6', [2023, 2022], [2024, 2023], 2]], array_map(fn ($f) => [$f['note'], $f['printed'], $f['used'], count($f['tables'])], $typos));
        $this->assertStringContainsString('in 2 tables', \App\Services\Statements\PriorPdf\PriorPdfIssues::of($doc)[0]['message']);
    }

    public function test_a_note_without_a_comparative_column_keeps_its_lines(): void
    {
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows())]);
        $cash = collect($doc['sections'])->firstWhere('printed', '7')['id'];
        $table = $doc['tables'][$doc['sections'][$cash]['tables'][0]];

        // The one amount column is the file's year (by position, R16); its lines are last year's lines (R11).
        $this->assertSame(['roles' => ['current'], 'printed' => [], 'by' => 'position'], $table['roles']);
        $this->assertSame(['نقد بالصندوق' => 20000.0, 'نقد لدى البنوك' => 30000.0], array_map(fn ($i) => $i['prior'], PriorNoteItems::items($doc, $cash, [])));
    }

    public function test_the_partners_table_is_read_as_printed_whatever_the_share_value(): void
    {
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows())]);
        $capital = collect($doc['sections'])->firstWhere('printed', '11')['id'];
        $table = $doc['tables'][$doc['sections'][$capital]['tables'][0]];

        // The total row found by its arithmetic (the share value column isn't added); no column taken for a total.
        $this->assertSame(['line', 'line', 'total'], array_column($table['rows'], 'kind'));
        $this->assertArrayNotHasKey('total_column', $table);
        foreach (array_slice($table['rows'], 0, 2) as $r) {
            $this->assertEquals($r['cells'][0]['amount'] * $r['cells'][1]['amount'], $r['cells'][2]['amount']);
        }
        // The partners by the columns' arithmetic: names, shares, the share value, the amounts adding up to the capital.
        $partners = Partners::read(['document' => $doc]);
        $this->assertEquals(['11', 'read', true, 200000], [$partners['note'], $partners['status'], $partners['sum_ok'], $partners['capital']]);
        $this->assertEquals([['سالم عبدالله', 120, 1000, 120000], ['هند خالد', 80, 1000, 80000]], array_map(fn ($r) => array_values($r), $partners['rows']));
    }

    public function test_the_partners_columns_in_any_order_and_the_share_value_only_in_the_text(): void
    {
        $capital = fn (array $table) => self::page(2, 'notes', [self::h('رأس المال', '5'),
            self::p('يبلغ رأس مال الشركة 200,000 ريال مقسم إلى 80 حصة قيمة الحصة (2,500) ريال.'), $table]);

        // The amount first, then the shares; no share value column: the text's.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => $capital(self::t(['الإجمالي', 'عدد الحصص'], [
            ['سالم عبدالله', ['125,000', '50']], ['هند خالد', ['75,000', '30']], ['الإجمالي', ['200,000', '80']]]))]);
        $this->assertEquals([['سالم عبدالله', 50, 2500, 125000], ['هند خالد', 30, 2500, 75000]], array_map(fn ($r) => array_values($r), Partners::read(['document' => $doc])['rows']));

        // No headers, the value first: shares × value = amount both ways round; the value is the column the same on
        // every row.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::page(2, 'notes', [self::h('رأس المال', '5'), self::t(['', '', ''], [
            ['سالم عبدالله', ['2,500', '50', '125,000']], ['هند خالد', ['2,500', '30', '75,000']], ['الإجمالي', ['-', '80', '200,000']]])])]);
        $this->assertEquals([[50, 2500], [30, 2500]], array_map(fn ($r) => [$r['shares'], $r['share_value']], Partners::read(['document' => $doc])['rows']));

        // Neither the text nor a column says the value: the same quotient on every row is the value.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::page(2, 'notes', [self::h('رأس المال', '5'), self::t(['عدد الحصص', 'المبلغ'], [
            ['سالم عبدالله', ['50', '125,000']], ['هند خالد', ['30', '75,000']], ['الإجمالي', ['80', '200,000']]])])]);
        $this->assertEquals([2500, 2500], array_column(Partners::read(['document' => $doc])['rows'], 'share_value'));
    }

    public function test_ppe_categories_printed_as_rows_are_read_each_with_its_own_life_and_the_misprinted_total_flagged(): void
    {
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows())]);
        $ppe = $doc['tables'][collect($doc['sections'])->firstWhere('printed', '9')['tables'][0]];
        $this->assertTrue($ppe['transposed'] && $ppe['movement']);
        $this->assertSame(3, $ppe['total_column']);

        // Without the statements' blocks, the note is found by its title; three categories, each its own rate and life
        // (land's «-»: no depreciation, no life).
        $categories = PriorPpe::categories(['document' => $doc]);
        $this->assertSame([['أراضي', 0.0, null], ['معدات ثقيلة', 15.0, 6.67], ['سيارات', 25.0, 4.0]],
            array_map(fn ($c) => [$c['label'], $c['rate'], $c['life']], $categories));
        $this->assertEquals([[100000, 0, 0], [250000, 85000, 25000], [80000, 45000, 15000]],
            array_map(fn ($c) => [$c['last']['cost_closing'], $c['last']['acc_closing'], $c['last']['depreciation'] ?? 0], $categories));
        // 2024's balances: of the printed total and the categories' sum only the sum agrees (430,000 − 130,000 = 300,000), and
        // it ties to the balance sheet: used, flagged once with its page.
        $this->assertEquals([[2024, 'ties', [[430000, 130000, 300000]], 0]], self::yearEnds($doc));
        $this->assertSame([['total_group_ties', 2]], array_map(fn ($f) => [$f['code'], $f['page']], array_values(array_filter($doc['flags'], fn ($f) => str_starts_with($f['code'], 'total_')))));
        $this->assertEquals(130000, PriorPpe::totals(['document' => $doc])['acc_closing']);
        $this->assertSame([], array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok'])));
    }

    /**
     * The PPE note read as three tables without its parts' headings (the cost, the depreciation, the net book values).
     *
     * @param string $carsDepreciation the cars' accumulated depreciation at the end of 2024 as read (45,000 printed)
     */
    private static function ppeSplit(string $carsDepreciation = '45,000'): array
    {
        $charge = number_format((float) str_replace(',', '', $carsDepreciation) - 30000);
        $total = number_format(85000 + (float) str_replace(',', '', $carsDepreciation));
        $headers = ['أراضي', 'معدات ثقيلة', 'سيارات', 'الإجمالي'];

        return [self::h('ممتلكات ومعدات', '9'),
            self::t($headers, [['الرصيد في 1 يناير 2024', ['100,000', '200,000', '80,000', '380,000']], ['إضافات', ['-', '50,000', '-', '50,000']],
                ['الرصيد في 31 ديسمبر 2024', ['100,000', '250,000', '80,000', '430,000']]]),
            self::t($headers, [['الرصيد في 1 يناير 2024', ['-', '60,000', '30,000', '90,000']], ['إهلاك السنة', ['-', '25,000', $charge, number_format(25000 + (float) str_replace(',', '', $charge))]],
                ['الرصيد في 31 ديسمبر 2024', ['-', '85,000', $carsDepreciation, $total]]]),
            self::t($headers, [['في 31 ديسمبر 2024', ['100,000', '165,000', '35,000', '300,000']], ['في 31 ديسمبر 2023', ['100,000', '140,000', '50,000', '290,000']]])];
    }

    public function test_a_ppe_note_split_into_tables_without_its_parts_headings_is_combined_and_tied_to_the_balance_sheet(): void
    {
        // The cost, then the depreciation (a later table of the same categories), then the net book values: combined.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeSplit())]);
        $categories = PriorPpe::categories(['document' => $doc]);
        $this->assertEquals([[100000, 0, 0], [250000, 85000, 25000], [80000, 45000, 15000]],
            array_map(fn ($c) => [$c['last']['cost_closing'], $c['last']['acc_closing'] ?? 0, $c['last']['depreciation'] ?? 0], $categories));
        $this->assertEquals([100000, 165000, 35000], array_map(fn ($c) => $c['last']['nbv_closing'], $categories));
        $tie = collect($doc['checks'])->firstWhere('code', 'ppe_tie');
        $this->assertSame([true, [1, 2]], [$tie['ok'], $tie['pages']]);

        // A depreciation misread the table's own arithmetic can't see (its charge and total read along): cost − depreciation
        // isn't the balance sheet's line, so the note's page is read again.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeSplit('54,000'))]);
        $this->assertSame([], array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok'] && $c['code'] === 'movement')));
        $tie = collect($doc['checks'])->firstWhere('code', 'ppe_tie');
        $this->assertSame([false, 291000.0, 300000.0], [$tie['ok'], (float) $tie['printed'], (float) $tie['expected']]);
        $this->assertContains(2, PriorPdfReader::failingPages($doc));
    }

    public function test_the_registrations_hijri_date_is_read_or_its_page_read_again(): void
    {
        // «ه» for «هـ»: the date still read.
        $notes = fn (string $text) => self::page(2, 'notes', [self::h('نبذة عن الشركة', '1'), self::p($text)]);
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => $notes('مسجلة بموجب السجل التجاري رقم 1010999999 الصادر من مدينة أبها بتاريخ 12/ 4/ 1442 ه.')]);
        $this->assertSame('1442/04/12', CompanyFacts::of($doc)['cr_date_hijri']['value']);
        $this->assertTrue(collect($doc['checks'])->firstWhere('code', 'company_cr_date')['ok']);

        // The date after the number not read as a Hijri date: the page is read again, then flagged with it.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => $notes('مسجلة بموجب السجل التجاري رقم 1010999999 الصادر من مدينة أبها بتاريخ 12/ ؟/ 14؟2.')]);
        $this->assertSame([false, [2]], [collect($doc['checks'])->firstWhere('code', 'company_cr_date')['ok'], collect($doc['checks'])->firstWhere('code', 'company_cr_date')['pages']]);
        $this->assertContains(2, PriorPdfReader::failingPages($doc));
    }

    public function test_a_printed_total_that_ties_to_the_balance_sheet_is_used_when_its_categories_dont_add_up(): void
    {
        // 2023's net book value: the categories add up to 290,002; the printed 290,000 agrees with cost − depreciation and
        // is the balance sheet's.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows(['سيارات' => [7 => '50,002'], 'الإجمالي' => [5 => '130,000']]))]);

        $this->assertEquals([[2023, 'ties', [[380000, 90000, 290000]], 0]], self::yearEnds($doc));
        $this->assertEquals(290000, PriorPpe::totals(['document' => $doc])['nbv_opening']);
    }

    public function test_the_only_combination_that_agrees_is_used_and_said_when_it_doesnt_tie(): void
    {
        // The depreciation at the start of 2024 printed 90,002 (its categories 90,000); the balance sheet says 289,999.
        // Only 380,000 − 90,000 = 290,000 agrees with the printed net book value: used, a riyal off the balance sheet, said.
        $doc = PriorPdfReader::process([1 => self::balanceSheet('289,999'), 2 => self::notes(self::ppeAsRows(['الإجمالي' => [3 => '90,002', 5 => '130,000']]))]);

        $this->assertEquals([[2023, 'only', [[380000, 90000, 290000]], 0]], self::yearEnds($doc));
        $this->assertStringContainsString('بمبلغ 1', $this->issue($doc, 'total_group_only'));
    }

    public function test_several_combinations_that_agree_are_the_accountants_to_choose(): void
    {
        // Two readings agree (380,000 − 90,000 = 290,000; 380,000 − 90,002 = 289,998), neither the balance sheet's 289,999.
        $doc = PriorPdfReader::process([1 => self::balanceSheet('289,999'), 2 => self::notes(self::ppeAsRows(['الإجمالي' => [3 => '90,002', 5 => '130,000', 7 => '289,998']]))]);

        $this->assertEquals([[2023, 'choose', [[380000, 90000, 290000], [380000, 90002, 289998]], 0]], self::yearEnds($doc));
        $id = $doc['total_groups'][0]['id'];
        // The categories' sums until the accountant chooses; the other combination, whole, once chosen.
        $this->assertEquals([90000, 290000], array_values(array_intersect_key(PriorPpe::totals(['document' => $doc]), ['acc_opening' => 1, 'nbv_opening' => 1])));
        $this->assertEquals([90002, 289998], array_values(array_intersect_key(PriorPpe::totals(['document' => $doc, 'total_choices' => [$id => '1']]), ['acc_opening' => 1, 'nbv_opening' => 1])));
    }

    public function test_no_combination_that_agrees_is_said_plainly(): void
    {
        // The net book value printed 290,005 and added up 290,003: neither is 380,000 − 90,000.
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows(['سيارات' => [7 => '50,003'], 'الإجمالي' => [5 => '130,000', 7 => '290,005']]))]);

        $this->assertEquals([[2023, 'none', [], null]], self::yearEnds($doc));
        $this->assertStringContainsString('لا يوجد أي اختيار', $this->issue($doc, 'total_group_none'));
    }

    public function test_a_total_with_no_statement_line_to_tie_to_is_for_the_accountant_to_choose(): void
    {
        // No balance sheet: what agrees is offered, nothing to tie it to.
        $doc = PriorPdfReader::process([2 => self::notes(self::ppeAsRows())]);

        $this->assertEquals([[2024, 'unlinked', [[430000, 130000, 300000]], 0]], self::yearEnds($doc));
    }

    private function issue(array $doc, string $code): string
    {
        app()->setLocale('ar');

        return collect(\App\Services\Statements\PriorPdf\PriorPdfIssues::of($doc))->firstWhere('code', $code)['message'];
    }

    public function test_ppe_cost_and_depreciation_printed_as_two_tables_are_read_as_one(): void
    {
        $ppe = [self::h('ممتلكات ومعدات', '9'),
            self::t(['الرصيد في 1 يناير 2024', 'إضافات', 'الرصيد في 31 ديسمبر 2024'], [
                ['معدات ثقيلة', ['200,000', '50,000', '250,000']], ['سيارات', ['80,000', '-', '80,000']], ['الإجمالي', ['280,000', '50,000', '330,000']],
            ], 'التكلفة'),
            // The second table lists the categories in another order.
            self::t(['الرصيد في 1 يناير 2024', 'إهلاك السنة', 'الرصيد في 31 ديسمبر 2024'], [
                ['سيارات', ['30,000', '15,000', '45,000']], ['معدات ثقيلة', ['60,000', '25,000', '85,000']], ['الإجمالي', ['90,000', '40,000', '130,000']],
            ], 'مجمع الإهلاك'),
        ];
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes($ppe)]);

        $categories = PriorPpe::categories(['document' => $doc]);
        $this->assertEquals([['معدات ثقيلة', 200000, 250000, 60000, 85000], ['سيارات', 80000, 80000, 30000, 45000]],
            array_map(fn ($c) => [$c['label'], $c['last']['cost_opening'], $c['last']['cost_closing'], $c['last']['acc_opening'], $c['last']['acc_closing']], $categories));
    }

    public function test_the_changes_in_equity_as_two_tables_in_different_column_orders(): void
    {
        $equity = self::page(3, 'changes_in_equity', [self::h('قائمة التغيرات في حقوق الملكية'),
            self::t(['رأس المال', 'الأرباح (الخسائر) المبقاة', 'المجموع'], [
                ['الرصيد في 1 يناير 2023م', ['200,000', '(10,000)', '190,000']], ['صافي خسارة السنة', ['-', '(20,000)', '(20,000)']],
                ['الرصيد في 31 ديسمبر 2023م', ['200,000', '(30,000)', '170,000']],
            ]),
            // The year's own table, its total column first and the components the other way round.
            self::t(['المجموع', 'الأرباح (الخسائر) المبقاة', 'رأس المال'], [
                ['الرصيد في 1 يناير 2024م', ['170,000', '(30,000)', '200,000']], ['صافي خسارة السنة', ['(5,000)', '(5,000)', '-']],
                ['الرصيد في 31 ديسمبر 2024م', ['165,000', '(35,000)', '200,000']],
            ]),
        ]);
        $doc = PriorPdfReader::process([1 => self::balanceSheet(), 2 => self::notes(self::ppeAsRows()), 3 => $equity]);

        $block = EquityBlock::of($doc, new LineCatalogue('sa-ifrs-smes-3'));
        $this->assertEquals(['opening' => ['share_capital' => 200000, 'retained_earnings' => -30000], 'net_profit' => ['retained_earnings' => -5000]], $block['rows']);
        $this->assertEquals(['share_capital' => 200000, 'retained_earnings' => -35000], $block['closing']);
        $this->assertSame([[], []], [$block['unplaced_rows'], $block['unplaced_columns']]);
        // Each table's total column found by its arithmetic, wherever it is printed.
        $this->assertSame([], array_values(array_filter($doc['checks'], fn ($c) => $c['code'] === 'equity_columns')));
    }

    public function test_a_word_printed_with_its_article_is_never_corrected_to_a_verb(): void
    {
        // «المتوفر» is known nowhere; «تتوفر» (a verb) is one letter from «متوفر» but never takes the article.
        $doc = ['tables' => [], 'sections' => [['id' => 0, 'number' => '9', 'printed' => '9', 'title' => 'النقد المتوفر', 'page' => 4, 'parent' => null,
            'paragraphs' => [], 'tables' => [], 'slip' => false]]];
        $texts = ['عندما تتوفر الأدلة', 'النقد'];

        $check = Wording::check($doc, Wording::words($texts), Wording::surfaces($texts));

        $this->assertSame([], $check['corrections']);
        $this->assertSame(['المتوفر'], $check['flags'][0]['words']);
    }
}
