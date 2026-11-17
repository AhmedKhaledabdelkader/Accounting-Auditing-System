<?php

namespace Tests\Feature;

use App\Support\Amounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** How an amount is written everywhere (R1): «-» for zero, a negative per the one setting. */
class AmountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parentheses_by_default(): void
    {
        $this->assertSame('parentheses', Amounts::negatives());
        $this->assertSame(['1,235', '(1,235)', '-', '-'], [Amounts::whole(1234.5), Amounts::whole(-1234.5), Amounts::whole(0.0), Amounts::whole(-0.4)]);
        $this->assertSame('[$-409]#,##0;(#,##0);"-"', Amounts::xlsxFormat());
        $this->assertSame('[$-409]#,##0.00;(#,##0.00);"-"', Amounts::xlsxFormat(2));
    }

    public function test_one_setting_switches_to_a_minus_sign(): void
    {
        config(['financial_statements.negatives' => 'minus']);

        $this->assertSame(['-1,235', '-', '1,235'], [Amounts::whole(-1234.5), Amounts::whole(0.0), Amounts::whole(1234.5)]);
        $this->assertSame('[$-409]#,##0;-#,##0;"-"', Amounts::xlsxFormat());
    }

    public function test_the_screens_get_the_setting(): void
    {
        config(['financial_statements.negatives' => 'minus']);
        $this->get('/financial-reports')
            ->assertOk()->assertSee('window.AmountsConfig = {"negatives":"minus"}', false);
    }
}
