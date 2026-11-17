<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Services\Statements\Figure;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorNoteItems;
use App\Services\Statements\PriorStatementsPdf;
use App\Services\Statements\ReportStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * The notes' item tables as last year's notes printed them (R11, R12, D3), with last year's statements from a PDF: last
 * year's lines and amounts, this year's accounts placed on them. Made-up figures.
 */
class PriorNoteItemsTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** A report on a trial balance with inventory and cash accounts, last year's statements confirmed from a PDF. */
    private function report(array $liabilities = [], array $accounts = []): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'مخزون إنتاج تام', ['opening' => 400, 'closing' => 450], ['account_name' => 'مخزون انتاج تام']),
            $this->account(3, 'مخزون مواد خام', ['opening' => 100, 'closing' => 120], ['account_name' => 'مواد أولية بالمستودع']),
            $this->account(4, 'مخزون إنتاج تحت التشغيل', ['opening' => 0, 'closing' => 25], ['account_name' => 'بضاعة بالطريق']),
            $this->account(5, 'نقدية بالصندوق', ['opening' => 30, 'closing' => 40], ['account_name' => 'الخزينة الرئيسية']),
            $this->account(6, 'نقدية لدى البنوك', ['opening' => 50, 'closing' => 60], ['account_name' => 'بنك أ']),
            $this->account(7, 'نقدية لدى البنوك', ['opening' => 20, 'closing' => 10], ['account_name' => 'بنك ب']),
            $this->account(8, 'سيارات ووسائل نقل', ['opening' => 250, 'closing' => 220], ['account_name' => 'سيارات']),
            ...$accounts,
        ], ['opening', 'closing'])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024'], ['header' => '2023']], 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table([$row('الممتلكات والآلات والمعدات', ['250', '200']), $row('أصول أخرى', ['50', '60']),
                $row('المخزون', ['500', '450'], '7'), $row('النقد ومعادلات النقد', ['100', '90'], '9'),
                ...array_map(fn ($l) => $row(...$l), $liabilities), $row('إجمالي الأصول', ['900', '800'])])],
            // Last year's notes 7 and 9, each with its lines and its total.
            3 => [$heading('الإيضاحات'), $heading('المخزون', '7'),
                $table([$row('مخزون انتاج تام', ['400', '380']), $row('خامات', ['100', '70']), $row('المجموع', ['500', '450'])]),
                $heading('نقدية وأرصدة لدى البنوك', '9'),
                $table([$row('نقدية بالصندوق', ['30', '20']), $row('النقد بالبنوك', ['70', '70']), $row('المجموع', ['100', '90'])]),
                $heading('مخصص الزكاة الشرعية', '12'),
                $table([$row('صافي الربح قبل الزكاة', ['100', '90']), $row('مخصصات مكونة', ['10', '5']), $row('صافي الدخل المعدل', ['110', '95']),
                    $row('إجمالي الإضافات إلى الوعاء', ['500', '400']), $row('إجمالي الحسميات من الوعاء', ['(300)', '(250)']), $row('صافي الوعاء', ['310', '245']),
                    $row('الزكاة المستحقة', ['8', '6'])]),
                $heading('اعتماد القوائم المالية', '19'), ['type' => 'paragraph', 'number' => null, 'text' => 'قامت الإدارة العليا بالموافقة على القوائم المالية في 2025/03/30', 'columns' => [], 'rows' => []]],
        ];
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answers) {
            $body = $request->data();
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => $answers[(int) ($m[1] ?? 0)] ?? [], 'tables' => []]));
        }]);
        $path = $this->pdfFile(['<h1>قائمة المركز المالي</h1>', '<p>الإيضاحات</p>', '<p>.</p>']);
        $kinds = [['page' => 2, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null], ['page' => 3, 'kind' => 'notes', 'rotation' => 0, 'printed_page' => null]];
        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));
        $file = ['name' => 'قوائم 2024.pdf', 'kind' => 'pdf', 'status' => 'ready', 'year' => 2024, 'uploaded_at' => now()->toIso8601String(), 'pages_total' => 3,
            'pages' => [], 'found' => [], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null];
        FinancialReport::find($id)->update(['comparative_start' => '2024-01-01', 'comparative_end' => '2024-12-31',
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false]]);

        return $id;
    }

    private function rows(string $id, string $note): array
    {
        return array_map(fn ($r) => [$r['label_ar'], $r['current']->amount, $r['comparative']->amount],
            app(ReportStatements::class)->build(FinancialReport::find($id))['breakdowns'][$note]['rows']);
    }

    public function test_the_notes_keep_last_years_lines_and_this_years_accounts_are_placed_on_them(): void
    {
        $id = $this->report();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        // Inventory: by name; by an opening equal to the line last year (the names differ); a new account is a new item.
        $by = array_column($state['note_items']['inventory']['accounts'], null, 'label');
        $this->assertSame([['مخزون انتاج تام', 'name'], ['خامات', 'amount'], ['new', 'new']],
            [[$by['مخزون انتاج تام']['item'], $by['مخزون انتاج تام']['by']], [$by['مواد أولية بالمستودع']['item'], $by['مواد أولية بالمستودع']['by']],
                [$by['بضاعة بالطريق']['item'], $by['بضاعة بالطريق']['by']]]);
        $this->assertSame(['7', 'المخزون'], [$state['note_items']['inventory']['note'], $state['note_items']['inventory']['title']]);
        // No zakat in either year and no line of last year's linked to its zakat note: no zakat note.
        $this->assertFalse(collect($state['notes'])->firstWhere('key', 'zakat')['visible']);
        // Last year's lines, as printed, last year's amounts; the new item «-» last year (D3).
        $this->assertEquals([['مخزون انتاج تام', 450, 400], ['خامات', 120, 100], ['مخزون إنتاج تحت التشغيل', 25, 0]], $this->rows($id, 'inventory'));
        // Cash: one account by its opening, a class's accounts together by theirs.
        $this->assertEquals([['نقدية بالصندوق', 40, 30], ['النقد بالبنوك', 70, 70]], $this->rows($id, 'cash'));

        // The accountant moves the new account onto a line of last year (R12), then back to the proposal.
        $this->patchJson(self::URL."/{$id}/notes", ['items' => ['inventory' => ['4' => 'خامات']]])->assertOk();
        $this->assertEquals([['مخزون انتاج تام', 450, 400], ['خامات', 145, 100]], $this->rows($id, 'inventory'));
        $this->patchJson(self::URL."/{$id}/notes", ['items' => ['inventory' => ['4' => null]]])->assertOk();
        $this->assertCount(3, $this->rows($id, 'inventory'));
        $this->patchJson(self::URL."/{$id}/notes", ['items' => ['inventory' => ['4' => 'سطر لا يوجد']]])->assertStatus(422)->assertJsonValidationErrors('items.inventory.4');
        $this->patchJson(self::URL."/{$id}/notes", ['items' => ['inventory' => ['99' => 'new']]])->assertStatus(422)->assertJsonValidationErrors('items.inventory.99');
    }

    public function test_a_note_last_year_printed_no_table_for_takes_last_year_from_its_line(): void
    {
        $row = fn (string $label, float $current) => ['key' => $label, 'label_ar' => $label, 'current' => new Figure($current, 'trial_balance'), 'opening' => null,
            'comparative' => Figure::missing(), 'rows' => []];
        $breakdowns = [
            'trade_payables' => ['lines' => ['sofp.trade_payables'], 'rows' => [$row('موردون', 120)]],
            'other_income'   => ['lines' => ['is.other_income'], 'rows' => [$row('أرباح بيع أصول', 80), $row('إيرادات متنوعة', 5)]],
            'finance_cost'   => ['lines' => ['is.finance_cost'], 'rows' => [$row('عمولات بنكية', 30)]],
            'accruals'       => ['lines' => ['sofp.accruals_other_payables'], 'rows' => [$row('مصروفات مستحقة', 40), $row('عهد دائنة', 10)]],
            'inventory'      => ['lines' => ['sofp.inventory'], 'rows' => [$row('خامات', 10)], 'prior_items' => true],
        ];
        $comparative = ['sofp.trade_payables' => new Figure(300.0, 'prior_year_statements'), 'is.other_income' => new Figure(0.0, 'prior_year_statements'),
            'is.finance_cost' => new Figure(-25.0, 'prior_year_statements'), 'sofp.accruals_other_payables' => new Figure(90.0, 'prior_year_statements'),
            'sofp.inventory' => new Figure(70.0, 'prior_year_statements')];
        $out = PriorNoteItems::fromLines($breakdowns, $comparative, new LineCatalogue('sa-ifrs-smes-3'));
        $prior = fn (string $note) => array_map(fn ($r) => $r['comparative']->isMissing() ? null : $r['comparative']->amount, $out[$note]['rows']);

        // One row: the line's amount (an expense as the notes list it, positive); the line «-»: every row «-».
        $this->assertSame([300.0], $prior('trade_payables'));
        $this->assertSame([25.0], $prior('finance_cost'));
        $this->assertSame([0.0, 0.0], $prior('other_income'));
        $this->assertSame('prior_year_statements', $out['trade_payables']['rows'][0]['comparative']->origin);
        // Several rows of a line that had an amount: the accountant's; a note read from last year's table: as it is.
        $this->assertSame([null, null], $prior('accruals'));
        $this->assertSame([null], $prior('inventory'));
    }

    public function test_last_years_note_a_riyal_off_its_line_puts_the_riyal_on_its_largest_line(): void
    {
        $row = fn (string $label, float $prior) => ['key' => $label, 'label_ar' => $label, 'current' => new Figure(0.0, 'trial_balance'), 'opening' => null,
            'comparative' => new Figure($prior, 'prior_year_statements', 'ok', [['type' => 'prior_pdf', 'page' => 3]]), 'rows' => []];
        $breakdowns = [
            'other_receivables' => ['lines' => ['sofp.prepayments_other_receivables'], 'rows' => [$row('سلف موظفين', 60), $row('مصروفات مقدمة', 20)], 'prior_items' => true],
            'cash'              => ['lines' => ['sofp.cash'], 'rows' => [$row('بنوك', 70), $row('صندوق', 20)], 'prior_items' => true],
        ];
        $comparative = ['sofp.prepayments_other_receivables' => new Figure(81.0, 'prior_year_statements'), 'sofp.cash' => new Figure(95.0, 'prior_year_statements')];
        $out = PriorNoteItems::fromLines($breakdowns, $comparative, new LineCatalogue('sa-ifrs-smes-3'));
        $prior = fn (string $note) => array_map(fn ($r) => $r['comparative']->amount, $out[$note]['rows']);

        // One riyal: on the largest line, said; five: a real difference, left for the tie check.
        $this->assertSame([61.0, 20.0], $prior('other_receivables'));
        $this->assertSame(['amount' => 1.0, 'item' => 'سلف موظفين'], $out['other_receivables']['comparative_remainder']);
        $this->assertSame([70.0, 20.0], $prior('cash'));
        $this->assertArrayNotHasKey('comparative_remainder', $out['cash']);
    }

    public function test_a_note_with_no_lines_last_year_takes_its_accounts_openings_when_they_add_up_to_its_line(): void
    {
        $row = fn (string $label, float $opening) => ['key' => $label, 'label_ar' => $label, 'current' => new Figure(0.0, 'trial_balance'),
            'opening' => new Figure($opening, 'trial_balance_opening'), 'comparative' => Figure::missing(), 'rows' => []];
        $breakdowns = [
            'other_assets' => ['lines' => ['sofp.other_assets'], 'rows' => [$row('أصول أخرى', 100.4), $row('مجمع إطفاء أصول أخرى', -99.8)]],
            'other_payables' => ['lines' => ['sofp.accruals_other_payables'], 'rows' => [$row('مصروفات مستحقة', 40), $row('عهد دائنة', 10)]],
        ];
        $comparative = ['sofp.other_assets' => new Figure(1.0, 'prior_year_statements'), 'sofp.accruals_other_payables' => new Figure(90.0, 'prior_year_statements')];
        $out = PriorNoteItems::fromLines($breakdowns, $comparative, new LineCatalogue('sa-ifrs-smes-3'));
        $prior = fn (string $note) => array_map(fn ($r) => $r['comparative']->isMissing() ? null : $r['comparative']->amount, $out[$note]['rows']);

        // The openings add up to the line but for the riyal's rounding: they are last year's, the rest on the largest.
        $this->assertSame([100.8, -99.8], $prior('other_assets'));
        $this->assertSame(['amount' => 0.4, 'item' => 'أصول أخرى'], $out['other_assets']['comparative_remainder']);
        // They don't: the accountant's.
        $this->assertSame([null, null], $prior('other_payables'));
    }

    public function test_a_note_last_year_printed_stays_though_its_lines_are_nothing_in_both_years(): void
    {
        // Last year's zakat line «-» in both years, with its note 12: the note stays (R11; without the line, the first test).
        $state = $this->getJson(self::URL.'/'.$this->report([['مخصص الزكاة الشرعية', ['-', '-'], '12']]))->assertOk()->json();
        $this->assertTrue(collect($state['notes'])->firstWhere('key', 'zakat')['visible']);
    }

    public function test_a_provisions_comparative_closing_is_last_years_approved_line(): void
    {
        // Last year's approved zakat provision «-»; the trial balance opens it at 30 (the year's openings kept as they are).
        $id = $this->report([['مخصص الزكاة الشرعية', ['-', '-'], '12']], [$this->account(9, 'مخصص الزكاة الشرعية', ['opening' => -30, 'closing' => -30])]);
        $this->putJson(self::URL."/{$id}/prior-year/approved-opening", ['enabled' => false])->assertOk();
        $built = app(ReportStatements::class)->build(FinancialReport::find($id));
        $this->assertEquals([0.0, 'prior_year_statements'], [$built['schedules_comparative']['zakat.closing.amount']->amount, $built['schedules_comparative']['zakat.closing.amount']->origin]);
        $this->assertEquals(30, $built['schedules'][array_search('zakat', array_column($built['schedules'], 'key'))]['rows'][0]['cells']['amount']->amount);
    }

    public function test_the_notes_questions_take_last_years_answers_from_the_pdf(): void
    {
        $id = $this->report();
        $fields = collect($this->getJson(self::URL."/{$id}/intake")->assertOk()->json('fields'))->keyBy('key');

        // The approving body as last year's approval note says it (the date stays this year's, the accountant's).
        $this->assertSame('الإدارة العليا', $fields['approval.body']['default']);
        $this->assertNull($fields['approval.date']['default'] ?? null);
    }

    public function test_the_zakat_base_takes_last_years_lines_and_asks_only_what_the_statements_dont_give(): void
    {
        $id = $this->report();
        $base = fn () => $this->getJson(self::URL."/{$id}")->assertOk()->json('zakat_base');
        $rows = fn (array $b) => array_map(fn ($r) => [$r['label'], $r['kind'], $r['prior'], $r['current']['amount'] ?? null, $r['from']], $b['rows']);

        // Last year's lines, as printed, last year's amounts; this year's from the statements where they give it. The two
        // «إجمالي» lines are lines (R10), asked under their own wording. Last year's deductions (300) are its PPE (250) and
        // other assets (50): proposed again with this year's (PPE 220; other assets 50: last year's approved 50, no movement,
        // the trial balance has none, Step B), flagged; nothing explains the additions (500).
        $this->assertEquals([['صافي الربح قبل الزكاة', 'line', 100, 0, 'statements'], ['مخصصات مكونة', 'line', 10, 0, 'schedules'], ['صافي الدخل المعدل', 'total', 110, 0, 'total'],
            ['إجمالي الإضافات إلى الوعاء', 'line', 500, null, 'input'], ['إجمالي الحسميات من الوعاء', 'line', -300, -270, 'derived'], ['صافي الوعاء', 'total', 310, null, 'total'],
            ['الزكاة المستحقة', 'line', 8, 0, 'statements']], $rows($base()));
        $fields = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->keyBy('key');
        $this->assertSame(['إجمالي الإضافات إلى الوعاء', false], [$fields['zakat.base.4']['label_ar'], $fields['zakat.base.4']['required']]);
        $this->assertArrayNotHasKey('zakat.base.1', $fields->all());
        $this->assertEquals(-270, $fields['zakat.base.5']['default']);
        $this->assertSame(['الممتلكات والآلات والمعدات', 'أصول أخرى'], $base()['rows'][4]['components']);
        $this->assertContains('zakat_base_derived', array_column($this->getJson(self::URL."/{$id}")->json('checks'), 'code'));

        // Answered: the base adds up this year.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.base.4' => 700, 'zakat.base.5' => -200]])->assertOk();
        $this->assertEquals([700, -200, 500], array_map(fn ($r) => $r['current']['amount'], array_slice($base()['rows'], 3, 3)));
        // The notes' zakat base table (the Word file's too): a row per line, as printed, both years.
        $table = $this->getJson(self::URL."/{$id}")->json('narrative.items.zakat_base_label');
        $this->assertEquals([['صافي الربح قبل الزكاة', 0, 100], ['إجمالي الإضافات إلى الوعاء', 700, 500], ['صافي الوعاء', 500, 310]],
            array_map(fn ($r) => [$r['zakat_base_label'], $r['zakat_base_cy']['amount'], $r['zakat_base_py']['amount']], array_values(array_intersect_key($table, [0 => 1, 3 => 1, 5 => 1]))));
    }

    public function test_no_zakat_with_its_reason_leaves_nothing_to_enter_in_the_zakat_base(): void
    {
        // A zakat provision this year (the note shows); no charge.
        $id = $this->report([], [$this->account(9, 'مخصص الزكاة الشرعية', ['opening' => 0, 'closing' => -5])]);
        $state = fn () => $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $this->assertContains('notes.zakat', array_column($state()['missing'], 'key'));
        $this->assertContains('zakat_base_derived', array_column($state()['checks'], 'code'));

        // The reason is the one switch: a line not answered is «-» (0), not asked or proposed; the note says the reason.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.zero_reason' => 'سبب تجريبي']])->assertOk();
        $s = $state();
        $this->assertEquals([[0, 'input'], [0, 'input'], [0, 'total']], array_map(fn ($r) => [$r['current']['amount'], $r['from']], array_slice($s['zakat_base']['rows'], 3, 3)));
        $this->assertNotContains('notes.zakat', array_column($s['missing'], 'key'));
        $this->assertNotContains('zakat_base_derived', array_column($s['checks'], 'code'));
        $this->assertSame([true, 'سبب تجريبي'], [$s['narrative']['blocks']['zakat_zero'], $s['narrative']['values']['zakat_zero_reason']]);

        // A line still typed is the accountant's.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.base.4' => 700]])->assertOk();
        $this->assertEquals(700, $state()['zakat_base']['rows'][3]['current']['amount']);
    }
}
