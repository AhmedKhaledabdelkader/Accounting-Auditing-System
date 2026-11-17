<?php

namespace Tests\Unit;

use App\Services\Statements\OpeningCheck;
use PHPUnit\Framework\TestCase;

/** This year's opening against last year's closing in whole riyals, as the statements print them. Made-up figures. */
class OpeningCheckTest extends TestCase
{
    public function test_no_riyal_apart_is_equal_a_riyal_is_probably_rounding(): void
    {
        // Halalas aren't printed: an opening of 14,946.30 is last year's 14,946.
        $this->assertSame(['equal', 'equal', 'equal'], [OpeningCheck::kind(14946.30, 14946), OpeningCheck::kind(-500.2, -500), OpeningCheck::kind(1, 1)]);
        $this->assertSame(['rounding', 'rounding'], [OpeningCheck::kind(101, 100), OpeningCheck::kind(-99, -100)]);
        $this->assertSame(['difference', 'difference'], [OpeningCheck::kind(102, 100), OpeningCheck::kind(0, 2003)]);
    }
}
