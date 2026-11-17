<?php

namespace Tests\Unit\Statements;

use App\Services\Statements\Figure;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

class LayoutProfileTest extends TestCase
{
    use ClassifiedRows;

    private function full(): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [50, 30]]),
            $this->account(3, 'موردون', ['opening' => -40, 'period' => [10, 20]]),
            $this->account(4, 'رأس المال', ['opening' => -60, 'period' => [0, 0]]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [0, 100]]),
            $this->account(6, 'إيجارات', ['period' => [90, 0]]),
        ];
    }

    public function test_layouts_from_the_real_groups(): void
    {
        $rows = $this->full();
        $this->assertSame('OMC', $this->profile($rows, ['opening', 'period', 'closing'])->layout);
        $this->assertSame('OMC', $this->profile($rows, ['opening', 'period', 'adjustment', 'closing'])->layout);
        $this->assertSame('OC', $this->profile($rows, ['opening', 'closing'])->layout);
        $this->assertSame('C', $this->profile($rows, ['closing'])->layout);
        $this->assertSame('C', $this->profile($rows, ['period', 'closing'])->layout);
    }

    public function test_a_copied_group_is_ignored(): void
    {
        // Closing-only file: the classifier copied closing into opening and period. Neither is read.
        $row = $this->account(2, 'نقدية لدى البنوك', ['opening' => 500, 'period' => [500, 0], 'closing' => 500]);
        $profile = $this->profile([$row], ['closing']);

        $this->assertSame(500.0, $profile->closing($row));
        $this->assertNull($profile->opening($row));
        $this->assertNull($profile->movement($row));
        $this->assertNull($profile->net($row, 'adjustment'));
        $this->assertFalse($profile->has('opening'));
    }

    public function test_closing_is_derived_when_the_file_has_no_closing_columns(): void
    {
        $row = $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [50, 30], 'adjustment' => [0, 5], 'closing' => 999]);
        $profile = $this->profile([$row], ['opening', 'period', 'adjustment']);

        $this->assertTrue($profile->closingDerived);
        $this->assertSame(115.0, $profile->closing($row));   // 100 + 50 − 30 − 5; the copied 999 is ignored
        $this->assertSame(['debit' => 0.0, 'credit' => 5.0], $profile->movement($row, 'adjustment'));
    }

    public function test_empty_groups_partial_and_counts(): void
    {
        $rows = [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100]),
            $this->account(3, 'موردون', ['closing' => -100], ['needs_review' => true]),
            $this->account(4, null, ['closing' => 5]),
        ];
        $profile = $this->profile($rows, ['adjustment', 'closing']);

        $this->assertSame(['adjustment'], $profile->emptyGroups);
        $this->assertSame(['أصول', 'التزامات'], $profile->families);
        $this->assertTrue($profile->isPartial());
        $this->assertSame(1, $profile->unclassified);
        $this->assertSame(2, $profile->needsReview);   // the unclassified row needs a review too

        $this->assertFalse($this->profile($this->full())->isPartial());
        $this->assertSame([], $this->profile($this->full())->emptyGroups);
    }

    public function test_post_closing_is_detected(): void
    {
        $this->assertFalse($this->profile($this->full())->postClosing);

        // P&L accounts closed into retained earnings: their closing balances are zero.
        $closed = [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 110]),
            $this->account(4, 'أرباح محتجزة', ['closing' => -110]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [100, 100]]),
            $this->account(6, 'إيجارات', ['period' => [90, 90]]),
        ];
        $this->assertTrue($this->profile($closed)->postClosing);
        $this->assertTrue($this->profile($closed)->toArray()['post_closing']);
    }

    public function test_figure_rules(): void
    {
        $this->assertSame(['amount' => null, 'origin' => 'missing', 'status' => 'missing', 'refs' => []], Figure::missing()->toArray());

        $sum = Figure::sum([new Figure(10.005, 'trial_balance'), new Figure(5, 'user_input', 'pending_confirmation')], [['type' => 'line', 'key' => 'a']]);
        $this->assertSame(15.01, $sum->amount);
        $this->assertSame('derived', $sum->origin);
        $this->assertSame('pending_confirmation', $sum->status);
        $this->assertTrue(Figure::sum([new Figure(1, 'trial_balance'), Figure::missing()])->isMissing());
        $this->assertSame(0.0, Figure::sum([])->amount);

        foreach ([fn () => new Figure(null, 'trial_balance'), fn () => new Figure(0, 'missing'), fn () => new Figure(1, 'guess'), fn () => new Figure(1, 'derived', 'maybe')] as $bad) {
            try {
                $bad();
                $this->fail('accepted an invalid figure');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
