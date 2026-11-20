<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * PR 17: last year's issued statements uploaded as Excel, read by PHP, reviewed in step 2, then the comparatives. Nothing
 * is used before the user confirms; a figure the file can't read is missing; rows not matched exactly wait for a choice;
 * the figures go through the comparative checks (21: opening vs prior closing) and the file's totals are checked against
 * the lines read. Made-up names and amounts.
 */
class PriorStatementsUploadTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** 2025, opening and closing: cash 100 → 150, capital 100, suppliers 30, revenue 200, rent 180. */
    private function report(): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'موردون', ['opening' => 0, 'closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -200]),
            $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 180]),
        ], ['opening', 'closing'])]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    /** @param array<string, list<list<mixed>>> $sheets */
    private function file(array $sheets, string $name = 'قوائم 2024.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $book->createSheet()->setTitle($title)->fromArray($rows, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'prior').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * 2024's issued statements: cash 100 = capital 100; revenue 80, general and administrative (80); cash flow: capital
     * paid in 100. The 2023 column is there to be left alone. $edit changes rows before writing.
     */
    private function statements(?callable $edit = null): UploadedFile
    {
        $sheets = [
            'تقرير المراجع' => [['تقرير المراجع المستقل'], ['إلى السادة الشركاء في شركة المقارنة']],
            'المركز المالي' => [
                ['شركة المقارنة'],
                ['قائمة المركز المالي كما في 31 ديسمبر 2024م'],
                ['(المبالغ بالريال السعودي)'],
                ['', 'إيضاح', '31 ديسمبر 2024م', '31 ديسمبر 2023م'],
                ['الأصول المتداولة'],
                ['نقد الشركة في الخزينة والمصارف', 4, 100, 70],
                ['إجمالي الأصول المتداولة', null, 100, 70],
                ['إجمالي الأصول', null, 100, 70],
                ['حقوق الملكية'],
                ['رأس المال', 5, 100, 70],
                ['إجمالي حقوق الملكية', null, 100, 70],
                ['إجمالي حقوق الملكية والالتزامات', null, '١٠٠', '٧٠'],
            ],
            'الدخل' => [
                ['قائمة الدخل الشامل للسنة المنتهية في 31 ديسمبر 2024م'],
                ['', '2024', '2023'],
                ['إيرادات النشاط', 80, 60],
                ['المصروفات العمومية والإدارية', '(80)', '(60)'],
                ['صافي الدخل (الخسارة) للسنة', '-', '-'],
            ],
            'التدفقات' => [
                ['قائمة التدفقات النقدية للسنة المنتهية في 31 ديسمبر 2024م'],
                ['', '2024', '2023'],
                ['متحصلات من زيادة رأس المال', 100, 70],
                ['صافي التدفقات النقدية من الأنشطة التمويلية', 100, 70],
                ['رصيد النقدية في آخر السنة', 100, 70],
            ],
        ];

        return $this->file($edit ? $edit($sheets) : $sheets);
    }

    private function upload(string $id, UploadedFile $file): array
    {
        return $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();
    }

    private function line(array $state, string $statement, string $key): array
    {
        return collect($state['statements'][$statement])->firstWhere('key', $key);
    }

    private function codes(array $state): array
    {
        return array_column(array_filter($state['checks'], fn ($c) => $c['scope'] === 'comparative'), 'code');
    }

    /** The row id of the block's row with this label. */
    private function ref(array $upload, string $statement, string $label): string
    {
        foreach ($upload['blocks'] as $b => $block) {
            if ($block['statement'] === $statement) {
                foreach ($block['rows'] as $r) {
                    if ($r['label'] === $label) {
                        return "{$b}:{$r['id']}";
                    }
                }
            }
        }
        $this->fail("no row {$label}");
    }

    public function test_a_file_is_read_and_waits_for_review_before_it_is_used(): void
    {
        $id = $this->report();
        $state = $this->upload($id, $this->statements());
        $upload = $state['comparative']['upload'];

        // Read: three statements, the 2024 column named by its header; nothing used yet.
        $this->assertSame(['financial_position', 'comprehensive_income', 'cash_flows'], array_column($upload['blocks'], 'statement'));
        $this->assertSame([['C', true], ['B', true], ['B', true]], array_map(fn ($b) => [$b['column'], $b['column_certain']], $upload['blocks']));
        $this->assertFalse($upload['in_use']);
        $this->assertSame('none', $state['comparative']['source']);
        $this->assertSame('trial_balance_opening', $this->line($state, 'financial_position', 'sofp.cash')['comparative']['origin']);

        // Each row: its line proposed; the cash row's wording is neither the template's nor a synonym's, so it waits for a choice.
        $rows = array_column($upload['blocks'][0]['rows'], null, 'label');
        $this->assertSame(['sofp.share_capital', 'exact'], [$rows['رأس المال']['match'], $rows['رأس المال']['certainty']]);
        $this->assertSame(['sofp.total_equity_and_liabilities', 'exact'], [$rows['إجمالي حقوق الملكية والالتزامات']['match'], $rows['إجمالي حقوق الملكية والالتزامات']['certainty']]);
        $this->assertSame('none', $rows['نقد الشركة في الخزينة والمصارف']['certainty']);
        $this->assertEquals(['amount' => 100, 'raw' => '١٠٠', 'status' => 'ok'], (array) $rows['إجمالي حقوق الملكية والالتزامات']['values']['C']);
        $this->assertArrayNotHasKey('الأصول المتداولة', array_filter($rows, fn ($r) => array_filter((array) $r['values'], fn ($v) => $v['status'] !== 'empty')));

        // Confirming without deciding the cash row: refused, nothing changes.
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertStatus(422)
            ->assertJsonValidationErrors('rows.'.$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف'));
        $this->assertSame('none', FinancialReport::find($id)->prior_figures['source'] ?? 'none');
    }

    public function test_confirmed_figures_are_the_comparatives_and_pass_the_same_checks(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements())['comparative']['upload'];
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements',
            'rows' => [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash']])->assertOk()->json();

        $this->assertSame('statements', $state['comparative']['source']);
        $this->assertTrue($state['comparative']['upload']['in_use']);
        $cash = $this->line($state, 'financial_position', 'sofp.cash')['comparative'];
        $this->assertEquals([100, 'prior_year_statements'], [$cash['amount'], $cash['origin']]);
        $this->assertSame([['type' => 'prior_file', 'sheet' => 'المركز المالي', 'row' => 6]], $cash['refs']);
        $this->assertEquals(-80, $this->line($state, 'comprehensive_income', 'is.general_admin')['comparative']['amount']);
        $this->assertEquals(80, $this->line($state, 'comprehensive_income', 'is.revenue')['comparative']['amount']);
        // A line the file doesn't show is 0; the cash flow comes from the file too.
        $this->assertEquals([0, 'prior_year_statements'], array_values(array_intersect_key($this->line($state, 'financial_position', 'sofp.inventory')['comparative'], array_flip(['amount', 'origin']))));
        $this->assertEquals(100, $this->line($state, 'cash_flows', 'cf.capital_increase')['comparative']['amount']);
        // Last year's closing equals this year's opening, the comparative balance sheet balances, every total agrees.
        $this->assertSame([], array_values(array_intersect($this->codes($state), ['opening_vs_prior_closing', 'sofp_unbalanced', 'prior_file_total'])));
        $this->assertSame([], array_values(array_filter($state['missing'], fn ($m) => in_array($m['key'], ['comparative.financial_position', 'comparative.comprehensive_income', 'comparative.cash_flows'], true))));
    }

    public function test_last_years_closing_must_equal_this_years_opening(): void
    {
        $id = $this->report();
        // This year's openings the trial balance's own (Step B's setting off): a difference is an error.
        $this->putJson(self::URL."/{$id}/prior-year/approved-opening", ['enabled' => false])->assertOk();
        $file = $this->statements(function ($s) {
            $s['المركز المالي'][5][2] = 120;
            $s['المركز المالي'][6][2] = $s['المركز المالي'][7][2] = 120;
            $s['المركز المالي'][9][2] = $s['المركز المالي'][10][2] = $s['المركز المالي'][11][2] = 120;
            return $s;
        });
        $upload = $this->upload($id, $file)['comparative']['upload'];
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements',
            'rows' => [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash']])->assertOk()->json();

        $diff = collect($state['checks'])->firstWhere(fn ($c) => $c['code'] === 'opening_vs_prior_closing' && $c['line_key'] === 'sofp.cash');
        $this->assertSame('error', $diff['severity']);
        $this->assertFalse($state['can_finalize']);

        // With the setting on (the default): the year opens at last year's approved closing, the difference reconciled.
        $state = $this->putJson(self::URL."/{$id}/prior-year/approved-opening", ['enabled' => true])->assertOk()->json();
        $this->assertNotContains('opening_vs_prior_closing', $this->codes($state));
        $this->assertContains('approved_opening', array_column($state['checks'], 'code'));
        $this->assertSame('difference', collect($state['comparative']['approved_opening']['lines'])->firstWhere('line', 'sofp.cash')['kind']);
    }

    public function test_every_difference_from_this_years_opening_is_shown_a_riyal_as_probably_rounding(): void
    {
        $id = $this->report();
        $this->putJson(self::URL."/{$id}/prior-year/approved-opening", ['enabled' => false])->assertOk();   // the trial balance's openings (Step B off)
        // Last year's closing 101 for this year's opening 100 (cash and capital): a riyal apart.
        $upload = $this->upload($id, $this->statements(function ($s) {
            foreach ([5, 6, 7, 9, 10, 11] as $r) {
                $s['المركز المالي'][$r][2] = 101;
            }

            return $s;
        }))['comparative']['upload'];

        // Before it is confirmed: the lines the file places exactly (the cash row waits for its line).
        $this->assertEquals([['line' => 'sofp.share_capital', 'label' => 'رأس المال', 'opening' => 100, 'prior' => 101, 'difference' => -1, 'kind' => 'rounding', 'pages' => []]],
            $upload['opening_check']);

        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements',
            'rows' => [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash']])->assertOk()->json();
        $this->assertSame(['sofp.cash' => 'rounding', 'sofp.share_capital' => 'rounding'], array_column($state['comparative']['upload']['opening_check'], 'kind', 'line'));
        // Less than 3 riyals apart: accepted (the accountant's rule), shown as accepted in the comparison and no check raised.
        $this->assertNull(collect($state['checks'])->firstWhere(fn ($c) => $c['code'] === 'opening_vs_prior_closing' && $c['line_key'] === 'sofp.cash'));
    }

    public function test_accounts_on_another_line_than_last_year_are_proposed_back(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 185, 'closing' => 185]),
            $this->account(3, 'رأس المال', ['opening' => -0, 'closing' => -0]),
            $this->account(4, 'موردون', ['opening' => -100, 'closing' => -100], ['account_name' => 'مورد أ']),
            $this->account(5, 'موردون', ['opening' => -45, 'closing' => -45], ['account_name' => 'مستحقات متنوعة']),
            $this->account(6, 'موردون', ['opening' => -40, 'closing' => -40], ['account_name' => 'حساب غير مفسر']),
        ], ['opening', 'closing'])]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        // Last year: suppliers 100 and accruals 45, where this year's trial balance has all three on trade payables.
        $upload = $this->upload($id, $this->file(['تقرير المراجع' => [['تقرير المراجع المستقل']], 'المركز المالي' => [
            ['قائمة المركز المالي كما في 31 ديسمبر 2024م'], ['', '31 ديسمبر 2024م'],
            ['النقد ومعادلات النقد', 185], ['إجمالي الأصول', 185],
            ['ذمم تجارية دائنة', 100], ['مصاريف مستحقة وأرصدة دائنة أخرى', 45], ['إجمالي الالتزامات', 145], ['إجمالي حقوق الملكية والالتزامات', 145],
        ]]))['comparative']['upload'];

        // Accruals short 45: the one account whose opening is 45, proposed back. Trade payables keep the account equal to last
        // year's 100; the other (40) wasn't on it last year: listed, with the lines still short to choose from.
        $this->assertEquals([['sofp.accruals_other_payables', [[5, 'sofp.trade_payables', 45.0, 'sum']]]],
            array_map(fn ($p) => [$p['line'], array_map(fn ($a) => [$a['row'], $a['from'], $a['amount'], $a['by']], $p['accounts'])], $upload['comparable']['proposals']));
        $this->assertSame([['sofp.trade_payables', [6], ['sofp.accruals_other_payables']]],
            array_map(fn ($st) => [$st['from'], array_column($st['accounts'], 'row'), array_column($st['targets'], 'line')], $upload['comparable']['strays']));

        // Accepted with one click (an override): the accruals now equal last year's.
        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 5, 'line_key' => 'sofp.accruals_other_payables']]])->assertOk()->json();
        $this->assertSame('equal', collect($state['comparative']['upload']['opening_check'])->firstWhere('line', 'sofp.accruals_other_payables')['kind']);
        $this->assertSame([], $state['comparative']['upload']['comparable']['proposals']);
    }

    public function test_a_row_of_nothing_needs_no_line(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements(function ($s) {
            array_splice($s['المركز المالي'], 6, 0, [['بند لا يعرفه أحد', null, '-', '-']]);

            return $s;
        }))['comparative']['upload'];

        // Only the cash row waits for its line; the «-» row places nothing.
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash']])
            ->assertOk();
    }

    public function test_a_figure_the_file_cant_read_is_missing_never_zero(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements(function ($s) {
            $s['الدخل'][2][1] = 'غير متاح';
            return $s;
        }))['comparative']['upload'];
        $revenue = collect($upload['blocks'][1]['rows'])->firstWhere('label', 'إيرادات النشاط');
        $this->assertSame(['amount' => null, 'raw' => 'غير متاح', 'status' => 'unread'], (array) $revenue['values']['B']);

        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements',
            'rows' => [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash']])->assertOk()->json();
        $this->assertNull($this->line($state, 'comprehensive_income', 'is.revenue')['comparative']['amount']);
        $this->assertContains('comparative.comprehensive_income', array_column($state['missing'], 'key'));
    }

    public function test_a_row_left_out_shows_in_the_files_totals(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements())['comparative']['upload'];
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements',
            'rows' => [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'ignore']])->assertOk()->json();

        $check = collect($state['checks'])->firstWhere(fn ($c) => $c['code'] === 'prior_file_total' && $c['line_key'] === 'sofp.total_assets');
        $this->assertNotNull($check);
        $this->assertSame(['error', 'comparative', 100, 0], [$check['severity'], $check['scope'], $check['expected'], $check['actual']]);
        $this->assertStringContainsString('الصف 8 في ورقة «المركز المالي»', $check['message_ar']);
    }

    public function test_expenses_shown_positive_wait_for_the_users_word(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements(function ($s) {
            $s['الدخل'][3][1] = 80;
            return $s;
        }))['comparative']['upload'];
        $this->assertTrue($upload['expenses_shown_positive']);
        $rows = [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash'];

        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => $rows])->assertStatus(422)->assertJsonValidationErrors('expenses_shown_positive');
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => $rows, 'expenses_shown_positive' => true])->assertOk()->json();
        $this->assertEquals(-80, $this->line($state, 'comprehensive_income', 'is.general_admin')['comparative']['amount']);
        $this->assertNotContains('prior_file_total', $this->codes($state));
    }

    public function test_without_a_year_in_the_header_the_user_picks_the_column(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements(function ($s) {
            $s['الدخل'][1] = ['', 'السنة', 'السنة السابقة'];
            return $s;
        }))['comparative']['upload'];
        $this->assertSame([false, ['B', 'C']], [$upload['blocks'][1]['column_certain'], array_column($upload['blocks'][1]['columns'], 'column')]);
        $rows = [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash'];

        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => $rows])->assertStatus(422)->assertJsonValidationErrors('columns.1');
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => $rows, 'columns' => [1 => 'B']])->assertOk()->json();
        $this->assertEquals(80, $this->line($state, 'comprehensive_income', 'is.revenue')['comparative']['amount']);
    }

    public function test_a_new_file_waits_while_the_confirmed_one_stays_in_use(): void
    {
        $id = $this->report();
        $upload = $this->upload($id, $this->statements())['comparative']['upload'];
        $rows = [$this->ref($upload, 'financial_position', 'نقد الشركة في الخزينة والمصارف') => 'sofp.cash'];
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => $rows])->assertOk();

        $state = $this->upload($id, $this->statements(function ($s) {
            $s['الدخل'][2][1] = 90;
            return $s;
        }));
        $this->assertFalse($state['comparative']['upload']['in_use']);
        $this->assertEquals(80, $this->line($state, 'comprehensive_income', 'is.revenue')['comparative']['amount']);

        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => $rows])->assertOk()->json();
        $this->assertEquals(90, $this->line($state, 'comprehensive_income', 'is.revenue')['comparative']['amount']);
        $this->assertTrue($state['comparative']['upload']['in_use']);
        $this->assertNull(FinancialReport::find($id)->prior_upload);
    }

    public function test_a_total_in_the_income_statement_is_read_like_any_row(): void
    {
        $id = $this->report();
        // A total's wording matches it exactly; it has no side, so it says nothing about how expenses are shown.
        $upload = $this->upload($id, $this->statements(function ($s) {
            array_splice($s['الدخل'], 3, 0, [['مجمل الربح (الخسارة)', 80, 60]]);

            return $s;
        }))['comparative']['upload'];

        $row = collect($upload['blocks'][1]['rows'])->firstWhere('label', 'مجمل الربح (الخسارة)');
        $this->assertSame(['is.gross_profit', 'exact'], [$row['match'], $row['certainty']]);
        $this->assertFalse($upload['expenses_shown_positive']);
    }

    public function test_a_file_without_the_independent_auditors_report_is_refused(): void
    {
        $id = $this->report();
        $without = $this->statements(function ($sheets) {
            unset($sheets['تقرير المراجع']);

            return $sheets;
        });
        $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => $without], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        // A title naming the report inside a statements' sheet is enough as well as a sheet of its own.
        $titled = $this->statements(function ($sheets) {
            unset($sheets['تقرير المراجع']);
            $sheets['الدخل'][] = ['تقرير مراجع الحسابات المستقل مرفق'];

            return $sheets;
        });
        $this->upload($id, $titled);
    }

    public function test_a_file_without_statements_or_not_excel_is_refused(): void
    {
        $id = $this->report();
        $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => $this->file(['ورقة' => [['حساب', 'رصيد'], ['الصندوق', 10]]])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post(self::URL."/{$id}/prior-year/statements-file", ['file' => UploadedFile::fake()->create('قوائم.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertStatus(422)->assertJsonValidationErrors('source');
        $this->post(self::URL.'/'.Str::uuid().'/prior-year/statements-file', ['file' => $this->statements()], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_synonym_places_a_row_and_the_user_can_still_change_it(): void
    {
        $id = $this->report();
        // «النقدية وما في حكمها» is in the starting list for «النقد ومعادلات النقد».
        $upload = $this->upload($id, $this->statements(function ($s) {
            $s['المركز المالي'][5][0] = 'النقدية وما في حكمها';
            return $s;
        }))['comparative']['upload'];
        $cash = collect($upload['blocks'][0]['rows'])->firstWhere('label', 'النقدية وما في حكمها');
        $this->assertSame(['sofp.cash', 'synonym'], [$cash['match'], $cash['certainty']]);

        // Placed without a choice, as an exact match is…
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertOk()->json();
        $this->assertEquals(100, $this->line($state, 'financial_position', 'sofp.cash')['comparative']['amount']);
        // …and the review can still move it.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements', 'rows' => [$this->ref($upload, 'financial_position', 'النقدية وما في حكمها') => 'sofp.murabaha_deposits']])->assertOk()->json();
        $this->assertEquals([0, 100], [$this->line($state, 'financial_position', 'sofp.cash')['comparative']['amount'], $this->line($state, 'financial_position', 'sofp.murabaha_deposits')['comparative']['amount']]);
    }

    public function test_a_waiting_file_follows_the_list_and_a_confirmed_one_keeps_its_matches(): void
    {
        $id = $this->report();
        $this->upload($id, $this->statements());
        $synonym = $this->postJson('/api/v1/statement-synonyms', ['line_key' => 'sofp.cash', 'label' => 'نقد الشركة في الخزينة والمصارف'])->assertCreated()->json('id');

        // The file waiting is matched again with the new synonym: nothing left to decide.
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame('synonym', collect($state['comparative']['upload']['blocks'][0]['rows'])->firstWhere('label', 'نقد الشركة في الخزينة والمصارف')['certainty']);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'statements'])->assertOk();

        // Deleting the synonym doesn't move the confirmed figures.
        $this->deleteJson("/api/v1/statement-synonyms/{$synonym}")->assertNoContent();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertEquals(100, $this->line($state, 'financial_position', 'sofp.cash')['comparative']['amount']);
        $this->assertSame('synonym', collect($state['comparative']['upload']['blocks'][0]['rows'])->firstWhere('label', 'نقد الشركة في الخزينة والمصارف')['certainty']);
    }
}
