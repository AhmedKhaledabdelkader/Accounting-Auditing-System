<?php

namespace Tests\Browser;

use App\Models\ClassificationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\BusyRun;
use Tests\Support\ClassifiedRows;

/**
 * The accountant's notes (2026-10-22): each user drags a table's columns into their own order, kept on the account
 * and applied to every row, also to rows drawn later; several rows' classes confirmed and saved at once.
 */
class TableColumnsTest extends DuskTestCase
{
    use BusyRun, ClassifiedRows, DatabaseMigrations;

    /** The header's columns, as drawn. */
    private function heads(Browser $browser): array
    {
        return $browser->script("return [...document.querySelectorAll('#thead tr:first-child th[data-col]')].map((th) => th.dataset.col)")[0];
    }

    /** A drag of one header onto the start side of another (HTML drag events, as a pointer makes them). */
    private function drag(Browser $browser, string $from, string $onto): void
    {
        $browser->script("
            const src = document.querySelector('th[data-col=\"{$from}\"]'), dst = document.querySelector('th[data-col=\"{$onto}\"]');
            const dt = new DataTransfer(), r = dst.getBoundingClientRect(), at = { clientX: r.right - 3, clientY: r.top + 5 };
            src.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
            dst.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, ...at }));
            dst.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt, ...at }));
            src.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));");
    }

    public function test_columns_are_dragged_into_the_users_order_and_rows_confirmed_at_once(): void
    {
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [50, 0]], ['needs_review' => true]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'period' => [0, 50]], ['needs_review' => true]),
        ]);

        $this->browse(function (Browser $browser) use ($run) {
            $browser->visit("/classifier?run={$run->id}")->waitFor('#tbody tr[data-row="3"]', 10);
            $this->assertSame(['account_number', 'account_name', 'statement'], array_slice($this->heads($browser), 0, 3));

            // The name dragged before the number: the header, every row, and the account keep it.
            $this->drag($browser, 'account_name', 'account_number');
            $browser->waitForTextIn('#colToast', 'تم حفظ ترتيب الأعمدة');
            $this->assertSame(['account_name', 'account_number', 'statement'], array_slice($this->heads($browser), 0, 3));
            $firstCells = fn () => $browser->script("return [...document.querySelectorAll('#tbody tr[data-row=\"2\"] td')].slice(2, 4).map((td) => td.innerText.trim())")[0];
            $this->assertSame(['حساب 2', '1002'], $firstCells());
            $this->assertSame(['account_name', 'account_number'], array_slice(User::first()->preferences['columns']['classifier'], 0, 2));

            // Rows drawn again (a search) and the page opened again: the same order.
            $browser->type('#q', '1002')->pause(300);
            $this->assertSame(['حساب 2', '1002'], $firstCells());
            $browser->visit("/classifier?run={$run->id}")->waitFor('#tbody tr[data-row="3"]', 10);
            $this->assertSame(['account_name', 'account_number', 'statement'], array_slice($this->heads($browser), 0, 3));
            $this->assertSame(['حساب 2', '1002'], $firstCells());

            // Both rows ticked: «all correct» saves their classes at once.
            $browser->check('tr[data-row="2"] .pick')->check('tr[data-row="3"] .pick')->waitFor('#picks.on')
                ->click('#pickOk')->waitFor('#ask[open]')->click('#askYes')->waitFor('#saveMsg:not(:empty)');
            $saved = collect(ClassificationRun::find($run->id)->result['data'])->keyBy('row');
            $this->assertSame(['manual_correction', 'manual_correction'], [$saved[2]['classification_source'], $saved[3]['classification_source']]);
            $this->assertSame(['نقدية لدى البنوك', 'رأس المال'], [$saved[2]['sub_class'], $saved[3]['sub_class']]);
            $browser->assertMissing('#picks.on');

            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));
        });
    }
}
