<?php

namespace Tests\Unit\Statements;

use App\Services\Statements\LineMapper;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** Check 26a: periodic opening/closing stock accounts labelled as inventory are proposed for cost of revenue, never moved. */
class PeriodicInventoryPairsTest extends TestCase
{
    use ClassifiedRows;

    private function map(array $rows, array $groups = ['opening', 'period', 'closing'], array $options = []): array
    {
        return app(LineMapper::class)->map($rows, $this->profile($rows, $groups), $options);
    }

    /** A periodic system: the stock account keeps last year's figure until the closing entry; two P&L-type stock accounts. */
    private function periodic(): array
    {
        return [
            $this->account(10, 'مخزون بضاعة', ['opening' => 500, 'period' => [800, 500]]),          // stock: 500 → 800
            $this->account(11, 'مخزون بضاعة', ['opening' => 0, 'period' => [500, 0]]),             // opening stock, labelled inventory
            $this->account(12, 'مخزون بضاعة', ['opening' => 0, 'period' => [0, 800]]),             // closing stock, labelled inventory
            $this->account(13, 'تكلفة المشتريات', ['period' => [2000, 0]]),
        ];
    }

    public function test_opening_and_closing_stock_are_proposed_never_moved(): void
    {
        $m = $this->map($this->periodic());

        $this->assertCount(2, $m['proposals']);
        [$opening, $closing] = $m['proposals'];
        $this->assertSame(['opening_stock', 11, [10], 500.0, 'sofp.inventory', 'is.cost_of_revenue', 'pending'],
            [$opening['kind'], $opening['row'], $opening['matches'], $opening['amount'], $opening['current_line'], $opening['proposed_line'], $opening['status']]);
        $this->assertSame(['closing_stock', 12, [10], 800.0], [$closing['kind'], $closing['row'], $closing['matches'], $closing['amount']]);

        foreach ([11, 12] as $n) {
            $this->assertSame(['sofp.inventory' => 1.0], $m['rows'][$n]['lines']['closing'], "R{$n} stays on inventory");
            $this->assertSame('pending_confirmation', $m['rows'][$n]['status']);
            $this->assertSame('periodic_inventory_pair', end($m['rows'][$n]['reasons'])['code']);
        }
        $this->assertSame('ok', $m['rows'][10]['status']);
    }

    public function test_closing_layout_finds_the_closing_stock_only(): void
    {
        // No opening columns: nothing to compare an opening-stock balance with.
        $rows = [
            $this->account(10, 'مخزون بضاعة', ['closing' => 800]),
            $this->account(11, 'مخزون بضاعة', ['closing' => 500]),
            $this->account(12, 'مخزون بضاعة', ['closing' => -800]),
        ];
        $m = $this->map($rows, ['closing']);

        $this->assertSame([[12, 'closing_stock', [10]]], array_map(fn ($p) => [$p['row'], $p['kind'], $p['matches']], $m['proposals']));
    }

    public function test_matches_the_total_of_several_stock_accounts_and_movements(): void
    {
        $rows = [
            $this->account(10, 'مخزون مواد خام', ['opening' => 300, 'period' => [100, 0]]),
            $this->account(11, 'مخزون إنتاج تام', ['opening' => 200, 'period' => [0, 50]]),
            $this->account(12, 'مخزون بضاعة', ['opening' => 0, 'period' => [500, 0]]),    // = 300 + 200
            // Closing stock booked with its movement: the credit equals stock 10's closing (400).
            $this->account(13, 'مخزون بضاعة', ['opening' => 0, 'period' => [0, 400]]),
        ];
        $m = $this->map($rows);

        $byRow = array_column($m['proposals'], null, 'row');
        $this->assertSame(['opening_stock', [10, 11], 'balance_total'], [$byRow[12]['kind'], $byRow[12]['matches'], $byRow[12]['basis']]);
        $this->assertSame(['closing_stock', [10], 'balance'], [$byRow[13]['kind'], $byRow[13]['matches'], $byRow[13]['basis']]);
    }

    public function test_no_proposal_for_ordinary_inventory_overrides_or_cost_accounts(): void
    {
        $ordinary = [
            $this->account(10, 'مخزون بضاعة', ['opening' => 500, 'period' => [300, 100]]),
            $this->account(11, 'مخزون مواد خام', ['opening' => 120, 'period' => [40, 10]]),
            $this->account(12, 'مخصص هبوط أسعار المخزون', ['opening' => -20, 'period' => [0, 5]]),
        ];
        $this->assertSame([], $this->map($ordinary)['proposals']);

        // Already classified as periodic stock (cost of sales sub-classes): nothing to propose.
        $classified = $this->periodic();
        $classified[1] = $this->account(11, 'مخزون أول المدة', ['period' => [500, 0]]);
        $classified[2] = $this->account(12, 'مخزون آخر المدة', ['period' => [0, 800]]);
        $m = $this->map($classified);
        $this->assertSame([], $m['proposals']);
        $this->assertSame('is.cost_of_revenue', array_key_first($m['rows'][11]['lines']['closing']));

        // The user has decided: an override is never second-guessed.
        $m = $this->map($this->periodic(), options: ['overrides' => [11 => 'sofp.inventory', 12 => 'is.cost_of_revenue']]);
        $this->assertSame([], $m['proposals']);
        $this->assertSame('ok', $m['rows'][11]['status']);
    }
}
