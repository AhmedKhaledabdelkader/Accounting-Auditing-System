<?php

namespace App\Services\Statements;

/**
 * This year's opening balances against last year's closing figures read from its statements' file (Excel or PDF), line
 * by line on the balance sheet, before the file is confirmed (brief §10, the user's cross-source check). Every
 * difference is shown, a riyal either way too: labelled «probably rounding», never hidden; figures are compared in whole
 * riyals (halalas aren't printed: a few of them are no difference). A difference is a reading
 * error (the PDF's page is read again: ReadPriorStatementsPdf) or a real one, for the accountant.
 */
final class OpeningCheck
{
    /** A difference this small, either way, is probably rounding. */
    public const ROUNDING = 1.0;

    /**
     * @param ?array<string, Figure> $opening the balance sheet at the start of the year (CurrentYearStatements), null without opening columns
     * @param array $file a file of last year's statements (PriorStatementsFile's, with `page` on a PDF's rows)
     * @param ?array $choices the user's choices on it (rows, columns, expenses_shown_positive) once it is in use; null
     *        while it waits for review: then the lines it places are compared, and a line it doesn't print with an opening
     *        balance this year against last year's «-»
     * @return list<array{line: string, label: string, opening: float, prior: float, difference: float, kind: string, pages: list<int>}>
     *         kind: equal, rounding or difference; lines where both are nothing are left out
     */
    public static function lines(LineCatalogue $catalogue, ?array $opening, array $file, ?array $choices = null): array
    {
        if ($opening === null || ! in_array('financial_position', array_column($file['blocks'] ?? [], 'statement'), true)) {
            return [];
        }
        $prior = PriorYearResolver::fromFile($catalogue, ['file' => $file] + ($choices ?? []))['figures'];
        $pages = self::pages($file, $choices ?? []);
        // While the file waits for review, a balance-sheet row with an amount not placed yet may still go on any line:
        // then a line the file doesn't print isn't compared.
        $waiting = $choices === null && array_filter($file['blocks'], fn ($b) => $b['statement'] === 'financial_position' && array_filter($b['rows'],
            fn ($r) => $r['match'] === null && ($r['kind'] ?? null) !== 'total' && array_filter($r['values'], fn ($v) => abs((float) ($v['amount'] ?? 0)) > 0.5) !== []) !== []) !== [];
        $out = [];
        foreach ($catalogue->statement('financial_position') as $item) {
            $key = $item['key'] ?? null;
            if ($item['type'] !== 'line' || ! isset($opening[$key]) || $opening[$key]->isMissing()) {
                continue;
            }
            $known = isset($prior[$key]) && ! $prior[$key]->isMissing();
            $inFile = $choices === null ? isset($pages[$key]) : $known;
            if ($inFile && ! $known) {
                continue;   // placed, its figure not read
            }
            // A line last year's file doesn't print, with an opening balance this year: against last year's «-».
            if (! $inFile && ($waiting || abs($opening[$key]->amount) < 0.5)) {
                continue;
            }
            [$a, $b] = [round($opening[$key]->amount, 2), $inFile ? round($prior[$key]->amount, 2) : 0.0];
            if (abs($a) < 0.005 && abs($b) < 0.005) {
                continue;
            }
            $d = round($a - $b, 2) + 0.0;
            $out[] = ['line' => $key, 'label' => $item['label_ar'], 'opening' => $a + 0.0, 'prior' => $b + 0.0, 'difference' => $d,
                'kind' => self::kind($a, $b), 'pages' => $pages[$key] ?? []];
        }

        return $out;
    }

    /**
     * Two figures in whole riyals, as the statements show them: `equal` (no riyal apart: halalas aren't printed),
     * `rounding` (a riyal either way), `difference`.
     */
    public static function kind(float $a, float $b): string
    {
        $d = abs(round($a) - round($b));

        return $d < 0.5 ? 'equal' : ($d <= self::ROUNDING ? 'rounding' : 'difference');
    }

    /** The lines the file's rows are placed on, each with the PDF pages of its rows (none for an Excel file). */
    private static function pages(array $file, array $choices): array
    {
        $pages = [];
        foreach ($file['blocks'] ?? [] as $b => $block) {
            foreach ($block['rows'] as $r) {
                $key = PriorYearResolver::rowLine(['rows' => $choices['rows'] ?? []], $b, $r);
                if ($key !== null) {
                    $pages[$key] ??= [];
                    isset($r['page']) && $pages[$key][] = $r['page'];
                }
            }
        }

        return array_map(fn ($p) => array_values(array_unique($p)), $pages);
    }
}
