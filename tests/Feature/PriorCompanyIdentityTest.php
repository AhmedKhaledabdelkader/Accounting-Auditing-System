<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\Company;
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
 * Last year's statements naming another company than the report's: said (a warning, the file's details apart from the
 * profile's, the app's companies the file names), never acted on. Made-up companies.
 */
class PriorCompanyIdentityTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private function report(string $companyId): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 100]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
        ], ['opening', 'closing'])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'company_id' => $companyId, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $h = fn ($text, $number = null) => ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
        $t = fn (array $headers, array $rows) => ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($x) => ['header' => $x], $headers),
            'rows' => array_map(fn ($r) => ['label' => $r[0], 'note' => null, 'cells' => $r[1]], $rows)];
        $answers = [
            2 => [$h('قائمة المركز المالي'), $t(['2024', '2023'], [['نقد', ['100', '100']], ['إجمالي الأصول', ['100', '100']], ['رأس المال', ['100', '100']],
                ['إجمالي حقوق الملكية والالتزامات', ['100', '100']]])],
            3 => [$h('الإيضاحات'), $h('نبذة عن الشركة', '1'), ['type' => 'paragraph', 'number' => null, 'columns' => [], 'rows' => [],
                'text' => 'شركة النور للتجارة شركة ذات مسؤولية محدودة مسجلة بموجب السجل التجاري رقم 2222222222 الصادر من مدينة جدة.']],
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

    public function test_a_file_of_another_company_is_said_and_the_report_stays_where_it_is(): void
    {
        $chosen = Company::create(['name_ar' => 'شركة جديدة']);
        $other = Company::create(['name_ar' => 'شركة النور للتجارة', 'cr_number' => '2222222222']);
        $id = $this->report($chosen->id);
        $state = $this->getJson(self::URL."/{$id}")->assertOk()->json();

        // The registration isn't in the profile yet: only the name differs. The app's company of that registration is named.
        $identity = $state['comparative']['upload']['company_identity'];
        $this->assertSame([['name_ar', 'شركة النور للتجارة', 'شركة جديدة']], array_map(fn ($d) => [$d['field'], $d['file'], $d['company']], $identity['differs']));
        $this->assertSame([[$other->id, 'cr_number']], array_map(fn ($m) => [$m['id'], $m['by']], $identity['matches']));
        [$check] = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'company_differs_from_prior'));
        $this->assertSame('warning', $check['severity']);
        $this->assertStringContainsString('«شركة النور للتجارة»', $check['message_ar']);
        // The notes' company details still empty are named (the file's are offered, never filled on their own).
        $company = collect($state['missing'])->firstWhere('key', 'notes.company');
        $this->assertSame('company_profile_incomplete', $company['reason']);
        $this->assertContains('unified_number', $company['fields']);
        $this->assertContains('cr_number', $company['fields']);
        // Never acted on: the report keeps its company, the profile its name.
        $this->assertSame([$chosen->id, 'شركة جديدة'], [FinancialReport::find($id)->company_id, $chosen->fresh()->name_ar]);

        // The user moves the report to the company the file names: nothing left to say.
        $this->patchJson(self::URL."/{$id}", ['company_id' => $other->id])->assertOk();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame([], $state['comparative']['upload']['company_identity']['differs']);
        $this->assertNotContains('company_differs_from_prior', array_column($state['checks'], 'code'));
    }
}
