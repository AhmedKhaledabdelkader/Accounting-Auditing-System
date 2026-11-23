<?php

namespace App\Services\Audit;

use App\Models\Company;
use App\Services\Portal\Workspace;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The audit program as a working paper (right to left): the materiality it rests on, then each area (or the one asked for):
 * its code and title, its balance, its procedures with the assertions, the type, the status, the reference, the note and who
 * answered, the area's conclusion and its sign-offs. A summary sheet first: every area's progress and sign-offs.
 */
final class ProceduresExport
{
    private const MONEY = '#,##0.00;(#,##0.00)';

    public static function xlsx(Company $company, int $year, array $p, ?string $only = null): string
    {
        $m = $p['m'];
        $areas = array_values(array_filter($p['areas'], fn ($a) => $only === null || $a['key'] === $only));
        $book = new Spreadsheet();
        $head = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]];
        $grid = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]]];
        $t = fn (string $k, array $r = []) => __("audit_plan.procedures.{$k}", $r);

        // The summary.
        $sum = $book->getActiveSheet();
        $sum->setTitle(mb_substr($t('sheet_summary'), 0, 31));
        $sum->setRightToLeft(true);
        $sum->setCellValue('A1', $t('title').' — '.$company->name_ar);
        $sum->setCellValue('A2', Workspace::periodText($company, $year));
        $sum->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sum->setCellValue('A3', __('audit_plan.materiality.overall').': '.self::money($m['overall_rounded']).'   —   '.__('audit_plan.materiality.performance').': '.self::money($m['performance'])
            .'   —   '.__('audit_plan.materiality.trivial').': '.self::money($m['trivial']));
        $cols = [$t('col.code'), $t('col.area'), $t('col.balance'), $t('col.significant'), $t('col.procedures'), $t('col.done'), $t('col.exception'), $t('col.na'), $t('col.pending'),
            $t('col.status'), $t('col.prepared'), $t('col.reviewed')];
        $sum->fromArray($cols, null, 'A5');
        $sum->getStyle('A5:L5')->applyFromArray($head);
        $r = 6;
        foreach ($areas as $a) {
            $sum->fromArray([$a['code'], $a['title'], $a['balance'], $a['significant'] ? $t('yes') : '', count($a['items']), $a['count']['done'], $a['count']['exception'],
                $a['count']['na'], $a['count']['pending'], $t("status.{$a['status']}"), self::signed($a['prepared']), self::signed($a['reviewed'])], null, "A{$r}");
            $r++;
        }
        $sum->getStyle('A5:L'.max(5, $r - 1))->applyFromArray($grid);
        $sum->getStyle('C6:C'.max(6, $r))->getNumberFormat()->setFormatCode(self::MONEY);
        foreach (['A' => 8, 'B' => 44, 'C' => 16, 'D' => 10, 'E' => 10, 'F' => 9, 'G' => 9, 'H' => 9, 'I' => 9, 'J' => 16, 'K' => 22, 'L' => 22] as $c => $w) {
            $sum->getColumnDimension($c)->setWidth($w);
        }

        // An area a sheet.
        foreach ($areas as $a) {
            $sheet = $book->createSheet();
            $sheet->setTitle(mb_substr($a['code'].' '.preg_replace('#[\\\\/?*\[\]:]#u', ' ', $a['title']), 0, 31));
            $sheet->setRightToLeft(true);
            $sheet->setCellValue('A1', $a['code'].' — '.$a['title']);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->setCellValue('A2', $company->name_ar.' — '.Workspace::periodText($company, $year));
            $r = 3;
            if ($a['balance'] !== null) {
                $sheet->setCellValue("A{$r}", $t('col.balance').': '.self::money($a['balance']).($a['prior'] !== null ? '   —   '.$t('prior_balance').': '.self::money($a['prior']) : '')
                    .($a['significant'] ? '   —   '.$t('significant') : ''));
                $r++;
            }
            if ($a['standards'] !== [] || $a['framework'] !== []) {
                $sheet->setCellValue("A{$r}", trim(($a['standards'] !== [] ? $t('standards').': '.implode('، ', $a['standards']) : '')
                    .($a['framework'] !== [] ? '   —   '.$t('framework').': '.implode('، ', $a['framework']) : ''), ' —'));
                $r++;
            }
            if ($a['na']) {
                $sheet->setCellValue("A{$r}", $t('area_na').($a['na_reason'] ? ': '.$a['na_reason'] : ''));
                $sheet->getStyle("A{$r}")->getFont()->setBold(true);
                $r++;
            }
            $r++;
            $sheet->fromArray([$t('col.code'), $t('col.procedure'), $t('col.assertions'), $t('col.type'), $t('col.status'), $t('col.ref'), $t('col.note'), $t('col.by'), $t('col.at'), $t('evidence')], null, "A{$r}");
            $sheet->getStyle("A{$r}:J{$r}")->applyFromArray($head);
            $first = $r + 1;
            foreach ($a['items'] as $i) {
                $r++;
                $ev = $i['evidence'] === null ? null : trim(($i['evidence']['number'] ? $i['evidence']['number'].'. ' : '').$i['evidence']['title']).' ('.$t("request_status.{$i['evidence']['status']}").')';
                $sheet->fromArray([$a['code'].'-'.$i['code'], $i['text'], implode('، ', array_map(fn ($x) => __("audit_plan.procedures.assertions.{$x}"), $i['assertions'])),
                    $t("types.{$i['type']}"), $t("statuses.{$i['status']}"), $i['ref'], $i['note'], $i['by'], $i['at'], $ev], null, "A{$r}");
                if ($i['status'] === 'exception') {
                    $sheet->getStyle("E{$r}")->getFont()->getColor()->setRGB('C00000');
                }
            }
            $sheet->getStyle("A{$first}:J{$r}")->applyFromArray($grid);
            $sheet->getStyle("A{$first}:J{$r}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            // The requirements the area rests on, with their status.
            if ($a['requirements'] !== []) {
                $r += 2;
                $sheet->setCellValue("A{$r}", $t('requirements'));
                $sheet->getStyle("A{$r}")->getFont()->setBold(true);
                foreach ($a['requirements'] as $q) {
                    $r++;
                    $sheet->setCellValue("A{$r}", $q['number'] ?? '');
                    $sheet->setCellValue("B{$r}", $q['title'].($q['optional'] ? ' ('.$t('optional').')' : ''));
                    $sheet->setCellValue("E{$r}", $t("request_status.{$q['status']}"));
                    $sheet->getStyle("B{$r}")->getAlignment()->setWrapText(true);
                }
            }
            $r += 2;
            $sheet->setCellValue("A{$r}", $t('conclusion'));
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$r}", $a['conclusion'] ?? '');
            $sheet->getStyle("B{$r}")->getAlignment()->setWrapText(true);
            $r += 2;
            $sheet->setCellValue("A{$r}", $t('col.prepared'));
            $sheet->setCellValue("B{$r}", self::signed($a['prepared']));
            $sheet->setCellValue('A'.($r + 1), $t('col.reviewed'));
            $sheet->setCellValue('B'.($r + 1), self::signed($a['reviewed']));
            $sheet->getStyle("A{$r}:A".($r + 1))->getFont()->setBold(true);
            foreach (['A' => 10, 'B' => 70, 'C' => 26, 'D' => 14, 'E' => 12, 'F' => 14, 'G' => 34, 'H' => 16, 'I' => 12, 'J' => 40] as $c => $w) {
                $sheet->getColumnDimension($c)->setWidth($w);
            }
        }
        $book->setActiveSheetIndex($only === null ? 0 : min(1, $book->getSheetCount() - 1));

        $path = tempnam(sys_get_temp_dir(), 'prog');
        (new Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private static function money(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }

    private static function signed(?array $s): string
    {
        return $s === null ? '' : $s['name'].' — '.$s['at'];
    }
}
