<?php

namespace Tests\Unit;

use App\Services\Statements\ArticlesOfAssociation as A;
use PHPUnit\Framework\TestCase;

class ArticlesOfAssociationTest extends TestCase
{
    public function test_nationalities_are_grouped_by_who_pays_zakat(): void
    {
        foreach (['سعودي', 'سعودية الجنسية', 'المملكة العربية السعودية', 'سعودي الجنسية'] as $n) {
            $this->assertSame('saudi', A::group($n), $n);
        }
        foreach (['كويتي', 'إماراتية', 'قطري الجنسية', 'بحريني', 'عماني', 'خليجي'] as $n) {
            $this->assertSame('gulf', A::group($n), $n);
        }
        foreach (['مصري', 'بريطاني', 'أردني الجنسية', 'هندي'] as $n) {
            $this->assertSame('foreign', A::group($n), $n);
        }
        $this->assertSame('unknown', A::group(null));
        $this->assertSame('unknown', A::group('  '));
    }

    public function test_the_zakat_share_is_the_saudi_and_gulf_owners_share_by_shares(): void
    {
        $in = A::normalise(['partners' => [
            ['name' => 'أ', 'nationality' => 'سعودي', 'shares' => 500, 'share_value' => 10, 'percentage' => null],
            ['name' => 'ب', 'nationality' => 'كويتي', 'shares' => 250, 'share_value' => 10, 'percentage' => null],
            ['name' => 'ج', 'nationality' => 'مصري', 'shares' => 250, 'share_value' => 10, 'percentage' => null],
        ], 'capital' => 10000, 'shares_total' => 1000, 'share_value' => 10]);

        $this->assertSame([50.0, 25.0, 25.0], A::percentages($in['partners']));
        $this->assertSame(75.0, A::zakatShare($in['partners']));
    }

    public function test_an_unclear_nationality_or_share_leaves_the_share_unknown(): void
    {
        $unclear = A::normalise(['partners' => [
            ['name' => 'أ', 'nationality' => 'سعودي', 'shares' => 60, 'share_value' => 1, 'percentage' => null],
            ['name' => 'ب', 'nationality' => null, 'shares' => 40, 'share_value' => 1, 'percentage' => null],
        ]]);
        $this->assertNull(A::zakatShare($unclear['partners']));
        $this->assertNull(A::zakatShare([]));

        // No shares printed: the printed percentage is used.
        $byPercent = A::normalise(['partners' => [
            ['name' => 'أ', 'nationality' => 'سعودي', 'shares' => null, 'share_value' => null, 'percentage' => 70],
            ['name' => 'ب', 'nationality' => 'هندي', 'shares' => null, 'share_value' => null, 'percentage' => 30],
        ]]);
        $this->assertSame(70.0, A::zakatShare($byPercent['partners']));
    }

    public function test_a_partner_without_a_name_is_dropped(): void
    {
        $this->assertCount(1, A::normalise(['partners' => [['name' => ' ', 'nationality' => 'سعودي'], ['name' => 'أ', 'nationality' => 'سعودي']]])['partners']);
    }
}
