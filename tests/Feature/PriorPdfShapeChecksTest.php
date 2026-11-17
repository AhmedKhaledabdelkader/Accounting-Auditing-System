<?php

namespace Tests\Feature;

use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPdf\PriorPdfIssues;
use App\Services\Statements\PriorStatementsPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * Last year's statements checked once their rows are placed on lines, by their own shape: a reading whose own arithmetic
 * holds can still lose a line (a label read on a row of its own, a row of nothing with a label read another way). The page
 * is read once more, then what still fails is flagged with its page. Made-up figures; the AI answers the same each time.
 */
class PriorPdfShapeChecksTest extends TestCase
{
    use PdfFiles, RefreshDatabase;

    public function test_a_line_the_statements_shape_requires_is_read_again_then_flagged_with_its_page(): void
    {
        $row = fn ($label, $cells) => ['label' => $label, 'note' => null, 'cells' => $cells];
        $table = fn (array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024'], ['header' => '2023']], 'rows' => $rows];
        $answers = [
            1 => [$table([$row('النقد ومعادلات النقد', ['150', '150']), $row('إجمالي الأصول', ['150', '150']), $row('رأس المال', ['100', '100']),
                $row('إجمالي حقوق الملكية', ['100', '100']), $row('ذمم تجارية دائنة', ['50', '50']), $row('إجمالي الالتزامات', ['50', '50']),
                $row('إجمالي حقوق الملكية والالتزامات', ['150', '150'])])],
            // Gross profit's label read on a row of its own, its amounts on a row without one; no revenue, so the gross loss
            // is the cost's amount (the cost's row is not it).
            2 => [$table([$row('إيرادات النشاط', ['-', '-']), $row('تكلفة النشاط', ['(100)', '(100)']), $row('', ['(100)', '(100)']), $row('مجمل الربح', [null, null]),
                $row('مصروفات عمومية وإدارية', ['(50)', '(50)']), $row('صافي الدخل (الخسارة) للسنة', ['(150)', '(150)'])])],
            // The year's net change of nothing, its label read another way: on no line, and step 2 asks nothing of a row of nothing.
            3 => [$table([$row('صافي التدفقات النقدية من الأنشطة التشغيلية', ['-', '-']), $row('مجموع النقد المتوفرمن جميع الانشطة', ['-', '-']),
                $row('رصيد النقدية في أول السنة', ['150', '150']), $row('رصيد النقدية في آخر السنة', ['150', '150'])])],
        ];
        $calls = [];
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answers, &$calls) {
            $body = $request->data();
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);
            $page = (int) ($m[1] ?? 0);
            $calls[$page] = ($calls[$page] ?? 0) + 1;

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => $answers[$page] ?? [], 'tables' => []]));
        }]);
        $path = $this->pdfFile(['<h1>قائمة المركز المالي</h1>', '<h1>قائمة الدخل</h1>', '<h1>قائمة التدفقات النقدية</h1>']);
        $kinds = [['page' => 1, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null], ['page' => 2, 'kind' => 'comprehensive_income', 'rotation' => 0, 'printed_page' => null],
            ['page' => 3, 'kind' => 'cash_flows', 'rotation' => 0, 'printed_page' => null]];

        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));

        // Each page read once more (their own arithmetic holds: no retry before), then flagged with its page.
        $this->assertSame([2, 2], [$calls[2], $calls[3]]);
        $failing = array_values(array_filter($read['document']['checks'], fn ($c) => ! $c['ok'] && $c['code'] === 'line_missing'));
        $this->assertSame([['comprehensive_income', 'مجمل الربح (الخسارة)', [2]], ['cash_flows', 'صافي التدفقات النقدية', [3]]],
            array_map(fn ($c) => [$c['statement'], $c['label'], $c['pages']], $failing));
        $issues = array_values(array_filter(PriorPdfIssues::of($read['document']), fn ($i) => $i['code'] === 'check_line_missing'));
        $this->assertSame([[2], [3]], array_column($issues, 'pages'));
        $this->assertStringContainsString('«مجمل الربح (الخسارة)»', $issues[0]['message']);
    }

    public function test_a_cash_flow_read_a_row_off_its_lines_has_no_closing_cash_and_is_read_again(): void
    {
        // The amounts a row up: the net change shows the cash, the closing cash nothing (its arithmetic still holds above).
        $row = fn ($label, $cells) => ['label' => $label, 'note' => null, 'cells' => $cells];
        $answer = [['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024'], ['header' => '2023']], 'rows' => [
            $row('صافي التدفقات النقدية من الأنشطة التشغيلية', ['-', '-']), $row('صافي التدفقات النقدية', ['150', '150']),
            $row('رصيد النقدية في أول السنة', ['150', '150']), $row('رصيد النقدية في آخر السنة', [null, null])]]];
        $calls = 0;
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answer, &$calls) {
            $body = $request->data();
            $calls++;

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => FakeWire::toolName($body) === 'submit_page' ? $answer : [], 'tables' => []]));
        }]);
        $read = app(PriorStatementsPdf::class)->read($this->pdfFile(['<h1>قائمة التدفقات النقدية</h1>']), [['page' => 1, 'kind' => 'cash_flows', 'rotation' => 0, 'printed_page' => null]],
            2024, new LineCatalogue('sa-ifrs-smes-3'));

        $missing = collect($read['document']['checks'])->first(fn ($c) => $c['code'] === 'line_missing' && $c['statement'] === 'cash_flows');
        $this->assertSame(['رصيد النقدية في آخر السنة', [1]], [$missing['label'], $missing['pages']]);
        // Read once more after its own checks (the page alone), then flagged.
        $this->assertGreaterThanOrEqual(2, $calls);
    }
}
