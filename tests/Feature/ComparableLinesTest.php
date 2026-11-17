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
 * Accounts this year's trial balance puts on another line than last year's statements (comparability): proposed back
 * by last year's note lines (an account named as one, or the one whose opening equals one, wherever its line), by the
 * one set of accounts adding up to a note line or to what a line is short; never against the account's side; never
 * moved by themselves. Made-up accounts and figures.
 */
class ComparableLinesTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** @param bool $vat whether this year's VAT account is there (named as last year's line) */
    private function report(bool $vat = true): string
    {
        $rows = [
            $this->account(2, 'مصروفات مدفوعة مقدماً', ['opening' => 250, 'closing' => 250], ['account_name' => 'مصاريف مدفوعة مقدما']),
            // Last year's «الزكاة» among the prepayments; this year on the zakat provision with a debit balance.
            $this->account(3, 'مخصص الزكاة الشرعية', ['opening' => 50, 'closing' => 50], ['account_name' => 'مخصص الزكاة والدخل']),
            // An employee's custody, a debit: named as an accruals note line, but never to a credit line.
            $this->account(4, 'عُهد وسُلف العاملين', ['opening' => 20, 'closing' => 20], ['account_name' => 'عهد وسلف موظفين سالم']),
            $this->account(5, 'نقدية لدى البنوك', ['opening' => 850, 'closing' => 850], ['account_name' => 'البنك الأهلي']),
            $this->account(6, 'موردون', ['opening' => -400, 'closing' => -400], ['account_name' => 'مورد المواد']),
            $this->account(7, 'موردون', ['opening' => -300, 'closing' => -300], ['account_name' => 'مؤسسة الريان']),
            $this->account(8, 'موردون', ['opening' => -150, 'closing' => -150], ['account_name' => 'مكتب الندى']),
            $this->account(9, 'موردون', ['opening' => -55, 'closing' => -55], ['account_name' => 'فندق الساحل']),
            ...($vat ? [$this->account(10, 'موردون', ['opening' => -50, 'closing' => -50], ['account_name' => 'ضريبة القيمة المضافة المستحقة'])] : []),
            // On a line last year's statements don't print, with the amount of last year's «استقطاعات» note line; without
            // the VAT account, the amount of last year's VAT line.
            $this->account(13, 'إيرادات مؤجلة', ['opening' => $vat ? -30 : -50, 'closing' => $vat ? -30 : -50], ['account_name' => 'إيرادات مقدمة']),
            $this->account(11, 'موردون', ['opening' => -120, 'closing' => -120], ['account_name' => 'شركة الواحة']),
            $this->account(12, 'مصروفات مستحقة', ['opening' => -100, 'closing' => -100], ['account_name' => 'ايجارات مستحقة']),
        ];
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, ['opening', 'closing'])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024'], ['header' => '2023']], 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table([$row('مصاريف مقدمة وأرصدة مدينة أخرى', ['300', '200'], '8'), $row('النقد ومعادلات النقد', ['850', '600']),
                $row('إجمالي الأصول', ['1,150', '800']), $row('ذمم تجارية دائنة', ['400', '300']), $row('مصاريف مستحقة وأرصدة دائنة أخرى', ['630', '400'], '11'),
                $row('مستحق لأطراف ذات علاقة', ['120', '100']), $row('إجمالي حقوق الملكية والالتزامات', ['1,150', '800'])])],
            3 => [$heading('الإيضاحات'), $heading('مصاريف مقدمة وأرصدة مدينة أخرى', '8'),
                $table([$row('مصروفات مدفوعة مقدما', ['250', '200']), $row('الزكاة', ['50', '-']), $row('', ['300', '200'])]),
                $heading('مصاريف مستحقة وأرصدة دائنة أخرى', '11'),
                $table([$row('ايجارات مستحقة', ['100', '100']), $row('دائنون متنوعون', ['450', '300']), $row('ضريبة القيمة المضافة', ['50', '-']),
                    $row('استقطاعات', ['30', '-']), $row('', ['630', '400'])])],
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

    public function test_accounts_are_proposed_back_by_last_years_note_lines_and_sets_never_against_their_side(): void
    {
        $id = $this->report();
        $comparable = $this->getJson(self::URL."/{$id}")->assertOk()->json('comparative.upload.comparable');
        $proposals = array_map(fn ($p) => [$p['line'], array_map(fn ($a) => [$a['row'], $a['from'], $a['amount'], $a['by'], $a['item']], $p['accounts'])], $comparable['proposals']);
        // Matched by the amount alone (by amount or a set), the names differ: said, to be accepted one by one.
        $this->assertSame([3 => false, 10 => false, 13 => true, 7 => true, 8 => true, 11 => true],
            array_column(array_merge(...array_column($comparable['proposals'], 'accounts')), 'amount_only', 'row'));

        $this->assertEquals([
            // The zakat account, on the provision with a debit balance: last year's «الزكاة» of the prepayments note.
            ['sofp.prepayments_other_receivables', [[3, 'sofp.zakat_provision', 50, 'name', 'الزكاة']]],
            // VAT by its name; a line last year didn't print, by the amount of a note line; «دائنون متنوعون» by the one set
            // adding up to it; the custody (a debit) never here.
            ['sofp.accruals_other_payables', [[10, 'sofp.trade_payables', 50, 'name', 'ضريبة القيمة المضافة'], [13, 'sofp.contract_liabilities', 30, 'amount', 'استقطاعات'],
                [7, 'sofp.trade_payables', 300, 'sum', 'دائنون متنوعون'], [8, 'sofp.trade_payables', 150, 'sum', 'دائنون متنوعون']]],
            // A line without a note, short: the one account adding up to it.
            ['sofp.due_to_related_parties', [[11, 'sofp.trade_payables', 120, 'sum', null]]],
        ], $proposals);
        // Nothing moved by itself; the decoy stays where it is.
        $this->assertSame([], FinancialReport::find($id)->account_overrides ?? []);
    }

    public function test_an_amount_match_of_another_nature_is_never_proposed(): void
    {
        // Deferred revenue whose opening equals last year's VAT line, and no VAT account this year: the same amount by
        // chance (a revenue is no tax); nor is it part of a set for a payables line.
        $id = $this->report(vat: false);
        $comparable = $this->getJson(self::URL."/{$id}")->assertOk()->json('comparative.upload.comparable');
        $accounts = array_merge(...array_column($comparable['proposals'], 'accounts'));

        $this->assertNotContains(13, array_column($accounts, 'row'));
        $this->assertNotContains('ضريبة القيمة المضافة', array_column($accounts, 'item'));
        // The zakat provision to last year's «الزكاة» (both zakat) is still proposed.
        $this->assertContains([3, 'الزكاة'], array_map(fn ($a) => [$a['row'], $a['item']], $accounts));
    }
}
