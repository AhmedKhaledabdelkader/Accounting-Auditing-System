<?php

namespace Tests\Feature;

use App\Services\Statements\PriorPdf\Alignment;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPdf\Checks;
use App\Services\Statements\PriorPdf\CompanyFacts;
use App\Services\Statements\PriorPdf\EquityBlock;
use App\Services\Statements\PriorPdf\PdfDefaults;
use App\Services\Statements\PriorPdf\PageExtractor;
use App\Services\Statements\PriorPdf\PriorPdfReader;
use App\Services\Statements\PriorPdf\Sections;
use App\Services\Statements\PriorPdf\Tables;
use App\Services\Statements\PriorPdf\Wording;
use Tests\TestCase;

/**
 * What PHP makes of the pages the AI copied (brief §8, R10, R16), without the AI: years, totals, the notes and their
 * links, the checks. Made-up figures.
 */
class PriorPdfProcessingTest extends TestCase
{
    private static function rows(array $rows): array
    {
        return PageExtractor::blocks(['blocks' => [['type' => 'table', 'number' => null, 'text' => null,
            'columns' => array_fill(0, count($rows[0][1]), ['header' => '']), 'rows' => array_map(fn ($r) => ['label' => $r[0], 'note' => $r[2] ?? null, 'cells' => $r[1]], $rows)]]])[0]['rows'];
    }

    private static function kinds(array $rows): array
    {
        return array_column(Tables::totals(self::rows($rows)), 'kind');
    }

    public function test_the_latest_year_printed_is_the_current_one_whatever_the_order_or_the_typo(): void
    {
        $roles = fn (array $headers, bool $positional = false) => Tables::roles(array_map(fn ($h) => ['header' => $h], $headers), $positional);

        $this->assertSame(['roles' => ['comparative', 'current'], 'printed' => [2024, 2023], 'by' => 'years'], $roles(['31 ديسمبر 2023م / ريال سعودي', '31 ديسمبر ٢٠٢٤م']));
        // A note headed 2023/2022 in 2024's statements: still the latest first (the reader flags it).
        $this->assertSame(['current', 'comparative'], $roles(['2023م', '2022م'])['roles']);
        // A statement without years: the column nearest the labels; a note's category columns: no years.
        $this->assertSame(['roles' => ['current', 'comparative'], 'printed' => [], 'by' => 'position'], $roles(['ريال سعودي', 'ريال سعودي'], true));
        $this->assertSame('none', $roles(['مباني', 'سيارات', 'الإجمالي'])['by']);
    }

    public function test_totals_are_found_by_their_arithmetic_never_by_their_words(): void
    {
        // R10: «إجمالي الإضافات/الحسميات» are lines (they add nothing above them); «صافي الوعاء» adds them, a rounding off by 1.
        $this->assertSame(['heading', 'line', 'line', 'total', 'line', 'line', 'total', 'line'], self::kinds([
            ['البيان', [null, null]],
            ['صافي الخسارة قبل الزكاة', ['(700)', '(600)']], ['مخصصات مكونة', ['-', '-']], ['صافي الدخل المعدل', ['(700)', '(600)']],
            ['إجمالي الإضافات إلى الوعاء', ['5,200,060', '9,000']], ['إجمالي الحسميات من الوعاء', ['(5,900,471)', '(9,500)']],
            ['صافي الوعاء', ['(700,410)', '(500)']], ['الزكاة المستحقة', ['-', '-']],
        ]));
        // Subtotals, grand totals across headings, a roll-up under the same heading, an empty heading's «-».
        $this->assertSame(['heading', 'line', 'line', 'total', 'heading', 'line', 'line', 'total', 'total', 'heading', 'line', 'heading', 'line', 'line', 'total', 'total', 'total'], self::kinds([
            ['الأصول غير المتداولة', [null]], ['ممتلكات', ['100']], ['أصول أخرى', ['1']], ['إجمالي', ['101']],
            ['الأصول المتداولة', [null]], ['نقد', ['50']], ['مخزون', ['49']], ['إجمالي', ['99']], ['إجمالي الأصول', ['200']],
            ['المطلوبات غير المتداولة', [null]], ['إجمالي المطلوبات غير المتداولة', ['-']],
            ['المطلوبات المتداولة', [null]], ['دائنون', ['150']], ['زكاة', ['50']], ['إجمالي', ['200']], ['إجمالي المطلوبات', ['200']], ['إجمالي المطلوبات وحقوق الملكية', ['200']],
        ]));
        // A «-» line is not a total because its rows above cancel out unless it closes its part; one line's total closes its part.
        $this->assertSame(['line', 'line', 'total', 'heading', 'line', 'line', 'total', 'heading', 'line', 'total'], self::kinds([
            ['صافي الربح', ['(400)']], ['إهلاك', ['400']], ['المجموع', ['-']], ['التغيرات', [null]], ['مستحق لأطراف', ['10']], ['التغير في المخصصات', ['(10)']],
            ['صافي التدفق', ['-']], ['الاستثمار', [null]], ['شراء أصول', ['(5)']], ['صافي الاستثمار', ['(5)']],
        ]));
        // A line equal to a total above it, under another heading, stays a line; unread figures never add up.
        $this->assertSame(['line', 'line', 'total', 'heading', 'line'], self::kinds([['أ', ['1']], ['ب', ['1']], ['المجموع', ['2']], ['حقوق', [null]], ['رأس المال', ['2']]]));
        $this->assertSame(['line', 'line', 'line'], self::kinds([['أ', ['1?']], ['ب', ['1']], ['المجموع', ['2']]]));
    }

    public function test_notes_keep_their_parts_and_a_number_out_of_sequence_stays_in_its_note(): void
    {
        $h = fn (?string $n, string $t) => ['type' => 'heading', 'number' => $n, 'text' => $t];
        $p = fn (string $t) => ['type' => 'paragraph', 'number' => null, 'text' => $t];
        $t = fn (int $id) => ['type' => 'table', 'table' => $id, 'number' => null, 'text' => null];
        $built = Sections::build([
            10 => ['page' => 10, 'blocks' => [$h(null, 'الشركة'), $h(null, 'الإيضاحات المتممة'), $h('4', 'السياسات المحاسبية'), $h('4.1', 'الممتلكات'), $p('سياسة'),
                $h('4', 'مكافأة نهاية الخدمة'), $h('5', 'النقد وما في حكمه'), $p('يتكون النقد'), $h('4.6', 'الأطراف ذات العلاقة')]],
            11 => ['page' => 11, 'blocks' => [$h(null, 'الشركة'), $h('5', 'ممتلكات ومعدات'), $t(0), $h('13', 'تكاليف التشغيل'), $t(1), $h(null, 'مصاريف تشغيلية *'), $t(2),
                $h('1/16', 'مخاطر الائتمان'), $p('يتمثل خطر')]],
            12 => ['page' => 12, 'blocks' => [$h(null, 'الشركة'), $t(3)]],   // the risk note's table continued on the next page
        ]);
        $s = $built['sections'];
        $byTitle = array_column($s, null, 'title');

        $this->assertSame(['4', '4.1', '4', '5', '4.6', '5', '13', null, '16.1'], array_column($s, 'number'));
        $this->assertSame([true, true], [$byTitle['مكافأة نهاية الخدمة']['slip'], $byTitle['النقد وما في حكمه']['slip']]);
        $this->assertSame($byTitle['السياسات المحاسبية']['id'], $byTitle['النقد وما في حكمه']['parent']);
        $this->assertNull($byTitle['ممتلكات ومعدات']['parent']);   // the real note 5
        $this->assertSame([$byTitle['تكاليف التشغيل']['id'], [2]], [$byTitle['مصاريف تشغيلية *']['parent'], $byTitle['مصاريف تشغيلية *']['tables']]);
        $this->assertSame([3], $byTitle['مخاطر الائتمان']['tables']);
        $this->assertSame([['code' => 'note_number_slip', 'page' => 10, 'printed' => '4', 'title' => 'مكافأة نهاية الخدمة', 'in' => '4'],
            ['code' => 'note_number_slip', 'page' => 10, 'printed' => '5', 'title' => 'النقد وما في حكمه', 'in' => '4']], $built['flags']);

        // A line refers to note 5: the PPE note (number, title, amount), never the cash policy numbered 5 by mistake.
        $tables = [0 => ['id' => 0, 'roles' => ['roles' => [null]], 'rows' => self::rows([['صافي القيمة الدفترية', ['1,250,000']]])]];
        $links = Sections::link([['statement' => 'financial_position', 'row' => 2, 'label' => 'الممتلكات والمعدات، بالصافي', 'note' => '5', 'amounts' => [1250000.0]],
            ['statement' => 'financial_position', 'row' => 3, 'label' => 'ممتلكات ومعدات', 'note' => null, 'amounts' => [1250000.0]],
            ['statement' => 'financial_position', 'row' => 4, 'label' => 'الذمم المدينة', 'note' => null, 'amounts' => [5.0]],
            // Its words and one amount in note 5, but other figures than the line naming note 5: not linked to it.
            ['statement' => 'financial_position', 'row' => 5, 'label' => 'ممتلكات استثمارية', 'note' => null, 'amounts' => [800.0, 1250000.0]]], $s, $tables);
        $this->assertSame([$byTitle['ممتلكات ومعدات']['id'], ['number', 'title', 'amount'], true], [$links[0]['section'], $links[0]['by'], $links[0]['certain']]);
        $this->assertSame([$byTitle['ممتلكات ومعدات']['id'], ['title', 'amount']], [$links[1]['section'], $links[1]['by']]);   // no number: title and amount
        $this->assertNull($links[2]['section']);
        $this->assertNull($links[3]['section']);
    }

    public function test_the_checks_point_at_their_pages(): void
    {
        $page = fn (int $n, string $kind, array $blocks) => ['page' => $n, 'kind' => $kind, 'rotation' => 0, 'printed_page' => null, 'attempt' => 0,
            'blocks' => PageExtractor::blocks(['blocks' => $blocks]), 'usage' => null];
        $table = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers),
            'rows' => array_map(fn ($r) => ['label' => $r[0], 'note' => $r[2] ?? null, 'cells' => $r[1]], $rows)];
        $doc = PriorPdfReader::process([
            2 => $page(2, 'financial_position', [$table(['2024', '2023'], [['نقد', ['100', '90'], '9'], ['مخزون', ['20', '10']], ['إجمالي الأصول', ['120', '100']],
                ['حقوق الملكية', [null, null]], ['رأس المال', ['100', '100']], ['أرباح مبقاة', ['20', '-']], ['إجمالي حقوق الملكية', ['120', '100']]])]),
            // The cash flow: an amount moved a row down (the net change printed «-», the opening read as the change).
            4 => $page(4, 'cash_flows', [$table(['2024', '2023'], [['صافي الربح', ['20', '10']], ['التغير في المخزون', ['(10)', '(10)']], ['صافي التدفق التشغيلي', ['10', '-']],
                ['صافي التغير في النقد', ['90', '-']], ['النقد أول السنة', ['90', '90']], ['النقد آخر السنة', ['100', '90']]])]),
            5 => $page(5, 'notes', [['type' => 'heading', 'number' => '9', 'text' => 'النقد'],
                $table(['2024', '2023'], [['بنوك', ['80', '70']], ['صندوق', ['20', '20']], ['المجموع', ['100', '90']]]),
                ['type' => 'heading', 'number' => '10', 'text' => 'مخصص الزكاة'],
                $table(['2024', '2023'], [['الرصيد أول السنة', ['5', '-']], ['المكون', ['10', '5']], ['المسدد', ['(5)', '-']], ['الرصيد آخر السنة', ['15', '5']]]),
                ['type' => 'paragraph', 'number' => null, 'text' => 'لا توجد التزامات محتملة منذ 2019.']]),
        ]);
        $failing = array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok']));

        // The balance sheet adds up; the cash flow doesn't (its end isn't a total, and 100 ≠ 90 + 90); the zakat movement is off
        // by 5 (5 + 10 − 5 ≠ 15).
        $this->assertSame([['statement_total', [4]], ['cash_roll', [4]], ['movement', [5]]], array_map(fn ($c) => [$c['code'], $c['pages']], $failing));
        $this->assertSame([4, 5], PriorPdfReader::failingPages($doc));
        $this->assertStringContainsString('the cash at the end of the year does not equal', Checks::hint($doc, 4));
        $this->assertContains(['code' => 'note_tie', 'ok' => true, 'pages' => [5], 'statement' => 'financial_position', 'label' => 'نقد', 'note' => '9'], $doc['checks']);
        $this->assertSame([['code' => 'year_in_text', 'year' => 2019]], array_map(fn ($f) => ['code' => $f['code'], 'year' => $f['year']], $doc['flags']));
    }

    public function test_amounts_printed_off_their_lines_are_placed_by_the_arithmetic(): void
    {
        $labels = ['من أنشطة التشغيل', 'صافي الخسارة', 'إهلاك', 'مخصص', 'صافي الدخل المعدل', 'مستحق لأطراف', 'التغير في المخصصات',
            'صافي التدفق التشغيلي', 'من أنشطة الاستثمار', 'صافي التدفق الاستثماري', 'صافي التغير في النقد', 'النقد أول السنة', 'النقد آخر السنة'];
        // Read row by row: one «-» dropped, so the net change took the opening cash and the opening cash was repeated.
        $perRow = self::rows(array_map(fn ($l, $v) => [$l, [$v]], $labels, [null, '(700)', '300', '-', '(400)', '400', '-', '-', null, '-', '90', '90', '90']));
        $holds = fn (array $rows) => array_filter(Checks::statement('cash_flows', Tables::totals(array_map(fn ($r) => $r + ['page' => 4], $rows))), fn ($c) => ! $c['ok']) === [];
        $this->assertFalse($holds($perRow));
        $list = fn (array $values) => [array_map(fn ($v) => PageExtractor::cell($v), $values)];

        // The column read alone: every «-» there. One placement holds: used.
        $one = Alignment::align($perRow, $list(['(700)', '300', '-', '(400)', '400', '-', '-', '-', '-', '90', '90']), $holds);
        $this->assertSame('one', $one['result']);
        $this->assertSame([null, -700.0, 300.0, 0.0, -400.0, 400.0, 0.0, 0.0, null, 0.0, 0.0, 90.0, 90.0], array_map(fn ($r) => $r['cells'][0]['amount'], $one['rows']));

        // The list drops a «-» too: the row left empty may be any of the lines around the related parties' 400 (the
        // totals hold either way): several placements, nothing chosen.
        $several = Alignment::align($perRow, $list(['(700)', '300', '-', '(400)', '400', '-', '-', '-', '90', '90']), $holds);
        $this->assertSame('several', $several['result']);
        $this->assertArrayNotHasKey('rows', $several);

        // A list that no placement makes add up (the closing cash read 95): none.
        $this->assertSame('none', Alignment::align($perRow, $list(['(700)', '300', '-', '(400)', '400', '-', '-', '-', '-', '90', '95']), $holds)['result']);
        // A list for another number of columns: none, nothing tried.
        $this->assertSame('none', Alignment::align($perRow, [], $holds)['result']);
    }

    public function test_wording_read_two_ways_is_corrected_a_misprint_too_and_the_rest_flagged(): void
    {
        $table = fn (int $id, int $page, array $headers, array $labels) => ['id' => $id, 'page' => $page, 'title' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers),
            'rows' => array_map(fn ($l) => ['label' => $l, 'cells' => []], $labels)];
        $doc = ['tables' => [
            // The PPE note's header has one letter misread; the rate table on another page has it right; years apart are R16's.
            $table(0, 12, ['مباني وانشاءات / ريال', 'خزابات ومستودعات / ريال'], ['الرصيد في 1 يناير 2023', 'الرصيد في 1 يناير 2024', 'النقد بالبنوك']),
            $table(1, 10, ['النسبة %'], ['مباني وانشاءات', 'خزانات ومستودعات', 'الشاملة']),
            // A partner's name: no known word is a letter longer, so it is only flagged.
            $table(2, 14, ['عدد الحصص'], ['عبدالرحمن بن مسعود']),
        ], 'sections' => [['title' => 'مكافأة نهاية الخدمة', 'page' => 11, 'paragraphs' => ['تودع البنوك الفوائد.']], ['title' => 'عهد زملاكات', 'page' => 11, 'paragraphs' => []],
            // Misprinted in the file: «للمتلكات» for «للممتلكات».
            ['title' => 'الأعمار الإنتاجية للمتلكات', 'page' => 9, 'paragraphs' => []]]];
        $texts = ['مجمع اهلاك خزانات', 'المباني', 'إنشاءات', 'الرصيد', 'يناير', 'نقدية بالصندوق', 'الدخل الشامل', 'مكافأة نهاية الخدمة', 'عهد', 'النسبة', 'ريال',
            'الممتلكات والآلات', 'الأعمار الإنتاجية', 'عدد الحصص', 'عبدالرحمن', 'سعود'];

        $check = Wording::check($doc, Wording::words($texts), Wording::surfaces($texts));

        // The slip corrected to the printed word; the misprint to the known word, keeping its «لل»; both with their pages.
        $this->assertSame([['reading_slip', 12, [12, 10], 'خزابات', 'خزانات'], ['misprint', 9, [9], 'للمتلكات', 'للممتلكات']],
            array_map(fn ($c) => [$c['kind'], $c['page'], $c['pages'], $c['original'], $c['corrected']], $check['corrections']));
        // Known nowhere and no single correction: flagged. «بالبنوك» is «البنوك» of the paragraph; «الشاملة» is «الشامل»;
        // «مسعود» is not «سعود» (never by leaving a letter out).
        $this->assertSame([['مسعود'], ['زملاكات']], array_map(fn ($f) => $f['words'], array_values(array_filter($check['flags'], fn ($f) => $f['code'] === 'wording_unknown'))));
        $this->assertSame([], array_values(array_filter($check['flags'], fn ($f) => $f['code'] === 'wording_differs')));
        // Without a vocabulary nothing is corrected: the two readings are flagged.
        $this->assertSame(['wording_differs'], array_column(Wording::flags($doc), 'code'));

        // The output takes the corrections, except one the accountant keeps as printed.
        $text = 'الأعمار الإنتاجية للمتلكات وخزابات ومستودعات';
        $this->assertSame('الأعمار الإنتاجية للممتلكات وخزانات ومستودعات', Wording::apply('الأعمار الإنتاجية للمتلكات وخزانات ومستودعات', $check['corrections']));
        $this->assertSame('خزانات ومستودعات', Wording::apply('خزابات ومستودعات', $check['corrections']));
        $this->assertSame('مباني وخزانات', Wording::apply('مباني وخزابات', $check['corrections']));
        $this->assertSame('الأعمار الإنتاجية للمتلكات وخزابات ومستودعات', Wording::apply($text, $check['corrections'], array_fill_keys(array_column($check['corrections'], 'id'), 'original')));
    }

    public function test_a_slip_repeated_in_the_file_is_still_corrected_to_the_word_known_outside_it(): void
    {
        $table = fn (int $id, int $page, array $labels) => ['id' => $id, 'page' => $page, 'title' => null, 'columns' => [['header' => '2024']], 'rows' => array_map(fn ($l) => ['label' => $l, 'cells' => []], $labels)];
        // Read «الدقترية» twice on page 12 (so the file alone would know it), «الدفترية» on page 13 and in the templates.
        $doc = ['tables' => [$table(0, 12, ['صافي القيمة الدقترية', 'في 31 ديسمبر', 'صافي القيمة الدقترية']), $table(1, 13, ['صافي القيمة الدفترية'])], 'sections' => []];
        $texts = ['صافي القيمة الدفترية', 'في ديسمبر'];

        $check = Wording::check($doc, Wording::words($texts), Wording::surfaces($texts));

        $this->assertSame([['reading_slip', 12, [12, 13], 'الدقترية', 'الدفترية']], array_map(fn ($c) => [$c['kind'], $c['page'], $c['pages'], $c['original'], $c['corrected']], $check['corrections']));
        $this->assertSame([], array_values(array_filter($check['flags'], fn ($f) => $f['code'] === 'wording_differs')));
    }

    public function test_a_word_split_by_the_fonts_spacing_is_rejoined_not_flagged(): void
    {
        $doc = ['tables' => [], 'sections' => [['title' => 'اعتماد القو ائم المالية', 'page' => 16, 'paragraphs' => ['تمت المو افقة على القو ائم المالية ف القيمة.']],
            ['title' => 'عبد الله سالم', 'page' => 14, 'paragraphs' => []]]];
        $texts = ['القوائم المالية', 'الموافقة', 'اعتماد', 'الله', 'عبد', 'سالم', 'فالقيمة'];

        $check = Wording::check($doc, Wording::words($texts), Wording::surfaces($texts));

        // Rejoined (a known word), never flagged; two known words stay two; a letter alone («ف» for «في») is no piece.
        $this->assertSame([['split', 'القو ائم', 'القوائم'], ['split', 'المو افقة', 'الموافقة']], array_map(fn ($c) => [$c['kind'], $c['original'], $c['corrected']], $check['corrections']));
        $this->assertSame([], $check['flags']);
        $this->assertSame([], \App\Services\Statements\PriorPdf\PriorPdfIssues::of(['corrections' => $check['corrections']]));
        $this->assertSame('تمت الموافقة على القوائم المالية.', Wording::apply('تمت المو افقة على القو ائم المالية.', $check['corrections']));
    }

    public function test_general_words_and_peoples_names_are_never_flagged_and_never_a_correction(): void
    {
        $doc = ['tables' => [['id' => 0, 'page' => 14, 'title' => null, 'columns' => [['header' => 'عدد الحصص']], 'rows' => [['label' => 'هند بنت مسعود الزهراني', 'cells' => []]]]],
            'sections' => [['title' => 'نبذه عن الشركة', 'page' => 9, 'paragraphs' => []], ['title' => 'عنوان الشركة', 'page' => 9, 'paragraphs' => []],
                ['title' => 'بنود سيعاد تصنيفها', 'page' => 6, 'paragraphs' => []], ['title' => 'إجمالي الحسميات من الوعاء', 'page' => 14, 'paragraphs' => []],
                ['title' => 'مبانى زجاحية', 'page' => 8, 'paragraphs' => []]]];
        $texts = ['الشركة', 'عدد الحصص', 'تصنيف', 'بنود', 'إجمالي', 'الوعاء', 'مباني زجاجية'];
        $general = \App\Services\Statements\PriorPdf\Vocabulary::general();

        $check = Wording::check($doc, Wording::words($texts), Wording::surfaces($texts), $general, ['هند بنت مسعود الزهراني']);

        // Common words (نبذه، عنوان، سيعاد), general accounting words (الحسميات) and a partner's name: not flagged. A word
        // known nowhere is still flagged, or corrected to a word of the trial balance, templates or taxonomy only.
        $this->assertSame([['misprint', 'زجاحية', 'زجاجية']], array_map(fn ($c) => [$c['kind'], $c['original'], $c['corrected']], $check['corrections']));
        $this->assertSame([], $check['flags']);
        $this->assertTrue(isset($general['نبذه']) && isset($general['حسميات']));
    }

    public function test_a_misprint_the_file_repeats_or_a_word_list_holds_is_corrected_when_the_term_is_known(): void
    {
        // PPE categories as printed in the note's header and again in the rates table.
        $headers = ['السيارت', 'عدد واداوت', 'أجهزة حاسب الى'];
        $doc = ['tables' => [['id' => 0, 'page' => 10, 'title' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => []],
            ['id' => 1, 'page' => 9, 'title' => null, 'columns' => [['header' => 'النسبة']], 'rows' => array_map(fn ($h) => ['label' => $h, 'cells' => []], [...$headers,
                'التغير في رأس المال العامل', 'وما فى حكمه'])]], 'sections' => []];
        $texts = ['السيارات', 'عدد وأدوات', 'أجهزة حاسب آلي', 'النسبة', 'التغير', 'رأس المال', 'العوامل', 'وما في حكمه'];

        $check = Wording::check($doc, Wording::words($texts), Wording::surfaces($texts), \App\Services\Statements\PriorPdf\Vocabulary::general());

        // «سيارت» is in the word list (a common misspelling), «اداوت» printed twice, «الى» a word of its own: each a
        // misprint of a term known outside the file, with its page. «العامل» would be «العوامل» but no known term says
        // so; «فى» is «في» spelled another way.
        $this->assertSame([['misprint', 10, 'السيارت', 'السيارات'], ['misprint', 10, 'واداوت', 'وأدوات'], ['misprint', 10, 'حاسب الى', 'حاسب آلي']],
            array_map(fn ($c) => [$c['kind'], $c['page'], $c['original'], $c['corrected']], $check['corrections']));
        $this->assertSame([], $check['flags']);
        $this->assertSame(['السيارات', 'عدد وأدوات', 'أجهزة حاسب آلي'], array_map(fn ($h) => Wording::apply($h, $check['corrections']), $headers));
        // One click keeps the original.
        $this->assertSame('أجهزة حاسب الى', Wording::apply('أجهزة حاسب الى', $check['corrections'], [$check['corrections'][2]['id'] => 'original']));
    }

    public function test_last_years_block_of_the_changes_in_equity_is_placed_on_the_grid(): void
    {
        $columns = ['رأس المال / ريال', 'الاحتياطي النظامي', 'الأرباح (الخسائر) المبقاة', 'فائض أخرى', 'المجموع'];
        $rows = array_map(fn ($r) => $r + ['kind' => 'line', 'page' => 7], self::rows([
            ['الرصيد في 1 يناير 2023م', ['100', '-', '(40)', '-', '60']], ['صافي ربح (خسارة) السنة', ['-', '-', '(10)', '-', '(10)']],
            ['الرصيد في 31 ديسمبر 2023م', ['100', '-', '(50)', '-', '50']],
            ['الرصيد في 1 يناير 2024م', ['100', '-', '(50)', '-', '50']], ['صافي ربح (خسارة) السنة', ['-', '-', '30', '-', '30']],
            ['إجمالي الدخل الشامل للسنة', ['-', '-', '30', '-', '30']], ['المحول إلى الاحتياطي النظامي', ['-', '3', '(3)', '-', '-']],
            ['زيادة رأس المال', ['20', '-', '-', '-', '20']], ['تسوية سنوات سابقة', ['-', '-', '5', '-', '5']], ['بند لا شيء', ['-', '-', '-', '-', '-']],
            ['الرصيد في 31 ديسمبر 2024م', ['120', '3', '(18)', '-', '105']],
        ]));
        $doc = ['years' => ['current' => 2024], 'statements' => ['changes_in_equity' => ['tables' => [0], 'rows' => $rows]],
            'tables' => [0 => ['columns' => array_map(fn ($h) => ['header' => $h], $columns)]]];

        $block = EquityBlock::of($doc, new LineCatalogue('sa-ifrs-smes-3'));

        // 2024's block, not 2023's; the total column and the total comprehensive income computed, never taken.
        $this->assertEquals(['opening' => ['share_capital' => 100, 'retained_earnings' => -50], 'net_profit' => ['retained_earnings' => 30],
            'transfer_to_reserve' => ['statutory_reserve' => 3, 'retained_earnings' => -3], 'capital_increase' => ['share_capital' => 20]], $block['rows']);
        $this->assertEquals(['share_capital' => 120, 'statutory_reserve' => 3, 'retained_earnings' => -18], $block['closing']);
        // What can't be placed is listed with its page: a row of no known kind, a column of no known equity component.
        $this->assertSame([['label' => 'تسوية سنوات سابقة', 'page' => 7]], $block['unplaced_rows']);
        $this->assertSame(['فائض أخرى'], $block['unplaced_columns']);
    }

    public function test_the_company_as_last_years_notes_describe_it(): void
    {
        $section = fn (int $id, ?string $number, string $title, array $paragraphs, ?int $parent = null, int $page = 9) => ['id' => $id, 'number' => $number, 'printed' => $number,
            'title' => $title, 'page' => $page, 'parent' => $parent, 'paragraphs' => $paragraphs, 'tables' => [], 'slip' => false];
        $doc = ['sections' => [
            $section(0, '1', 'نبذة عن الشركة', ['شركة المثال للتجارة شركة ذات مسئولية محدودة تمارس نشاطها بموجب السجل التجاري رقم ١٠١٠١٢٣٤٥٦ والصادر من جدة بتاريخ 5/ 3/ 1440 هـ.',
                'يتمثل نشاط الشركة في تجارة الجملة والتجزئة في مواد البناء.']),
            $section(1, null, 'عنوان الشركة', ['الرياض'], 0),
            $section(2, '2.1', 'المعايير المطبقة', ['أعدت وفقاً للمعيار الدولي للتقرير المالي للمنشآت الصغيرة ومتوسطة الحجم.']),
            $section(3, '19', 'اعتماد القوائم المالية', ['اعتمد مجلس المديرين القوائم المالية بالموافقة عليها في 2025/03/30'], null, 16),
        ]];

        $facts = array_map(fn ($f) => $f['value'], CompanyFacts::of($doc));

        // The address note: the head office, and the city over where the registry was issued; the approving body as written; nothing guessed.
        $this->assertSame(['name_ar' => 'شركة المثال للتجارة', 'legal_form' => 'llc', 'cr_number' => '1010123456', 'cr_date_hijri' => '1440/03/05', 'city' => 'الرياض',
            'activity' => 'تجارة الجملة والتجزئة في مواد البناء', 'head_office' => 'الرياض', 'framework' => 'ifrs_for_smes', 'approval.body' => 'مجلس المديرين'], $facts);
        $this->assertSame(16, CompanyFacts::of($doc)['approval.body']['page']);
    }

    public function test_the_policy_choices_last_years_policies_note_made(): void
    {
        $section = fn (int $id, ?string $number, string $title, array $paragraphs, ?int $parent = null) => ['id' => $id, 'number' => $number, 'printed' => $number,
            'title' => $title, 'page' => 10, 'parent' => $parent, 'paragraphs' => $paragraphs, 'tables' => [], 'slip' => false];
        $policies = fn (array $parts) => ['sections' => [$section(0, '4', 'السياسات المحاسبية الهامة', ['ملخص السياسات.']), ...array_map(fn ($p, $i) => $section($i + 1, '4.'.($i + 1), $p[0], [$p[1]], 0), $parts, array_keys($parts))], 'tables' => []];

        $this->assertSame(['policies.employee_benefits' => 'actuarial', 'policies.inventory' => 'general', 'policies.foreign_currency' => true, 'policies.lessor' => true,
            'policies.subsidiaries' => null, 'policies.revenue' => ['goods', 'services']], PdfDefaults::policies($policies([
                ['منافع الموظفين', 'تحتسب بطريقة الوحدة الإضافية المقدرة بتقييم اكتواري.'], ['المخزون', 'يقاس المخزون بالتكلفة بطريقة المتوسط المرجح.'],
                ['العملات الأجنبية', 'تحول المعاملات بسعر الصرف.'], ['عقود الإيجار', 'عندما تكون الشركة المؤجر تثبت الإيرادات بالقسط الثابت.'],
                ['الاعتراف بالإيرادات', 'تثبت الإيرادات من بيع البضائع عند التسليم ومن تقديم الخدمات عند أدائها.'],
            ])));
        // Nothing said, nothing chosen: the accountant's (labour law named without a policy title is still read).
        $this->assertSame(['policies.employee_benefits' => 'labour_law', 'policies.inventory' => null, 'policies.foreign_currency' => null, 'policies.lessor' => null,
            'policies.subsidiaries' => null, 'policies.revenue' => null], PdfDefaults::policies($policies([['مكافأة نهاية الخدمة', 'وفقاً لنظام العمل في المملكة.']])));
        $this->assertSame([], PdfDefaults::policies(['sections' => [], 'tables' => []]));
    }

    public function test_the_other_provisions_description_comes_from_last_years_note(): void
    {
        $section = fn (int $id, string $title, array $paragraphs, ?int $parent = null) => ['id' => $id, 'number' => $parent === null ? (string) ($id + 1) : null, 'printed' => null,
            'title' => $title, 'page' => 12, 'parent' => $parent, 'paragraphs' => $paragraphs, 'tables' => [], 'slip' => false];
        $doc = ['sections' => [$section(0, 'مخصصات أخرى', ['يمثل المخصص  قيمة الالتزامات المتوقعة', 'عن قضايا قائمة.']), $section(1, 'تفاصيل', ['بحسب تقدير الإدارة.'], 0),
            $section(2, 'مخصص الزكاة', ['لا علاقة.'])], 'tables' => []];
        $this->assertSame('يمثل المخصص قيمة الالتزامات المتوقعة عن قضايا قائمة. بحسب تقدير الإدارة.', PdfDefaults::otherProvisions($doc));
        $this->assertNull(PdfDefaults::otherProvisions(['sections' => [$section(0, 'مخصص الزكاة', ['نص'])], 'tables' => []]));
        $this->assertNull(PdfDefaults::otherProvisions(['sections' => [], 'tables' => []]));
    }
}
