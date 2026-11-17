<?php

namespace Tests\Unit;

use App\Services\WorkingPapers\Recalculations;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

/** The vectors are figures of the firm's own recalculation sheets (service benefits, fixed assets), without their names. */
class RecalculationsTest extends TestCase
{
    private function at(string $d): DateTimeImmutable
    {
        return new DateTimeImmutable($d);
    }

    /** A date as the firm's sheets hold it (an Excel serial). */
    private function serial(int $n): DateTimeImmutable
    {
        return DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject($n));
    }

    private function eosb(array $over = []): array
    {
        return $over + ['name' => 'م', 'start' => null, 'end' => null, 'basic' => 0.0, 'housing' => 0.0, 'transport' => 0.0, 'other' => 0.0, 'reason' => '', 'book' => 0.0];
    }

    public function test_service_benefit_matches_the_firms_sheet(): void
    {
        $r = new Recalculations();
        // 10.2959 years on a wage of 2,850: 2.5 wages + the years past five (1826 days) at a wage each.
        $row = $this->eosb(['start' => $this->serial(41533), 'end' => $this->serial(45291), 'basic' => 2850.0]);
        $this->assertSame(22210.48, $r->figures('eosb', $row, $this->at('2023-12-31'))[0]);
        // Four years: half a wage a year.
        $row = $this->eosb(['start' => $this->at('2020-01-01'), 'end' => $this->at('2023-12-31'), 'basic' => 1500.0, 'housing' => 350.0]);
        $this->assertSame(3700.0, $r->figures('eosb', $row, $this->at('2023-12-31'))[0]);
        // The end of service blank: the date of the calculation; a resignation after four years is paid a third (art. 85).
        $row = $this->eosb(['start' => $this->at('2020-01-01'), 'basic' => 1850.0, 'reason' => 'استقالة']);
        $this->assertSame(1233.33, $r->figures('eosb', $row, $this->at('2023-12-31'))[0]);
        $this->assertSame('بداية التعاقد ناقصة', $r->figures('eosb', $this->eosb(), $this->at('2023-12-31'))[2]);
    }

    public function test_depreciation_matches_the_firms_sheet(): void
    {
        $row = ['name' => 'لابتوب', 'category' => '', 'cost' => 2800.0, 'additions' => 0.0, 'disposals' => 0.0, 'bought' => $this->serial(41942), 'rate' => 0.1,
            'opening' => 2289.0, 'disposal_dep' => 0.0, 'book' => 230.9];
        [$nbv, $book] = (new Recalculations())->figures('depreciation', $row, $this->at('2023-12-31'));
        $this->assertSame([230.9, 230.9], [round($nbv, 1), round($book, 1)]);
        // The charge never takes the net value below nothing, and a rate typed as 10 is 10%.
        $old = ['bought' => $this->at('2000-01-01'), 'opening' => 2700.0, 'rate' => 10.0] + $row;
        $this->assertSame(0.0, (new Recalculations())->figures('depreciation', $old, $this->at('2023-12-31'))[0]);
    }

    public function test_prepaid_is_the_days_left_of_the_contract(): void
    {
        $row = ['name' => 'إيجار', 'start' => $this->at('2023-07-01'), 'end' => $this->at('2024-06-30'), 'value' => 36600.0, 'opening' => 0.0, 'payments' => 36600.0, 'book' => 0.0];
        // 366 days in the contract, 182 left after 31 Dec 2023.
        $this->assertSame(18200.0, (new Recalculations())->figures('prepaid', $row, $this->at('2023-12-31'))[0]);
    }

    public function test_allowance_by_age_and_payroll(): void
    {
        $r = new Recalculations();
        $rates = ['current' => 0.01, 'd90' => 0.05, 'd180' => 0.2, 'd365' => 0.5, 'over' => 1.0];
        $row = ['name' => 'ع', 'balance' => 1000.0, 'current' => 400.0, 'd90' => 200.0, 'd180' => 100.0, 'd365' => 100.0, 'over' => 200.0, 'specific' => 10.0, 'book' => 0.0];
        $this->assertSame(294.0, $r->figures('ecl', $row, $this->at('2023-12-31'), $rates)[0]);   // 4 + 10 + 20 + 50 + 200 + 10
        $this->assertSame([5000.0, 4900.0], array_slice($r->figures('payroll', ['name' => 'م', 'payroll' => 5000.0, 'paid' => 4900.0, 'bank' => 4900.0], $this->at('2023-12-31')), 0, 2));
    }

    public function test_a_filled_paper_is_read_and_compared_with_the_books(): void
    {
        $r = new Recalculations();
        $bytes = $r->template('eosb');
        $path = tempnam(sys_get_temp_dir(), 'rc').'.xlsx';
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $sheet->setCellValue('D3', 'شركة المثال');
        $sheet->setCellValue('D4', Date::PHPToExcel(new DateTimeImmutable('2023-12-31')));
        $sheet->setCellValue('D5', 30000);
        foreach ([[8, 'الأول', Date::excelToDateTimeObject(41533)->format('Y-m-d'), 2850, 22210.48], [9, 'الثاني', '2020-01-01', 1500, 2900]] as [$row, $name, $start, $basic, $booked]) {
            $sheet->setCellValue("A{$row}", $name);
            $sheet->setCellValue("B{$row}", Date::PHPToExcel(new DateTimeImmutable($start)));
            $sheet->setCellValue("D{$row}", $basic);
            $sheet->setCellValue("K{$row}", $booked);
        }
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $a = $r->analyse('eosb', $path);
        $this->assertSame([2, 'recalc'], [$a['rows_used'], $a['kind']]);
        $this->assertSame([25210.48, 25110.48], [round($a['computed'], 2), round($a['book'], 2)]);
        // The second employee: 4 years × half a wage = 3,000 in the sheet against 2,900 in the books.
        $this->assertCount(1, $a['differing']);
        $this->assertSame('الثاني', $a['differing'][0]['name']);
        // The total in the books of the paper (25,110.48) against the trial balance's 30,000: rows are missing.
        $this->assertSame(-4889.52, $a['tb_difference']);
        $this->assertSame([], $a['missing']);
    }
}
