<?php

namespace Tests\Browser;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\BusyRun;
use Tests\Support\ClassifiedRows;

/**
 * The classifier page after a run (the accountant's notes, 2026-10-20): the work stays when the page is left or
 * refreshed, unsaved class edits with it, until «خلاص»; a class is found by searching; rows are added, edited and
 * removed, and the page says when the trial balance no longer balances.
 */
class ClassifierRowsTest extends DuskTestCase
{
    use BusyRun, ClassifiedRows, DatabaseMigrations;

    private function rowsShown(Browser $browser): array
    {
        return $browser->script("return [...document.querySelectorAll('#tbody tr[data-row]')].map((tr) => tr.querySelector('td.name').innerText.trim())")[0];
    }

    public function test_the_work_stays_and_rows_are_added_edited_and_removed(): void
    {
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [50, 0]]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'period' => [0, 50]]),
        ]);

        $this->browse(function (Browser $browser) use ($run) {
            $browser->visit("/classifier?run={$run->id}")->waitFor('#tbody tr[data-row="3"]', 10);

            // A class found by searching, kept on the page only (not saved yet).
            $browser->click('tr[data-row="3"] .sub-edit')->waitFor('.cp input')
                ->type('.cp input', 'رأس المال المدفوع')->keys('.cp input', '{enter}')
                ->waitFor('#edits.on')->assertSeeIn('#nEdits', '1');

            // Left and opened again from the menu: the last run comes back, its unsaved edit with it.
            $browser->visit('/classifier')->waitFor('#tbody tr[data-row="3"]', 10)
                ->assertQueryStringHas('run', $run->id)
                ->waitFor('#edits.on')->assertSeeIn('tr[data-row="3"] .sub-edit', 'رأس المال المدفوع');

            // A new account: the trial balance is off by its 40, and the page says so.
            $browser->click('#addRow')->waitFor('#rowDialog[open]')
                ->type('#rdNumber', '1150')->type('#rdName', 'عهدة جديدة')
                ->type('#rdAmounts input[data-k="closing_debit"]', '40')
                ->click('#rdSave')->waitFor('#balanceWarn.on')
                ->assertSeeIn('#balanceWarn', 'الرصيد الختامي')->assertSeeIn('#balanceWarn', '40.00');
            $this->assertContains('عهدة جديدة', $this->rowsShown($browser));

            // Edited: back to zero, the warning goes.
            $added = (int) collect(ClassificationRun::find($run->id)->result['data'])->firstWhere('account_name', 'عهدة جديدة')['row'];
            $browser->click("button[data-edit-row=\"{$added}\"]")->waitFor('#rowDialog[open]')
                ->clear('#rdName')->type('#rdName', 'عهدة سامي')
                ->clear('#rdAmounts input[data-k="closing_debit"]')->type('#rdAmounts input[data-k="closing_debit"]', '0')
                ->click('#rdSave')->waitUntilMissing('#balanceWarn.on');
            $this->assertContains('عهدة سامي', $this->rowsShown($browser));

            // Removed, after the confirmation.
            $browser->click("button[data-delete-row=\"{$added}\"]")->waitFor('#ask[open]')->click('#askYes')
                ->waitUntil("!document.querySelector('tr[data-row=\"{$added}\"]')");
            $this->assertSame(['حساب 2', 'حساب 3'], $this->rowsShown($browser));
            $this->assertSame(['row' => $added, 'account_number' => '1150', 'account_name' => 'عهدة سامي'],
                ClassificationRun::find($run->id)->result['meta']['removed_rows'][0]);

            // «خلاص»: the unsaved edit would be lost, so it asks; then the page starts empty, and stays so.
            $browser->click('#reset')->waitFor('#ask[open]')->click('#askYes')->waitUntilMissing('#results.on')
                ->assertQueryStringMissing('run');
            $browser->visit('/classifier')->pause(1500)->assertMissing('#results.on');

            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));
        });
    }
}
