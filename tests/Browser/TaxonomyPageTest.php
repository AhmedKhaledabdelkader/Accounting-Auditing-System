<?php

namespace Tests\Browser;

use App\Models\TaxonomySubClass;
use App\Repositories\Eloquents\TaxonomyRepository;
use App\Support\Taxonomy\TaxonomyRules;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ArabicText;
use Tests\Support\BusyRun;
use Tests\Support\ClassifiedRows;

/**
 * The taxonomy page in a browser: tabs, search, adding a sub class with its line, renaming a main class, a refused
 * delete; then a report built by the served app follows the rename and the new sub class's line.
 */
class TaxonomyPageTest extends DuskTestCase
{
    use ArabicText, BusyRun, ClassifiedRows, DatabaseMigrations;

    private function visibleText(Browser $browser): string
    {
        return $browser->script('return document.body.innerText')[0];
    }

    public function test_categories_are_added_renamed_and_kept_when_used_and_reports_follow(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/taxonomy')->waitForText('دليل التصنيفات')
                ->assertSee('النقد ومافي حكمه')->assertDontSee('إيرادات النشاط');
            $text = $this->visibleText($browser);
            $this->assertSame([], $this->codesInText($text));
            $this->assertDoesNotMatchRegularExpression('/\b(sofp|is)\.[a-z_]+|\b(debit|credit|assets|liabilities|equity)\b/', $text);
            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));

            // Tabs and search.
            $browser->click('.tab[data-tab="1"]')->waitForText('إيرادات النشاط')->assertDontSee('النقد ومافي حكمه');
            $browser->click('.tab[data-tab="0"]')->type('#tx-search', 'مكيفات')->pause(100);
            $this->assertSame(['الممتلكات و الالات و المعدات'], $browser->script("return [...document.querySelectorAll('.tx-statement:not([hidden]) .tx-main:not([hidden])')].map((m) => m.dataset.main)")[0]);
            $this->assertSame(['مكيفات'], $browser->script("return [...document.querySelectorAll('.tx-main:not([hidden]) .tx-sub:not([hidden])')].map((r) => r.dataset.sub)")[0]);
            $browser->clear('#tx-search')->type('#tx-search', ' ')->keys('#tx-search', '{backspace}');

            // A sub class with its line.
            $card = '.tx-main[data-main="استثمارات"]';
            $browser->click("{$card} details.tx-form summary")
                ->type("{$card} details.tx-form input[name=name]", 'صكوك محتفظ بها')
                ->select("{$card} details.tx-form select[name=normal_side]", 'debit')
                ->select("{$card} details.tx-form select[name=line_key]", 'sofp.amortised_cost_non_current')
                ->press("{$card} details.tx-form button[type=submit]")
                ->waitForText('تمت إضافة التصنيف الفرعي.')
                ->assertSeeIn('.tx-sub[data-sub="صكوك محتفظ بها"]', 'استثمارات بالتكلفة المطفأة (غير متداولة)');

            // A main class renamed.
            $cash = '.tx-main[data-main="النقد ومافي حكمه"]';
            $browser->click("{$cash} .tx-head details.tx-edit summary")
                ->type("{$cash} .tx-head input[name=name]", 'النقدية وما في حكمها')
                ->press("{$cash} .tx-head button[type=submit]")
                ->waitForText('تم حفظ التعديل.')
                ->assertSee('أسماء سابقة: النقد ومافي حكمه');

            // A sub class a rule names: the delete is confirmed, then refused with where it is used.
            $browser->click('.tx-sub[data-sub="مكيفات"] button.danger')->acceptDialog()
                ->waitForText('لا يمكن حذف «مكيفات» لأنه مستخدم في: جداول حركة الإيضاحات.');
            $this->assertNotNull(TaxonomySubClass::where('name', 'مكيفات')->first());
        });

        // A report the served app builds: the renamed main class keeps its side rule (a credit balance is an overdraft), the
        // new sub class sits on its line.
        app(TaxonomyRepository::class)->flush();
        app(TaxonomyRules::class)->apply(reload: true);
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 150]),
            $this->account(3, 'نقدية بالصندوق', ['closing' => -20]),
            $this->account(4, 'صكوك محتفظ بها', ['closing' => 70]),
            $this->account(5, 'رأس المال', ['closing' => -200]),
        ], ['closing']);
        $this->assertSame('النقدية وما في حكمها', $run->result['data'][1]['main_class']);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');

        $this->browse(function (Browser $browser) use ($id) {
            $browser->visit("/financial-reports/{$id}#review")->waitFor('[data-statement="financial_position"]');
            $lines = $browser->script("return [...document.querySelectorAll('[data-statement=\"financial_position\"] tr[data-line]')].map((tr) => tr.dataset.line)")[0];
            foreach (['sofp.cash', 'sofp.bank_overdraft', 'sofp.amortised_cost_non_current'] as $line) {
                $this->assertContains($line, $lines);
            }
            $browser->assertSeeIn('[data-statement="financial_position"] [data-line="sofp.bank_overdraft"]', '20');
            $browser->assertSeeIn('[data-statement="financial_position"] [data-line="sofp.amortised_cost_non_current"]', '70');
        });
    }
}
