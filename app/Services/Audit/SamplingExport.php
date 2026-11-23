<?php

namespace App\Services\Audit;

use App\Models\Company;
use App\Services\Portal\Workspace;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * One revenue sample as a working paper (right to left): the materiality it rests on (locked), the account and its population
 * reconciled with the revenue, how the sample was drawn (the method, the assurance, the interval or the strata, the random
 * start), the entries with their audited amounts and misstatements and the tests' columns to tick, then the misstatement
 * found, projected on the population, and the conclusion.
 */
final class SamplingExport
{
    private const MONEY = '#,##0.00;(#,##0.00)';

    public static function xlsx(Company $company, int $year, array $m, array $s): string
    {
        $sample = $s['sample'];
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(__('audit_plan.sampling.sheet'), 0, 31));
        $sheet->setRightToLeft(true);
        $put = function (int $row, string $label, mixed $value, bool $isMoney = true) use ($sheet) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("D{$row}", $value);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            if ($isMoney && is_numeric($value)) {
                $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
            }
        };
        $section = function (int $row, string $title) use ($sheet) {
            $sheet->setCellValue("A{$row}", $title);
            $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '1F4E79'], 'size' => 12]]);
        };
        $sheet->setCellValue('A1', __('audit_plan.sampling.title').' — '.$company->name_ar);
        $sheet->setCellValue('A2', Workspace::periodText($company, $year).' — '.($sample['account_name'] ?? __('audit_plan.sampling.whole_list')));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $r = 4;
        $section($r++, __('audit_plan.materiality.title'));
        $choice = $m['chosen']['choice'];
        $put($r++, __('audit_plan.materiality.choice'), $choice === null ? '-' : (in_array($choice, Materiality::AGGREGATES, true)
            ? __("audit_plan.materiality.{$choice}") : __("audit_plan.benchmarks.{$choice}").' × '.$m['chosen']['percent'].'%'), false);
        $put($r++, __('audit_plan.materiality.overall'), $m['overall_rounded']);
        $put($r++, __('audit_plan.materiality.performance').' ('.$m['chosen']['performance_percent'].'%)', $m['performance']);
        $put($r++, __('audit_plan.materiality.trivial').' ('.$m['chosen']['trivial_percent'].'%)', $m['trivial']);
        $r++;
        $section($r++, __('audit_plan.sampling.population_title'));
        $put($r++, __('audit_plan.sampling.account'), $sample['account_name'] ?? __('audit_plan.sampling.whole_list'), false);
        $put($r++, __('audit_plan.sampling.col.count'), $sample['population']['count'] ?? null, false);
        $put($r++, __('audit_plan.sampling.col.population'), $sample['population']['total'] ?? null);
        if ($s['reconciliation'] !== null) {
            $put($r++, __('audit_plan.sampling.revenue'), $s['reconciliation']['revenue']);
            $put($r++, __('audit_plan.sampling.difference'), $s['reconciliation']['difference']);
        }
        $r++;
        $section($r++, __('audit_plan.sampling.draw_title'));
        $put($r++, __('audit_plan.sampling.method'), __('audit_plan.sampling.methods.'.($sample['method'] ?? 'mus')), false);
        $put($r++, __('audit_plan.sampling.assurance'), __("audit_plan.assurance.{$sample['assurance']}").' ('.$sample['factor'].')', false);
        if ($sample['interval']) {
            $put($r++, __('audit_plan.sampling.interval'), $sample['interval']);
        }
        foreach ($sample['strata'] ?? [] as $band) {
            $put($r++, __('audit_plan.sampling.col.stratum').' '.$band['label'], __('audit_plan.sampling.stratum_line', ['count' => $band['count'],
                'value' => number_format($band['total'], 2), 'size' => $band['size']]), false);
        }
        $put($r++, __('audit_plan.sampling.seed'), (string) $sample['seed'], false);
        $put($r++, __('audit_plan.sampling.sample_total'), $sample['total']);
        $put($r++, __('audit_plan.sampling.coverage'), $sample['coverage'] === null ? '-' : $sample['coverage'].'%', false);
        $r++;

        $cols = ['n', 'date', 'number', 'party', 'amount', 'reason', 'audited', 'misstatement', 'invoice', 'delivery', 'collection', 'period', 'vat', 'notes'];
        $widths = [6, 13, 16, 32, 16, 22, 16, 14, 12, 12, 12, 12, 12, 30];
        foreach ($cols as $i => $c) {
            $letter = chr(65 + $i);
            $sheet->setCellValue("{$letter}{$r}", __("audit_plan.sampling.col.{$c}"));
            $sheet->getColumnDimension($letter)->setWidth($widths[$i]);
        }
        $last = chr(65 + count($cols) - 1);
        $head = $r;
        $sheet->getStyle("A{$r}:{$last}{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']], 'alignment' => ['wrapText' => true]]);
        $r++;
        foreach ($sample['items'] as $n => $it) {
            $reason = __("audit_plan.reasons.{$it['reason']}").(isset($it['stratum']) ? ' '.$it['stratum'] : '');
            $values = [$n + 1, $it['date'] ?? '', $it['number'] ?? '', $it['party'] ?? '', (float) ($it['amount'] ?? 0), $reason, $it['audited'], null];
            foreach ($values as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $sheet->setCellValueExplicit(chr(65 + $i).$r, $v, is_float($v) || is_int($v) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            // The misstatement: the book amount less the audited one, once the audited amount is given (a formula, so the paper
            // still works when the auditor fills it in Excel).
            $sheet->setCellValue("H{$r}", "=IF(G{$r}=\"\",\"\",E{$r}-G{$r})");
            if (($it['note'] ?? null) !== null) {
                $sheet->setCellValue("N{$r}", $it['note']);
            }
            if ($it['reason'] === 'key') {
                $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
            }
            $r++;
        }
        $sheet->setCellValue("D{$r}", __('audit_plan.sampling.total'));
        $sheet->setCellValue("E{$r}", '=SUM(E'.($head + 1).':E'.($r - 1).')');
        $sheet->setCellValue("H{$r}", '=SUM(H'.($head + 1).':H'.($r - 1).')');
        $sheet->getStyle("D{$r}:H{$r}")->getFont()->setBold(true);
        $sheet->getStyle('E'.($head + 1).":H{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("A{$head}:{$last}{$r}")->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP]]);
        $r += 2;
        $section($r++, __('audit_plan.sampling.results_title'));
        $put($r++, __('audit_plan.sampling.col.found'), $sample['found']);
        $put($r++, __('audit_plan.sampling.col.projected'), $sample['projected']);
        $put($r++, __('audit_plan.sampling.col.conclusion'), __("audit_plan.sampling.conclusions.{$sample['conclusion']}"), false);
        $sheet->setCellValue("A{$r}", __("audit_plan.sampling.conclusion_text.{$sample['conclusion']}", ['pm' => number_format((float) $m['performance'], 2),
            'trivial' => number_format((float) $m['trivial'], 2), 'projected' => number_format($sample['projected'], 2)]));
        $r += 2;
        $sheet->setCellValue("A{$r}", __('audit_plan.sampling.conclusion'));
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->freezePane('A'.($head + 1));

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }
}
