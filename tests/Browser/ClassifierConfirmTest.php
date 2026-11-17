<?php

namespace Tests\Browser;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\BusyRun;
use Tests\Support\ClassifiedRows;

/**
 * Rows corrected before a class saved by the user re-checked their flags still carry the old doubts. Their class is
 * confirmed as it is from the page (the ✓ button, or the class chosen again, even after another one in between): saved
 * as a correction, the server re-checks the row against that class, the counter follows the server. A balance on the
 * class's wrong side stays flagged. Made-up accounts.
 */
class ClassifierConfirmTest extends DuskTestCase
{
    use BusyRun, ClassifiedRows, DatabaseMigrations;

    private function flags(ClassificationRun $run, int $row): array
    {
        $r = collect(ClassificationRun::find($run->id)->result['data'])->firstWhere('row', $row);

        return [$r['sub_class'], $r['classification_source'], $r['needs_review'], array_column($r['data_flags'], 'type')];
    }

    public function test_a_corrected_rows_class_is_confirmed_as_it_is_and_its_review_follows_the_server(): void
    {
        $stale = fn (string $type) => ['classification_source' => 'manual_correction', 'classification_confidence' => 100, 'needs_review' => true,
            'data_flags' => [['type' => $type, 'difference' => null, 'message' => 'رقم الحساب من نوع تاني']], 'note' => 'رقم الحساب من نوع تاني'];
        $run = $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 20, 'closing' => 20]),
            // Corrected before: a debtor in debit, its old doubt (the account number's family) left on it.
            $this->account(3, 'مدينون متنوعون', ['opening' => 50, 'closing' => 50], ['account_name' => 'شركة الضياء'] + $stale('prefix_mismatch')),
            // Corrected before: a debtor in credit, an old rule's doubt on it; its balance is on the class's wrong side.
            $this->account(4, 'مدينون متنوعون', ['opening' => -70, 'closing' => -70], ['account_name' => 'شركة الهدى'] + $stale('rule_review')),
        ]);

        $this->browse(function (Browser $browser) use ($run) {
            $browser->visit("/classifier?run={$run->id}")->waitFor('#tbody tr[data-row="4"]', 10)->assertSeeIn('#cReview', '2');

            // ✓ on a corrected row that still needs review: its class kept, one edit waiting.
            $browser->waitFor('tr[data-row="3"] [data-ok]')->click('tr[data-row="3"] [data-ok]')
                ->waitFor('#edits.on')->assertSeeIn('#nEdits', '1');

            // Another class, then the same one again: still a confirmation waiting, not cancelled.
            $browser->click('tr[data-row="4"] .sub-edit')->waitFor('.cp input')->type('.cp input', 'نقدية لدى البنوك')->keys('.cp input', '{enter}')
                ->waitUntilMissing('.cp input')->assertSeeIn('tr[data-row="4"] .sub-edit', 'نقدية لدى البنوك');
            $browser->click('tr[data-row="4"] .sub-edit')->waitFor('.cp input')->type('.cp input', 'مدينون متنوعون')->keys('.cp input', '{enter}')
                ->waitUntilMissing('.cp input')->assertSeeIn('tr[data-row="4"] .sub-edit', 'مدينون متنوعون')->assertSeeIn('#nEdits', '2');

            // Saved: the server's result.
            $browser->click('#saveEdits')->waitFor('#ask[open]')->click('#askYes')->waitUntilMissing('#edits.on')->waitForTextIn('#saveMsg', '2');
            $this->assertSame(['مدينون متنوعون', 'manual_correction', false, []], $this->flags($run, 3));
            // The wrong-side balance is still flagged under the class confirmed; the old rule's doubt is gone.
            $this->assertSame(['مدينون متنوعون', 'manual_correction', true, ['abnormal_opening', 'abnormal_closing']], $this->flags($run, 4));
            $browser->assertSeeIn('#cReview', '1');
            // The notes follow the server: the resolved doubt's words gone; the wrong-side balance still said.
            $note = fn (int $row) => $browser->script("return document.querySelector('tr[data-row=\"{$row}\"]').innerText")[0];
            $this->assertStringNotContainsString('رقم الحساب من نوع تاني', $note(3).$note(4));
            $this->assertStringContainsString('رصيد ختامي دائن 70.00 على حساب طبيعته مدينة', $note(4));

            // Reopened: the same.
            $browser->refresh()->waitFor('#tbody tr[data-row="4"]', 10)->assertSeeIn('#cReview', '1')
                ->assertMissing('tr[data-row="3"] [data-ok]');
            $this->assertStringNotContainsString('رقم الحساب من نوع تاني', $note(3).$note(4));
            $this->assertStringContainsString('رصيد ختامي دائن 70.00 على حساب طبيعته مدينة', $note(4));
            $this->assertSame([], array_values(array_filter($browser->driver->manage()->getLog('browser'), fn ($l) => $l['level'] === 'SEVERE')));
        });
    }
}
