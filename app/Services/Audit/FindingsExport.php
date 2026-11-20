<?php

namespace App\Services\Audit;

use App\Models\Company;
use App\Services\Portal\Workspace;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** The findings of a client's year as a workbook (right to left), to file with the working papers or send on. */
final class FindingsExport
{
    /** @return string the workbook's bytes */
    public static function xlsx(Company $company, array $built): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(__('audit.sheet'), 0, 31));
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', __('audit.title').' — '.$company->name_ar);
        $sheet->setCellValue('A2', Workspace::periodText($company, $built['year']));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $cols = ['n', 'source', 'finding', 'account', 'detail', 'document', 'book', 'difference', 'entry', 'recommendation', 'status', 'comment'];
        $widths = [5, 14, 42, 26, 60, 18, 18, 16, 40, 50, 18, 34];
        foreach ($cols as $i => $c) {
            $letter = chr(65 + $i);
            $sheet->setCellValue("{$letter}4", __("audit.col.{$c}"));
            $sheet->getColumnDimension($letter)->setWidth($widths[$i]);
        }
        $last = chr(65 + count($cols) - 1);
        $sheet->getStyle("A4:{$last}4")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']]]);

        $r = 5;
        foreach ($built['findings'] as $n => $f) {
            $entry = $f['entry'] ? __('audit.entry', ['debit' => $f['entry']['debit'], 'credit' => $f['entry']['credit'], 'amount' => number_format($f['entry']['amount'], 2)]) : '';
            $values = [$n + 1, __("audit.source.{$f['source']['type']}").': '.$f['source']['name'], $f['title'], (string) $f['account'], $f['detail'],
                $f['amounts']['document'], $f['amounts']['book'], $f['amounts']['difference'], $entry, $f['recommendation'], __("audit.status.{$f['status']}"), (string) $f['comment']];
            foreach ($values as $i => $v) {
                $sheet->setCellValue(chr(65 + $i).$r, $v);
            }
            $f['severity'] === 'difference' && $sheet->getStyle("H{$r}")->getFont()->getColor()->setRGB('C00000');
            $r++;
        }
        if ($r > 5) {
            $sheet->getStyle('F5:H'.($r - 1))->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
            $sheet->getStyle("A4:{$last}".($r - 1))->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);
        }
        $sheet->freezePane('A5');

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }
}
