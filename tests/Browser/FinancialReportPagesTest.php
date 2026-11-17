<?php

namespace Tests\Browser;

use App\Models\Company;
use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use App\Services\Exports\StatementsPdf;
use App\Services\Exports\StatementsXlsx;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\DuskTestCase;
use Tests\Support\ArabicText;
use Tests\Support\BusyRun;
use Tests\Support\ClassifiedRows;

/**
 * PR 13 in a browser: once the scripts have drawn the pages (list, new report, the wizard's seven steps, a version), the
 * visible text is Arabic and shows no code; each step saves through the API.
 */
class FinancialReportPagesTest extends DuskTestCase
{
    use ArabicText, BusyRun, ClassifiedRows, DatabaseMigrations;

    /** The page's visible text after its scripts ran: Arabic, with no code, no «undefined» and no raw key; no script error (a 422 the page shows is expected). */
    private function assertArabicPage(Browser $browser, string $where): void
    {
        $text = $browser->script('return document.body.innerText')[0];
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, $where);
        $this->assertSame([], $this->codesInText($text), "{$where}: codes visible");
        $this->assertDoesNotMatchRegularExpression('/\b[a-z]+(?:_[a-z0-9]+)+\b|\$\{/', $text, "{$where}: an identifier is visible");
        // Leftovers of a script: Arabic text never contains these, even glued to a word.
        $this->assertDoesNotMatchRegularExpression('/null|undefined|NaN|\[object/', $text, "{$where}: a script value is visible");
        // An account is «number — name», never an internal row number.
        $this->assertDoesNotMatchRegularExpression('/\bR\d+\b|الصفوف|\bصف \d/u', $text, "{$where}: a row reference is visible");
        $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE' && ! str_contains($l['message'], 'status of 422'))), "{$where}: script errors");
    }

    /**
     * Waits for this save's «تم الحفظ.»: the page redraws after a save and shows it in a new box, while the last save's box can
     * still be on screen, so a box already seen doesn't count.
     */
    private function waitForSaved(Browser $browser): void
    {
        $browser->waitUsing(5, 100, fn () => $browser->script(
            "const b = [...document.querySelectorAll('.msg.ok:not([data-seen])')].find((e) => e.innerText.includes('تم الحفظ.'));
            if (b) b.dataset.seen = '1';
            return !! b;")[0]);
    }

    private function seedRun(): ClassificationRun
    {
        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'ميزان 2025.xlsx', 'progress_total' => 6,
            'result' => $this->runResult([
                $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
                $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
                $this->account(4, 'موردون', ['opening' => 0, 'closing' => -30]),
                $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -200]),
                $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 175]),
                $this->account(7, 'استثمارات قصيرة الأجل', ['opening' => 0, 'closing' => 5]),   // a line to choose
            ], ['opening', 'closing'])]);
    }

    public function test_a_report_is_started_and_its_first_three_steps_saved(): void
    {
        $run = $this->seedRun();
        Company::create(['name_ar' => 'شركة الاختبار التجارية']);

        $this->browse(function (Browser $browser) use ($run) {
            // The list, empty.
            $browser->visit('/financial-reports')->waitForText('لا توجد قوائم مالية بعد');
            $this->assertArabicPage($browser, 'list');

            // A new report from the run: the company picked, the default period.
            $browser->visit("/financial-reports/new?run={$run->id}")->waitForText('شركة الاختبار التجارية');
            $this->assertArabicPage($browser, 'new');
            $browser->click('table tr.clickable')->waitForText('تغيير الشركة')
                ->script("document.querySelector('[data-field=period_start]').value='2025-01-01'; document.querySelector('[data-field=period_end]').value='2025-12-31';");
            $browser->press('إنشاء القوائم المالية')->waitForText('حالة القوائم المالية');
            $report = FinancialReport::firstOrFail();
            $this->assertSame([$run->id, '2025-01-01', '2025-12-31'], [$report->classification_run_id, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d')]);
            $this->assertNotNull($report->company_id);
            $browser->assertPathIs("/financial-reports/{$report->id}");

            // Step 1: the company's profile.
            $browser->waitForText('حفظ بيانات الشركة');
            $this->assertArabicPage($browser, 'step 1');
            $browser->type('[data-field=city]', 'الرياض')->press('حفظ بيانات الشركة');
            $this->waitForSaved($browser);
            $this->assertSame('الرياض', $report->company->fresh()->city);

            // A server error shows under its own field, in Arabic, naming it.
            $browser->script("document.querySelector('[data-field=name_ar]').value = 'ش'.repeat(300);");
            $browser->press('حفظ بيانات الشركة')->waitForText('يجب ألا يزيد طول اسم الشركة بالعربيةة على 255 حرفًا.');
            $this->assertSame('يجب ألا يزيد طول اسم الشركة بالعربيةة على 255 حرفًا.', $browser->text('[data-field=name_ar] + .err'));
            $this->assertArabicPage($browser, 'step 1 error');
            $this->assertSame('شركة الاختبار التجارية', $report->company->fresh()->name_ar);

            // Step 2: manual comparatives.
            $browser->click('a[href="#prior_year"]')->waitForText('مصدر أرقام السنة السابقة');
            $this->assertArabicPage($browser, 'step 2');
            $browser->radio('source', 'manual')->waitFor('[data-field="figures.sofp.cash"]')
                ->type('[data-field="figures.sofp.cash"]', '100')->type('[data-field="figures.sofp.share_capital"]', '100')
                ->press('حفظ السنة السابقة');
            $this->waitForSaved($browser);
            $this->assertEquals(['sofp.cash' => 100, 'sofp.share_capital' => 100], $report->fresh()->prior_figures['figures']);
            $this->assertArabicPage($browser, 'step 2 saved');

            // Step 3: the account with a line to choose, and an adjusting entry.
            $browser->click('a[href="#accounts"]')->waitForText('حسابات من غير سطر في القوائم');
            $this->assertArabicPage($browser, 'step 3');
            $browser->script("const s = document.querySelector('[data-unmapped-row=\"7\"]'); s.value = 'sofp.murabaha_deposits'; s.dispatchEvent(new Event('change'));");
            $browser->click('[data-unmapped-save="7"]');
            $this->waitForSaved($browser);
            $this->assertSame([['row' => 7, 'line_key' => 'sofp.murabaha_deposits']], $report->fresh()->account_overrides);

            $browser->press('قيد جديد')->waitFor('.entry')
                ->type('.entry textarea', 'إثبات مصروف مستحق')
                ->script("const t = document.querySelectorAll('.entry tbody tr'); t[0].querySelector('select').value = 'line:is.general_admin'; t[1].querySelector('select').value = 'row:4';
                    t[0].querySelectorAll('input')[0].value = '10'; t[1].querySelectorAll('input')[1].value = '10';");
            $browser->press('حفظ القيود');
            $this->waitForSaved($browser);
            $this->assertSame('إثبات مصروف مستحق', $report->fresh()->adjustments[0]['reason']);
            $this->assertArabicPage($browser, 'step 3 saved');
            // D1: each line's accounts next to the line; the entry is the difference; the trial balance's sides agree.
            $ga = $browser->script("return [...document.querySelectorAll('[data-line-total=\"is.general_admin\"] td')].map((td) => td.innerText.trim())")[0];
            $this->assertSame(['1', '(175.00)', '(185.00)', '(10.00)'], array_slice($ga, 1));
            $browser->assertSeeIn('[data-tb-totals]', 'الفرق -');

            // Review: the prior-year lines left empty (manual) are listed as counted 0, the typed ones aren't.
            $browser->click('a[href="#review"]')->waitForText('جاهزية النسخة النهائية');
            $this->assertArabicPage($browser, 'review');
            $browser->assertSeeIn('[data-defaulted]', 'أرقام السنة السابقة اللي اتحسبت صفر');
            $items = $browser->script("return [...document.querySelectorAll('[data-defaulted] li')].map((li) => li.firstChild.textContent)")[0];
            $this->assertContains('ذمم تجارية دائنة', $items);
            $this->assertNotContains('رأس المال', $items);
            $this->assertNotContains('النقد ومعادلات النقد', $items);

            // What changes the figures: the adjusting entry in full (Q39), the account moved with its line by the classification.
            $entries = $browser->script("return document.querySelector('[data-entries]').innerText")[0];
            foreach (['قيد 1', '1004 — حساب 4', '10.00', 'السبب: إثبات مصروف مستحق'] as $text) {
                $this->assertStringContainsString($text, $entries);
            }
            $moved = $browser->script("return document.querySelector('[data-override-row=\"7\"]').innerText")[0];
            $this->assertStringContainsString('1007 — حساب 7', $moved);
            $this->assertStringContainsString('من غير سطر', $moved);
            $this->assertStringContainsString('ودائع مرابحة', $moved);
            $browser->click('[data-override-row="7"] a')->waitForText('حسابات من غير سطر في القوائم')->assertFragmentIs('accounts');
            $browser->back()->waitForText('تعديلاتك على الأرقام');

            // The old preview link leads to the review step.
            $browser->visit("/financial-reports/{$report->id}/preview")->waitForText('جاهزية النسخة النهائية')->assertFragmentIs('review');
        });
    }

    /** Movements on PPE (with accumulated depreciation) and end-of-service benefits, opening / period / closing. */
    private function seedMovementRun(): ClassificationRun
    {
        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'ميزان الحركة.xlsx', 'progress_total' => 9,
            'result' => $this->runResult([
                $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [500, 287]]),
                $this->account(3, 'رأس المال', ['opening' => -260]),
                $this->account(5, 'إيرادات المبيعات', ['period' => [0, 500]]),
                $this->account(6, 'إيجارات', ['period' => [253, 0]]),
                $this->account(14, 'سيارات ووسائل نقل', ['opening' => 200, 'period' => [50, 30]]),
                $this->account(15, 'مجمع إهلاك الأصول الثابتة', ['opening' => -40, 'period' => [0, 20]]),
                $this->account(16, 'مخصص نهاية الخدمة', ['opening' => 0, 'period' => [2, 7]]),
                $this->account(17, 'مصروف مكافأة نهاية الخدمة', ['period' => [7, 0]]),
                $this->account(18, 'إهلاك السيارات', ['period' => [20, 0]]),
            ])]);
    }

    public function test_movements_and_notes_are_decided_and_accounts_named(): void
    {
        $run = $this->seedMovementRun();
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة الحركة'])->json('id');
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'company_id' => $company,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            // Step 3 names the accounts too.
            $browser->visit("/financial-reports/{$id}#accounts")->waitForText('كل الحسابات');
            $this->assertArabicPage($browser, 'step 3');
            $this->assertStringContainsString('1014 — حساب 14', $browser->script('return document.body.innerText')[0]);

            // Step 4: the questions on figures, a suggestion confirmed, then the rest at once.
            $browser->visit("/financial-reports/{$id}#movements")->waitForText('الحركات المقترحة');
            $this->assertArabicPage($browser, 'step 4');
            $this->assertStringContainsString('«1014 — حساب 14»', $browser->script('return document.body.innerText')[0]);   // the basis names its accounts
            $browser->select('[data-field="inputs.oci.has_items"]', '0')
                ->type('[data-field="inputs.zakat.zero_reason"]', 'الشركة مش خاضعة للزكاة السنة دي.')
                ->press('حفظ الإجابات');
            $this->waitForSaved($browser);
            $this->assertSame(false, $report->fresh()->inputs['oci.has_items']);

            $browser->click('[data-confirm="ppe.additions.vehicles"]');
            $this->waitForSaved($browser);
            $this->assertSame('confirm', $report->fresh()->movement_decisions['ppe.additions.vehicles']['action']);
            $browser->press('تأكيد كل المقترحات')->acceptDialog();
            $this->waitForSaved($browser);
            $this->assertSame([], array_values(array_filter($this->getJson("/api/v1/financial-reports/{$id}")->json('suggestions'), fn ($x) => $x['status'] === 'pending')));
            $this->assertArabicPage($browser, 'step 4 saved');

            // Step 5: the notes' questions, a note hidden, a paragraph reworded.
            $browser->visit("/financial-reports/{$id}#notes")->waitForText('أسئلة الإيضاحات');
            $this->assertArabicPage($browser, 'step 5');
            $browser->type('[data-field="inputs.approval.body"]', 'الشركاء')
                ->script("document.querySelector('[data-field=\"inputs.approval.date\"]').value = '2026-02-15';");
            // The form has suggested answers left as they are (policy defaults): saved only the user's own.
            $browser->press('حفظ الإجابات')->waitFor('dialog[open]')->click('[data-dialog=mine]');
            $this->waitForSaved($browser);
            $this->assertSame(['الشركاء', '2026-02-15'], [$report->fresh()->inputs['approval.body'], $report->fresh()->inputs['approval.date']]);

            $browser->script("document.querySelector('[data-note-key=cash]').open = true;");
            $browser->select('[data-note="cash"]', 'off')
                ->click('[data-note-key=cash] .toolbar .btn.ghost.small');
            $this->waitForSaved($browser);
            $this->assertSame('off', $report->fresh()->notes['visibility']['cash']);
            // The note's own warning (its line has an amount, the note is hidden) shows in the note, next to the problem.
            $browser->script("document.querySelector('[data-note-key=cash]').open = true;");
            $browser->waitFor('[data-note-key=cash] [data-note-checks]')->assertSeeIn('[data-note-key=cash] [data-note-checks]', 'النقد');

            $browser->script("document.querySelector('[data-note-key=incorporation]').open = true;");
            $browser->click('[data-paragraph="incorporation.4"]')->waitFor('[data-field="texts.incorporation.4"]')
                ->clear('[data-field="texts.incorporation.4"]')->type('[data-field="texts.incorporation.4"]', 'يقع المركز الرئيسي للشركة في جدة.')
                ->click('[data-note-key=incorporation] form button[type=submit]');
            $this->waitForSaved($browser);
            $this->assertSame('يقع المركز الرئيسي للشركة في جدة.', $report->fresh()->notes['texts']['incorporation.4']['text']);
            $browser->script("document.querySelector('[data-note-key=incorporation]').open = true;");
            $browser->waitForText('نص معدّل');
            $this->assertArabicPage($browser, 'step 5 saved');
        });
    }

    public function test_suggested_answers_are_saved_only_when_accepted(): void
    {
        // Last year's report for the same company answered the approval body: this year's suggests it.
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة المقترحات'])->json('id');
        $prior = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id, 'company_id' => $company,
            'period_start' => '2024-01-01', 'period_end' => '2024-12-31'])->json('id');
        $this->patchJson("/api/v1/financial-reports/{$prior}/inputs", ['inputs' => ['approval.body' => 'مدير الشركة']])->assertOk();
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id, 'company_id' => $company,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->putJson("/api/v1/financial-reports/{$id}/prior-year", ['source' => 'report', 'prior_report_id' => $prior])->assertOk();
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            $browser->visit("/financial-reports/{$id}#notes")->waitForText('أسئلة الإيضاحات');
            $this->assertSame('مدير الشركة', $browser->value('[data-field="inputs.approval.body"]'));

            // The user's own answer, with the suggestion untouched: the box lists it.
            $browser->script("document.querySelector('[data-field=\"inputs.approval.date\"]').value = '2026-02-15';");
            $browser->press('حفظ الإجابات')->waitFor('dialog[open]')->assertSeeIn('dialog[open]', 'جهة اعتماد القوائم المالية');
            $this->assertArabicPage($browser, 'suggestions box');

            // Back to the form: nothing saved.
            $browser->click('[data-dialog=cancel]')->waitUntilMissing('dialog[open]');
            $this->assertArrayNotHasKey('approval.date', $report->fresh()->inputs ?? []);

            // Only the user's change.
            $browser->press('حفظ الإجابات')->waitFor('dialog[open]')->click('[data-dialog=mine]');
            $this->waitForSaved($browser);
            $this->assertSame('2026-02-15', $report->fresh()->inputs['approval.date']);
            $this->assertArrayNotHasKey('approval.body', $report->fresh()->inputs);

            // Then the suggestion accepted.
            $browser->press('حفظ الإجابات')->waitFor('dialog[open]')->click('[data-dialog=accept]');
            $this->waitForSaved($browser);
            $this->assertSame('مدير الشركة', $report->fresh()->inputs['approval.body']);
            $this->assertArabicPage($browser, 'suggestion accepted');
        });
    }

    public function test_step_2_saves_only_the_note_figures_typed_not_those_shown_from_the_openings(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            // The notes grid shows the cash note's figure from the trial balance's openings (no source saved yet).
            $browser->visit("/financial-reports/{$id}#prior_year")->waitForText('مصدر أرقام السنة السابقة')
                ->radio('source', 'manual')->waitFor('[data-field="figures.sofp.cash"]')
                ->type('[data-field="figures.sofp.cash"]', '100')->type('[data-field="figures.sofp.share_capital"]', '100');
            $shown = $browser->script("return [...document.querySelectorAll('[data-notes-grid] input[data-field=\"notes.cash\"]')].map((i) => i.value)")[0];
            $this->assertContains('100', $shown);
            // Saved untouched: not typed (it would rank above last year's figures).
            $browser->press('حفظ السنة السابقة');
            $this->waitForSaved($browser);
            $this->assertArrayNotHasKey('cash', $report->fresh()->prior_figures['notes'] ?? []);
            // Changed: typed.
            $browser->waitFor('[data-notes-grid]')->script("const d = document.querySelector('[data-notes-grid]'); d.open = true;
                const i = d.querySelector('input[data-field=\"notes.cash\"]'); i.value = '90';");
            $browser->press('حفظ السنة السابقة');
            $this->waitForSaved($browser);
            $this->assertEquals(90, array_values($report->fresh()->prior_figures['notes']['cash'])[0]);
        });
    }

    public function test_a_second_click_on_save_opens_no_second_box(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $this->browse(function (Browser $browser) use ($id) {
            // The approving body is suggested («الإدارة», R3): saving asks about it, once however many clicks.
            $browser->visit("/financial-reports/{$id}#notes")->waitForText('أسئلة الإيضاحات');
            $browser->script("const b = [...document.querySelectorAll('button[type=submit]')].find((x) => x.innerText.includes('حفظ الإجابات')); b.click(); b.click();");
            $browser->waitFor('dialog[open]')->pause(300);
            $this->assertSame(1, $browser->script("return document.querySelectorAll('dialog').length")[0]);
            $browser->click('[data-dialog=cancel]')->waitUntilMissing('dialog[open]');
            $this->assertSame(0, $browser->script("return document.querySelectorAll('dialog').length")[0]);
        });
    }

    public function test_the_company_chosen_on_the_classifier_page_comes_with_its_run(): void
    {
        $company = Company::create(['name_ar' => 'شركة الميزان المرفوع']);
        $run = $this->seedRun();
        $run->forceFill(['company_id' => $company->id])->save();

        $this->browse(function (Browser $browser) use ($run, $company) {
            // Chosen already (its chip, not the picker), still changeable.
            $browser->visit("/financial-reports/new?run={$run->id}")->waitForText('الشركة');
            $this->assertTrue($browser->script("return [...document.querySelectorAll('#app .chip')].some((c) => c.innerText.trim() === 'شركة الميزان المرفوع')")[0]);
            $browser->press('إنشاء القوائم المالية')->waitForText('الشركة والفترة');
            $this->assertSame($company->id, FinancialReport::where('classification_run_id', $run->id)->value('company_id'));
        });
    }

    public function test_a_check_on_a_note_links_to_the_notes_step(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $this->browse(function (Browser $browser) use ($id) {
            $browser->visit("/financial-reports/{$id}#review")->waitForText('المراجعة');
            $steps = $browser->driver->executeAsyncScript("const done = arguments[0]; import('/js/financial-report/describe.js').then((m) => done([
                m.stepForCheck({ code: 'partners_review', note: 'capital', scope: 'current' }),
                m.stepForCheck({ code: 'related_parties_unmatched', note: 'related_parties', scope: 'current' }),
                m.stepForCheck({ code: 'disposal_pending', scope: 'current' }),
                m.stepForCheck({ code: 'ppe_additions_depreciation', note: 'ppe', scope: 'current' })]));");
            // The partners' table and the related parties are in step 5 (the notes), not step 4 (the movements); an entry
            // proposed for the additions' depreciation is added with the accounts' entries.
            $this->assertSame(['notes', 'notes', 'accounts', 'accounts'], $steps);
        });
    }

    public function test_the_accounts_steps_line_totals_leave_no_heading_with_nothing_under_it(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $this->browse(function (Browser $browser) use ($id) {
            $browser->visit("/financial-reports/{$id}#accounts")->waitFor('[data-totals="financial_position"]');
            // No account and no figure on any non-current liability: neither its heading nor its total.
            $text = $browser->script("return document.querySelector('[data-totals=\"financial_position\"]').innerText")[0];
            $this->assertStringContainsString('الالتزامات المتداولة', $text);
            $this->assertStringNotContainsString('الالتزامات غير المتداولة', $text);
        });
    }

    public function test_the_wizard_fits_a_laptop_screen_with_no_sideways_scroll(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $this->browse(function (Browser $browser) use ($id) {
            $columns = fn () => count(explode(' ', trim($browser->script("return getComputedStyle(document.querySelector('.wizard')).gridTemplateColumns")[0])));
            // 1366 wide: the side panel goes under the step, which takes the width (a 7-column table fits without scrolling).
            $browser->resize(1366, 800)->visit("/financial-reports/{$id}#review")->waitFor('.wizard');
            $this->assertSame(1, $columns());
            $this->assertSame([], $browser->script("return [...document.querySelectorAll('.panel')].filter((p) => p.scrollWidth > p.clientWidth + 1).map((p) => p.innerText.slice(0, 40))")[0]);
            $browser->resize(1920, 1080)->refresh()->waitFor('.wizard');
            $this->assertSame(2, $columns());
        });
    }

    /** A first-year report with every question answered and every suggestion confirmed; two accounts to review make one warning. */
    private function readyReport(): FinancialReport
    {
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة الإصدارات', 'city' => 'الرياض', 'cr_number' => '1010000000',
            'cr_date_hijri' => '1440/01/01', 'unified_number' => '7000000000', 'activity' => 'التجارة', 'head_office' => 'الرياض'])->json('id');
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 150]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -200]),
            $this->account(6, 'إيجارات', ['closing' => 170]),
            $this->account(7, 'موردون', ['closing' => -10], ['needs_review' => true]),
            $this->account(8, 'إيجارات', ['closing' => 20], ['needs_review' => true]),
        ], ['closing']);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'company_id' => $company,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->putJson("/api/v1/financial-reports/{$id}/prior-year", ['source' => 'first_year'])->assertOk();
        $this->patchJson("/api/v1/financial-reports/{$id}/inputs", ['inputs' => [
            'zakat.zero_reason' => 'الشركة في أول سنة ولا زكاة عليها.', 'oci.has_items' => false, 'approval.body' => 'الشركاء',
            'approval.date' => '2026-02-15', 'partners' => [['name' => 'الشريك الأول', 'shares' => 10, 'share_value' => 10]],
            'first_application.this_period' => false, 'policies.revenue' => ['goods'],
        ]])->assertOk();
        $pending = array_filter($this->getJson("/api/v1/financial-reports/{$id}")->json('suggestions'), fn ($x) => $x['status'] === 'pending');
        $this->postJson("/api/v1/financial-reports/{$id}/suggestions", ['decisions' => array_values(array_map(fn ($x) => ['key' => $x['key'], 'action' => 'confirm'], $pending))])->assertOk();

        return FinancialReport::findOrFail($id);
    }

    public function test_a_report_is_reviewed_and_issued_as_versions(): void
    {
        $report = $this->readyReport();
        $id = $report->id;

        $this->browse(function (Browser $browser) use ($id, $report) {
            // Review: only the warning stands between the report and a final, so the final is closed.
            $browser->visit("/financial-reports/{$id}#review")->waitForText('جاهزية النسخة النهائية');
            $this->assertArabicPage($browser, 'review');
            $browser->assertSee('تنبيهات لم تُؤكَّد بعد')->assertSee('قائمة المركز المالي')->assertSee('قائمة التدفقات النقدية')
                ->assertSee('التكوين والنشاط')->assertButtonDisabled('[data-version=final]');
            $this->assertNull($browser->element('[data-defaulted]'));   // a first year: nothing typed by hand
            // Nothing but the statements under them: no stray text (a «true» once printed under each).
            $stray = $browser->script("return [...document.querySelectorAll('section[data-statement]')].flatMap((s) => [...s.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim())).filter((t) => t !== '')")[0];
            $this->assertSame([], $stray);

            // A line opens to its accounts, by number and name.
            $browser->script("document.querySelector('[data-line=\"is.general_admin\"] details, [data-line=\"is.revenue\"] details').open = true;");
            $this->assertMatchesRegularExpression('/100[56] — حساب [56]/u', $browser->script('return document.body.innerText')[0]);

            // The warning acknowledged with a note: the final opens.
            $browser->check('[data-ack]')->type('[data-ack-note]', 'راجعناهم مع المحاسب.')->press('حفظ التأكيدات');
            $this->waitForSaved($browser);
            $this->assertSame('راجعناهم مع المحاسب.', $report->fresh()->acknowledgements[0]['note']);
            $browser->waitForText('التقرير جاهز لنسخة نهائية.')->assertButtonEnabled('[data-version=final]');
            $this->assertArabicPage($browser, 'review acknowledged');

            // A draft, then the final (asked first): each listed on the versions step.
            $browser->click('[data-version=draft]')->waitForText('تم حفظ النسخة رقم 1 (مسودة).')->assertFragmentIs('versions');
            $browser->click('[data-version=final]')->assertDialogOpened('إصدار نسخة نهائية؟ النسخة بتتحفظ زي ما هي ومبتتغيرش بعد كده؛ التقرير نفسه يفضل قابل للتعديل.')
                ->acceptDialog()->waitForText('تم حفظ النسخة رقم 2 (نهائي).');
            $this->assertSame(['final', [1, 2]], [$report->fresh()->status, $report->versions()->orderBy('version')->pluck('version')->all()]);
            $browser->waitForText('القوائم زي آخر نسخة نهائية (رقم 2) بالظبط.')->assertPresent('[data-version-row="1"]');
            $this->assertArabicPage($browser, 'versions');

            // Every version downloads its statements as an Excel file (PR 14)…
            $this->assertStringEndsWith("/api/v1/financial-reports/{$id}/versions/1/export.xlsx", $browser->attribute('[data-download="1"]', 'href'));
            $browser->script("fetch(document.querySelector('[data-download=\"2\"]').href).then((r) => { window.__xlsx = [r.status, r.headers.get('content-type')]; });");
            $browser->waitUsing(5, 100, fn () => $browser->script('return window.__xlsx !== undefined')[0]);
            $this->assertSame([200, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $browser->script('return window.__xlsx')[0]);
            // …and its notes as a Word file (PR 15).
            $this->assertStringEndsWith("/api/v1/financial-reports/{$id}/versions/1/export.docx", $browser->attribute('[data-download-docx="1"]', 'href'));
            $browser->script("fetch(document.querySelector('[data-download-docx=\"2\"]').href).then((r) => { window.__docx = [r.status, r.headers.get('content-type')]; });");
            $browser->waitUsing(5, 100, fn () => $browser->script('return window.__docx !== undefined')[0]);
            $this->assertSame([200, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $browser->script('return window.__docx')[0]);
            // …and both as a PDF (PR 16).
            $this->assertStringEndsWith("/api/v1/financial-reports/{$id}/versions/1/export.pdf", $browser->attribute('[data-download-pdf="1"]', 'href'));
            $browser->script("fetch(document.querySelector('[data-download-pdf=\"2\"]').href).then((r) => { window.__pdf = [r.status, r.headers.get('content-type')]; });");
            $browser->waitUsing(10, 100, fn () => $browser->script('return window.__pdf !== undefined')[0]);
            $this->assertSame([200, 'application/pdf'], $browser->script('return window.__pdf')[0]);

            // A version opens as it was: read-only, at its own address.
            $browser->click('[data-version-row="2"] a')->waitForText('للقراءة بس')->assertFragmentIs('versions/2')->assertSee('النسخة رقم 2 — نهائي')
                ->assertSee('قائمة المركز المالي')->assertMissing('[data-version], [data-ack]')->assertPresent('[data-download="2"]')->assertPresent('[data-download-docx="2"]')->assertPresent('[data-download-pdf="2"]');
            $this->assertArabicPage($browser, 'version 2');

            // Changed after the final: the versions step says so, and version 2 stays as it was.
            $this->patchJson("/api/v1/financial-reports/{$id}/inputs", ['inputs' => ['approval.body' => 'الجمعية العامة']])->assertOk();
            $browser->visit('/financial-reports')->visit("/financial-reports/{$id}#versions")->waitForText('التقرير اتغير بعد آخر نسخة نهائية (رقم 2)');
            $browser->visit("/financial-reports/{$id}#versions/2")->waitForText('للقراءة بس');
            $this->assertStringNotContainsString('الجمعية العامة', $browser->script('return document.body.innerText')[0]);

            // A version that doesn't exist: said in Arabic, with the way back.
            $browser->visit("/financial-reports/{$id}#versions/9")->waitForText('هذا الإصدار غير موجود.')->assertSee('كل الإصدارات');
        });
    }

    /** The report states that raise most checks, inputs, overrides, entries, notes and versions: review and versions in Arabic. */
    public function test_every_kind_of_state_reads_in_arabic_on_the_review(): void
    {
        $run = $this->busyRun();
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $review = function (string $where, array $names = []) use ($id) {
            $this->browse(function (Browser $browser) use ($id, $where, $names) {
                $browser->visit('/financial-reports')->visit("/financial-reports/{$id}#review")->waitForText('جاهزية النسخة النهائية');
                $browser->script("document.querySelectorAll('.fs').forEach((s) => s.classList.add('show-zero')); document.querySelectorAll('details').forEach((d) => d.open = true);");
                $this->assertArabicPage($browser, $where);
                $text = $browser->script('return document.body.innerText')[0];
                foreach ($names as $name) {
                    $this->assertStringContainsString($name, $text, "{$where}: {$name}");
                }
            });
        };
        $review('no input', ['حساب مخزون أول أو آخر المدة (جرد دوري)', 'ميزان المراجعة غير متوازن', 'معلّق', '[ناقص]']);

        $this->patchJson("/api/v1/financial-reports/{$id}/inputs", ['inputs' => ['partners_current' => 'equity', 'zakat.charge' => 12.5,
            'oci.has_items' => true, 'oci.revaluation' => 30]])->assertOk();
        $this->putJson("/api/v1/financial-reports/{$id}/account-overrides", ['overrides' => [['row' => 12, 'line_key' => 'is.cost_of_revenue'],
            ['row' => 6, 'shares' => ['is.cost_of_revenue' => 0.5, 'is.general_admin' => 0.5]], ['row' => 15, 'category' => 'vehicles'],
            ['row' => 18, 'tags' => ['deferred_finance_cost']]]])->assertOk();
        $this->putJson("/api/v1/financial-reports/{$id}/adjustments", ['entries' => [['reason' => 'مخصص ضمان', 'lines' => [
            ['line_key' => 'is.other_expenses', 'debit' => 12, 'credit' => 0], ['row' => 9, 'debit' => 0, 'credit' => 12]]]]])->assertOk();
        $this->putJson("/api/v1/financial-reports/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 90, 'is.revenue' => 10],
            'restated' => true, 'restatement_note' => 'تصحيح خطأ', 'changes_in_equity' => ['opening' => ['share_capital' => 100]],
            'schedules' => ['employee_benefits.opening' => 4]])->assertOk();
        $this->postJson("/api/v1/financial-reports/{$id}/suggestions", ['decisions' => [['key' => 'ppe.additions.vehicles', 'action' => 'confirm'],
            ['key' => 'ppe.disposals.vehicles', 'action' => 'confirm'], ['key' => 'ppe.depreciation.vehicles', 'action' => 'reject'],
            ['key' => 'employee_benefits.charge', 'action' => 'edit', 'amount' => 5]]])->assertOk();
        $this->patchJson("/api/v1/financial-reports/{$id}/inputs", ['inputs' => ['ppe.disposal_proceeds' => 4, 'loans.current_portion' => 10,
            'approval.body' => 'الشركاء', 'approval.date_hijri' => '17 شعبان 1447هـ', 'approval.date' => '2026-02-15',
            'partners' => [['name' => 'الشريك الأول', 'shares' => 10, 'share_value' => 10]],
            'first_application.this_period' => true, 'first_application.effect' => 'none', 'policies.revenue' => ['goods', 'services'],
            'policies.employee_benefits' => 'actuarial', 'useful_life.vehicles' => '5', 'events.significant' => 'افتتاح فرع جديد خلال السنة.']])->assertOk();
        $this->patchJson("/api/v1/financial-reports/{$id}/notes", ['visibility' => ['investment_property' => 'on', 'policy_subsidiaries' => 'off'],
            'texts' => ['incorporation.4' => 'يقع المركز الرئيسي للشركة في المدينة.']])->assertOk();
        $review('every input', ['أرقام السنة السابقة اللي اتحسبت صفر', 'ليه رقم السنة دي', '(معدلة)', 'قائمة التغيرات في حقوق الملكية',
            'الحسابات الجارية للشركاء', 'صافي التدفقات النقدية من الأنشطة التشغيلية', 'التكوين والنشاط', 'التطبيق الأول للإصدار الثالث',
            'افتتاح فرع جديد خلال السنة.', 'يقع المركز الرئيسي للشركة في المدينة.', 'إيضاحات مش ظاهرة', 'الشريك الأول',
            'الممتلكات والآلات والمعدات', 'تعديلاتك على الأرقام', 'السبب: مخصص ضمان', 'الفئة: سيارات', 'تكاليف تمويل مؤجلة تُخصم من القروض',
            'السطر حسب التصنيف']);

        // Versions: a warning acknowledged, a draft; the review and the version read in Arabic.
        $warning = collect($this->getJson("/api/v1/financial-reports/{$id}")->json('checks'))->firstWhere('severity', 'warning');
        $this->putJson("/api/v1/financial-reports/{$id}/acknowledgements", ['acknowledgements' => [['key' => $warning['key']]]])->assertOk();
        $this->postJson("/api/v1/financial-reports/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $review('acknowledged', ['تم التأكيد', 'أخطاء يجب تصحيحها']);
        $this->browse(function (Browser $browser) use ($id) {
            $browser->visit("/financial-reports/{$id}#versions/1")->waitForText('للقراءة بس');
            $this->assertArabicPage($browser, 'draft version');
        });
    }

    /** A probable parent (row 3) and a parent by the file's levels (row 9) in an otherwise ordinary trial balance. */
    private function parentsRun(): ClassificationRun
    {
        $flag = fn (string $type, array $rows) => ['type' => $type, 'difference' => null, 'rows' => $rows, 'message' => 'ممكن يكون حساب تجميعي: أرصدته بتساوي مجموع الصف 4 في الملف اللي تحته.']
            + ($type === 'parent_level' ? ['severity' => 'info'] : []);

        return $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100]),
            $this->account(3, 'مباني وإنشاءات', ['closing' => 500], ['data_flags' => [$flag('probable_parent', [4])]]),
            $this->account(4, 'مباني وإنشاءات', ['closing' => 500]),
            $this->account(9, 'آلات ومعدات', ['closing' => 70], ['data_flags' => [$flag('parent_level', [10])]]),
            $this->account(10, 'آلات ومعدات', ['closing' => 70]),
            $this->account(5, 'رأس المال', ['closing' => -570]),
            $this->account(8, 'موردون', ['closing' => -100]),
            $this->account(6, 'إيرادات المبيعات', ['closing' => -100]),
            $this->account(7, 'إيجارات', ['closing' => 100]),
        ], ['closing']);
    }

    public function test_parent_accounts_are_excluded_with_a_reason_and_shown_in_the_review(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->parentsRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            // Step 3: the probable parent, proposed; the parent by levels, listed as left out with why.
            $browser->visit("/financial-reports/{$id}#accounts")->waitForText('حسابات ممكن تكون تجميعية');
            $this->assertArabicPage($browser, 'step 3 parents');
            $browser->script("document.querySelector('[data-excluded]').open = true;");
            $browser->assertSeeIn('[data-excluded]', 'حساب تجميعي حسب مستويات الملف')->assertSeeIn('[data-excluded]', '1009 — حساب 9');

            // Excluding needs a reason.
            $browser->click('[data-exclude="3"]')->waitForText('اكتب سبب الاستبعاد');
            $this->assertNull($report->fresh()->account_overrides);
            $browser->type('[data-exclude-reason="3"]', 'رصيده بيساوي الحساب اللي تحته')->click('[data-exclude="3"]');
            $this->waitForSaved($browser);
            $this->assertSame([['row' => 3, 'exclude' => true, 'reason' => 'رصيده بيساوي الحساب اللي تحته']], $report->fresh()->account_overrides);
            $browser->assertMissing('[data-parents]')->assertSeeIn('[data-excluded]', 'استبعدته أنت: رصيده بيساوي الحساب اللي تحته');
            $this->assertArabicPage($browser, 'step 3 excluded');

            // Any account, from its editor: «استبعاد من القوائم» with a reason.
            $browser->click('[data-account-row="7"] button')->waitFor('[data-mode]')
                ->select('[data-mode]', 'exclude')->type('[data-field="exclude-reason"]', 'اتسجل في حساب تاني')->click('[data-save-account]');
            $this->waitForSaved($browser);
            $this->assertSame(['row' => 7, 'exclude' => true, 'reason' => 'اتسجل في حساب تاني'], collect($report->fresh()->account_overrides)->firstWhere('row', 7));

            // The review lists each exclusion with its line by the classification and its reason.
            $browser->visit("/financial-reports/{$id}#review")->waitForText('تعديلاتك على الأرقام');
            $row = $browser->script("return document.querySelector('[data-override-row=\"3\"]').innerText")[0];
            foreach (['1003 — حساب 3', 'الممتلكات والآلات والمعدات', 'مستبعد من القوائم', 'رصيده بيساوي الحساب اللي تحته'] as $text) {
                $this->assertStringContainsString($text, $row);
            }
            $this->assertArabicPage($browser, 'review exclusions');
        });
    }

    public function test_a_line_chosen_for_an_account_in_the_classification_mode_is_applied_and_said(): void
    {
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->parentsRun()->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($report, $id) {
            // «حسب التصنيف»: the line the classification gives shows; choosing another switches to «سطر واحد», said so.
            $browser->visit("/financial-reports/{$id}#accounts")->waitForText('كل الحسابات')
                ->click('[data-account-row="7"] button')->waitFor('[data-account-line]')
                ->assertSelected('[data-mode]', 'rule')
                ->select('[data-account-line]', 'sofp.inventory')
                ->assertSelected('[data-mode]', 'line')
                ->assertPresent('[data-line-applied]')
                ->click('[data-save-account]');
            $this->waitForSaved($browser);
            $this->assertSame('sofp.inventory', collect($report->fresh()->account_overrides)->firstWhere('row', 7)['line_key']);
            $this->assertArabicPage($browser, 'step 3 line chosen');
        });
    }

    public function test_a_gain_on_disposal_with_nothing_disposed_is_kept_as_other_income_and_undone_with_a_click(): void
    {
        $run = $this->doneRun([
            $this->account(2, 'سيارات ووسائل نقل', ['opening' => 100]),
            $this->account(3, 'نقدية لدى البنوك', ['opening' => 20, 'period' => [80, 0]]),
            $this->account(4, 'رأس المال', ['opening' => -120]),
            $this->account(5, 'أرباح بيع أصول ثابتة', ['period' => [0, 80]]),
        ], ['opening', 'period', 'closing']);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            $browser->visit("/financial-reports/{$id}#accounts")->waitFor('[data-disposal="disposal_unmatched"]')
                ->assertSeeIn('[data-disposal="disposal_unmatched"]', 'أرباح أو خسائر بيع أصول دون استبعاد');
            $this->assertArabicPage($browser, 'step 3 disposal unmatched');

            $browser->click('[data-disposal-action="disposal_unmatched"]');
            $this->waitForSaved($browser);
            $this->assertSame([['row' => 5, 'tags' => ['not_disposal']]], $report->fresh()->account_overrides);
            $browser->waitFor('[data-disposal="disposal_pending"]')->assertMissing('[data-disposal="disposal_unmatched"]')
                ->assertSeeIn('[data-disposal="disposal_pending"]', 'في انتظار تأكيد المحاسب');
            $this->assertArabicPage($browser, 'step 3 disposal pending');

            $browser->click('[data-disposal-action="disposal_pending"]');
            $this->waitForSaved($browser);
            $this->assertSame([], $report->fresh()->account_overrides ?? []);
            $browser->waitFor('[data-disposal="disposal_unmatched"]');
        });
    }

    public function test_an_unexplained_retained_earnings_movement_is_named_with_one_click(): void
    {
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 1000]),
            $this->account(3, 'أرباح محتجزة', ['opening' => -1000, 'period' => [100, 0]]),
            $this->account(4, 'موردون', ['opening' => 0, 'period' => [0, 100]]),
        ], ['opening', 'period', 'closing']);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
        $report = FinancialReport::findOrFail($id);

        $this->browse(function (Browser $browser) use ($id, $report) {
            $browser->visit("/financial-reports/{$id}#movements")->waitFor('[data-retained]')
                ->assertSeeIn('[data-retained]', 'حركة غير مفسرة في الأرباح المبقاة')->assertSeeIn('[data-retained]', 'تسوية سنوات سابقة');
            $this->assertArabicPage($browser, 'step 4 retained earnings');
            $browser->click('[data-retained-choice="prior_year_adjustment"]');
            $this->waitForSaved($browser);
            $this->assertSame('prior_year_adjustment', $report->fresh()->inputs['soce.retained_movement']);
            // A prior-year adjustment stays flagged (Section 10 would restate the comparatives): pending the accountant.
            $browser->waitFor('[data-retained="prior_year_adjustment_pending"]')
                ->assertSeeIn('[data-retained]', 'تسوية سنوات سابقة: في انتظار قرار المحاسب');
            $this->assertArabicPage($browser, 'step 4 prior-year adjustment pending');
            $browser->click('[data-retained-choice="dividends"]');
            $this->waitForSaved($browser);
            $browser->waitUntilMissing('[data-retained]');
        });
    }

    /**
     * A statement's rows as shown on the page: `h:<label>` for a heading, the line key otherwise. Hidden rows (zero) are
     * in the page but not displayed.
     */
    private function wizardRows(Browser $browser, string $statement): array
    {
        return $browser->script("return [...document.querySelectorAll('[data-statement=\"{$statement}\"] tr')]
            .filter((tr) => !tr.querySelector('th') && getComputedStyle(tr).display !== 'none')
            .map((tr) => tr.classList.contains('heading') ? 'h:' + tr.innerText.trim() : tr.dataset.line);")[0];
    }

    /** The same from the PDF's print view and, lines and totals only (headings carry no key there), the Excel file. */
    private function exportRows(FinancialReportVersion $version): array
    {
        $payload = json_decode($version->payload, true);
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8">'.app(StatementsPdf::class)->html($payload, $version->created_at->toIso8601String()), LIBXML_NONET);
        $xp = new DOMXPath($dom);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, app(StatementsXlsx::class)->build($payload, $version->created_at->toIso8601String()));
        $book = IOFactory::load($path);
        unlink($path);
        $out = [];
        foreach (config('financial_statements.templates.sa-ifrs-smes-3.xlsx.statements') as $statement => $layout) {
            $out['pdf'][$statement] = array_map(fn ($tr) => str_contains($tr->getAttribute('class'), 'heading') ? 'h:'.trim($tr->textContent) : $tr->getAttribute('data-line'),
                iterator_to_array($xp->query("//h2[@data-statement='{$statement}']/following-sibling::table[1]/tr")));
            $keys = array_flip(array_filter(array_column($payload['statements'][$statement], 'key')));
            $sheet = $book->getSheetByName($layout['sheet']);
            $out['xlsx'][$statement] = [];
            foreach ($sheet->getRowIterator() as $row) {
                $key = $sheet->getCell($layout['key'].$row->getRowIndex())->getValue();
                if (isset($keys[$key]) && $sheet->getRowDimension($row->getRowIndex())->getVisible()) {
                    $out['xlsx'][$statement][] = $key;
                }
            }
        }
        $book->disconnectWorksheets();

        return $out;
    }

    public function test_the_wizard_the_pdf_and_the_excel_file_show_the_same_lines(): void
    {
        // Opening and closing columns, no prior source: the balance sheet has last year, the income statement's is missing.
        // Stock: an account with nothing on it (a zero line with an account under it).
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'موردون', ['opening' => 0, 'closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -200]),
            $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 180]),
            $this->account(8, 'مخزون بضاعة', ['opening' => 0, 'closing' => 0]),
        ], ['opening', 'closing']);
        $twoYears = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $this->postJson("/api/v1/financial-reports/{$twoYears}/versions", ['kind' => 'draft'])->assertCreated();
        $firstYear = $this->readyReport()->id;
        $this->postJson("/api/v1/financial-reports/{$firstYear}/versions", ['kind' => 'draft'])->assertCreated();

        $this->browse(function (Browser $browser) use ($twoYears, $firstYear) {
            foreach ([$twoYears, $firstYear] as $id) {
                $exports = $this->exportRows(FinancialReportVersion::where('report_id', $id)->where('version', 1)->firstOrFail());
                foreach (['review', 'versions/1'] as $at) {
                    $browser->visit('/financial-reports')->visit("/financial-reports/{$id}#{$at}")->waitFor('[data-statement="cash_flows"]');
                    foreach ($exports['pdf'] as $statement => $pdf) {
                        $wizard = $this->wizardRows($browser, $statement);
                        $this->assertSame($pdf, $wizard, "{$at} {$statement}");
                        $this->assertSame($exports['xlsx'][$statement], array_values(array_filter($wizard, fn ($r) => ! str_starts_with($r, 'h:'))), "{$at} {$statement} xlsx");
                    }
                }
            }
            // The cases the rule settles: a zero line with an account under it is hidden; a zero line whose prior year is
            // missing shows «[ناقص]»; a total zero in both years and the heading over nothing are hidden.
            $browser->visit('/financial-reports')->visit("/financial-reports/{$twoYears}#review")->waitFor('[data-statement="cash_flows"]');
            $shown = fn (string $statement) => $this->wizardRows($browser, $statement);
            $state = $this->getJson("/api/v1/financial-reports/{$twoYears}")->json('statements');
            $line = fn (string $statement, string $key) => collect($state[$statement])->firstWhere('key', $key);
            $this->assertContains(8, $line('financial_position', 'sofp.inventory')['rows']);
            $this->assertNotContains('sofp.inventory', $shown('financial_position'));
            // Zero this year, last year missing: shown, with «[ناقص]».
            $this->assertEquals([0, null], [$line('comprehensive_income', 'is.finance_cost')['current']['amount'], $line('comprehensive_income', 'is.finance_cost')['comparative']['amount'] ?? null]);
            $this->assertContains('is.finance_cost', $shown('comprehensive_income'));
            $browser->assertSeeIn('[data-statement="comprehensive_income"] [data-line="is.finance_cost"]', '[ناقص]');
            $this->assertNotContains('sofp.total_non_current_liabilities', $shown('financial_position'));
            $this->assertNotContains('h:الالتزامات غير المتداولة', $shown('financial_position'));
            $this->assertContains('cf.depreciation_ppe', $shown('cash_flows'));
            $browser->assertSeeIn('[data-statement="cash_flows"] [data-line="cf.depreciation_ppe"]', '[ناقص]');
        });
    }

    public function test_step_2_asks_for_last_year_only_with_opening_balances(): void
    {
        // R8: an opening column all zero: a first year, nothing asked; the form when the user says there is a prior year.
        $zero = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'ميزان أول سنة.xlsx',
            'result' => $this->runResult([
                $this->account(2, 'نقدية لدى البنوك', ['opening' => 0, 'closing' => 100]),
                $this->account(3, 'رأس المال', ['opening' => 0, 'closing' => -100]),
            ], ['opening', 'closing'])]);
        $first = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $zero->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        // Opening balances: asked as before; «first year» chosen is the user's override, said and kept.
        $opening = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $this->seedRun()->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $this->browse(function (Browser $browser) use ($first, $opening) {
            $browser->visit("/financial-reports/{$first}#prior_year")->waitFor('[data-prior-not-needed]')
                ->assertSeeIn('[data-prior-not-needed]', 'الميزان مفيهوش أرصدة أول المدة، فدي أول سنة للشركة ومش محتاجة قوائم سنة سابقة.')
                ->assertMissing('input[name=source]');
            $this->assertArabicPage($browser, 'step 2, no prior year needed');
            $browser->click('[data-prior-anyway]')->waitFor('input[name=source]')->assertRadioSelected('source', 'first_year');

            $browser->visit("/financial-reports/{$opening}#prior_year")->waitForText('مصدر أرقام السنة السابقة')
                ->assertMissing('[data-prior-not-needed]')
                ->assertSeeIn('[data-opening-signal="opening_column"]', 'الميزان فيه أرصدة أول المدة، فلازم أرقام السنة السابقة.')
                ->radio('source', 'first_year')->waitForText('الميزان فيه أرصدة أول المدة.')
                ->press('حفظ السنة السابقة');
            $this->waitForSaved($browser);
            $this->assertSame(['first_year', true], [FinancialReport::find($opening)->prior_figures['source'], FinancialReport::find($opening)->prior_figures['skip_opening']]);
        });
    }

    public function test_last_years_issued_statements_are_uploaded_and_reviewed_in_step_2(): void
    {
        // 2025 with opening columns (cash 100, capital 100); 2024's issued statements say the same, the cash row in words of
        // their own, the total assets in a synonym's.
        $run = $this->seedRun();
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setTitle('القوائم')->fromArray([
            ['قائمة المركز المالي كما في 31 ديسمبر 2024م'],
            ['', '2024', '2023'],
            ['نقد الشركة في الخزينة والمصارف', 100, 60],
            ['مجموع الأصول', 100, 60],
            ['رأس المال', 100, 60],
            ['إجمالي حقوق الملكية والالتزامات', 100, 60],
            ['قائمة الدخل الشامل للسنة المنتهية في 31 ديسمبر 2024م'],
            ['', '2024', '2023'],
            ['إيرادات النشاط', 50, 40],
            ['المصروفات العمومية والإدارية', '(50)', '(40)'],
        ], null, 'A1', true);
        $path = sys_get_temp_dir().'/قوائم-2024-'.Str::random(6).'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $this->browse(function (Browser $browser) use ($id, $path) {
            $browser->visit("/financial-reports/{$id}#prior_year")->waitForText('مصدر أرقام السنة السابقة')
                ->radio('source', 'statements')->waitFor('[data-field="file"]')
                ->attach('[data-field="file"]', $path)->click('[data-upload-prior]')->waitFor('[data-file-review]');
            $this->assertArabicPage($browser, 'step 2 file review');
            $browser->assertSeeIn('[data-file-review]', 'مستني مراجعتك')
                ->assertSeeIn('[data-file-label="رأس المال"]', 'مطابق')
                ->assertSeeIn('[data-file-label="مجموع الأصول"]', 'مطابق من المرادفات')
                ->assertSelected('[data-file-label="مجموع الأصول"] select', 'sofp.total_assets')
                ->assertSeeIn('[data-file-label="نقد الشركة في الخزينة والمصارف"]', 'اختر سطرًا')
                ->assertPresent('[data-file-review] a[href="/statement-synonyms"]');
            // Saving before placing the cash row: refused, the row named.
            $browser->press('حفظ السنة السابقة')->waitForText('اختر السطر بتاعه أو تجاهله');
            $this->assertSame('none', FinancialReport::find($id)->prior_figures['source'] ?? 'none');
            $browser->select('[data-file-label="نقد الشركة في الخزينة والمصارف"] select', 'sofp.cash')->press('حفظ السنة السابقة');
            $this->waitForSaved($browser);
            $browser->waitFor('[data-file-review]')->assertSeeIn('[data-file-review]', 'هو اللي أرقام السنة السابقة جاية منه');
        });
        @unlink($path);

        $report = FinancialReport::find($id);
        $this->assertSame('statements', $report->prior_figures['source']);
        $state = $this->getJson("/api/v1/financial-reports/{$id}")->json();
        $cash = collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.cash')['comparative'];
        $this->assertEquals([100, 'prior_year_statements'], [$cash['amount'], $cash['origin']]);
        $this->assertEquals(-50, collect($state['statements']['comprehensive_income'])->firstWhere('key', 'is.general_admin')['comparative']['amount']);
    }

    public function test_the_synonyms_are_listed_added_edited_and_deleted(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/statement-synonyms')->waitFor('[data-syn-statement="financial_position"]');
            $words = fn (string $statement) => $browser->script("return [...document.querySelectorAll('[data-syn-statement=\"{$statement}\"] [data-field=\"label\"]')].map((i) => i.value);")[0];
            $this->assertContains('النقدية وما في حكمها', $words('financial_position'));
            $this->assertArabicPage($browser, 'synonyms');

            // Added: a statement, its line, the wording.
            $browser->select('[data-syn-add] select', 'comprehensive_income')
                ->select('[data-syn-add] [data-field="line_key"]', 'is.general_admin')
                ->type('[data-syn-add] [data-field="label"]', 'مصاريف إدارية عامة')->press('إضافة')->waitForText('اتضاف المرادف.');
            $id = \App\Models\StatementSynonym::where('label', 'مصاريف إدارية عامة')->value('id');
            $this->assertNotNull($id);
            // A line's own label is refused, and said why.
            $browser->select('[data-syn-add] select', 'comprehensive_income')->select('[data-syn-add] [data-field="line_key"]', 'is.general_admin')
                ->type('[data-syn-add] [data-field="label"]', 'المصروفات العمومية والإدارية')->press('إضافة')->waitForText('ومش محتاج مرادف');

            // Edited: the line moved.
            $browser->select("[data-synonym=\"{$id}\"] [data-field=\"line_key\"]", 'is.other_expenses')->click("[data-synonym=\"{$id}\"] [data-syn-save]")->waitForText('تم حفظ المرادف.');
            $this->assertSame('is.other_expenses', \App\Models\StatementSynonym::find($id)->line_key);

            // Searched, then deleted (asked first).
            $browser->type('[data-syn-search]', 'إدارية عامة')->assertPresent("[data-synonym=\"{$id}\"]")->assertMissing('[data-syn-statement="financial_position"] [data-synonym]');
            $browser->click("[data-synonym=\"{$id}\"] [data-syn-delete]")->assertDialogOpened('تحذف المرادف «مصاريف إدارية عامة»؟')->acceptDialog()->waitForText('اتحذف المرادف.');
            $this->assertNull(\App\Models\StatementSynonym::find($id));
            $this->assertArabicPage($browser, 'synonyms after edits');
        });
    }
}
