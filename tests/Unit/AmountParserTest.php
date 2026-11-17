<?php

namespace Tests\Unit;

use App\Services\AmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountParserTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'int'                     => [1500, 1500.0],
            'float'                   => [12.5, 12.5],
            'null'                    => [null, 0.0],
            'empty'                   => ['', 0.0],
            'spaces'                  => ['   ', 0.0],
            'plain string'            => ['250', 250.0],
            'thousands comma'         => ['1,250,000.50', 1250000.5],
            'european'                => ['1.250.000,50', 1250000.5],
            'european short'          => ['1.250,5', 1250.5],
            'decimal comma'           => ['12,50', 12.5],
            'thousands comma no dec'  => ['1,234', 1234.0],
            'dot thousands'           => ['1.234.567', 1234567.0],
            'arabic-indic digits'     => ['١٢٣٤', 1234.0],
            'arabic separators'       => ['١٬٢٥٠٫٥٠', 1250.5],
            'persian digits'          => ['۱۲۳', 123.0],
            'parentheses negative'    => ['(500)', -500.0],
            'parentheses with commas' => ['(1,500.00)', -1500.0],
            'leading minus'           => ['-75', -75.0],
            'trailing minus'          => ['75-', -75.0],
            'currency before'         => ['SAR 1,000', 1000.0],
            'currency after arabic'   => ['1,000 ر.س', 1000.0],
            'dollar'                  => ['$99.90', 99.9],
            'Dr suffix'               => ['1,000.00 Dr', 1000.0],
            'Cr suffix negative'      => ['1,000.00 Cr', -1000.0],
            'دائن suffix negative'    => ['500 دائن', -500.0],
            'مدين suffix'             => ['500 مدين', 500.0],
            'nbsp'                    => ["1\u{00A0}000", 1000.0],
            // Zero markers: a cell that is nothing but dashes is zero.
            'dash'                    => ['-', 0.0],
            'dash with spaces'        => [' - ', 0.0],
            'em dash'                 => ['—', 0.0],
            'en dash'                 => ['–', 0.0],
            'minus sign'              => ['−', 0.0],
            'tatweel'                 => ['ـ', 0.0],
            'double dash'             => ['--', 0.0],
            'dash in parentheses'     => ['(-)', 0.0],
            // Real negatives still parse as negative.
            'negative'                => ['-1234', -1234.0],
            'negative with space'     => ['- 1234', -1234.0],
            'negative in parentheses' => ['(1,234)', -1234.0],
            'trailing minus thousands' => ['1,234-', -1234.0],
        ];
    }

    #[DataProvider('amounts')]
    public function test_parses(mixed $input, float $expected): void
    {
        $this->assertSame($expected, AmountParser::parse($input));
    }

    public static function invalid(): array
    {
        return [
            'text'            => ['N/A'],
            'words'           => ['الإجمالي'],
            'mixed'           => ['12abc'],
            'bad separators'  => ['1,2,3'],
            'bad dots'        => ['1.23.4'],
            'bool'            => [true],
            'dash and text'   => ['- لا يوجد'],
            'dashes and text' => ['--abc'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_returns_null_for_non_numbers(mixed $input): void
    {
        $this->assertNull(AmountParser::parse($input));
    }

    public function test_ascii_digits(): void
    {
        $this->assertSame('110101', AmountParser::asciiDigits('١١٠١٠١'));
        $this->assertSame('ABC-12', AmountParser::asciiDigits('ABC-١٢'));
    }
}
