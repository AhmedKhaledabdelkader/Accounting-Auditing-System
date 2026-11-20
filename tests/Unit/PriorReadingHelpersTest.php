<?php

namespace Tests\Unit;

use App\Services\Statements\PriorPdf\Tables;
use App\Services\Statements\PriorPpe;
use PHPUnit\Framework\TestCase;

/** What is read from last year's statements without the accountant typing it: the column names, the depreciation rates. */
class PriorReadingHelpersTest extends TestCase
{
    public function test_a_column_name_drops_its_year_and_currency(): void
    {
        $this->assertSame('ادوات طبية', Tables::columnName('٢٠٢٤م / ادوات طبية'));
        $this->assertSame('مباني', Tables::columnName('مباني / ريال سعودي'));
        $this->assertSame('العجز في حقوق الملكية', Tables::columnName('العجز في / حقوق الملكية'));
        $this->assertSame('سيارات', Tables::columnName('سيارات'));
    }

    public function test_the_rate_is_implied_by_last_years_movement(): void
    {
        // 10% of a cost of 100,000 that didn't move.
        $this->assertSame(10.0, PriorPpe::impliedRate(['cost_opening' => 100000, 'cost_closing' => 100000, 'depreciation' => 10000]));
        // 25% on a cost that grew from 40,000 to 48,000 (the charge 11,000 between 22.9% and 27.5%: only 25 fits).
        $this->assertSame(25.0, PriorPpe::impliedRate(['cost_opening' => 40000, 'cost_closing' => 48000, 'depreciation' => 11000]));
        // Fully depreciated (no charge) or a cost that moved too much to tell: none.
        $this->assertNull(PriorPpe::impliedRate(['cost_opening' => 50000, 'cost_closing' => 50000, 'depreciation' => 0]));
        $this->assertNull(PriorPpe::impliedRate(['cost_opening' => 10000, 'cost_closing' => 90000, 'depreciation' => 5000]));
    }
}
