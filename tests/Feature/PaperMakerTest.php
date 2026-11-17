<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\Office;
use App\Models\PreparedPaper;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkingPapers\PaperMaker;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The AI prepares the working papers and the recalculations from what the client uploaded; the client and the accountant do not. */
class PaperMakerTest extends TestCase
{
    use RefreshDatabase;

    private function setUpClient(): array
    {
        Storage::fake('local');
        config(['financial_statements.prior_pdf.cache' => sys_get_temp_dir().'/paper-'.uniqid()]);
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'محاسب', 'is_admin' => true]);
        $sara = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $company = Company::forceCreate(['office_id' => $office->id, 'name_ar' => 'شركة النور', 'name_en' => 'Al Noor', 'client_name' => 'أحمد', 'client_email' => 'a@example.test',
            'cr_number' => '1010123456', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);

        return [$office, $sara, $company];
    }

    /** A document the client uploaded to a request of the given section. */
    private function upload(Office $office, Company $company, string $section, string $name = 'كشف الموظفين.csv'): ClientFile
    {
        $request = DocumentRequest::create(['office_id' => $office->id, 'company_id' => $company->id, 'year' => 2025, 'section' => $section, 'number' => 1, 'title' => 'بيان تفصيلي', 'position' => 1, 'status' => 'uploaded']);
        Storage::disk('local')->put("client-files/{$name}", "الموظف,البداية,الأساسي\nموظف أول,2020-01-01,1500\n");

        return ClientFile::create(['office_id' => $office->id, 'company_id' => $company->id, 'document_request_id' => $request->id, 'from_client' => true, 'original_name' => $name, 'path' => "client-files/{$name}", 'size' => 10]);
    }

    private function fakeAi(array $answer): object
    {
        $driver = new class($answer) implements ClassifierDriver
        {
            public array $calls = [];

            public function __construct(private array $answer) {}

            public function classify(ToolCall $call): PromiseInterface
            {
                $this->calls[] = $call;

                return Create::promiseFor(new ToolAnswer($this->answer, null));
            }
        };
        $this->app->instance(ClassifierDriver::class, $driver);
        $this->app->forgetInstance(\App\Services\Statements\PriorPdf\Llm::class);

        return $driver;
    }

    public function test_the_ai_prepares_a_recalculation_from_the_clients_documents_and_the_books_are_compared(): void
    {
        [$office, $sara, $company] = $this->setUpClient();
        $this->actingAs($sara);
        $this->upload($office, $company, 'المصاريف العمومية والإدارية والبيعية والتسويقية');
        ClassificationRun::forceCreate(['office_id' => $office->id, 'id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'company_id' => $company->id,
            'result' => ['data' => [['row_type' => 'account', 'account_number' => '2101', 'account_name' => 'مخصص نهاية الخدمة', 'sub_class' => 'مخصص نهاية الخدمة', 'closing_debit' => 0, 'closing_credit' => 5900]]]]);
        $ai = $this->fakeAi(['rows' => [
            ['name' => 'موظف أول', 'start' => '2020-01-01', 'end' => null, 'basic' => 1500, 'housing' => null, 'transport' => null, 'other' => null, 'reason' => 'إنهاء', 'book' => 5250],
            ['name' => 'موظف ثان', 'start' => '2020-01-01', 'end' => null, 'basic' => 1500, 'housing' => null, 'transport' => null, 'other' => null, 'reason' => 'إنهاء', 'book' => 5000],
        ], 'trial_balance_total' => 5900, 'notes' => []]);

        $paper = PaperMaker::queue($company, 2025, 'recalc', 'eosb')->fresh();   // the test queue runs at once

        $this->assertSame(PreparedPaper::DONE, $paper->status);
        Storage::disk('local')->assertExists($paper->path);
        $this->assertSame(['recalc', 2], [$paper->analysis['kind'], $paper->analysis['rows_used']]);
        // Six years of service: 2.5 + 1 months' wage = 5,250 recalculated; the books hold 5,250 for the first and 5,000 for the second.
        $this->assertSame(['موظف ثان'], array_column($paper->analysis['differing'], 'name'));
        $this->assertEquals(250.0, $paper->analysis['difference']);
        $this->assertEquals(5900.0, $paper->analysis['trial_balance']);
        // The AI was given the document and the trial balance, and told never to calculate.
        $this->assertCount(1, $ai->calls);
        $this->assertStringContainsString('كشف الموظفين.csv', $ai->calls[0]->content);
        $this->assertStringContainsString('مخصص نهاية الخدمة | مخصص نهاية الخدمة | -5900', $ai->calls[0]->system[0]['text']);
        $this->assertStringContainsString('Never calculate', $ai->calls[0]->system[0]['text']);
        // The workbook holds the rows and the entity the way the firm's paper does.
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::disk('local')->path($paper->path))->getSheet(0);
        $this->assertSame(['شركة النور', 'موظف أول', 'موظف ثان'], [$sheet->getCell('D3')->getValue(), $sheet->getCell('A8')->getValue(), $sheet->getCell('A9')->getValue()]);
    }

    public function test_a_paper_with_no_documents_waits_for_the_client_and_the_ai_is_not_asked(): void
    {
        [, $sara, $company] = $this->setUpClient();
        $this->actingAs($sara);
        $ai = $this->fakeAi(['rows' => [], 'trial_balance_total' => null, 'notes' => []]);

        $paper = PaperMaker::queue($company, 2025, 'recalc', 'depreciation')->fresh();

        $this->assertSame(PreparedPaper::BLOCKED, $paper->status);
        $this->assertCount(0, $ai->calls);
        $page = $this->get(route('clients.file.recalculation', ['company' => $company, 'year' => 2025]))->assertOk()->getContent();
        $this->assertStringContainsString(__('client_file.prepared.blocked'), $page);
    }

    public function test_a_working_paper_is_filled_into_the_firms_form(): void
    {
        [$office, $sara, $company] = $this->setUpClient();
        $this->actingAs($sara);
        $this->upload($office, $company, 'الذمم المدينة (مدينون تجاريون)', 'أرصدة العملاء.csv');
        $read = \App\Services\WorkingPapers\WorkingPaperReader::read(\App\Services\WorkingPapers\Papers::path('customer-confirmations'));
        $inputs = array_values(array_filter($read['columns'], fn ($c) => $c['input']));
        $first = $inputs[0]['column'];
        $row = array_fill_keys(array_column($inputs, 'column'), null);
        $row[$first] = 'عميل أول';
        $this->fakeAi(['rows' => [$row], 'trial_balance_total' => null, 'notes' => ['العمود ناقص']]);

        $paper = PaperMaker::queue($company, 2025, 'paper', 'customer-confirmations')->fresh();

        $this->assertSame(PreparedPaper::DONE, $paper->status);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::disk('local')->path($paper->path))->getSheet(0);
        $this->assertSame('عميل أول', $sheet->getCell($first.$read['first_row'])->getValue());
        $this->assertSame(['أرصدة العملاء.csv: العمود ناقص'], $paper->notes);
        $this->assertSame(1, $paper->analysis['rows_used']);
    }

    public function test_the_pages_start_the_ai_and_download_the_result_and_only_the_manager_starts_it(): void
    {
        [$office, $sara, $company] = $this->setUpClient();
        $this->upload($office, $company, 'الموجودات الثابتة', 'سجل الأصول.csv');
        $this->fakeAi(['rows' => [['name' => 'سيارة', 'category' => 'سيارات', 'cost' => 100000, 'additions' => 0, 'disposals' => 0, 'bought' => '2023-01-01', 'rate' => 0.2, 'opening' => 40000,
            'disposal_dep' => 0, 'book' => 40000]], 'trial_balance_total' => null, 'notes' => []]);
        $this->actingAs($sara);

        $this->get(route('work.recalc'))->assertOk()->assertSee('data-choose-client', false);   // asks which client when none is open
        $this->get(route('clients.file.recalculation', ['company' => $company, 'year' => 2025]))->assertOk()->assertSee(__('client_file.prepared.prepare_all'));
        $this->post(route('clients.papers.prepare', $company), ['year' => 2025, 'kind' => 'recalc', 'key' => 'all'])->assertRedirect()->assertSessionHas('status');
        $paper = PreparedPaper::where('company_id', $company->id)->where('key', 'depreciation')->first();
        $this->assertSame(PreparedPaper::DONE, $paper->status);
        $this->assertSame(PreparedPaper::BLOCKED, PreparedPaper::where('key', 'eosb')->first()->status);   // no document for it
        $this->get(route('clients.papers.download', [$company, $paper->id]))->assertOk();
        $this->get(route('clients.papers.status', ['company' => $company, 'year' => 2025]))->assertOk()->assertJsonPath('active', 0);

        // A viewer looks and downloads; the AI is not started by them. Another office sees nothing.
        $viewerRole = Role::create(['name_ar' => 'مشاهد']);
        $viewerRole->syncPermissions(['clients.view']);
        $viewer = User::create(['office_id' => $office->id, 'name' => 'مشاهد', 'username' => 'viewer', 'password' => 'secret123', 'role_id' => $viewerRole->id, 'status' => User::ACTIVE]);
        $this->actingAs($viewer);
        $this->get(route('clients.papers.download', [$company, $paper->id]))->assertOk();
        $this->post(route('clients.papers.prepare', $company), ['year' => 2025, 'kind' => 'recalc', 'key' => 'all'])->assertForbidden();
        $other = Office::create(['name_ar' => 'ب', 'code' => 'beta']);
        $this->actingAs(User::create(['office_id' => $other->id, 'name' => 'عمر', 'username' => 'omar', 'password' => 'secret123', 'role_id' => Role::create(['name_ar' => 'م', 'is_admin' => true])->id, 'status' => User::ACTIVE]));
        $this->get(route('clients.papers.download', [$company, $paper->id]))->assertNotFound();
    }

    public function test_the_catalogue_holds_the_five_recalculations_and_the_sixteen_working_papers(): void
    {
        $catalogue = PaperMaker::catalogue();

        $this->assertCount(21, $catalogue);
        $this->assertCount(5, array_filter($catalogue, fn ($p) => $p['kind'] === 'recalc'));
        foreach (array_keys($catalogue) as $key) {
            $this->assertNotEmpty(config('paper_sources.sources')[$key] ?? null, $key);
        }
    }
}
