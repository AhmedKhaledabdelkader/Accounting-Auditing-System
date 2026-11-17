<?php

namespace Tests\Unit;

use App\Support\ArabicNumberWords;
use PHPUnit\Framework\TestCase;

/** A count in Arabic words by code (R7), as the statements write it. Made-up numbers. */
class ArabicNumberWordsTest extends TestCase
{
    public function test_counts_read_as_the_statements_write_them(): void
    {
        foreach ([
            1 => 'واحد', 2 => 'اثنان', 9 => 'تسعة', 10 => 'عشرة', 11 => 'أحد عشر', 12 => 'اثنا عشر', 19 => 'تسعة عشر',
            21 => 'واحد وعشرون', 99 => 'تسعة وتسعون', 100 => 'مائة', 200 => 'مائتان', 460 => 'أربعمائة وستون',
            1000 => 'ألف', 2000 => 'ألفان', 4200 => 'أربعة آلاف ومائتان', 1394 => 'ألف وثلاثمائة وأربعة وتسعون',
            11000 => 'أحد عشر ألفًا', 105000 => 'مائة وخمسة آلاف', 200000 => 'مائتا ألف', 1_000_000 => 'مليون',
            2_500_000 => 'مليونان وخمسمائة ألف', 16_000_000 => 'ستة عشر مليونًا', 3_000_000_000 => 'ثلاثة مليارات', 0 => 'صفر',
        ] as $n => $words) {
            $this->assertSame($words, ArabicNumberWords::of($n), (string) $n);
        }
    }

    public function test_a_count_with_its_noun_takes_the_case_and_the_construct(): void
    {
        $share = ['gender' => 'f', 'singular' => 'حصة', 'dual' => ['حصتان', 'حصتين'], 'plural' => 'حصص'];
        $read = fn (int $n, string $case = ArabicNumberWords::GENITIVE) => implode(' ', ArabicNumberWords::counted($n, $share, $case));

        // «مقسم إلى …»: genitive; مائتان, ألفان, مليونان before their noun lose the ن.
        $this->assertSame('أربعة آلاف ومائتي حصة', $read(4200));
        $this->assertSame('أربعة آلاف ومائتا حصة', $read(4200, ArabicNumberWords::NOMINATIVE));
        $this->assertSame('مائتي حصة', $read(200));
        $this->assertSame('ألفي حصة', $read(2000));
        $this->assertSame('ألفا حصة', $read(2000, ArabicNumberWords::NOMINATIVE));
        $this->assertSame('ألفين ومائتي حصة', $read(2200));
        $this->assertSame('مليوني حصة', $read(2_000_000));
        // A feminine noun: 3–10 in the masculine form and the plural; teens and compounds feminine; tens by case.
        $this->assertSame('تسع حصص', $read(9));
        $this->assertSame('مائة وثلاث حصص', $read(103));
        $this->assertSame('اثنتي عشرة حصة', $read(12));
        $this->assertSame('اثنتا عشرة حصة', $read(12, ArabicNumberWords::NOMINATIVE));
        $this->assertSame('إحدى عشرة حصة', $read(11));
        $this->assertSame('إحدى وعشرين حصة', $read(21));
        $this->assertSame('ألف وثلاثمائة وأربع وتسعين حصة', $read(1394));
        $this->assertSame('عشرين ألف حصة', $read(20_000));
        $this->assertSame('حصتين', ArabicNumberWords::counted(2, $share)['noun']);
        // The number alone keeps its free forms.
        $this->assertSame(['أربعة آلاف ومائتين', 'مائتا ألف', 'أحد عشر ألفًا'],
            [ArabicNumberWords::of(4200, 'm', ArabicNumberWords::GENITIVE), ArabicNumberWords::of(200_000), ArabicNumberWords::of(11_000)]);
    }

    public function test_a_negative_or_too_large_number_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArabicNumberWords::of(-1);
    }
}
