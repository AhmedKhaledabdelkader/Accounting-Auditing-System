<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorStatementsPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * This year's openings from last year's approved statements (Step B): each line's closing = last year's approved closing
 * + this year's movement in the trial balance, everywhere (statements, notes, PPE table, changes in equity, cash flow),
 * the trial balance's differing openings reconciled line by line. Made-up figures.
 *
 * The trial balance opens at PPE 90 (cost 110, depreciation 20), inventory 500 (finished goods 400, raw materials 100),
 * cash 100; trade payables 120, a related party 30, capital 500, retained earnings 40. Last year's approved statements
 * close at PPE 85 (depreciation 25), inventory 480 (finished goods 380), cash 100; trade payables 100, the related
 * party 45 (no note: the line), capital 500, retained earnings 20. The year: sales 300, salaries 100, depreciation 5,
 * a building 10, goods 70 bought on credit.
 */
class ApprovedOpeningTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /**
     * @param list<string> $groups the trial balance's columns (a closing-only one: ['closing'])
     * @param string $depreciation the accumulated depreciation account's name: placed on last year's category by it, or not
     */
    private function report(array $groups = ['opening', 'period', 'closing'], string $depreciation = 'مجمع إهلاك مباني وانشاءات'): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'مباني وإنشاءات', ['opening' => 110, 'period' => [10, 0]], ['account_name' => 'مبنى المصنع']),
            $this->account(3, 'مجمع إهلاك الأصول الثابتة', ['opening' => -20, 'period' => [0, 5]], ['account_name' => $depreciation]),
            $this->account(4, 'مخزون إنتاج تام', ['opening' => 400, 'period' => [50, 0]], ['account_name' => 'مخزون انتاج تام']),
            $this->account(5, 'مخزون مواد خام', ['opening' => 100, 'period' => [20, 0]], ['account_name' => 'خامات']),
            $this->account(6, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [300, 110]], ['account_name' => 'بنك أ']),
            $this->account(7, 'موردون', ['opening' => -120, 'period' => [0, 70]], ['account_name' => 'الموردون']),
            $this->account(8, 'اطراف ذات علاقة دائنة', ['opening' => -30], ['account_name' => 'شركة شقيقة']),
            $this->account(9, 'رأس المال', ['opening' => -500], ['account_name' => 'رأس المال']),
            $this->account(10, 'أرباح محتجزة', ['opening' => -40], ['account_name' => 'أرباح مبقاة']),
            $this->account(11, 'إيرادات المبيعات', ['period' => [0, 300]], ['account_name' => 'المبيعات']),
            $this->account(12, 'أجور ورواتب', ['period' => [100, 0]], ['account_name' => 'رواتب']),
            $this->account(13, 'إهلاك الأصول الثابتة', ['period' => [5, 0]], ['account_name' => 'إهلاك']),
        ], $groups)]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table(['2024', '2023'], [
                $row('الممتلكات والآلات والمعدات', ['85', '80'], '5'), $row('المخزون', ['480', '400'], '7'), $row('النقد ومعادلات النقد', ['100', '90']),
                $row('إجمالي الأصول', ['665', '570']),
                $row('رأس المال', ['500', '500']), $row('أرباح (خسائر) مبقاة', ['20', '(10)']), $row('إجمالي حقوق الملكية', ['520', '490']),
                $row('ذمم تجارية دائنة', ['100', '60']), $row('مستحق إلى أطراف ذات علاقة', ['45', '20']), $row('إجمالي الالتزامات', ['145', '80']),
                $row('إجمالي حقوق الملكية والالتزامات', ['665', '570'])])],
            3 => [$heading('الإيضاحات'), $heading('السياسات المحاسبية', '4'), $table(['النسبة %'], [$row('مباني وانشاءات', ['5'])]),
                $heading('ممتلكات وآلات ومعدات', '5'), $table(['مباني وانشاءات / ريال', 'الإجمالي / ريال'], [
                    $row('التكلفة', [null, null]), $row('الرصيد في 1 يناير 2024', ['100', '100']), $row('إضافات خلال السنة', ['10', '10']),
                    $row('الرصيد في 31 ديسمبر 2024', ['110', '110']),
                    $row('الإهلاك المتراكم', [null, null]), $row('الرصيد في 1 يناير 2024', ['20', '20']), $row('إضافات خلال السنة', ['5', '5']),
                    $row('الرصيد في 31 ديسمبر 2024', ['25', '25']),
                    $row('صافي القيمة الدفترية', [null, null]), $row('في 31 ديسمبر 2024', ['85', '85']), $row('في 31 ديسمبر 2023', ['80', '80'])]),
                $heading('المخزون', '7'),
                $table(['2024', '2023'], [$row('مخزون انتاج تام', ['380', '330']), $row('خامات', ['100', '70']), $row('المجموع', ['480', '400'])])],
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
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false], 'inputs' => ['oci.has_items' => false]]);

        return $id;
    }

    /**
     * Accounts on another line than last year: deferred revenue 15 (last year inside other payables, its note naming it)
     * and a related party 40 (last year in trade payables with other suppliers, nothing saying so), paid 35 this year.
     * Cash 100, suppliers 20, capital 25. Last year's approved statements: cash 100; trade payables 55, other payables 15;
     * capital 30.
     */
    private function movedReport(float $cash = 100, float $capital = 25): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => $cash, 'period' => [0, 35]], ['account_name' => 'بنك أ']),
            $this->account(3, 'إيرادات مؤجلة', ['opening' => -15], ['account_name' => 'إيرادات مقدمة']),
            $this->account(4, 'اطراف ذات علاقة دائنة', ['opening' => -40, 'period' => [35, 0]], ['account_name' => 'شركة شقيقة']),
            $this->account(5, 'موردون', ['opening' => -20], ['account_name' => 'الموردون']),
            $this->account(6, 'رأس المال', ['opening' => -$capital], ['account_name' => 'رأس المال']),
        ])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table(['2024', '2023'], [
                $row('النقد ومعادلات النقد', ['100', '100']), $row('إجمالي الأصول', ['100', '100']),
                $row('رأس المال', ['30', '30']), $row('إجمالي حقوق الملكية', ['30', '30']),
                $row('ذمم تجارية دائنة', ['55', '55']), $row('مصاريف مستحقة وأرصدة دائنة أخرى', ['15', '15'], '8'), $row('إجمالي الالتزامات', ['70', '70']),
                $row('إجمالي حقوق الملكية والالتزامات', ['100', '100'])])],
            3 => [$heading('الإيضاحات'), $heading('مصاريف مستحقة وأرصدة دائنة أخرى', '8'),
                $table(['2024', '2023'], [$row('إيرادات مقدمة', ['15', '15']), $row('المجموع', ['15', '15'])])],
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
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false], 'inputs' => ['oci.has_items' => false]]);

        return $id;
    }

    public function test_a_difference_follows_an_account_that_moved_lines_and_a_sign_it_changes_is_never_silent(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->movedReport())->assertOk()->json();
        $line = fn (string $key) => $this->line($state, 'financial_position', $key);

        // The deferred revenue was in other payables last year (its note says so): its difference follows it, so this
        // year's line keeps the account's balance and other payables start without it; never a line zeroed by it.
        $this->assertSame([15, 0], $line('sofp.contract_liabilities'));
        $this->assertSame([0, 15], $line('sofp.accruals_other_payables'));
        $moved = collect($state['comparative']['approved_opening']['lines'])->firstWhere('line', 'sofp.contract_liabilities')['moved'];
        $this->assertSame([['row' => 3, 'to' => 'sofp.accruals_other_payables', 'amount' => 15]], array_map(fn ($m) => array_intersect_key($m, array_flip(['row', 'to', 'amount'])), $moved));
        // Nothing says where the related party was last year: its line starts at last year's «-» and shows −35 (40 paid
        // 35). Never silent: a warning naming the line, the amount and the cause.
        $this->assertSame([-35, 0], $line('sofp.due_to_related_parties'));
        $checks = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'approved_opening_sign'));
        $this->assertSame([['warning', 'sofp.due_to_related_parties', 5.0, -35.0, -40.0]], array_map(fn ($c) => [$c['severity'], $c['line_key'], (float) $c['expected'], (float) $c['actual'], (float) $c['difference']], $checks));
        $this->assertStringContainsString('مستحق لأطراف ذات علاقة', $checks[0]['message_ar']);
        $this->assertStringContainsString('40.00', $checks[0]['message_ar']);
        $lines = collect($state['comparative']['approved_opening']['lines'])->keyBy('line');
        $this->assertEquals([-15, 15], [$lines['sofp.contract_liabilities']['moved_total'], $lines['sofp.accruals_other_payables']['moved_total']]);
        // Explained by the account: no opening-vs-last-year error on either line.
        $this->assertSame([], array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'opening_vs_prior_closing'
            && in_array($c['line_key'], ['sofp.contract_liabilities', 'sofp.accruals_other_payables'], true))));
        // Still balanced.
        $this->assertSame($line('sofp.total_assets')[0], $line('sofp.total_equity_and_liabilities')[0]);
    }

    public function test_a_line_turned_over_names_the_accounts_the_user_moved_to_it(): void
    {
        // The user moves the suppliers' account (opening 20) to the related parties: last year's approved statements had
        // it on trade payables, so the related parties' line opens at last year's «-» and shows −35. The warning names the
        // account moved and its opening; nothing is moved back on its own.
        $id = $this->movedReport();
        $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 5, 'line_key' => 'sofp.due_to_related_parties']]])->assertOk();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        $this->assertSame(-35, $this->line($state, 'financial_position', 'sofp.due_to_related_parties')[0]);
        [$check] = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'approved_opening_sign'));
        $this->assertSame([['row' => 5, 'from' => 'sofp.trade_payables', 'to' => 'sofp.due_to_related_parties', 'opening' => 20]],
            array_map(fn ($a) => array_intersect_key($a, array_flip(['row', 'from', 'to', 'opening'])), $check['reclassified']));
        $this->assertStringContainsString('«الموردون» (20.00)', $check['message_ar']);
        $line = collect($state['comparative']['approved_opening']['lines'])->firstWhere('line', 'sofp.due_to_related_parties');
        $this->assertSame([5], array_column($line['reclassified'], 'row'));
        $this->assertSame('sofp.due_to_related_parties', collect($state['account_overrides'])->firstWhere('row', 5)['line_key']);
    }

    /** A statement's line: [this year, last year]. */
    private function line(array $state, string $statement, string $key): array
    {
        $r = collect($state['statements'][$statement])->firstWhere('key', $key);

        return [$r['current']['amount'] ?? null, $r['comparative']['amount'] ?? null];
    }

    private function codes(array $state): array
    {
        return array_column($state['checks'], 'code');
    }

    public function test_each_line_closes_at_last_years_approved_closing_plus_this_years_movement(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report())->assertOk()->json();
        $line = fn (string $key) => $this->line($state, 'financial_position', $key);

        // Approved closing + the year's movement; the comparative column the approved statements'.
        $this->assertSame([90, 85], $line('sofp.ppe'));                       // 85 + 10 − 5 (the trial balance: 95)
        $this->assertSame([550, 480], $line('sofp.inventory'));               // 480 + 70 (the trial balance: 570)
        $this->assertSame([290, 100], $line('sofp.cash'));
        $this->assertSame([170, 100], $line('sofp.trade_payables'));          // 100 + 70 (the trial balance: 190)
        $this->assertSame([45, 45], $line('sofp.due_to_related_parties'));    // no movement (the trial balance: 30)
        $this->assertSame([215, 20], $line('sofp.retained_earnings'));        // 20 + the year's profit 195
        // The balance sheet balances.
        $this->assertSame([930, 665], $line('sofp.total_assets'));
        $this->assertSame([930, 665], $line('sofp.total_equity_and_liabilities'));
        $this->assertNotContains('sofp_unbalanced', $this->codes($state));

        // The changes in equity roll forward from the approved closing.
        $equity = collect($state['statements']['changes_in_equity']['current'])->keyBy('key');
        $this->assertSame([520, 195, 715], [$equity['opening']['cells']['total']['amount'], $equity['net_profit']['cells']['total']['amount'],
            $equity['closing']['cells']['total']['amount']]);
        $this->assertSame([715, 520], $line('sofp.total_equity'));
        $this->assertEmpty(array_intersect(['soce_opening_mismatch', 'soce_closing_mismatch', 're_roll'], $this->codes($state)));

        // The cash flow's changes are the year's movements: from the approved openings to the closings presented.
        $cf = fn (string $key) => $this->line($state, 'cash_flows', $key)[0];
        $this->assertSame([-70, 70, 200, -10, 100, 290], [$cf('cf.wc_inventory'), $cf('cf.wc_trade_payables'), $cf('cf.net_operating'), $cf('cf.net_investing'),
            $cf('cf.opening_cash'), $cf('cf.closing_cash')]);
        $this->assertEmpty(array_intersect(['cash_flow_closing_mismatch', 'cash_flow_opening_mismatch'], $this->codes($state)));
    }

    public function test_the_notes_and_the_ppe_table_start_from_the_approved_closing_and_tie_in_both_years(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report())->assertOk()->json();

        // Inventory: last year's lines, each opening at its approved amount; finished goods 380 + 50, raw materials 100 + 20.
        $inventory = array_column($state['note_items']['inventory']['accounts'], null, 'label');
        $this->assertSame([['مخزون انتاج تام', 'name'], ['خامات', 'name']], [[$inventory['مخزون انتاج تام']['item'], $inventory['مخزون انتاج تام']['by']],
            [$inventory['خامات']['item'], $inventory['خامات']['by']]]);
        $differences = array_values(array_filter($state['note_items']['inventory']['accounts'], fn ($a) => $a['approved_opening']));
        $this->assertSame([['مخزون انتاج تام', -20]], array_map(fn ($a) => [$a['item'], $a['current']], $differences));

        // The PPE table by last year's category: opening at the approved closing, the year's movement from the trial balance.
        $ppe = collect($state['schedules'])->firstWhere('key', 'ppe');
        $cell = fn (string $row) => collect($ppe['rows'])->firstWhere('key', $row)['cells']['total']['amount'];
        $this->assertSame([110, 10, 120, 25, 5, 30, 90], [$cell('cost_opening'), $cell('additions'), $cell('cost_closing'), $cell('acc_opening'),
            $cell('depreciation'), $cell('acc_closing'), $cell('nbv_closing')]);
        // Per category, this year's opening in the trial balance against last year's closing: the difference still shown.
        $this->assertSame([['cost', 'equal'], ['accumulated', 'difference']], array_map(fn ($o) => [$o['side'], $o['kind']], $state['ppe_prior']['categories'][0]['opening_check']));
        // An account on no category of last year's: the category holds its balance; the account keeps only its movement.
        $state = $this->getJson(self::URL.'/'.$this->report(depreciation: 'مجمع إهلاك مبنى المصنع'))->assertOk()->json();
        $ppe = collect($state['schedules'])->firstWhere('key', 'ppe');
        $cell = fn (string $row) => collect($ppe['rows'])->firstWhere('key', $row)['cells']['total']['amount'];
        $this->assertSame([110, 25, 30, 90], [$cell('cost_opening'), $cell('acc_opening'), $cell('acc_closing'), $cell('nbv_closing')]);
        $this->assertSame([90, 85], $this->line($state, 'financial_position', 'sofp.ppe'));

        // Every note ties to its statement line in both years; the related party at last year's balance (the line, no note).
        $this->assertEmpty(array_intersect(['note_total_mismatch', 'asset_rollforward'], $this->codes($state)));
        $this->assertNotContains('opening_vs_prior_closing', $this->codes($state));
    }

    public function test_last_years_line_put_on_the_one_party_whose_account_opened_otherwise_is_flagged(): void
    {
        // No related parties' note: the line (45) goes on the one party, whose account opened at 30. Said, until the table is saved.
        $id = $this->report();
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $flag = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'related_party_line_on_one'));
        $this->assertSame([['warning', 45, 30, 15]], array_map(fn ($c) => [$c['severity'], $c['expected'], $c['actual'], $c['difference']], $flag));
        $this->assertStringContainsString('«شركة شقيقة»', $flag[0]['message_ar']);
        $parties = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'related_parties')['default'];
        $this->assertEquals(-45, $parties[0]['balance_py']);   // the proposal still takes the line (R15); the accountant decides

        // Saved by the accountant (confirmed or split): no longer said.
        $parties[0] = ['relationship' => 'شركة شقيقة', 'nature' => 'تمويل', 'volume_cy' => 0, 'volume_py' => 0] + $parties[0];
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['related_parties' => $parties]])->assertOk()->json();
        $this->assertNotContains('related_party_line_on_one', $this->codes($state));
    }

    public function test_the_trial_balances_openings_are_reconciled_line_by_line_and_in_total(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report())->assertOk()->json();
        $a = $state['comparative']['approved_opening'];

        $this->assertSame([true, 'applied', true], [$a['enabled'], $a['status'], $a['applied']]);
        // Per line: the trial balance's opening, the approved closing, the difference (trial balance − approved), the
        // trial balance's closing and the closing presented (the difference apart).
        $lines = collect($a['lines'])->keyBy('line');
        $this->assertEquals(['tb_opening' => 90, 'approved' => 85, 'difference' => 5, 'kind' => 'difference', 'tb_closing' => 95, 'presented' => 90],
            array_intersect_key($lines['sofp.ppe'], array_flip(['tb_opening', 'approved', 'difference', 'kind', 'tb_closing', 'presented'])));
        $this->assertEquals([20, 20, -15, 20, 0], [$lines['sofp.inventory']['difference'], $lines['sofp.trade_payables']['difference'],
            $lines['sofp.due_to_related_parties']['difference'], $lines['sofp.retained_earnings']['difference'], $lines['sofp.cash']['difference']]);
        // The totals apart: assets +25, equity +20, liabilities +5.
        $this->assertEquals([['assets', 25, 690, 665], ['equity', 20, 540, 520], ['liabilities', 5, 150, 145]],
            array_map(fn ($t) => [$t['part'], $t['difference'], $t['tb_opening'], $t['approved']], $a['totals']));
        $this->assertSame([['accumulated', 20, 25, -5]], array_map(fn ($p) => [$p['side'], $p['tb_opening'], $p['approved'], $p['difference']],
            array_values(array_filter($a['ppe'], fn ($p) => $p['kind'] !== 'equal'))));
        // One information check with the totals; the accounts step's totals stay the trial balance's (D1).
        $check = collect($state['checks'])->firstWhere('code', 'approved_opening');
        $this->assertSame('info', $check['severity']);
        $this->assertStringContainsString('assets 25.00, equity 20.00, liabilities 5.00', $check['message']);
        $this->assertSame(570.0, (float) $state['mapping']['line_totals']['sofp.inventory']['amount']);
        // Beside them, each line's part from the approved openings (the line presented − the trial balance's), so the
        // accounts step shows no difference to look for (Step C.4); a line with no difference has none.
        foreach ($a['lines'] as $l) {
            $this->assertEqualsWithDelta($l['presented'] - $l['tb_closing'], $state['mapping']['line_totals'][$l['line']]['approved_opening'] ?? 0, 0.01, $l['line']);
        }
        $this->assertEquals(-20, $state['mapping']['line_totals']['sofp.inventory']['approved_opening'] + 0);
        $this->assertArrayNotHasKey('approved_opening', $state['mapping']['line_totals']['sofp.cash']);
        // The step-2 opening check still compares the trial balance's own openings.
        $this->assertSame('difference', collect($state['comparative']['upload']['opening_check'])->firstWhere('line', 'sofp.inventory')['kind']);
    }

    public function test_the_differences_are_related_to_each_other_and_to_the_statements_they_reach(): void
    {
        $a = $this->getJson(self::URL.'/'.$this->report())->assertOk()->json('comparative.approved_opening');

        // The equation holds in both balance sheets, the differences meet (25 = 5 + 20), and all of the equity's 20 is retained earnings'.
        $relations = collect($a['relations'])->keyBy('code');
        $this->assertSame(['equation_tb', 'equation_approved', 'differences_net', 'equity_other_than_retained', 'opening_cash'], $relations->keys()->all());
        foreach ($relations as $code => $r) {
            $this->assertEquals(0, $r['difference'], $code);
            $this->assertTrue($r['ok'], $code);
        }
        // What the statements present against the trial balance alone: PPE 95 → 90, inventory is not listed, equity −20 and assets −25.
        $impact = collect($a['impact'])->keyBy('line');
        $this->assertEquals([95, 90, -5], [$impact['sofp.ppe']['tb_closing'], $impact['sofp.ppe']['presented'], $impact['sofp.ppe']['effect']]);
        $this->assertEquals(-25, $impact['sofp.total_assets']['effect']);
        $this->assertEquals(-20, $impact['sofp.retained_earnings']['effect']);
        // The balance sheet, the changes in equity (retained earnings) and the PPE note are reached; the cash isn't.
        $this->assertSame(['financial_position', 'changes_in_equity', 'notes_ppe'], $a['affects']);
    }

    public function test_the_setting_off_keeps_the_trial_balances_figures(): void
    {
        $id = $this->report();
        $state = $this->putJson(self::URL."/{$id}/prior-year/approved-opening", ['enabled' => false])->assertOk()->json();

        $this->assertSame([false, 'off'], [$state['comparative']['approved_opening']['enabled'], $state['comparative']['approved_opening']['status']]);
        $this->assertSame([570, 480], $this->line($state, 'financial_position', 'sofp.inventory'));
        // The comparative column is still last year's approved statements; the differences are check 21's again.
        $this->assertContains('opening_vs_prior_closing', $this->codes($state));
        $this->assertNotContains('approved_opening', $this->codes($state));
        // The reconciliation still shown; kept when the source is confirmed again.
        $this->assertSame(20, collect($state['comparative']['approved_opening']['lines'])->firstWhere('line', 'sofp.inventory')['difference']);
        $this->assertFalse(FinancialReport::find($id)->prior_figures['approved_opening']);

        $state = $this->putJson(self::URL."/{$id}/prior-year/approved-opening", ['enabled' => true])->assertOk()->json();
        $this->assertSame([550, 480], $this->line($state, 'financial_position', 'sofp.inventory'));
    }

    public function test_a_closing_only_trial_balance_says_the_method_cant_apply(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report(['closing']))->assertOk()->json();

        $this->assertSame('no_opening', $state['comparative']['approved_opening']['status']);
        $check = collect($state['checks'])->firstWhere('code', 'approved_opening_unavailable');
        $this->assertSame('warning', $check['severity']);
        // Never rolled silently from something else: the trial balance's closing balances as they are.
        $this->assertSame([570, 480], $this->line($state, 'financial_position', 'sofp.inventory'));
    }

    public function test_the_journal_sheet_shows_the_differences_for_information_only(): void
    {
        $id = $this->report();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, $this->get(self::URL."/{$id}/versions/1/export.xlsx")->assertOk()->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName(__('exports.journal.sheet', [], 'ar'));
        unlink($path);

        // Not an adjusting entry: its own block, labelled for information, the lines that would bring the books to the
        // approved figures, balanced.
        $cells = $sheet->toArray(null, true, false, false);
        $at = array_search(__('exports.journal.approved_opening.title', [], 'ar'), array_column($cells, 0), true);
        $this->assertNotFalse($at);
        $this->assertSame(__('exports.journal.none', [], 'ar'), $cells[4][0]);
        $rows = array_values(array_filter(array_slice($cells, $at + 4), fn ($r) => $r[1] !== null && ($r[2] !== null || $r[3] !== null)));
        $entry = array_map(fn ($r) => [$r[1], $r[2], $r[3]], array_slice($rows, 0, -1));
        $this->assertContains(['المخزون', null, 20.0], $entry);                    // the trial balance 20 over: credited
        $this->assertContains(['مستحق لأطراف ذات علاقة', null, 15.0], $entry);    // 15 under on a credit line: credited
        $this->assertSame([__('exports.journal.approved_opening.total', [], 'ar'), 40.0, 40.0], [end($rows)[1], (float) end($rows)[2], (float) end($rows)[3]]);
    }

    public function test_a_line_tied_to_an_outside_source_keeps_its_own_amount_its_sub_riyal_on_retained_earnings(): void
    {
        // The bank says 100.40 at the start; last year's statements print 100 (whole riyals).
        $state = $this->getJson(self::URL.'/'.$this->movedReport(100.4, 25.4))->assertOk()->json();
        $lines = collect($state['comparative']['approved_opening']['lines'])->keyBy('line');
        $line = fn (string $key) => collect($state['statements']['financial_position'])->firstWhere('key', $key);

        // Cash stays the bank's (65.40 at the end), the 0.40 on retained earnings, shown in the reconciliation.
        $this->assertEquals([65.4, -0.4, -0.4], [$line('sofp.cash')['current']['amount'], $lines['sofp.cash']['rounding_kept'], $lines['sofp.retained_earnings']['rounding_kept']]);
        $this->assertEquals(0.4, $line('sofp.retained_earnings')['current']['amount']);
        // The cash flow closes on it, from the bank's opening; the changes in equity print last year's closing.
        $cf = fn (string $key) => collect($state['statements']['cash_flows'])->firstWhere('key', $key)['current']['amount'];
        $this->assertEquals([100.4, 65.4], [$cf('cf.opening_cash'), $cf('cf.closing_cash')]);
        $this->assertEmpty(array_intersect(['cash_flow_closing_mismatch', 'cash_flow_opening_mismatch', 'soce_opening_mismatch', 're_roll', 'sofp_unbalanced'], array_column($state['checks'], 'code')));
        $this->assertSame($line('sofp.total_assets')['shown']['current'], $line('sofp.total_equity_and_liabilities')['shown']['current']);
    }

    public function test_last_years_note_figures_show_in_step_2_and_saving_twice_loses_nothing(): void
    {
        $id = $this->report();
        $report = FinancialReport::find($id);
        $report->update(['prior_figures' => ['file' => ['page_kinds' => [['page' => 1, 'kind' => 'auditor_report', 'rotation' => 0, 'printed_page' => null]]]
            + $report->prior_figures['file']] + $report->prior_figures]);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();
        $prior = $state['comparative']['entry']['notes_prior'];

        // A note read from the PDF: its lines as printed, read-only (typing there would change nothing), with its page.
        $this->assertEquals(['read_only' => true, 'page' => 3, 'values' => ['مخزون انتاج تام' => 380, 'خامات' => 100]], $prior['inventory']);
        // A note the PDF printed no table for: last year's figure from its approved line, to check or correct.
        $this->assertEquals(['read_only' => false, 'page' => null, 'values' => ['موردون' => 100]], $prior['trade_payables']);

        // Saved as the page sends it (the editable notes' figures, a correction typed): twice, nothing lost.
        $body = fn (array $s) => ['source' => 'statements', 'comparative_start' => $s['comparative_start'], 'comparative_end' => $s['comparative_end'],
            'notes' => array_filter(array_map(fn ($n) => $n['read_only'] ? null : $n['values'], $s['comparative']['entry']['notes_prior']))];
        $typed = $body($state);
        $typed['notes']['trade_payables'] = ['موردون' => 101];
        $first = $this->putJson(self::URL."/{$id}/prior-year", $typed)->assertOk()->json();
        $second = $this->putJson(self::URL."/{$id}/prior-year", $body($first))->assertOk()->json();
        $this->assertSame(101, $second['comparative']['entry']['notes_prior']['trade_payables']['values']['موردون']);
        $this->assertEquals($first['comparative']['entry']['notes_prior'], $second['comparative']['entry']['notes_prior']);
        $this->assertEquals($first['comparative']['notes'], $second['comparative']['notes']);
        $this->assertEquals($prior['inventory'], $second['comparative']['entry']['notes_prior']['inventory']);
    }

    public function test_the_setting_is_validated(): void
    {
        $id = $this->report();
        $this->putJson(self::URL."/{$id}/prior-year/approved-opening", [])->assertStatus(422)->assertJsonValidationErrors('enabled');
        $this->putJson(self::URL.'/'.Str::uuid().'/prior-year/approved-opening', ['enabled' => true])->assertNotFound();
    }
}
