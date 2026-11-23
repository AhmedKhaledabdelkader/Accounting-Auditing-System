<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\Statements\PriorNoteItems;

/**
 * Last year's movement of each provision (end of service, zakat, other provisions, impairment) as its note in last year's
 * statements prints it: the note explaining the provision's line, its movement table (the opening and closing balances
 * by their words), each row placed on the schedule's row by its words (formed / charged, paid / used, reversed, prior
 * years' differences). The comparative of the note's movement table, where the accountant typed nothing: the notes are
 * last year's statements', never the trial balance's (the accountant's rule).
 */
final class Provisions
{
    /** Row words → the schedule's row (`increase` / `decrease` are the schedule's own rows). Checked in this order. */
    private const ROWS = [
        'closing'                => '/(نهاية|آخر|اخر|ختامي|الختامي)/u',
        'opening'                => '/(بداية|بدايه|أول|اول|افتتاحي|الافتتاحي)/u',
        'prior_year_adjustments' => '/(فروق|سنوات\s+سابق|الربط|ربط\s+نهائي)/u',
        'reversal'               => '/(\bرد\b|ردّ|انتفى|انتفي|عكس|إلغاء|الغاء)/u',
        'increase'               => '/(المكون|مكون|المحمل|محمل|المضاف|إضاف|اضاف|المكونة|تكوين)/u',
        'decrease'               => '/(المسدد|مسدد|المدفوع|مدفوع|المستخدم|مستخدم|المستبعد|مشطوب|شطب|سداد|المسدده)/u',
    ];

    /**
     * @param array $file the confirmed file of last year's statements (with its document)
     * @param array $fileRows the accountant's choices on its rows
     * @param array<string, array> $schedules the provision schedules' config (key → kind, line, increase, decrease)
     * @return array<string, float> `<schedule>.<row>` → last year's amount (positive), for the schedules last year's notes print
     */
    public static function comparative(array $file, array $fileRows, array $schedules): array
    {
        $doc = $file['document'] ?? null;
        if ($doc === null) {
            return [];
        }
        $out = [];
        foreach ($schedules as $key => $cfg) {
            if (($cfg['kind'] ?? null) !== 'provision' || ! isset($cfg['line'])) {
                continue;
            }
            $section = PriorNoteItems::section($doc, $file, $fileRows, [$cfg['line']]);
            if ($section === null) {
                continue;
            }
            foreach (Sections::tablesOf($section, $doc['sections'], $doc['tables']) as $t) {
                $rows = self::read($t, $cfg);
                if ($rows !== null) {
                    foreach ($rows as $row => $amount) {
                        $out["{$key}.{$row}"] = $amount;
                    }
                    break;
                }
            }
        }

        return $out;
    }

    /** A movement table's rows on the schedule's rows; null when it is no movement table (no opening and closing rows). */
    private static function read(array $t, array $cfg): ?array
    {
        $at = array_search('current', $t['roles']['roles'] ?? [], true);
        if ($at === false) {
            $at = count($t['columns']) === 1 ? 0 : null;
        }
        if ($at === null) {
            return null;
        }
        $out = [];
        foreach ($t['rows'] as $r) {
            if (($r['kind'] ?? 'line') === 'heading' || ($amount = $r['cells'][$at]['amount'] ?? null) === null) {
                continue;
            }
            foreach (self::ROWS as $row => $pattern) {
                if (preg_match($pattern, (string) $r['label']) === 1) {
                    $row = match ($row) {
                        'increase' => $cfg['increase'] ?? 'charge',
                        'decrease' => $cfg['decrease'] ?? 'paid',
                        default    => $row,
                    };
                    if (! isset($out[$row])) {
                        $out[$row] = abs((float) $amount);
                    }
                    break;
                }
            }
        }
        if (! isset($out['opening'], $out['closing'])) {
            return null;
        }
        // The movement must roll: opening + formed − used − reversed (± prior years' differences) = closing, a riyal or two aside.
        $increase = $out[$cfg['increase'] ?? 'charge'] ?? 0.0;
        $decrease = $out[$cfg['decrease'] ?? 'paid'] ?? 0.0;
        $rolled = $out['opening'] + $increase - $decrease - ($out['reversal'] ?? 0.0);
        if (abs($rolled - $out['closing']) > Tables::TOLERANCE && isset($out['prior_year_adjustments'])) {
            $rolled += $out['prior_year_adjustments'];
        }
        if (abs($rolled - $out['closing']) > Tables::TOLERANCE * 2) {
            return null;   // not read clearly enough to use: left to the accountant
        }
        unset($out['closing']);   // the closing row is last year's approved line already

        return $out;
    }
}
