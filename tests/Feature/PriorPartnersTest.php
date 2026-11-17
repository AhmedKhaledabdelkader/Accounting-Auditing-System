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
 * The partners from last year's capital note (Phase 7, R14): read by the table's arithmetic, prefilled with their
 * amounts and percentages; who is on which row confirmed by the trial balance's capital accounts in their names, else
 * the table waits for the accountant's review before the final version; names that don't pair up with the share rows
 * never put on a row. Made-up partners and figures.
 */
class PriorPartnersTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private const PARTNERS = [['سالم عبدالله الحربي', ['120', '1,000', '120,000']], ['هند خالد العتيبي', ['80', '1,000', '80,000']]];

    /**
     * A report with last year's PDF read: its capital note's partners table as given, the trial balance's capital
     * accounts as given (name → balance at the year's start).
     *
     * @param array<string, float> $capital
     */
    private function report(array $capital, array $partners = self::PARTNERS): string
    {
        $accounts = [$this->account(2, 'نقدية لدى البنوك', ['opening' => 200000, 'closing' => 200000])];
        foreach ($capital as $name => $amount) {
            $accounts[] = $this->account(count($accounts) + 2, 'رأس المال', ['opening' => -$amount, 'closing' => -$amount], ['account_name' => $name]);
        }
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($accounts, ['opening', 'closing'])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $row = fn ($label, $cells, $note = null) => ['label' => $label, 'note' => $note, 'cells' => $cells];
        $table = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers), 'rows' => $rows];
        $heading = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $answers = [
            2 => [$heading('قائمة المركز المالي'), $table(['2024', '2023'], [$row('نقد لدى البنوك', ['200,000', '200,000']), $row('إجمالي الأصول', ['200,000', '200,000']),
                $row('رأس المال', ['200,000', '200,000'], '11'), $row('إجمالي حقوق الملكية والالتزامات', ['200,000', '200,000'])])],
            3 => [$heading('الإيضاحات'), $heading('رأس المال', '11'),
                ['type' => 'paragraph', 'number' => null, 'text' => 'يبلغ رأس مال الشركة 200,000 ريال مقسم إلى 200 حصة قيمة كل حصة 1,000 ريال.', 'columns' => [], 'rows' => []],
                $table(['عدد الحصص', 'قيمة الحصة', 'المبلغ'], [...array_map(fn ($p) => $row($p[0], $p[1]), $partners), $row('الإجمالي', ['200', '-', '200,000'])])],
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

    private function checks(array $state, string $code): array
    {
        return array_values(array_filter($state['checks'], fn ($c) => $c['code'] === $code));
    }

    public function test_partners_confirmed_by_capital_accounts_in_their_names_need_no_review(): void
    {
        $id = $this->report(['رأس مال الشريك سالم عبدالله الحربي' => 120000, 'رأس مال الشريك هند خالد العتيبي' => 80000]);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        // Prefilled from last year's note, with their amounts and percentages; the accountant only reviews (R14).
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'partners');
        $this->assertSame([['سالم عبدالله الحربي', 120, 1000], ['هند خالد العتيبي', 80, 1000]], array_map(fn ($p) => [$p['name'], (int) $p['shares'], (int) $p['share_value']], $field['default']));
        $this->assertSame([['سالم عبدالله الحربي', '120', '1,000', '60%'], ['هند خالد العتيبي', '80', '1,000', '40%']],
            array_map(fn ($p) => [$p['partner_name'], $p['partner_shares'], $p['partner_share_value'], $p['partner_percentage']], $state['narrative']['items']['partner_name']));
        $this->assertCount(1, $this->checks($state, 'partners_confirmed'));
        $this->assertSame([], [...$this->checks($state, 'partners_review'), ...$this->checks($state, 'capital_note_mismatch')]);
    }

    public function test_partners_the_trial_balance_cant_confirm_wait_for_the_accountants_review(): void
    {
        $id = $this->report(['رأس المال' => 200000]);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        // Prefilled, but who is on which row only the accountant can say: the final version waits for the review.
        [$review] = $this->checks($state, 'partners_review');
        $this->assertSame(['warning', false], [$review['severity'], $review['acknowledged']]);
        $this->assertStringContainsString('صفحة 3', $review['message_ar']);
        // The way a scan goes wrong while the figures still add up: names a row off.
        $this->assertStringContainsString('منزاحة سطرًا عن أرقامها', $review['message_ar']);
        $this->assertContains('warnings_unacknowledged', array_column($state['blocking'], 'reason'));

        // Reviewed (acknowledged): no longer in the way.
        $state = $this->putJson(self::URL."/{$id}/acknowledgements", ['acknowledgements' => [['key' => $review['key']]]])->assertOk()->json();
        $this->assertTrue($this->checks($state, 'partners_review')[0]['acknowledged']);
        // A changed table is reviewed again.
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['partners' => [['name' => 'سالم عبدالله الحربي', 'shares' => 100, 'share_value' => 1000],
            ['name' => 'هند خالد العتيبي', 'shares' => 100, 'share_value' => 1000]]]])->assertOk()->json();
        $this->assertFalse($this->checks($state, 'partners_review')[0]['acknowledged']);
    }

    public function test_capital_accounts_holding_other_rows_amounts_disagree(): void
    {
        // Each partner's capital account holds the other's amount: the names may be on the wrong rows.
        $id = $this->report(['رأس مال الشريك سالم عبدالله الحربي' => 80000, 'رأس مال الشريك هند خالد العتيبي' => 120000]);
        [$review] = $this->checks($this->getJson(self::URL."/{$id}")->json(), 'partners_review');

        $this->assertStringContainsString('بمبالغ سطور أخرى', $review['message_ar']);
    }

    public function test_names_that_dont_pair_up_with_the_share_rows_are_never_put_on_a_row(): void
    {
        // The scan put the first name on a line of its own, and its shares on a row without a name.
        $id = $this->report(['رأس المال' => 200000], [['سالم عبدالله الحربي', [null, null, null]], ['', ['120', '1,000', '120,000']], ['هند خالد العتيبي', ['80', '1,000', '80,000']]]);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        // The share rows prefilled without names; the names read listed for the accountant to place.
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'partners');
        $this->assertSame(['', ''], array_column($field['default'], 'name'));
        [$review] = $this->checks($state, 'partners_review');
        $this->assertStringContainsString('سالم عبدالله الحربي، هند خالد العتيبي', $review['message_ar']);
        $this->assertStringContainsString('لم يوضع اسم على سطر', $review['message_ar']);

        // The accountant types the names: their table, still to be reviewed (the trial balance can't confirm it).
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['partners' => [['name' => 'سالم عبدالله الحربي', 'shares' => 120, 'share_value' => 1000],
            ['name' => 'هند خالد العتيبي', 'shares' => 80, 'share_value' => 1000]]]])->assertOk()->json();
        $this->assertStringContainsString('لا توجد في الميزان حسابات رأس مال', $this->checks($state, 'partners_review')[0]['message_ar']);
    }
}
