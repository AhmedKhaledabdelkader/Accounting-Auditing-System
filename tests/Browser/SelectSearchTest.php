<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Every dropdown can be searched (R2): one panel for every <select> on every page, also one drawn later. */
class SelectSearchTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_dropdown_drawn_later_is_searched_and_picked(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/account')->waitFor('.page-head');
            // A select a page draws after it loads, as the wizard's steps do.
            $browser->script("
                const s = document.createElement('select'); s.id = 'pick';
                for (const [v, t] of [['', 'اختار'], ['cash', 'النقد ومعادلات النقد'], ['loan', 'قروض إسلامية'], ['off', 'مستحق لأطراف ذات علاقة']]) s.add(new Option(t, v));
                s.options[3].disabled = true;
                window.changes = [];
                s.addEventListener('change', () => window.changes.push(s.value));
                document.querySelector('.page-head').append(s);");

            // A click opens the panel, not the browser's list; the search ignores how alef is written.
            $browser->click('#pick')->waitFor('.ss-panel .ss-search')
                ->assertFocused('.ss-panel .ss-search')
                ->keys('.ss-panel .ss-search', 'اسلاميه')
                ->waitUntil("document.querySelectorAll('.ss-option').length === 1");
            $this->assertSame(['قروض إسلامية'], $browser->script("return [...document.querySelectorAll('.ss-option')].map(o => o.textContent)")[0]);

            $browser->keys('.ss-panel .ss-search', '{enter}')->waitUntilMissing('.ss-panel');
            $this->assertSame(['loan', ['loan']], $browser->script('return [document.querySelector("#pick").value, window.changes]')[0]);

            // A letter typed on the field starts the search; a disabled option can't be picked; Escape closes.
            // (A key event of its own: WebDriver types an Arabic letter as the US layout's key.)
            $browser->script("const s = document.querySelector('#pick'); s.focus();
                s.dispatchEvent(new KeyboardEvent('keydown', { key: 'م', bubbles: true, cancelable: true }));");
            $browser->waitFor('.ss-panel');
            $this->assertSame('م', $browser->value('.ss-panel .ss-search'));
            $this->assertSame(1, $browser->script("return document.querySelectorAll('.ss-option.disabled').length")[0]);
            $browser->keys('.ss-panel .ss-search', '{escape}')->waitUntilMissing('.ss-panel')->assertFocused('#pick');
            $this->assertSame('loan', $browser->value('#pick'));
        });
    }
}
