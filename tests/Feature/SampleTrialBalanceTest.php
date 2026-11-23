<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\Statements\CurrentYearStatements;
use App\Services\Statements\LayoutProfile;
use App\Services\Statements\LineAggregator;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\LineMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\Support\DocxFiles;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * A real client trial balance (names anonymised, rows and amounts unchanged), with the structure and labels a live
 * run returned (tests/Fixtures/mappings/sample-trial-balance*.json). The answers are fixed: these tests pin what the
 * code does with them, not what the AI would say today.
 */
class SampleTrialBalanceTest extends TestCase
{
    use DocxFiles, RefreshDatabase, TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function classify(): array
    {
        $fixtures  = base_path('tests/Fixtures/');
        $structure = json_decode(file_get_contents($fixtures.'mappings/sample-trial-balance.json'), true);
        $labels    = json_decode(file_get_contents($fixtures.'mappings/sample-trial-balance-labels.json'), true)['labels'];
        $this->fakeLlm($structure, $labels);

        $file = new UploadedFile($fixtures.'sample-trial-balance.xlsx', 'sample-trial-balance.xlsx', null, null, true);

        return $this->postJson('/api/v1/classify/trial-balance', ['file' => $file])->assertOk()->json();
    }

    public function test_a_closed_year_s_result_the_ai_calls_a_parent_is_counted_and_the_file_not_refused(): void
    {
        // A live run (2026-10-20): the AI labelled R186 «ارباح وخسائر 2024» (opening credit 5,011,518.20 cleared by the year's
        // debit) a parent, the file came out unbalanced by exactly that and was refused. Under the real limit it is counted.
        config(['classification.reject_unbalanced_from' => 1.0]);
        $fixtures  = base_path('tests/Fixtures/');
        $labels    = json_decode(file_get_contents($fixtures.'mappings/sample-trial-balance-labels.json'), true)['labels'];
        $labels    = array_map(fn ($l) => $l['row'] === 186 ? ['row' => 186, 'row_type' => 'parent', 'confidence' => 80, 'reason' => 'حساب تجميعي'] : $l, $labels);
        $this->fakeLlm(json_decode(file_get_contents($fixtures.'mappings/sample-trial-balance.json'), true), $labels);

        $result = $this->postJson('/api/v1/classify/trial-balance', ['file' => new UploadedFile($fixtures.'sample-trial-balance.xlsx', 's.xlsx', null, null, true)])
            ->assertOk()->json();
        $row = collect($result['data'])->firstWhere('row', 186);
        $this->assertSame(['3406', null, true], [(string) $row['account_number'], $row['sub_class'], $row['needs_review']]);
        $this->assertContains('parent_rescued', array_column($row['data_flags'], 'type'));
    }

    public function test_the_note_number_column_is_read_as_note_references(): void
    {
        $result = $this->classify();
        $rows   = collect($result['data'])->keyBy('row');

        // The live run mapped «إيضاح» as remarks: 158 rows got a source_note, 144 of them needed a review for it alone.
        // Now they are note references.
        $this->assertSame([], array_values(array_filter(array_column($result['data'], 'source_note'))));
        $this->assertSame(158, count(array_filter(array_column($result['data'], 'note_reference'))));
        $this->assertSame(['5', '21', '17'], [$rows[4]['note_reference'], $rows[138]['note_reference'], $rows[177]['note_reference']]);
        $this->assertNull($rows[189]['note_reference']);
    }

    public function test_the_file_is_read_whole_and_reconciles(): void
    {
        $result = $this->classify();

        $this->assertSame(282, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['skipped_rows']);
        $this->assertSame(['opening', 'period', 'adjustment', 'closing'], $result['meta']['groups']);
        foreach ($result['data'] as $row) {
            $this->assertNotContains('reconciliation', array_column($row['data_flags'], 'type'), "R{$row['row']}");
        }
    }

    public function test_periodic_stock_classified_as_inventory_is_caught_by_the_statement_scheme(): void
    {
        $result = $this->classify();
        $rows   = collect($result['data'])->keyBy('row');

        // R189 «بضاعة بالمستودع» (61) and R285 «بضاعة آخر المدة» (901): opening and closing stock, numbered after the
        // balance sheet (1–3) but labelled inventory. R189 used to pass with no flag at all. The other P&L accounts start
        // with 4–8 (9 holds only 901).
        foreach ([189, 285] as $n) {
            $flag = collect($rows[$n]['data_flags'])->first(fn ($f) => $f['type'] === 'prefix_mismatch' && ($f['severity'] ?? null) === 'high');
            $this->assertNotNull($flag, "R{$n}");
            $this->assertStringContainsString('من 1 إلى 3 في قائمة المركز المالي ومن 4 إلى 8 في قائمة الدخل', $flag['message']);
            $this->assertTrue($rows[$n]['needs_review'], "R{$n}");
            $this->assertLessThanOrEqual(50, $rows[$n]['classification_confidence'], "R{$n}");
        }
        $this->assertStringContainsString('«بضاعة بالمستودع» (الصف 189 في الملف)', $result['meta']['warnings'][0]);
        $this->assertStringContainsString('«بضاعة آخر المدة» (الصف 285 في الملف)', $result['meta']['warnings'][0]);

        // Nothing else breaks the scheme: the balance-sheet inventory account and the P&L accounts are not flagged.
        $high = array_keys(array_filter($rows->all(), fn ($r) => collect($r['data_flags'])->contains(fn ($f) => $f['type'] === 'prefix_mismatch' && ($f['severity'] ?? null) === 'high')));
        $this->assertSame([189, 285], $high);
    }

    public function test_statement_lines_from_the_classified_file(): void
    {
        $result  = $this->classify();
        $profile = LayoutProfile::fromResult($result, app(TaxonomyRepositoryInterface::class));
        $mapping = app(LineMapper::class)->map($result['data'], $profile);
        $lines   = app(LineAggregator::class)->aggregate($result['data'], $mapping, $profile);

        $this->assertSame(['OMC', ['adjustment'], false, false], [$profile->layout, $profile->emptyGroups, $profile->isPartial(), $profile->postClosing]);
        $this->assertSame([], $mapping['unmapped']);

        // The periodic stock accounts: proposed for cost of revenue from the stock account's (R138) balances, not moved.
        $this->assertSame([[189, 'opening_stock', [138]], [285, 'closing_stock', [138]]], array_map(fn ($p) => [$p['row'], $p['kind'], $p['matches']], $mapping['proposals']));
        $this->assertSame(['sofp.inventory' => 1.0], $mapping['rows'][189]['lines']['closing']);
        $this->assertSame('pending_confirmation', $mapping['rows'][285]['status']);

        // Pre-closing: assets − liabilities − equity = the year's result from the income-statement lines.
        $sum = fn (string $prefix, string $side) => array_sum(array_map(fn ($key) => $lines[$key]['closing']->amount,
            array_filter(array_keys($lines), fn ($key) => str_starts_with($key, $prefix) && (new LineCatalogue)->side($key) === $side)));
        $netAssets = round($sum('sofp.', 'debit') - $sum('sofp.', 'credit'), 2);
        $profit    = round($sum('is.', 'credit') - $sum('is.', 'debit'), 2);
        $this->assertSame(179588.87, $netAssets);
        $this->assertSame($netAssets, $profit);
    }

    public function test_current_year_statements_from_the_classified_file(): void
    {
        $run   = (new ClassificationRun)->forceFill(['status' => 'done', 'result' => $this->classify()]);
        $built = app(CurrentYearStatements::class)->build($run, 'sa-ifrs-smes-3');
        $fig   = fn (string $statement, string $key) => collect($built['statements'][$statement])->firstWhere('key', $key)['current'];

        // Balanced, and the year's result (pre-closing) rolled into retained earnings. Suppliers by their balance side
        // (R140 debit → advances, R146 credit → suppliers) gross both sides up by 499,822.85.
        $this->assertSame(27481197.66, $fig('financial_position', 'sofp.total_assets')->amount);
        $this->assertSame(27481197.66, $fig('financial_position', 'sofp.total_equity_and_liabilities')->amount);
        $this->assertSame([179588.87, 'derived'], [$fig('financial_position', 'sofp.retained_earnings')->amount, $fig('financial_position', 'sofp.retained_earnings')->origin]);
        $this->assertSame([57983514.68, -54051622.91, 179588.87], [$fig('comprehensive_income', 'is.revenue')->amount,
            $fig('comprehensive_income', 'is.cost_of_revenue')->amount, $fig('comprehensive_income', 'is.net_profit')->amount]);
        // The periodic stock accounts keep inventory pending until the user decides.
        $this->assertSame('pending_confirmation', $fig('financial_position', 'sofp.inventory')->status);

        $codes = array_count_values(array_column($built['checks'], 'code'));
        $this->assertSame(2, $codes['periodic_inventory_pair']);
        $this->assertArrayNotHasKey('sofp_unbalanced', $codes);
        $this->assertArrayNotHasKey('net_income_mismatch', $codes);
        $this->assertArrayNotHasKey('tb_unbalanced', $codes);
        $this->assertSame('adjustment', collect($built['checks'])->firstWhere('code', 'empty_group')['group']);
        // No prior source: the comparative balance sheet from the opening columns.
        $cmp = fn (string $key) => collect($built['statements']['financial_position'])->firstWhere('key', $key)['comparative'];
        $this->assertSame([23434047.85, 23434047.85, 'trial_balance_opening'], [$cmp('sofp.total_assets')->amount,
            $cmp('sofp.total_equity_and_liabilities')->amount, $cmp('sofp.retained_earnings')->origin]);
        $this->assertNotContains('sofp_unbalanced', collect($built['checks'])->where('scope', 'comparative')->pluck('code')->all());
        // Whole riyals: total assets' parts show 27,481,197 for 27,481,197.66; the riyal goes on its largest line, and every
        // total shows the sum of its parts, the balance sheet balancing in both years.
        $rounding = collect($built['checks'])->where('code', 'rounding_difference')->firstWhere('line_key', 'sofp.total_assets');
        $this->assertSame([27481197.0, 27481198.0, 'info'], [$rounding['expected'], $rounding['actual'], $rounding['severity']]);
        $this->assertNotContains('rounding_over_limit', array_column($built['checks'], 'code'));
        foreach (['financial_position', 'comprehensive_income'] as $statement) {
            $shown = array_column($built['statements'][$statement], 'shown', 'key');
            foreach ($built['statements'][$statement] as $r) {
                foreach ($r['type'] === 'total' ? ['current', 'comparative'] : [] as $scope) {
                    if ($shown[$r['key']][$scope] !== null) {
                        $this->assertSame($shown[$r['key']][$scope], array_sum(array_map(fn ($k) => $shown[$k][$scope], $r['sum'])), "{$r['key']} {$scope}");
                    }
                }
            }
        }
        $shown = array_column($built['statements']['financial_position'], 'shown', 'key');
        $this->assertSame([27481198, 23434048], [$shown['sofp.total_assets']['current'], $shown['sofp.total_assets']['comparative']]);
        $this->assertSame($shown['sofp.total_assets'], $shown['sofp.total_equity_and_liabilities']);
    }

    public function test_movement_schedules_from_the_classified_file(): void
    {
        $run   = (new ClassificationRun)->forceFill(['status' => 'done', 'result' => $this->classify()]);
        $build = fn (array $report = []) => app(CurrentYearStatements::class)->build($run, 'sa-ifrs-smes-3', $report);
        $cell  = fn (array $built, string $schedule, string $row, string $column = 'total') => collect(collect($built['schedules'])->firstWhere('key', $schedule)['rows'])->firstWhere('key', $row)['cells'][$column];
        $codes = fn (array $built) => array_count_values(array_column($built['checks'], 'code'));

        // Plan §0.3: PPE additions 280,743.94 and depreciation 91,888.90 (= the expense), no disposals.
        $built = $build();
        $this->assertSame([280743.94, 91888.9, 0.0, 0.0], [$cell($built, 'ppe', 'additions')->amount, $cell($built, 'ppe', 'depreciation')->amount,
            $cell($built, 'ppe', 'disposals')->amount, $cell($built, 'ppe', 'acc_disposals')->amount]);
        $this->assertSame(848609.34, $cell($built, 'ppe', 'nbv_closing')->amount);
        // Four accumulated depreciation accounts against three cost accounts, each paired by its name («مجمع إستهلاك / السيارات» with
        // «السيارات», «الاجهزة المكتبية» with the furniture and office equipment): every category's figures, never the total alone.
        $this->assertNotContains(['key' => 'ppe.accumulated', 'reason' => 'category_split_required', 'input_key' => null], $built['missing']);
        $this->assertSame([184344.06, 776316.43], [$cell($built, 'ppe', 'acc_closing', 'vehicles')->amount, $cell($built, 'ppe', 'nbv_closing', 'vehicles')->amount]);
        $this->assertSame(11376.51, $cell($built, 'ppe', 'acc_closing', 'furniture')->amount);
        $this->assertArrayNotHasKey('depreciation_mismatch', $codes($built));
        $this->assertArrayNotHasKey('asset_rollforward', $codes($built));

        // End-of-service: the 975.34 debit is a reversal (credited back to the expense), not a payment.
        $this->assertSame([53240.67, 975.34, 0.0, 115690.72], [$cell($built, 'employee_benefits', 'charge', 'amount')->amount,
            $cell($built, 'employee_benefits', 'reversal', 'amount')->amount, $cell($built, 'employee_benefits', 'paid', 'amount')->amount,
            $cell($built, 'employee_benefits', 'closing', 'amount')->amount]);
        $this->assertSame(1, $codes($built)['reversal_as_payment']);
        // Doubtful debts: 74.70 charged with no expense account (26b); 1,225 written off.
        $this->assertSame([74.7, 1225.0], [$cell($built, 'receivables_impairment', 'charge', 'amount')->amount, $cell($built, 'receivables_impairment', 'written_off', 'amount')->amount]);
        $this->assertSame('receivables_impairment', collect($built['checks'])->firstWhere('code', 'provision_without_pl_counterpart')['schedule']);
        // Zakat: the 2024 provision used, no 2025 zakat (23).
        $this->assertSame(134455.96, collect($built['checks'])->firstWhere('code', 'zakat_used_no_charge')['actual']);
        $this->assertContains('loans.current_portion', array_column($built['intake'], 'key'));

        // The user pairs the accumulated depreciation accounts with their categories, and tags the deferred interest (Q36).
        $built = $build(['categories' => [171 => 'vehicles', 172 => 'furniture', 173 => 'furniture', 174 => 'machinery'], 'tags' => [180 => ['deferred_finance_cost']]]);
        $this->assertNotContains('ppe.accumulated', array_column($built['missing'], 'key'));
        $this->assertSame([85548.44, 4471.05, 1869.41], [$cell($built, 'ppe', 'depreciation', 'vehicles')->amount, $cell($built, 'ppe', 'depreciation', 'furniture')->amount,
            $cell($built, 'ppe', 'depreciation', 'machinery')->amount]);
        $this->assertSame(776316.43, $cell($built, 'ppe', 'nbv_closing', 'vehicles')->amount);
        $loans = fn (string $row) => $cell($built, 'loans', $row, 'current')->amount;
        $this->assertSame([3567555.43, 367239.73, 3200315.70], [$loans('gross'), $loans('deferred_finance_cost'), $loans('net')]);
        $sofp = fn (string $key) => collect($built['statements']['financial_position'])->firstWhere('key', $key)['current']->amount;
        $this->assertSame($sofp('sofp.total_assets'), $sofp('sofp.total_equity_and_liabilities'));
    }

    public function test_changes_in_equity_and_cash_flow_from_the_classified_file(): void
    {
        $run   = (new ClassificationRun)->forceFill(['status' => 'done', 'result' => $this->classify()]);
        $built = app(CurrentYearStatements::class)->build($run, 'sa-ifrs-smes-3', ['inputs' => ['oci.has_items' => false], 'tags' => [180 => ['deferred_finance_cost']]]);
        $cf    = fn (string $key) => collect($built['statements']['cash_flows'])->firstWhere('key', $key)['current']->amount;
        $soce  = fn (string $row, string $col) => collect($built['statements']['changes_in_equity']['current'])->firstWhere('key', $row)['cells'][$col]->amount;
        $codes = array_column($built['checks'], 'code');

        // Plan §0.3: the 2024 result (5,011,518.20) cleared to the partners' current accounts: dividends credited to them,
        // non-cash (24 until confirmed); the partners' net movement in financing.
        $this->assertSame([-5011518.2, 179588.87, 179588.87], [$soce('dividends', 'retained_earnings'), $soce('net_profit', 'retained_earnings'), $soce('closing', 'retained_earnings')]);
        $this->assertContains('re_cleared_no_dividend', $codes);
        $this->assertSame([5011518.2, 0.0, -589599.59], [$cf('cf.noncash_distributions_to_partners'), $cf('cf.dividends_paid'), $cf('cf.partners_current')]);
        // Murabaha (Q36, profit paid in financing): drawn net of the new deferred cost; finance cost paid = the part not amortised.
        $this->assertSame([1031000.0, -2239671.99, -2288.5], [$cf('cf.loans_drawn'), $cf('cf.loans_repaid'), $cf('cf.finance_cost_paid')]);
        $this->assertSame([-280743.94, 91888.9, -134455.96], [$cf('cf.ppe_purchases'), $cf('cf.depreciation_ppe'), $cf('cf.zakat_paid')]);
        // The cash flow reaches the balance sheet's cash.
        $this->assertSame([108838.39, 34728.95], [$cf('cf.opening_cash'), $cf('cf.closing_cash')]);
        $this->assertSame($cf('cf.closing_cash'), collect($built['statements']['financial_position'])->firstWhere('key', 'sofp.cash')['current']->amount);
        foreach (['cash_flow_closing_mismatch', 'soce_closing_mismatch', 're_roll'] as $code) {
            $this->assertNotContains($code, $codes, $code);
        }
    }

    public function test_notes_from_the_classified_file(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'sample.xlsx', 'result' => $this->classify()]);
        $id  = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        // The user confirms the periodic stock accounts (cost of revenue), pairs the accumulated depreciation, tags the deferred interest.
        $state = $this->putJson("/api/v1/financial-reports/{$id}/account-overrides", ['overrides' => [
            ['row' => 189, 'line_key' => 'is.cost_of_revenue'], ['row' => 285, 'line_key' => 'is.cost_of_revenue'],
            ['row' => 171, 'category' => 'vehicles'], ['row' => 172, 'category' => 'furniture'], ['row' => 173, 'category' => 'furniture'], ['row' => 174, 'category' => 'machinery'],
            ['row' => 180, 'tags' => ['deferred_finance_cost']]]])->assertOk()->json();
        $notes = collect($state['notes']);
        $shown = $notes->where('visible', true)->pluck('number', 'key')->all();
        $this->assertSame(range(1, count($shown)), array_values($shown));
        $this->assertSame(['incorporation', 'basis', 'policies', 'ppe', 'inventory', 'trade_receivables', 'other_receivables', 'cash', 'capital', 'loans', 'employee_benefits',
            'trade_payables', 'other_payables', 'related_parties', 'zakat', 'revenue', 'cost_of_revenue', 'selling', 'general_admin', 'finance_cost', 'financial_risk',
            'subsequent_events', 'approval'], array_keys($shown));
        $line = fn (string $key) => collect($state['statements']['financial_position'])->firstWhere('key', $key)['note'];
        $this->assertSame([$shown['ppe'], $shown['cash'], $shown['loans'], $shown['loans']], [$line('sofp.ppe'), $line('sofp.cash'), $line('sofp.murabaha_loans_non_current'), $line('sofp.murabaha_loans_current')]);

        // Every note table ties to its lines this year (the comparative income statement waits for a prior source).
        $this->assertSame([], array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'note_total_mismatch' && $c['scope'] === 'current')));
        // The periodic build-up: the stock accounts confirmed to cost of revenue (Q32).
        $this->assertTrue($state['narrative']['blocks']['cost_periodic_inventory']);
        $v = fn (string $k) => $state['narrative']['values'][$k]['amount'];
        $this->assertSame($v('cost_inventory_cogs_cy'), round($v('cost_inventory_opening_cy') + $v('cost_inventory_purchases_cy') + $v('cost_inventory_purchase_costs_cy') + $v('cost_inventory_closing_cy'), 2));
        $this->assertLessThan(0, $v('cost_inventory_closing_cy'));
        // The PPE note per category once paired; the loans note net of the deferred interest (Q36).
        $this->assertSame([776316.43, 848609.34], [$v('ppe_cy_nbv_closing_vehicles'), $v('ppe_cy_nbv_closing_total')]);
        $this->assertSame([3567555.43, 367239.73, 3200315.70], [$v('loans_gross_cy'), $v('loans_deferred_finance_cost_cy'), $v('loans_net_cy')]);
        // The end-of-service reversal on its own row (approved template row).
        $this->assertEquals([53240.67, 975.34, 0], [$v('eosb_charge_cy'), $v('eosb_reversal_cy'), $v('eosb_paid_cy')]);
        // Murabaha loans: the financing classification sentence; no going-concern indicator (a profit, equity positive).
        $this->assertTrue($state['narrative']['blocks']['cf_murabaha_finance_cost']);
        $this->assertSame([], $state['going_concern']);
    }

    public function test_the_notes_docx_shows_what_the_version_shows(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'sample.xlsx', 'result' => $this->classify()]);
        $id  = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->putJson("/api/v1/financial-reports/{$id}/account-overrides", ['overrides' => [
            ['row' => 189, 'line_key' => 'is.cost_of_revenue'], ['row' => 285, 'line_key' => 'is.cost_of_revenue'],
            ['row' => 171, 'category' => 'vehicles'], ['row' => 172, 'category' => 'furniture'], ['row' => 173, 'category' => 'furniture'], ['row' => 174, 'category' => 'machinery'],
            ['row' => 180, 'tags' => ['deferred_finance_cost']]]])->assertOk();
        $this->postJson("/api/v1/financial-reports/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $docx  = $this->docxBody($this->get("/api/v1/financial-reports/{$id}/versions/1/export.docx")->assertOk()->getContent());
        $shown = $this->versionShows($id);

        // 23 notes, in order; both years' columns (the balance sheet's comparative from the opening columns); the PPE
        // note in its landscape section, per category.
        $titles = array_column($shown['titles'], 1);
        $this->assertCount(23, $titles);
        $this->assertSame($shown['sequence'], $this->docxFromFirstNote($docx, $shown));
        $this->assertCount(count($shown['tables']), $docx['tables']);
        foreach ($docx['tables'] as $i => $rows) {
            $this->assertSame($shown['tables'][$i], $this->docxReadingOrder($docx['xp'], $i, $rows), "table {$i}");
        }
        $this->assertSame(1, $docx['xp']->query('//w:sectPr/w:pgSz[@w:orient="landscape"]')->length);
        $this->assertContains('3,200,316', array_merge(...array_merge(...$docx['tables'])));
    }

    public function test_the_pdf_of_the_sample_file(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'sample.xlsx', 'result' => $this->classify()]);
        $id  = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->putJson("/api/v1/financial-reports/{$id}/account-overrides", ['overrides' => [
            ['row' => 189, 'line_key' => 'is.cost_of_revenue'], ['row' => 285, 'line_key' => 'is.cost_of_revenue'],
            ['row' => 171, 'category' => 'vehicles'], ['row' => 172, 'category' => 'furniture'], ['row' => 173, 'category' => 'furniture'], ['row' => 174, 'category' => 'machinery'],
            ['row' => 180, 'tags' => ['deferred_finance_cost']]]])->assertOk();
        $this->postJson("/api/v1/financial-reports/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $v = $this->getJson("/api/v1/financial-reports/{$id}/versions/1")->json();

        // The 23 notes in the print view, numbered as the version; the PPE note's categories in a smaller font, on
        // a portrait page like every note's (only the changes in equity turn).
        $html = app(\App\Services\Exports\StatementsPdf::class)->html($v['payload'], $v['created_at']);
        preg_match_all('/<h3 data-note="(\d+)">/', $html, $m);
        $this->assertSame(range(1, 23), array_map('intval', $m[1]));
        $pdf = $this->get("/api/v1/financial-reports/{$id}/versions/1/export.pdf")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/<table class="fs (wide|medium)">/', $html);
        $this->assertStringNotContainsString('orientation="L"', substr($html, strpos($html, '<h3 data-note="1">')));
    }
}
