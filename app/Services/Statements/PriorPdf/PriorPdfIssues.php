<?php

namespace App\Services\Statements\PriorPdf;

use App\Support\Amounts;

/**
 * What the reading of a PDF couldn't settle, for the accountant (P3): each in words of the page's language with the
 * page it points at. Checks that still fail after reading the page again, note tables headed with other years than the
 * statements' (read as the statements' years, R16), years in a note's text to check, note numbers out of sequence, a
 * statement's figures placed by its arithmetic or not placeable, a printed total differing from its columns' sum (the
 * figure used, choosable), wording read two ways or known nowhere (Wording).
 */
final class PriorPdfIssues
{
    /**
     * @param array<string, string> $choices a correction's id → `original` when the accountant kept the word as printed
     * @param array<string, true> $known words the accountant marked as right (KnownWord), normalised: no longer flagged
     * @param array<string, string> $totals a total's id → `printed` or `sum`, the figure the accountant chose (Totals)
     * @return list<array{code: string, severity: string, pages: list<int>, message: string, correction?: array, words?: list<string>, total?: array}>
     */
    public static function of(?array $doc, array $choices = [], array $known = [], array $totals = []): array
    {
        $byId = array_column($doc['totals'] ?? [], null, 'id');
        $groups = array_column($doc['total_groups'] ?? [], null, 'id');
        $equation = fn (array $o) => ($o['cost'] !== null && $o['accumulated'] !== null ? Amounts::whole($o['cost']).' − '.Amounts::whole($o['accumulated']).' = ' : '')
            .($o['nbv'] === null ? '' : Amounts::whole($o['nbv']));
        if ($doc === null) {
            return [];
        }
        $out = [];
        foreach ($doc['checks'] ?? [] as $c) {
            if (! $c['ok']) {
                $out[] = ['code' => "check_{$c['code']}", 'severity' => 'error', 'pages' => $c['pages'],
                    'message' => __("report_messages.pdf_issues.check_{$c['code']}", ['pages' => implode('، ', $c['pages']),
                        'statement' => isset($c['statement']) ? __("statements.statements.{$c['statement']}") : '', 'label' => $c['label'] ?? '', 'note' => $c['note'] ?? '',
                        'printed' => isset($c['printed']) ? Amounts::whole((float) $c['printed']) : '', 'expected' => isset($c['expected']) ? Amounts::whole((float) $c['expected']) : ''])];
            }
        }
        // Words corrected (a misprint, an AI's slip): said with the text as read; the accountant may keep it as printed.
        foreach ($doc['corrections'] ?? [] as $c) {
            if ($c['kind'] === 'split') {
                continue;   // a word rejoined (the font's spacing): nothing to say
            }
            $kept = ($choices[$c['id']] ?? null) === 'original';
            $out[] = ['code' => "corrected_{$c['kind']}", 'severity' => 'warning', 'pages' => $c['pages'],
                'message' => __('report_messages.pdf_issues.corrected_'.$c['kind'].($kept ? '_kept' : ''), ['page' => $c['page'], 'original' => $c['original'],
                    'corrected' => $c['corrected'], 'title' => $c['title'], 'other_page' => $c['pages'][1] ?? $c['page']]),
                'correction' => ['id' => $c['id'], 'original' => $c['original'], 'corrected' => $c['corrected'], 'kept_original' => $kept]];
        }
        foreach ($doc['flags'] ?? [] as $f) {
            if ($f['code'] === 'wording_unknown') {
                // Words marked as right since the file was read are no longer flagged.
                $f['words'] = array_values(array_filter($f['words'] ?? explode('، ', (string) $f['printed']),
                    fn ($w) => ! isset($known[implode(' ', Wording::normalised($w))])));
                if ($f['words'] === []) {
                    continue;
                }
                $f['printed'] = implode('، ', $f['words']);
            }
            $printed = $f['printed'] ?? null;
            $vars = ['page' => $f['page'], 'note' => $f['note'] ?? ($f['in'] ?? ''), 'printed' => is_array($printed) ? implode('/', $printed) : (string) $printed,
                'used' => implode('/', $f['used'] ?? []), 'year' => $f['year'] ?? '', 'number' => is_string($printed) ? $printed : '', 'title' => $f['title'] ?? '',
                'statement' => isset($f['statement']) ? __("statements.statements.{$f['statement']}") : '', 'count' => $f['count'] ?? '',
                'other' => $f['other'] ?? '', 'other_page' => $f['pages'][1] ?? $f['page'],
                'printed_amount' => '', 'sum_amount' => '', 'statement_amount' => ''];
            // A year end's balances, chosen together: the combinations that agree, the one used (choosable).
            $group = isset($f['total_group']) ? $groups[$f['total_group']] ?? null : null;
            if ($group !== null) {
                $used = Totals::option($group, $totals);
                $vars = ['year' => $group['year'], 'statement_amount' => $group['statement'] === null ? '' : Amounts::whole($group['statement']),
                    'options' => implode('؛ ', array_map($equation, $group['options'])), 'used' => $used === null ? '' : $equation($group['options'][$used]),
                    'difference' => $used === null || $group['statement'] === null || $group['options'][$used]['nbv'] === null ? ''
                        : Amounts::whole(abs(abs($group['options'][$used]['nbv']) - abs($group['statement'])))] + $vars;
            }
            // A total differing from its columns' sum: both figures, the statement's, and the one used (choosable).
            $total = isset($f['total']) ? $byId[$f['total']] ?? null : null;
            if ($total !== null) {
                $vars = ['printed_amount' => Amounts::whole($total['printed']), 'sum_amount' => Amounts::whole($total['sum']),
                    'statement_amount' => $total['statement'] === null ? '' : Amounts::whole($total['statement'])] + $vars;
            }
            // A note's tables printed with other years: one flag, its tables counted.
            $wording = $f['code'] === 'year_typo' && count($f['tables'] ?? []) > 1 ? 'year_typo_tables' : $f['code'];
            $vars['count'] = $wording === 'year_typo_tables' ? count($f['tables']) : $vars['count'];
            $out[] = ['code' => $f['code'], 'severity' => 'warning', 'pages' => $f['pages'] ?? [$f['page']], 'message' => __("report_messages.pdf_issues.{$wording}", $vars)]
                + (isset($f['words']) ? ['words' => $f['words']] : [])
                + ($total !== null ? ['total' => ['id' => $total['id'], 'printed' => $total['printed'], 'sum' => $total['sum'],
                    'used' => Totals::choice($total, $totals, $groups), 'chosen' => isset($totals[$total['id']])]] : [])
                + ($group !== null ? ['total_group' => ['id' => $group['id'], 'year' => $group['year'], 'status' => $group['status'],
                    'options' => array_map(fn ($o) => ['cost' => $o['cost'], 'accumulated' => $o['accumulated'], 'nbv' => $o['nbv'], 'ties' => $o['ties'], 'text' => $equation($o)], $group['options']),
                    'used' => Totals::option($group, $totals), 'chosen' => isset($totals[$group['id']])]] : []);
        }

        return $out;
    }
}
