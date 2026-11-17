<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\PrintedLabels;

class PrintedLabelsTest extends TestCase
{
    use PrintedLabels;

    public function test_labels_compare_as_their_words_and_numbers_exactly(): void
    {
        // Typing that varies between readings: the same label.
        $this->assertSame(self::labels(['الإجمـــالي' => 1, 'غيرالمتداولة' => 2, 'الأرباح ( الخسائر ) المبقاة' => 3, 'مستشفى' => 4, 'الزكاة' => 5]),
            self::labels(['الاجمالي' => 1, 'غير المتداولة' => 2, 'الأرباح (الخسائر) المبقاة' => 3, 'مستشفي' => 4, 'الزكاه' => 5]));
        // A number never: 1,000 is not 1,001, and «2024» is not «2023».
        $this->assertNotEquals(self::labels(['المجموع' => 1000.0]), self::labels(['المجموع' => 1001.0]));
        $this->assertNotSame(self::label('الرصيد في 1 يناير 2024'), self::label('الرصيد في 1 يناير 2023'));
        // A label missing is still missing; a letter changed is another label.
        $this->assertNotEquals(self::labels(['مجمل الربح' => 200, 'صافي الربح' => 150]), self::labels(['صافي الربح' => 150]));
        $this->assertNotSame(self::label('صهاريج'), self::label('صباريج'));
    }
}
