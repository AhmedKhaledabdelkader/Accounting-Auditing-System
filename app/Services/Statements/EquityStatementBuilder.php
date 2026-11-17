<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * The statement of changes in equity (plan §3.4, Q23, Q24): per column (capital, unpaid capital, statutory reserve, other
 * reserves, retained earnings, and the partners' current accounts when presented in equity), the opening balance, the
 * year's comprehensive income, the movements, and the closing balance it adds up to, which check 9 (11 for retained
 * earnings) compares with the balance sheet. Nothing is forced to agree.
 *
 * - Opening: the opening columns; else the comparative balance sheet; in a first year, nothing (0).
 * - Net profit and OCI: the income statement (OCI in other reserves).
 * - Movements, suggestions the user confirms: with movement columns, capital credits − debits are a capital increase,
 *   reserve credits − debits a transfer to the reserve, and the retained earnings' debits (less that transfer) dividends;
 *   when the partners' current accounts are in equity and their credits cover those debits, a transfer to them instead,
 *   and the rest of their movement is their net movement. Without movement columns, from the net change of each column
 *   (dividends: what is left of the change in retained earnings); without an opening, missing until the prior year
 *   is given.
 * Amounts as displayed: an increase of a column +, a decrease −.
 *
 * What retained earnings lose beyond the year's result and the transfers (the `dividends` suggestion) is not taken for
 * dividends by itself (P3): it is a dividend, a prior-year adjustment or another entry as the user says
 * (`soce.retained_movement`). Its other side can say it: dividends payable or the partners' current accounts credited
 * with as much (a dividend declared; still a suggestion to confirm). Otherwise it is an unexplained movement, flagged.
 */
final class EquityStatementBuilder
{
    public const MOVEMENTS = ['capital_increase', 'transfer_to_reserve', 'dividends', 'prior_year_adjustments', 'other_retained_movements', 'transfer_to_partners_current', 'partners_current_movements'];
    /** What the retained earnings' unexplained movement is, as the user says (input `soce.retained_movement`) → its row. */
    public const RETAINED_NATURES = ['dividends' => 'dividends', 'prior_year_adjustment' => 'prior_year_adjustments', 'other' => 'other_retained_movements'];
    /** Rows a user types for the comparative year; the totals and the closing are computed. */
    public const ENTERED = ['opening', 'net_profit', 'oci', ...self::MOVEMENTS];

    /**
     * The comparative year typed from last year's issued statements: {row: {column: amount}} as displayed; a cell not
     * given is 0 (origin `prior_year_manual`). Same columns as this year; closing = opening + the year.
     *
     * @param list<array> $columns this year's columns (build()['columns'])
     */
    public function entered(LineCatalogue $catalogue, array $columns, array $entered): array
    {
        $cells = [];
        $keys  = array_values(array_filter(array_column($columns, 'key'), fn ($k) => $k !== 'total'));
        foreach (self::ENTERED as $row) {
            foreach ($keys as $col) {
                $cells[$row][$col] = isset($entered[$row][$col])
                    ? new Figure((float) $entered[$row][$col], 'prior_year_manual', 'ok', [['type' => 'input', 'key' => "prior.changes_in_equity.{$row}.{$col}"]])
                    : new Figure(0.0, 'prior_year_manual');
            }
        }
        foreach ($keys as $col) {
            $cells['total_comprehensive_income'][$col] = Figure::sum([$cells['net_profit'][$col], $cells['oci'][$col]]);
            $cells['closing'][$col] = Figure::sum([$cells['opening'][$col], $cells['total_comprehensive_income'][$col], ...array_map(fn ($r) => $cells[$r][$col], self::MOVEMENTS)]);
        }
        foreach ($cells as $row => $byCol) {
            $cells[$row]['total'] = Figure::sum(array_map(fn ($col) => $col === 'unpaid_capital' ? AssetNotesBuilder::neg($byCol[$col]) : $byCol[$col], $keys));
        }

        return array_map(fn ($r) => ['key' => $r, 'label_ar' => $catalogue->changesInEquity()['rows'][$r], 'cells' => $cells[$r]], array_keys($catalogue->changesInEquity()['rows']));
    }

    /**
     * The changes in equity in whole riyals (Q27), tying to the balance sheet as it shows them: each column closes at its
     * line's whole riyals and opens at last year's (the balance sheet's comparative, else rounded); the movements rounded,
     * the year's profit as the income statement shows it; a column's riyal left over on its largest other movement (else
     * the profit). The total column adds up the others. A cell with no figure has none.
     *
     * @param list<array> $rows build()['rows'], or last year's
     * @param list<array> $columns build()['columns']
     * @param array<string, ?int> $closing line → the balance sheet's whole riyals at the end of the year
     * @param array<string, ?int> $opening line → at its start ([]: rounded)
     * @param ?array{amount: float, shown: int} $profit the income statement's net profit
     * @return list<array> the rows with `shown`: column → whole riyals
     */
    public static function shown(array $rows, array $columns, array $closing, array $opening, ?array $profit): array
    {
        $byKey = array_column($rows, null, 'key');
        $movements = array_values(array_filter(['net_profit', 'oci', ...self::MOVEMENTS], fn ($k) => isset($byKey[$k])));
        $round = fn (?Figure $f) => $f === null || $f->isMissing() ? null : (int) round($f->amount);
        $shown = [];
        foreach ($columns as $c) {
            $col = $c['key'];
            if ($col === 'total') {
                continue;
            }
            $cell = fn (string $row) => $byKey[$row]['cells'][$col] ?? null;
            $line = $c['line_key'] ?? null;
            $shown['opening'][$col] = $line !== null && array_key_exists($line, $opening) ? $opening[$line] : $round($cell('opening'));
            foreach ($movements as $row) {
                $f = $cell($row);
                $shown[$row][$col] = $row === 'net_profit' && $profit !== null && $f !== null && ! $f->isMissing() && abs($f->amount - $profit['amount']) < 0.005
                    ? $profit['shown'] : $round($f);
            }
            $shown['closing'][$col] = $line !== null && array_key_exists($line, $closing) ? $closing[$line] : $round($cell('closing'));
            $parts = [$shown['opening'][$col], ...array_map(fn ($r) => $shown[$r][$col], $movements)];
            if ($shown['closing'][$col] === null || in_array(null, $parts, true)) {
                continue;
            }
            $diff = $shown['closing'][$col] - array_sum($parts);
            if ($diff !== 0) {
                $others = array_filter(array_diff($movements, ['net_profit']), fn ($r) => abs($cell($r)?->amount ?? 0.0) >= 0.005);
                usort($others, fn ($a, $b) => abs($cell($b)->amount) <=> abs($cell($a)->amount));
                $on = $others[0] ?? (abs($cell('net_profit')?->amount ?? 0.0) >= 0.005 ? 'net_profit' : null);
                if ($on !== null) {
                    $shown[$on][$col] += $diff;
                }
            }
        }
        foreach (isset($byKey['total_comprehensive_income']) ? $shown['net_profit'] ?? [] : [] as $col => $v) {
            $oci = $shown['oci'][$col] ?? 0;
            $shown['total_comprehensive_income'][$col] = $v === null || $oci === null ? null : $v + $oci;
        }
        foreach ($rows as $i => $r) {
            $cells = $shown[$r['key']] ?? null;
            if ($cells === null) {
                continue;
            }
            $values = array_map(fn ($col, $v) => $col === 'unpaid_capital' && $v !== null ? -$v : $v, array_keys($cells), $cells);
            $cells['total'] = in_array(null, $values, true) ? null : array_sum($values);
            $rows[$i]['shown'] = $cells;
        }

        return $rows;
    }

    /**
     * @param array<string, Figure>  $sofp    the current balance sheet's figures
     * @param array<string, Figure>  $is      the income statement's figures
     * @param ?array<string, Figure> $opening the balance sheet at the start of the year, null when unknown
     * @param ?string                $retainedNature the user's answer on the retained earnings' movement (RETAINED_NATURES), null: none yet
     * @return array{columns: list<array>, rows: list<array>, movements: array<string, Figure>, retained: array{amount: Figure, nature: ?string, rows: list<int>}}
     */
    public function build(ScheduleContext $ctx, MovementSuggester $s, array $sofp, array $is, ?array $opening, bool $partnersInEquity, ?string $retainedNature = null): array
    {
        $cfg     = $ctx->catalogue->changesInEquity();
        $columns = array_filter($cfg['columns'], fn ($c, $k) => $k !== 'partners_current' || $partnersInEquity, ARRAY_FILTER_USE_BOTH);
        $zero    = fn () => new Figure(0.0, 'derived');
        $line    = fn (string $col) => $columns[$col]['line'] ?? null;

        $cells = [];
        foreach (array_keys($columns) as $col) {
            $l = $line($col);
            $cells['opening'][$col]    = $l === null ? $zero() : ($opening === null ? Figure::missing() : ($opening[$l] ?? $zero()));
            $cells['net_profit'][$col] = $col === 'retained_earnings' ? $is['is.net_profit'] : $zero();
            $cells['oci'][$col]        = $col === 'other_reserves' ? $is['is.oci_total'] : $zero();
            $cells['total_comprehensive_income'][$col] = Figure::sum([$cells['net_profit'][$col], $cells['oci'][$col]]);
        }

        $m = $this->movements($ctx, $s, $sofp, $is, $opening, $partnersInEquity);
        // The retained earnings' movement on the row the user names; none named: an unexplained movement, not a dividend.
        $retained = $m['dividends'];
        $nature   = isset(self::RETAINED_NATURES[$retainedNature ?? '']) ? $retainedNature
            : ($this->declared($ctx, $sofp, $opening, $retained) ? 'dividends' : null);
        foreach (self::RETAINED_NATURES as $n => $row) {
            $m[$row] = $n === ($nature ?? 'other') ? $retained : $zero();
        }
        $place = [
            'capital_increase'             => ['share_capital' => 1],
            'transfer_to_reserve'          => ['statutory_reserve' => 1, 'retained_earnings' => -1],
            'dividends'                    => ['retained_earnings' => -1],
            'prior_year_adjustments'       => ['retained_earnings' => -1],
            'other_retained_movements'     => ['retained_earnings' => -1],
            'transfer_to_partners_current' => ['retained_earnings' => -1, 'partners_current' => 1],
            'partners_current_movements'   => ['partners_current' => 1],
        ];
        foreach ($place as $row => $signs) {
            foreach (array_keys($columns) as $col) {
                $sign = $signs[$col] ?? 0;
                $cells[$row][$col] = $sign === 0 ? $zero() : ($sign > 0 ? $m[$row] : AssetNotesBuilder::neg($m[$row]));
            }
        }
        foreach (array_keys($columns) as $col) {
            $cells['closing'][$col] = Figure::sum([$cells['opening'][$col], $cells['total_comprehensive_income'][$col],
                ...array_map(fn ($r) => $cells[$r][$col], array_keys($place))]);
        }
        // Total: unpaid capital is entered positive and subtracted.
        foreach ($cells as $row => $byCol) {
            $cells[$row]['total'] = Figure::sum(array_map(fn ($col) => $col === 'unpaid_capital' ? AssetNotesBuilder::neg($byCol[$col]) : $byCol[$col], array_keys($columns)));
        }

        $unexplained = $nature === null && ! $retained->isMissing() && abs($retained->amount) >= 0.005;

        return [
            'columns' => [...array_map(fn ($k, $c) => ['key' => $k, 'label_ar' => $c['label_ar'], 'line_key' => $c['line']], array_keys($columns), $columns),
                ['key' => 'total', 'label_ar' => 'إجمالي حقوق الملكية', 'line_key' => 'sofp.total_equity']],
            'rows' => array_map(fn ($r) => $r === 'other_retained_movements' && $unexplained
                ? ['key' => $r, 'label_ar' => __('statements.unexplained_retained_movement', [], 'ar'), 'label_set' => true, 'cells' => $cells[$r]]
                : ['key' => $r, 'label_ar' => $cfg['rows'][$r], 'cells' => $cells[$r]], array_keys($cfg['rows'])),
            'movements' => $m,
            'retained'  => ['amount' => $retained, 'nature' => $nature, 'rows' => array_keys($ctx->rowsOn('sofp.retained_earnings'))],
        ];
    }

    /**
     * Whether the retained earnings' movement has a dividend's other side: dividends payable, or the partners' current
     * accounts, credited (with movement columns; else increased) with at least as much.
     */
    private function declared(ScheduleContext $ctx, array $sofp, ?array $opening, Figure $retained): bool
    {
        if ($retained->isMissing() || $retained->amount < 0.005) {
            return false;
        }
        foreach (['sofp.dividends_payable', 'sofp.partners_current_liability', 'sofp.partners_current_equity'] as $line) {
            $rows = $ctx->rowsOn($line);
            if ($rows === []) {
                continue;
            }
            $sum = $ctx->sum($rows, 'credit');
            $credited = $ctx->movements() ? $sum['increase'] : ($sum['opening'] !== null ? $sum['closing'] - $sum['opening'] : null);
            if ($credited !== null && $credited >= $retained->amount - 0.01) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Figure> movement row → its amount, positive for a dividend or a transfer out of retained earnings */
    private function movements(ScheduleContext $ctx, MovementSuggester $s, array $sofp, array $is, ?array $opening, bool $partnersInEquity): array
    {
        $where = fn (string $r) => ['schedule' => 'changes_in_equity', 'row' => $r, 'column' => 'amount',
            'label_ar' => 'قائمة التغيرات في حقوق الملكية — '.($r === 'dividends' ? __('statements.retained_movement', [], 'ar') : $ctx->catalogue->changesInEquity()['rows'][$r])];
        $key   = fn (string $r) => "soce.{$r}";
        $sum   = function (string $line) use ($ctx) {
            $rows = $ctx->rowsOn($line);
            $sum  = $ctx->sum($rows, 'credit');
            $p    = $ctx->postingsOn($rows, 'credit', $line, [$line]);
            $sum['posted_up']   = array_sum(array_map(fn ($f) => $f->amount, $p['increase']));
            $sum['posted_down'] = array_sum(array_map(fn ($f) => $f->amount, $p['decrease']));

            return $sum + ['rows' => array_keys($rows)];
        };
        [$cap, $res, $re, $pc] = [$sum('sofp.share_capital'), $sum('sofp.statutory_reserve'), $sum('sofp.retained_earnings'), $sum('sofp.partners_current_equity')];
        $rowsText = fn (array $x) => ['in' => $x['rows'] ? Phrase::make('report_messages.basis.in_accounts', ['accounts' => $ctx->accounts($x['rows'])]) : ''];
        $basis    = fn (string $key, array $params = []) => Phrase::make("report_messages.basis.{$key}", $params);
        $none     = fn () => new Figure(0.0, 'derived');
        $out      = [];

        if ($ctx->movements()) {
            $capital  = $cap['increase'] - $cap['decrease'] + $cap['posted_up'] - $cap['posted_down'];
            $transfer = $res['increase'] - $res['decrease'] + $res['posted_up'] - $res['posted_down'];
            $cleared  = $re['decrease'] + $re['posted_down'] - $transfer;
            $out['capital_increase']    = $s->suggest($key('capital_increase'), $capital, $basis('capital_movement', $rowsText($cap)), $cap['refs'], $where('capital_increase'));
            $out['transfer_to_reserve'] = $s->suggest($key('transfer_to_reserve'), $transfer, $basis('reserve_movement', $rowsText($res)), $res['refs'], $where('transfer_to_reserve'));
            $toPartners = $partnersInEquity && $cleared > 0.005 && $pc['increase'] >= $cleared - 0.01;
            $out['transfer_to_partners_current'] = $partnersInEquity
                ? $s->suggest($key('transfer_to_partners_current'), $toPartners ? $cleared : 0.0, $basis('retained_to_partners', $rowsText($re)), $re['refs'], $where('transfer_to_partners_current'))
                : $none();
            $out['dividends'] = $s->suggest($key('dividends'), $toPartners ? 0.0 : $cleared, $basis('retained_dividends', $rowsText($re)), $re['refs'], $where('dividends'));
            $out['partners_current_movements'] = $partnersInEquity
                ? $s->suggest($key('partners_current_movements'), $pc['increase'] - $pc['decrease'] - ($toPartners ? $cleared : 0.0), $basis('partners_current_movement', $rowsText($pc)), $pc['refs'], $where('partners_current_movements'))
                : $none();

            return $out;
        }

        // No balance sheet at the start of the year: the movements wait for the prior year (one missing item, not five).
        $lines = array_filter(array_column($ctx->catalogue->changesInEquity()['columns'], 'line'));
        if ($opening === null || array_filter($lines, fn ($l) => ($opening[$l] ?? Figure::missing())->isMissing())) {
            foreach (self::MOVEMENTS as $r) {
                $out[$r] = in_array($r, ['transfer_to_partners_current', 'partners_current_movements'], true) && ! $partnersInEquity ? $none() : Figure::missing();
            }
            return $out;
        }

        // Opening and closing only: each column's net change.
        $change = fn (string $line) => round(($sofp[$line]->amount ?? 0.0) - ($opening[$line]->amount ?? 0.0), 2);
        $known  = fn (string $line) => ! $sofp[$line]->isMissing() && ! ($opening[$line] ?? Figure::missing())->isMissing();
        $out['capital_increase'] = $known('sofp.share_capital')
            ? $s->suggest($key('capital_increase'), $change('sofp.share_capital'), $basis('capital_change'), $cap['refs'], $where('capital_increase')) : $s->entered($key('capital_increase'));
        $out['transfer_to_reserve'] = $known('sofp.statutory_reserve')
            ? $s->suggest($key('transfer_to_reserve'), $change('sofp.statutory_reserve'), $basis('reserve_change'), $res['refs'], $where('transfer_to_reserve')) : $s->entered($key('transfer_to_reserve'));
        $out['transfer_to_partners_current'] = $partnersInEquity ? $s->entered($key('transfer_to_partners_current')) : $none();
        $transfers = [$out['transfer_to_reserve'], $out['transfer_to_partners_current']];
        $residual  = $known('sofp.retained_earnings') && ! $is['is.net_profit']->isMissing() && ! array_filter($transfers, fn ($f) => $f->isMissing())
            ? round(($opening['sofp.retained_earnings']->amount ?? 0.0) + $is['is.net_profit']->amount - array_sum(array_map(fn ($f) => $f->amount, $transfers)) - $sofp['sofp.retained_earnings']->amount, 2)
            : null;
        $out['dividends'] = $residual !== null
            ? $s->suggest($key('dividends'), $residual, $basis('retained_residual'), $re['refs'], $where('dividends')) : $s->entered($key('dividends'));
        $out['partners_current_movements'] = ! $partnersInEquity ? $none()
            : ($known('sofp.partners_current_equity') && ! $out['transfer_to_partners_current']->isMissing()
                ? $s->suggest($key('partners_current_movements'), $change('sofp.partners_current_equity') - $out['transfer_to_partners_current']->amount, $basis('partners_current_change'), $pc['refs'], $where('partners_current_movements'))
                : $s->entered($key('partners_current_movements')));

        return $out;
    }
}
