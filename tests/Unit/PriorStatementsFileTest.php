<?php

namespace Tests\Unit;

use App\Services\Statements\PriorStatementsFile;
use PHPUnit\Framework\TestCase;

/** How an issued statement's cell reads as an amount (PR 17): read exactly, or `unread`; never guessed. */
class PriorStatementsFileTest extends TestCase
{
    public function test_amounts_as_issued_statements_show_them(): void
    {
        $read = fn ($v) => [PriorStatementsFile::amount($v)['amount'], PriorStatementsFile::amount($v)['status']];

        $this->assertSame([1234567.0, 'ok'], $read(1234567));
        $this->assertSame([1234.5, 'ok'], $read(1234.5));
        $this->assertSame([1234567.0, 'ok'], $read('1,234,567'));
        $this->assertSame([-1234.0, 'ok'], $read('(1,234)'));
        $this->assertSame([-1234.0, 'ok'], $read('-1234'));
        $this->assertSame([1234.0, 'ok'], $read('١٬٢٣٤'));          // Arabic-Indic digits and separator
        $this->assertSame([-12.5, 'ok'], $read('(١٢٫٥)'));
        $this->assertSame([1200.0, 'ok'], $read('۱,۲۰۰'));         // Persian digits
        $this->assertSame([0.0, 'ok'], $read('-'));
        $this->assertSame([0.0, 'ok'], $read('—'));
        $this->assertSame([null, 'empty'], $read(null));
        $this->assertSame([null, 'empty'], $read(''));
    }

    public function test_anything_else_is_unread(): void
    {
        foreach (['غير متاح', '#REF!', '#ERROR', '(1,234', '1,23,4', '12 ريال', '1.2.3'] as $v) {
            $this->assertSame([null, 'unread'], [PriorStatementsFile::amount($v)['amount'], PriorStatementsFile::amount($v)['status']], $v);
        }
    }
}
