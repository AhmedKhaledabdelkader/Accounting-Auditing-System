<?php

namespace App\Services\WorkingPapers;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The recalculation papers: end-of-service benefits, depreciation, prepaid expenses, the receivables allowance, and the
 * payroll matched with payments and the bank (config/recalculations.php). The client fills what only the client has; the
 * paper's own formulas show the result in Excel, and `analyse()` recalculates the same in PHP from the filled cells, row by
 * row, and compares with the books: a difference per row, a total against the trial balance, and what is not filled.
 *
 * Layout of every paper: title (A1), the entity (D3), the date of the calculation (D4: the period end), the total per the
 * books / trial balance (D5), headers in row 7, one row per employee, asset, contract, customer. A column is `in` (typed by the
 * client) or `calc` (a formula, given as an Excel template where `{key}` is that column's letter and `{r}` the row).
 */
final class Recalculations
{
    public const FIRST_ROW = 8;
    public const ROWS = 60;

    /** @return array<string, list<array{0: string, 1: string, 2: string, 3?: string}>> key → columns [key, label, type: text|date|num|choice|calc, formula] */
    private static function columns(): array
    {
        $bands = config('recalculations.ecl.bands');

        return [
            'eosb' => [
                ['name', 'الموظف', 'text'], ['start', 'بداية التعاقد', 'date'], ['end', 'نهاية الخدمة (فارغة = تاريخ الاحتساب)', 'date'],
                ['basic', 'الأساسي', 'num'], ['housing', 'بدل السكن', 'num'], ['transport', 'بدل الانتقال', 'num'], ['other', 'بدلات أخرى', 'num'],
                ['reason', 'سبب انتهاء الخدمة (إنهاء / استقالة)', 'choice'],
                ['years', 'سنوات الخدمة', 'calc', '=IF({start}{r}="","",(IF({end}{r}="",$D$4,{end}{r})-{start}{r})/365)'],
                ['entitlement', 'المستحق (إعادة الاحتساب)', 'calc', '=IF({start}{r}="","",ROUND(IF({years}{r}<5,{years}{r}*0.5,2.5+MAX(0,{years}{r}-'.(1826 / 365).'))*({basic}{r}+{housing}{r}+{transport}{r}+{other}{r})*IF(OR({reason}{r}="",{reason}{r}="إنهاء"),1,IF({years}{r}<2,0,IF({years}{r}<5,1/3,IF({years}{r}<10,2/3,1)))),2))'],
                ['book', 'المخصص حسب الدفاتر', 'num'],
                ['diff', 'الفرق', 'calc', '=IF({start}{r}="","",{entitlement}{r}-{book}{r})'],
            ],
            'depreciation' => [
                ['name', 'اسم الأصل', 'text'], ['category', 'البند / الفئة', 'text'], ['cost', 'تكلفة الشراء', 'num'], ['additions', 'الإضافات', 'num'], ['disposals', 'الاستبعادات', 'num'],
                ['bought', 'تاريخ الشراء', 'date'], ['rate', 'نسبة الإهلاك (مثال 10%)', 'num'], ['opening', 'مجمع الإهلاك أول المدة', 'num'], ['disposal_dep', 'مجمع إهلاك الاستبعادات', 'num'],
                ['total', 'إجمالي قيمة الأصل', 'calc', '=IF({name}{r}="","",{cost}{r}+{additions}{r}-{disposals}{r})'],
                ['charge', 'إهلاك العام', 'calc', '=IF({name}{r}="","",MAX(0,MIN({total}{r},{total}{r}*{rate}{r}*($D$4-{bought}{r})/365)-{opening}{r}))'],
                ['closing', 'مجمع الإهلاك آخر المدة', 'calc', '=IF({name}{r}="","",{opening}{r}+{charge}{r}-{disposal_dep}{r})'],
                ['nbv', 'الصافي (إعادة الاحتساب)', 'calc', '=IF({name}{r}="","",{total}{r}-{closing}{r})'],
                ['book', 'الصافي حسب الدفاتر', 'num'],
                ['diff', 'الفرق', 'calc', '=IF({name}{r}="","",{nbv}{r}-{book}{r})'],
            ],
            'prepaid' => [
                ['name', 'البند (عقد إيجار، تأمين...)', 'text'], ['start', 'بداية العقد', 'date'], ['end', 'نهاية العقد', 'date'], ['value', 'قيمة العقد بعد التعديل', 'num'],
                ['opening', 'المدفوع مقدماً من العام السابق', 'num'], ['payments', 'الدفعات خلال العام', 'num'],
                ['days', 'أيام العقد', 'calc', '=IF({name}{r}="","",{end}{r}-{start}{r}+1)'],
                ['remaining', 'الأيام المتبقية بعد تاريخ الاحتساب', 'calc', '=IF({name}{r}="","",MAX(0,{end}{r}-$D$4))'],
                ['closing', 'المدفوع مقدماً آخر المدة (إعادة الاحتساب)', 'calc', '=IF({name}{r}="","",ROUND({value}{r}*{remaining}{r}/{days}{r},2))'],
                ['expense', 'المصروف المحمّل على العام', 'calc', '=IF({name}{r}="","",{opening}{r}+{payments}{r}-{closing}{r})'],
                ['book', 'المدفوع مقدماً حسب الدفاتر', 'num'],
                ['diff', 'الفرق', 'calc', '=IF({name}{r}="","",{closing}{r}-{book}{r})'],
            ],
            'ecl' => [
                ['name', 'العميل', 'text'], ['balance', 'الرصيد', 'num'],
                ...array_map(fn ($k, $b) => [$k, $b[0], 'num'], array_keys($bands), $bands),
                ['specific', 'مخصص محدد للعميل (إن وجد)', 'num'],
                ['allowance', 'المخصص (إعادة الاحتساب)', 'calc', '=IF({name}{r}="","",ROUND({current}{r}*{current}$6+{d90}{r}*{d90}$6+{d180}{r}*{d180}$6+{d365}{r}*{d365}$6+{over}{r}*{over}$6+{specific}{r},2))'],
                ['book', 'المخصص حسب الدفاتر', 'num'],
                ['diff', 'الفرق', 'calc', '=IF({name}{r}="","",{allowance}{r}-{book}{r})'],
            ],
            'payroll' => [
                ['name', 'الموظف', 'text'], ['payroll', 'صافي الراتب حسب كشف الرواتب', 'num'], ['paid', 'المسدد حسب ملف السداد', 'num'], ['bank', 'المخصوم حسب كشف حساب البنك', 'num'],
                ['diff_paid', 'فرق الكشف عن السداد', 'calc', '=IF({name}{r}="","",{payroll}{r}-{paid}{r})'],
                ['diff_bank', 'فرق السداد عن البنك', 'calc', '=IF({name}{r}="","",{paid}{r}-{bank}{r})'],
            ],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> the columns the paper is filled in (not the ones it calculates), with the letter of each */
    public static function inputColumns(string $key): array
    {
        $out = [];
        foreach (self::columns()[$key] as $i => $c) {
            $c[2] !== 'calc' && $out[] = [$c[0], $c[1], $c[2], Coordinate::stringFromColumnIndex($i + 1)];
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return isset(self::columns()[$key]);
    }

    /** The blank paper, made once for the definition as it is now and kept (making one is slow, a client takes five). */
    public function cachedTemplate(string $key): string
    {
        $version = md5(filemtime(__FILE__).'|'.filemtime(config_path('recalculations.php')).'|'.app()->getLocale());
        $file = storage_path("framework/cache/recalc-templates/{$key}-{$version}.xlsx");
        if (! is_file($file)) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $this->template($key));
        }

        return (string) file_get_contents($file);
    }

    /** The blank paper (xlsx bytes) for a recalculation. */
    public function template(string $key): string
    {
        $cols = self::columns()[$key];
        $letters = [];
        foreach ($cols as $i => $c) {
            $letters[$c[0]] = Coordinate::stringFromColumnIndex($i + 1);
        }
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr((string) config("recalculations.papers.{$key}"), 0, 31));
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', (string) config("recalculations.papers.{$key}"));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', __("portal.recalc.purpose.{$key}", [], 'ar'));
        $yellow = fn (string $range) => $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        foreach ([[3, 'اسم المنشأة'], [4, 'تاريخ الاحتساب (نهاية الفترة)'], [5, 'الإجمالي حسب ميزان المراجعة / الدفاتر']] as [$r, $label]) {
            $sheet->setCellValue("A{$r}", $label);
            $yellow("D{$r}");
        }
        $sheet->getStyle('D4')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('D5')->getNumberFormat()->setFormatCode('#,##0.00');
        if ($key === 'ecl') {
            $sheet->setCellValue('A6', 'نسبة المخصص لكل فئة عمرية (عدّلها عند الحاجة)');
            foreach (config('recalculations.ecl.bands') as $k => $b) {
                $sheet->setCellValue($letters[$k].'6', $b[1]);
                $sheet->getStyle($letters[$k].'6')->getNumberFormat()->setFormatCode('0.0%');
                $yellow($letters[$k].'6');
            }
        }
        foreach ($cols as $i => [$k, $label, $type]) {
            $col = $letters[$k];
            $sheet->setCellValue($col.'7', $label);
            $sheet->getColumnDimension($col)->setWidth(18);
            for ($r = self::FIRST_ROW; $r < self::FIRST_ROW + self::ROWS; $r++) {
                $cell = $sheet->getCell($col.$r);
                if ($type === 'calc') {
                    $formula = $cols[$i][3];
                    $cell->setValue(preg_replace_callback('/\{(\w+)\}/', fn ($m) => $m[1] === 'r' ? (string) $r : $letters[$m[1]], $formula));
                    $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
                    continue;
                }
                $yellow($col.$r);
                $cell->getStyle()->getFont()->getColor()->setRGB('0000FF');
                $format = match ($type) {
                    'date' => 'yyyy-mm-dd', 'num' => '#,##0.00', default => null,
                };
                if ($format) {
                    $cell->getStyle()->getNumberFormat()->setFormatCode($format);
                }
            }
            if ($type === 'choice') {
                $v = $sheet->getCell($col.self::FIRST_ROW)->getDataValidation();
                $v->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)->setFormula1('"إنهاء,استقالة"')->setShowDropDown(true);
                $sheet->setDataValidation($col.self::FIRST_ROW.':'.$col.(self::FIRST_ROW + self::ROWS - 1), $v);
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $head = $sheet->getStyle("A7:{$last}7");
        $head->getFont()->setBold(true);
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
        $head->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(7)->setRowHeight(45);
        $sheet->freezePane('B8');
        $total = self::FIRST_ROW + self::ROWS;
        $sheet->setCellValue("A{$total}", 'الإجمالي');
        foreach ($cols as [$k, , $type]) {
            if ($type === 'num' || $type === 'calc') {
                $col = $letters[$k];
                if (! in_array($k, ['rate', 'years', 'days', 'remaining'], true)) {
                    $sheet->setCellValue($col.$total, "=SUM({$col}".self::FIRST_ROW.":{$col}".($total - 1).')');
                    $sheet->getStyle($col.$total)->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
        }
        $sheet->getStyle("A{$total}:{$last}{$total}")->getFont()->setBold(true);

        $writer = new Xlsx($book);
        $path = tempnam(sys_get_temp_dir(), 'rc');
        $writer->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Recalculates a filled paper.
     *
     * @return array{kind: string, key: string, rows_used: int, computed: float, book: float, difference: float, trial_balance: ?float, tb_difference: ?float,
     *               differing: list<array{row: int, name: string, computed: float, book: float, difference: float}>, missing: list<string>, lines: list<string>}
     */
    public function analyse(string $key, string $path): array
    {
        $cols = self::columns()[$key];
        $sheet = IOFactory::load($path)->getSheet(0);
        $at = self::date($sheet->getCell('D4')->getValue());
        $tb = is_numeric($v = $sheet->getCell('D5')->getValue()) ? (float) $v : null;
        $letters = [];
        foreach ($cols as $i => $c) {
            $letters[$c[0]] = Coordinate::stringFromColumnIndex($i + 1);
        }
        $rates = [];
        if ($key === 'ecl') {
            foreach (config('recalculations.ecl.bands') as $k => $b) {
                $rates[$k] = is_numeric($r = $sheet->getCell($letters[$k].'6')->getValue()) ? (float) $r : $b[1];
            }
        }
        $missing = [];
        trim((string) $sheet->getCell('D3')->getValue()) === '' && $missing[] = 'اسم المنشأة';
        $at === null && $missing[] = 'تاريخ الاحتساب';

        $used = 0;
        $computed = 0.0;
        $book = 0.0;
        $differing = [];
        $tolerance = (float) config('recalculations.tolerance', 1.0);
        for ($r = self::FIRST_ROW; $r < self::FIRST_ROW + self::ROWS; $r++) {
            $row = [];
            foreach ($cols as [$k, , $type]) {
                if ($type !== 'calc') {
                    $raw = $sheet->getCell($letters[$k].$r)->getValue();
                    $row[$k] = match ($type) {
                        'date' => self::date($raw), 'num' => is_numeric($raw) ? (float) $raw : 0.0, default => trim((string) $raw),
                    };
                }
            }
            if ($row['name'] === '') {
                continue;
            }
            $used++;
            if ($at === null) {
                continue;
            }
            [$value, $booked, $why] = $this->figures($key, $row, $at, $rates);
            if ($why !== null) {
                $missing[] = "{$row['name']}: {$why}";
                continue;
            }
            $computed += $value;
            $book += $booked;
            if ($key === 'payroll' && abs($row['paid'] - $row['bank']) > $tolerance) {   // the payment against the bank statement
                $differing[] = ['row' => $r - self::FIRST_ROW + 1, 'name' => $row['name'].' ('.__('portal.recalc.paid_vs_bank').')', 'computed' => round($row['paid'], 2), 'book' => round($row['bank'], 2), 'difference' => round($row['paid'] - $row['bank'], 2)];
            }
            if ($key === 'ecl' && abs(array_sum(array_intersect_key($row, $rates)) - $row['balance']) > $tolerance) {
                $missing[] = "{$row['name']}: ".__('portal.recalc.bands_differ');
            }
            if (abs($value - $booked) > $tolerance) {
                $differing[] = ['row' => $r - self::FIRST_ROW + 1, 'name' => $row['name'], 'computed' => round($value, 2), 'book' => round($booked, 2), 'difference' => round($value - $booked, 2)];
            }
        }
        usort($differing, fn ($a, $b) => abs($b['difference']) <=> abs($a['difference']));

        return $this->result($key, $used, round($computed, 2), round($book, 2), $tb, $differing, $missing);
    }

    /**
     * One row's recalculated figure and the books' (the pair compared): for payroll, the payroll against the payments and,
     * in the second figure, the bank.
     *
     * @return array{0: float, 1: float, 2: ?string} [recalculated, per the books, why it can't be]
     */
    public function figures(string $key, array $row, DateTimeInterface $at, array $rates = []): array
    {
        $round = fn (float $v) => round($v, 2);
        switch ($key) {
            case 'eosb':
                if ($row['start'] === null) {
                    return [0.0, 0.0, 'بداية التعاقد ناقصة'];
                }
                $end = $row['end'] ?? $at;
                $years = max(0, ($end->getTimestamp() - $row['start']->getTimestamp()) / 86400) / 365;
                $gross = $row['basic'] + $row['housing'] + $row['transport'] + $row['other'];
                $five = (float) config('recalculations.eosb.five_years');
                $base = $years < 5 ? $years * 0.5 * $gross : 2.5 * $gross + max(0, $years - $five) * $gross;
                $share = 1.0;
                if (str_starts_with($row['reason'], 'استقال')) {
                    foreach (config('recalculations.eosb.resignation') as [$upTo, $s]) {
                        if ($years < $upTo) {
                            $share = $s;
                            break;
                        }
                    }
                }

                return [$round($base * $share), $row['book'], null];
            case 'depreciation':
                if ($row['bought'] === null) {
                    return [0.0, 0.0, 'تاريخ الشراء ناقص'];
                }
                $total = $row['cost'] + $row['additions'] - $row['disposals'];
                $days = max(0, ($at->getTimestamp() - $row['bought']->getTimestamp()) / 86400);
                $rate = $row['rate'] > 1 ? $row['rate'] / 100 : $row['rate'];
                $charge = max(0, min($total, $total * $rate * $days / 365) - $row['opening']);
                $nbv = $total - ($row['opening'] + $charge - $row['disposal_dep']);

                return [$round($nbv), $row['book'], null];
            case 'prepaid':
                if ($row['start'] === null || $row['end'] === null || $row['end'] < $row['start']) {
                    return [0.0, 0.0, 'تواريخ العقد ناقصة أو مقلوبة'];
                }
                $days = ($row['end']->getTimestamp() - $row['start']->getTimestamp()) / 86400 + 1;
                $remaining = max(0, ($row['end']->getTimestamp() - $at->getTimestamp()) / 86400);

                return [$round($row['value'] * $remaining / $days), $row['book'], null];
            case 'ecl':
                $allowance = $row['specific'];
                foreach ($rates as $band => $rate) {
                    $allowance += ($row[$band] ?? 0.0) * $rate;
                }

                return [$round($allowance), $row['book'], null];
            default: // payroll: the payroll against the payment (the difference to the bank is in the lines)
                return [$round($row['payroll']), $round($row['paid']), null];
        }
    }

    private function result(string $key, int $used, float $computed, float $book, ?float $tb, array $differing, array $missing): array
    {
        $title = (string) config("recalculations.papers.{$key}");
        $lines = [__('portal.recalc.rows', ['used' => $used]), __('portal.recalc.totals', ['computed' => number_format($computed, 2), 'book' => number_format($book, 2), 'difference' => number_format($computed - $book, 2)])];
        $tbDifference = $tb === null ? null : round($book - $tb, 2);
        if ($tbDifference !== null && abs($tbDifference) > (float) config('recalculations.tolerance', 1.0)) {
            $lines[] = __('portal.recalc.tb', ['difference' => number_format($tbDifference, 2)]);
        }
        foreach (array_slice($differing, 0, 5) as $d) {
            $lines[] = __('portal.recalc.row', ['name' => $d['name'], 'computed' => number_format($d['computed'], 2), 'book' => number_format($d['book'], 2), 'difference' => number_format($d['difference'], 2)]);
        }
        count($differing) > 5 && $lines[] = __('portal.recalc.more', ['count' => count($differing) - 5]);
        $missing !== [] && $lines[] = __('portal.recalc.missing', ['items' => implode('، ', array_slice($missing, 0, 6))]);

        return ['kind' => 'recalc', 'key' => $key, 'title' => $title, 'rows_used' => $used, 'computed' => $computed, 'book' => $book, 'difference' => round($computed - $book, 2),
            'trial_balance' => $tb, 'tb_difference' => $tbDifference, 'differing' => $differing, 'missing' => $missing, 'lines' => $lines];
    }

    private static function date(mixed $raw): ?DateTimeImmutable
    {
        if (is_numeric($raw) && $raw > 20000) {
            return DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject((float) $raw));
        }
        if (is_string($raw) && ($t = strtotime(trim($raw))) !== false && trim($raw) !== '') {
            return (new DateTimeImmutable())->setTimestamp($t);
        }

        return null;
    }
}
