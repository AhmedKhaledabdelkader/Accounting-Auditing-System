<?php

namespace Tests\Feature;

use App\Services\Statements\Figure;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorStatementsPdf;
use App\Support\Pdf\Qpdf;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * Last year's statements from a PDF: the AI finds the statements' pages in the whole file, then copies each statement's
 * table from its own pages; PHP reads the amounts, picks the year column, matches the lines and adds up the totals.
 */
class PriorStatementsPdfTest extends TestCase
{
    use PdfFiles;

    /** @var list<array{tool: string, pages: int, text: string}> what was sent: the tool, the PDF's page count, the message */
    private array $sent = [];

    private function file(): string
    {
        return $this->pdfFile(['<p>تقرير المراجع</p>', '<h1>قائمة المركز المالي</h1>', '<h1>قائمة الدخل</h1>', '<p>تابع قائمة الدخل</p>', '<p>إيضاحات</p>']);
    }

    /** The AI answering by tool: the pages it finds, a table per statement, and a page's amount columns as lists. */
    private function fakeAi(array $pages, array $tables, array $columns = [], array $misread = []): void
    {
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($pages, $tables, $columns, $misread) {
            $body = $request->data();
            $tool = FakeWire::toolName($body);
            $cut = tempnam(sys_get_temp_dir(), 'sent');
            file_put_contents($cut, FakeWire::pdf($body));
            $this->sent[] = ['tool' => $tool, 'pages' => (new Qpdf)->pages($cut), 'text' => FakeWire::userText($body)];
            @unlink($cut);
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);
            $input = match ($tool) {
                'submit_pages'   => ['pages' => $pages],
                'submit_columns' => ['tables' => $columns[(int) ($m[1] ?? 0)] ?? []],
                // Read once more because a line differs from this year's opening: $misread's answer, else the same.
                default          => ['blocks' => (str_contains(FakeWire::userText($body), 'may be misread') ? null : ($misread[(int) ($m[1] ?? 0)] ?? null))
                    ?? $tables[(int) ($m[1] ?? 0)] ?? []],
            };

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), $tool, $input));
        }]);
    }

    private static function row(string $label, array $cells, ?string $note = null): array
    {
        return ['label' => $label, 'note' => $note, 'cells' => $cells];
    }

    private static function table(array $headers, array $rows): array
    {
        return ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows];
    }

    private static function heading(string $text, ?string $number = null): array
    {
        return ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
    }

    private static function page(int $page, string $kind, array $notes = [], int $rotation = 0, ?string $printed = null): array
    {
        return ['page' => $page, 'kind' => $kind, 'printed_page' => $printed, 'rotation' => $rotation, 'notes' => $notes];
    }

    public function test_every_page_is_described_and_the_statements_pages_follow(): void
    {
        $path = $this->file();
        $this->fakeAi([
            self::page(1, 'auditor_report'),
            self::page(3, 'comprehensive_income', [], 0, '2'),
            self::page(2, 'financial_position', [], 0, '1'),
            self::page(2, 'cash_flows'),                                                   // a second answer: the first kept
            self::page(4, 'comprehensive_income', [], 90),                                 // continued, printed sideways
            self::page(5, 'notes', [['number' => '5', 'title' => 'ممتلكات وآلات ومعدات'], ['number' => null, 'title' => '']]),
            self::page(9, 'notes'),                                                        // past the last page: dropped
        ], []);

        $found = app(PriorStatementsPdf::class)->locate($path, 5);

        $this->assertSame(['auditor_report', 'financial_position', 'comprehensive_income', 'comprehensive_income', 'notes'], array_column($found['page_kinds'], 'kind'));
        $this->assertSame([null, '1', '2', null, null], array_column($found['page_kinds'], 'printed_page'));
        $this->assertSame([0, 0, 0, 90, 0], array_column($found['page_kinds'], 'rotation'));
        $this->assertSame([['number' => '5', 'title' => 'ممتلكات وآلات ومعدات']], $found['page_kinds'][4]['notes']);   // an empty title dropped
        $this->assertSame(['financial_position' => ['from' => 2, 'to' => 2], 'comprehensive_income' => ['from' => 3, 'to' => 4]], $found['pages']);
        // R9: last year's auditor's report is named so it is never read.
        $this->assertSame(['auditor_report' => ['from' => 1, 'to' => 1]], $found['skipped']);
        $this->assertSame([['tool' => 'submit_pages', 'pages' => 5, 'text' => 'The file has 5 pages. Describe every page, from 1 to 5.']], $this->sent);
        $this->assertSame(1, $found['usage']['requests']);

        // Read again: the answer is kept by the file's content, nothing is sent.
        $again = app(PriorStatementsPdf::class)->locate($path, 5);
        $this->assertSame($found['page_kinds'], $again['page_kinds']);
        $this->assertCount(1, $this->sent);
        $this->assertSame(0, $again['usage']['requests']);
    }

    public function test_each_page_is_read_alone_and_php_decides_years_totals_and_notes(): void
    {
        $path = $this->file();
        $years = ['31 ديسمبر 2025م / ريال سعودي', '31 ديسمبر 2024م / ريال سعودي'];
        $this->fakeAi([], [
            2 => [self::heading('شركة المثال'), self::heading('قائمة المركز المالي'), self::table($years, [
                self::row('الموجودات', [null, null]),
                self::row('النقد ومعادلات النقد', ['1,500', '1,200'], '7'),
                self::row('المخزون', ['٥٠٠', '300']),
                self::row('مجموع الأصول', ['2,000', '1,500']),
                self::row('حقوق الملكية', [null, null]),
                self::row('رأس المال', ['2,000', '1,500']),
                self::row('مجموع حقوق الملكية والالتزامات', ['2,000', '1,500']),
            ])],
            // The income statement over two pages; 2024's cost unread («?»); «صافي الربح» printed 1,700 for 1,600.
            3 => [self::heading('قائمة الدخل الشامل'), self::table(array_reverse($years), [
                self::row('إيرادات النشاط', ['4,000', '5,000']),
                self::row('تكلفة النشاط', ['(2,5?0)', '(3,000)']),
                self::row('مجمل الربح', ['1,500', '2,000']),
            ])],
            4 => [self::table(array_reverse($years), [
                self::row('مصروفات عمومية وإدارية', ['(300)', '(400)']),
                self::row('صافي الدخل (الخسارة) للسنة', ['1,200', '1,700']),
            ])],
            // A note headed with the wrong years (2024/2023 in 2025's statements): read as 2025/2024, flagged.
            5 => [self::heading('الإيضاحات'), self::heading('النقد ومعادلات النقد', '7'),
                self::table(['2024م', '2023م'], [self::row('نقد بالبنوك', ['1,400', '1,100']), self::row('نقد بالصندوق', ['100', '100']), self::row('المجموع', ['1,500', '1,200'])])],
        ]);
        $kinds = [self::page(1, 'auditor_report'), self::page(2, 'financial_position'), self::page(3, 'comprehensive_income'),
            self::page(4, 'comprehensive_income'), self::page(5, 'notes')];

        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));

        // One call per page read, each with its page alone; the auditor's report never sent. The income statement's checks
        // fail (an unread digit, a total that doesn't add up): its amount columns are asked as lists (none given here),
        // then its pages are read again, twice, then left flagged.
        $this->assertSame([2, 3, 4, 5], array_slice(array_map(fn ($t) => (int) substr($t, 13), array_map(fn ($c) => $c['text'], $this->sent)), 0, 4));
        $this->assertSame([1], array_values(array_unique(array_column($this->sent, 'pages'))));
        $this->assertSame([['submit_columns', 3], ['submit_columns', 4], ['submit_page', 3], ['submit_page', 4], ['submit_page', 3], ['submit_page', 4]],
            array_map(fn ($c) => [$c['tool'], (int) substr($c['text'], 13)], array_slice($this->sent, 4)));
        $this->assertStringContainsString('An earlier copy of this page failed a check', $this->sent[6]['text']);
        $this->assertSame(['financial_position', 'comprehensive_income'], array_column($read['blocks'], 'statement'));

        [$fp, $is] = $read['blocks'];
        $cash = collect($fp['rows'])->firstWhere('label', 'النقد ومعادلات النقد');
        $this->assertSame(['sofp.cash', 'exact', 2, '7'], [$cash['match'], $cash['certainty'], $cash['page'], $cash['note']]);
        $this->assertSame(1200.0, $cash['values'][$fp['column']]['amount']);
        $this->assertSame(300.0, collect($fp['rows'])->firstWhere('label', 'المخزون')['values'][$fp['column']]['amount']);   // Arabic digits read
        $rows = collect($is['rows'])->keyBy('label');
        $this->assertSame('unread', $rows['تكلفة النشاط']['values'][$is['column']]['status']);
        $this->assertSame([3, 3, 3, 4, 4], $rows->pluck('page')->values()->all());
        // «صافي الدخل (الخسارة) للسنة» is the matcher's net profit; 2025's rows above it (back to the gross profit) add up to 1,600, not the 1,700 printed.
        $this->assertSame([['column' => 'C', 'sum' => 1600.0, 'printed' => 1700.0]], $rows['صافي الدخل (الخسارة) للسنة']['check']);

        $doc = $read['document'];
        $this->assertSame(['current' => 2025, 'comparative' => 2024, 'from' => 'financial_position'], $doc['years']);
        $sofp = $doc['statements']['financial_position']['rows'];
        // Capital equals total assets, but a heading lies between: a line, not a roll-up of the total above it.
        $this->assertSame(['heading', 'line', 'line', 'total', 'heading', 'line', 'total'], array_column($sofp, 'kind'));
        $this->assertSame([1, 2], $sofp[3]['sum_of']);
        // The note: linked by its number, title and amount; its years read as the statements' and flagged.
        $link = collect($doc['links'])->first(fn ($l) => $l['statement'] === 'financial_position' && $l['row'] === 1);
        $this->assertSame([['number', 'title', 'amount'], true], [$link['by'], $link['certain']]);
        $this->assertSame('7', $doc['sections'][$link['section']]['printed']);
        $this->assertContains(['code' => 'year_typo', 'page' => 5, 'pages' => [5], 'table' => 3, 'tables' => [3], 'note' => '7', 'printed' => [2024, 2023], 'used' => [2025, 2024]], $doc['flags']);
        // Read again, the pages said the same: the first reading kept, the failing checks left for the accountant with their pages.
        $this->assertSame([2 => 0, 3 => 0, 4 => 0, 5 => 0], array_map(fn ($p) => $p['attempt'], $doc['pages']));
        app()->setLocale('ar');
        $issues = \App\Services\Statements\PriorPdf\PriorPdfIssues::of($doc);
        $this->assertContains(['code' => 'check_unread', 'severity' => 'error', 'pages' => [3], 'message' => 'صفحة 3: توجد أرقام لم تُقرأ جيدًا («?»). افتح الصفحة واكتبها.'], $issues);
        $this->assertContains('صفحة 5، إيضاح 7: مكتوب 2024/2023 وعومل على أنه 2025/2024.', array_column($issues, 'message'));
    }

    public function test_a_cash_flow_printed_off_its_lines_is_placed_by_its_arithmetic(): void
    {
        $path = $this->file();
        $years = ['2025', '2024'];
        $rows = fn (array $amounts) => array_map(fn ($l, $a) => self::row($l, $a), ['التدفقات من أنشطة التشغيل', 'صافي الخسارة قبل الزكاة', 'إهلاك',
            'صافي الدخل المعدل', 'مستحق إلى أطراف ذات علاقة', 'صافي التدفق النقدي من الأنشطة التشغيلية', 'صافي التغير في النقد', 'رصيد النقد في بداية السنة',
            'رصيد النقد في نهاية السنة'], $amounts);
        // Read row by row, the dash of the net change is lost: everything below moves up a row, the opening cash repeated.
        $misread = [[null, null], ['(500)', '(400)'], ['100', '100'], ['(400)', '(300)'], ['400', '300'], ['-', '-'], ['70', '70'], ['70', '70'], ['70', '70']];
        $this->fakeAi([], [2 => [self::heading('قائمة التدفقات النقدية'), self::table($years, $rows($misread))]],
            [2 => [['columns' => [['values' => ['(500)', '100', '(400)', '400', '-', '-', '70', '70']], ['values' => ['(400)', '100', '(300)', '300', '-', '-', '70', '70']]]]]]);

        $read = app(PriorStatementsPdf::class)->read($path, [self::page(2, 'cash_flows')], 2024, new LineCatalogue('sa-ifrs-smes-3'));

        // The columns asked as lists once; one placement holds: used, flagged, the page never read again.
        $this->assertSame(['submit_page', 'submit_columns'], array_column($this->sent, 'tool'));
        $doc = $read['document'];
        $this->assertSame([], array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok'])));
        $this->assertContains(['code' => 'aligned_by_arithmetic', 'page' => 2, 'statement' => 'cash_flows'], $doc['flags']);
        $cf = collect($read['blocks'][0]['rows'])->keyBy('label');
        $this->assertSame([0.0, 70.0, 70.0], [$cf['صافي التغير في النقد']['values']['C']['amount'], $cf['رصيد النقد في بداية السنة']['values']['C']['amount'],
            $cf['رصيد النقد في نهاية السنة']['values']['C']['amount']]);
        app()->setLocale('ar');
        $this->assertContains('قائمة التدفقات النقدية (صفحة 2): الأرقام مطبوعة بعيدًا عن سطورها، فوُضعت في أماكنها بالحساب (الترتيب الوحيد الذي تتطابق معه المجاميع). راجع الصفحة.',
            array_column(\App\Services\Statements\PriorPdf\PriorPdfIssues::of($doc), 'message'));
    }

    public function test_a_page_whose_line_differs_from_this_years_opening_is_read_once_more(): void
    {
        $path = $this->file();
        $sofp = fn (string $cash, string $stock) => [self::heading('قائمة المركز المالي'), self::table(['2024', '2023'], [
            self::row('النقد ومعادلات النقد', [$cash, '50']), self::row('المخزون', [$stock, '50']), self::row('إجمالي الأصول', ['200', '100']),
            self::row('رأس المال', ['200', '100']), self::row('إجمالي حقوق الملكية والالتزامات', ['200', '100'])])];
        // The first reading swaps 30 between two lines (the totals still hold); read again, the page says 100 and 100.
        $this->fakeAi([], [2 => $sofp('100', '100')], [], [2 => $sofp('130', '70')]);
        $opening = ['sofp.cash' => new Figure(100.0, 'trial_balance_opening'), 'sofp.inventory' => new Figure(100.0, 'trial_balance_opening'),
            'sofp.share_capital' => new Figure(200.0, 'trial_balance_opening')];

        $read = app(PriorStatementsPdf::class)->read($path, [self::page(2, 'financial_position')], 2024, new LineCatalogue('sa-ifrs-smes-3'), [], [], $opening);

        $this->assertSame(['submit_page', 'submit_page'], array_column($this->sent, 'tool'));
        $this->assertStringContainsString('the amounts of «المخزون», «النقد ومعادلات النقد» may be misread', $this->sent[1]['text']);
        $rows = collect($read['blocks'][0]['rows'])->keyBy('label');
        $this->assertSame([100.0, 100.0], [$rows['النقد ومعادلات النقد']['values']['B']['amount'], $rows['المخزون']['values']['B']['amount']]);
        $this->assertSame(3, $read['document']['pages'][2]['attempt']);
        $this->assertSame(2, $read['usage']['requests']);
    }

    public function test_the_statements_are_checked_against_each_other_and_the_equity_read_for_the_grid(): void
    {
        $path = $this->file();
        $years = ['2024', '2023'];
        $this->fakeAi([], [
            2 => [self::heading('قائمة المركز المالي'), self::table($years, [self::row('النقد ومعادلات النقد', ['70', '50']), self::row('إجمالي الأصول', ['70', '50']),
                self::row('رأس المال', ['100', '100']), self::row('أرباح (خسائر) مبقاة', ['(30)', '(50)']), self::row('إجمالي حقوق الملكية', ['70', '50']),
                self::row('إجمالي حقوق الملكية والالتزامات', ['70', '50'])])],
            3 => [self::heading('قائمة الدخل الشامل'), self::table($years, [self::row('إيرادات النشاط', ['20', '-']), self::row('صافي الدخل (الخسارة) للسنة', ['20', '-'])])],
            4 => [self::heading('قائمة التغيرات في حقوق الملكية'), self::table(['رأس المال', 'أرباح مبقاة', 'المجموع'], [
                self::row('الرصيد في 1 يناير 2024', ['100', '(50)', '50']), self::row('صافي ربح السنة', ['-', '20', '20']),
                self::row('الرصيد في 31 ديسمبر 2024', ['100', '(30)', '70'])])],
            // The cash flow ends on 80, the balance sheet's cash is 70.
            5 => [self::heading('قائمة التدفقات النقدية'), self::table($years, [self::row('صافي الدخل (الخسارة) للسنة', ['20', '-']),
                self::row('صافي التدفقات النقدية من الأنشطة التشغيلية', ['20', '-']), self::row('صافي التدفقات النقدية', ['20', '-']),
                self::row('رصيد النقدية في أول السنة', ['60', '50']), self::row('رصيد النقدية في آخر السنة', ['80', '50'])])],
        ]);
        $kinds = [self::page(2, 'financial_position'), self::page(3, 'comprehensive_income'), self::page(4, 'changes_in_equity'), self::page(5, 'cash_flows')];

        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));

        $cross = array_values(array_filter($read['document']['checks'], fn ($c) => str_starts_with($c['code'], 'cross_')));
        $this->assertSame([['cross_equity_sofp', true], ['cross_equity_sofp', true], ['cross_profit_equity', true], ['cross_cash_sofp', false]],
            array_map(fn ($c) => [$c['code'], $c['ok']], $cross));
        $this->assertSame([5, 2], $cross[3]['pages']);
        app()->setLocale('ar');
        $this->assertContains('قائمة التدفقات النقدية (صفحات 5، 2): رصيد النقد آخر السنة (80) لا يساوي النقد في قائمة المركز المالي (70). راجع الصفحات.',
            array_column(\App\Services\Statements\PriorPdf\PriorPdfIssues::of($read['document']), 'message'));
        // Last year's changes in equity, for step 2's grid.
        $this->assertEquals(['opening' => ['share_capital' => 100, 'retained_earnings' => -50], 'net_profit' => ['retained_earnings' => 20]], $read['equity']['rows']);
        // Each row says what its arithmetic makes it (a total needs no line of its own).
        $this->assertSame('total', collect($read['blocks'][0]['rows'])->firstWhere('label', 'إجمالي حقوق الملكية')['kind']);
    }
}
