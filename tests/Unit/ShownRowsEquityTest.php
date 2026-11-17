<?php

namespace Tests\Unit;

use App\Services\Exports\ShownRows;
use PHPUnit\Framework\TestCase;

class ShownRowsEquityTest extends TestCase
{
    private function row(string $key, array $amounts): array
    {
        return ['key' => $key, 'cells' => array_map(fn ($a) => ['amount' => $a], $amounts)];
    }

    public function test_a_movement_row_is_hidden_in_the_year_where_it_is_empty(): void
    {
        $columns = [['key' => 'capital'], ['key' => 'reserve'], ['key' => 'retained'], ['key' => 'total']];
        $cur = [$this->row('opening', ['capital' => 100, 'reserve' => 0, 'retained' => -50, 'total' => 50]),
            $this->row('other_movements', ['capital' => 0, 'reserve' => 0, 'retained' => -10, 'total' => -10]),
            $this->row('dividends', ['capital' => 0, 'reserve' => 0, 'retained' => 0, 'total' => 0]),
            $this->row('closing', ['capital' => 100, 'reserve' => 0, 'retained' => -60, 'total' => 40])];
        $cmp = [$this->row('opening', ['capital' => 100, 'reserve' => 0, 'retained' => -40, 'total' => 60]),
            $this->row('other_movements', ['capital' => 0, 'reserve' => 0, 'retained' => 0, 'total' => 0]),
            $this->row('dividends', ['capital' => 0, 'reserve' => 0, 'retained' => 0, 'total' => 0]),
            $this->row('closing', ['capital' => 100, 'reserve' => 0, 'retained' => -50, 'total' => 50])];

        $show = ShownRows::equity($columns, ['current' => $cur, 'comparative' => $cmp], ['opening', 'closing']);

        $this->assertSame(['opening', 'other_movements', 'closing'], $show['rows']['current']);
        $this->assertSame(['opening', 'closing'], $show['rows']['comparative']);   // its empty «other movements» row is gone
        $this->assertSame(['capital', 'retained', 'total'], $show['columns']);      // the empty reserve column is gone
    }
}
