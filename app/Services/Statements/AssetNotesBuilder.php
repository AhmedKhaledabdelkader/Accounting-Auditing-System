<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * The movement tables of the PPE, intangible assets and investment property notes (plan §3.6, Q8), per category:
 * cost (opening, additions, transfers, disposals, closing), accumulated depreciation (opening, charge, disposals,
 * closing) and net book value (closing, and at the start of the year, the comparative).
 *
 * - Closing: the trial balance. Opening: the opening columns; without them, the user's.
 * - Movements: with movement columns, suggestions to confirm (cost debits are additions, credits disposals; CWIP credits
 *   are transfers out; accumulated depreciation credits are the charge, debits disposals). Without them, the user's,
 *   except the year's charge and the depreciation on disposals when the table has one column of accumulated
 *   depreciation: the charge is the depreciation expense, and the rest follows (opening + charge − closing).
 * - A side (cost or accumulated depreciation) with an account of no category has no per-category figures, only the
 *   total, until the user assigns a category to each of its accounts (Q8).
 * - The report's entries by account count in their category; entries by line can't be placed in a category and are
 *   left to check 15 (the note's total vs the statement line).
 * - Revaluation (PPE, the revaluation model): the year's revaluation surplus (OCI) is a row of its own, non-cash, and
 *   is not suggested as an addition. One category takes it whole; with several, the user splits it (check 19).
 * Amounts are positive; disposals are subtracted.
 */
final class AssetNotesBuilder
{
    public const COST = ['cost_opening', 'additions', 'transfers', 'revaluation', 'disposals', 'cost_closing'];
    public const ACCUMULATED = ['acc_opening', 'depreciation', 'acc_disposals', 'acc_closing'];

    /** @return ?array the schedule, or null when the line has no account and no amount */
    public function build(string $key, array $cfg, ScheduleContext $ctx, MovementSuggester $s): ?array
    {
        $line = $cfg['line'];
        $rows = $ctx->rowsOn($line);
        if ($rows === []) {
            return null;
        }
        $sides = ['cost' => [], 'accumulated' => []];
        foreach ($rows as $n => $share) {
            $category = $ctx->categories[$n] ?? $ctx->catalogue->category($key, $ctx->rows[$n]['sub_class'] ?? null);
            $sides[$ctx->isContra($n) ? 'accumulated' : 'cost'][isset($cfg['categories'][$category]) ? $category : ''][$n] = $share;
        }
        $split      = ['cost' => ! isset($sides['cost']['']), 'accumulated' => ! isset($sides['accumulated'][''])];
        $categories = array_values(array_filter(array_keys($cfg['categories']), fn ($c) => isset($sides['cost'][$c]) || isset($sides['accumulated'][$c])));
        $columns    = [...$categories, 'total'];
        $from       = $cfg['transfers_from'] ?? null;
        $cwip       = $from !== null && isset($sides['cost'][$from]) ? $ctx->sum($sides['cost'][$from], 'debit') : null;
        $expense    = isset($cfg['charge']) ? $this->expense($ctx, $cfg['charge']['main_classes']) : null;
        $reval      = ($cfg['revaluation'] ?? false) && $ctx->revaluation !== null && ! $ctx->revaluation->isMissing() && abs($ctx->revaluation->amount) > 0.005
            ? $ctx->revaluation : null;
        $costCategories = array_values(array_filter(array_keys($sides['cost']), fn ($c) => $c !== ''));
        $costRows   = array_values(array_filter(self::COST, fn ($r) => ($r !== 'transfers' || $from !== null) && ($r !== 'revaluation' || $reval !== null)));

        $cells = [];
        foreach (['cost' => 'debit', 'accumulated' => 'credit'] as $side => $normal) {
            $rowKeys = $side === 'cost' ? $costRows : self::ACCUMULATED;
            foreach ($columns as $column) {
                $categoryRows = $column === 'total' ? array_replace(...array_values($sides[$side]) ?: [[]]) : ($sides[$side][$column] ?? []);
                if ($column !== 'total' && ! $split[$side]) {
                    foreach ($rowKeys as $r) {
                        $cells[$r][$column] = Figure::missing();
                    }
                    continue;
                }
                if ($column === 'total' && $split[$side] && $categories !== []) {
                    foreach ($rowKeys as $r) {
                        $cells[$r]['total'] = Figure::sum(array_map(fn ($c) => $cells[$r][$c], $categories));
                    }
                    continue;
                }
                $where = fn (string $r) => ['schedule' => $key, 'row' => $r, 'column' => $column,
                    'label_ar' => $this->label($key, $r, $column)];
                $revalCell = $side !== 'cost' || $reval === null ? null
                    : ($column === 'total' || count($costCategories) === 1 ? $reval : $s->entered("{$key}.revaluation.{$column}"));
                foreach ($this->side($key, $side, $normal, $column, $categoryRows, $ctx, $s, $where, $line, $from, $cwip, $expense, $revalCell) as $r => $figure) {
                    $cells[$r][$column] = $figure;
                }
            }
        }
        foreach ($columns as $column) {
            $cells['nbv_closing'][$column] = Figure::sum([$cells['cost_closing'][$column], self::neg($cells['acc_closing'][$column])]);
            $cells['nbv_opening'][$column] = Figure::sum([$cells['cost_opening'][$column], self::neg($cells['acc_opening'][$column])]);
        }

        $order = [...$costRows, ...self::ACCUMULATED, 'nbv_closing', 'nbv_opening'];

        return [
            'key' => $key, 'kind' => 'asset', 'title_ar' => __("statements.schedules.{$key}", [], 'ar'), 'line_key' => $line,
            'columns' => array_map(fn ($c) => ['key' => $c, 'label_ar' => $this->columnLabel($key, $c)], $columns),
            'rows' => array_map(fn ($r) => ['key' => $r, 'label_ar' => __("statements.schedule_rows.{$r}", [], 'ar'), 'cells' => $cells[$r]], $order),
            'split' => $split,
            // Every category of the note, so an account without one can be given it (the columns hold only the ones in use).
            'category_options' => array_map(fn ($c) => ['key' => $c, 'label_ar' => $this->columnLabel($key, $c)], array_keys($cfg['categories'])),
            'facts' => ['expense' => $expense, 'line' => $ctx->aggregated[$line]['closing'] ?? new Figure(0.0, 'trial_balance'),
                'line_postings' => $ctx->linePostings($line), 'cwip' => $from, 'revaluation' => $reval],
        ];
    }

    /** One column of one side: [row key => Figure]. */
    private function side(string $key, string $side, string $normal, string $column, array $rows, ScheduleContext $ctx, MovementSuggester $s,
        callable $where, string $line, ?string $from, ?array $cwip, ?Figure $expense, ?Figure $reval = null): array
    {
        [$open, $up, $down, $close] = $side === 'cost' ? ['cost_opening', 'additions', 'disposals', 'cost_closing'] : ['acc_opening', 'depreciation', 'acc_disposals', 'acc_closing'];
        $sum      = $ctx->sum($rows, $normal);
        $postings = $ctx->postingsOn($rows, $normal, $line);
        $refs     = $sum['refs'];
        $cellKey  = fn (string $r) => "{$key}.{$r}.{$column}";
        $out      = [];

        $out[$open] = $sum['opening'] !== null ? new Figure($sum['opening'], 'trial_balance_opening', 'ok', $refs) : ($rows === [] ? new Figure(0.0, 'derived') : $s->entered($cellKey($open)));
        $out[$close] = self::plus(new Figure($sum['closing'], 'trial_balance', 'ok', $refs), [...$postings['increase'], ...array_map([self::class, 'neg'], $postings['decrease'])]);

        $isCwip = $side === 'cost' && $column === $from;
        if ($rows === []) {
            $out[$up] = $out[$down] = new Figure(0.0, 'derived');
        } elseif ($ctx->movements()) {
            // The revaluation increment is debited to cost too: not an addition.
            $surplus  = $reval !== null && ! $reval->isMissing() ? $reval->amount : 0.0;
            $out[$up] = $s->suggest($cellKey($up), $sum['increase'] - $surplus, $surplus != 0.0 ? Phrase::make('report_messages.basis.after_revaluation', ['basis' => $this->basis($side, 'increase', $ctx->accounts(array_keys($rows)))]) : $this->basis($side, 'increase', $ctx->accounts(array_keys($rows))), $refs, $where($up));
            $out[$down] = $isCwip
                ? $s->suggest($cellKey($down), 0.0, '', $refs, $where($down))   // CWIP credits are suggested as transfers
                : $s->suggest($cellKey($down), $sum['decrease'], $this->basis($side, 'decrease', $ctx->accounts(array_keys($rows))), $refs, $where($down));
        } elseif ($side === 'accumulated' && $column === 'total' && $expense !== null) {
            // One column of accumulated depreciation, no movements: the charge is the year's expense; disposals follow.
            $out[$up] = $s->fact($cellKey($up), $expense);
            $out[$down] = Figure::sum([$out[$open], $out[$up], self::neg($out[$close])]);
        } else {
            $out[$up] = $s->entered($cellKey($up));
            $out[$down] = $s->entered($cellKey($down));
        }
        $out[$up]   = self::plus($out[$up], $postings['increase']);
        $out[$down] = self::plus($out[$down], $postings['decrease']);

        if ($reval !== null) {
            $out['revaluation'] = $reval;
        }
        if ($side === 'cost' && $from !== null) {
            $out['transfers'] = match (true) {
                $isCwip && $ctx->movements()      => self::neg($s->suggest($cellKey('transfers'), $sum['decrease'], Phrase::make('report_messages.basis.cwip_transfers'), $refs, $where('transfers'))),
                $cwip === null                    => new Figure(0.0, 'derived'),   // no CWIP: nothing to transfer
                $column === 'total'               => $s->fact($cellKey('transfers'), new Figure(0.0, 'derived')),   // transfers net to nothing
                $ctx->movements() && ! $isCwip && abs($cwip['decrease'] ?? 0.0) < 0.005 => $s->fact($cellKey('transfers'), new Figure(0.0, 'derived')),
                default                           => $s->entered($cellKey('transfers')),
            };
        }

        return $out;
    }

    /** The year's depreciation expense: the expense classes' accounts, with the report's entries on them. */
    private function expense(ScheduleContext $ctx, array $mainClasses): Figure
    {
        $rows     = $ctx->rowsOf([], $mainClasses);
        $sum      = $ctx->sum($rows, 'debit');
        $postings = $ctx->postingsOn($rows, 'debit');

        return self::plus(new Figure($sum['closing'], 'trial_balance', 'ok', $sum['refs']), [...$postings['increase'], ...array_map([self::class, 'neg'], $postings['decrease'])]);
    }

    private function basis(string $side, string $direction, Phrase $accounts): Phrase
    {
        return Phrase::make("report_messages.basis.{$side}_{$direction}", ['accounts' => $accounts]);
    }

    private function label(string $key, string $row, string $column): string
    {
        return __("statements.schedules.{$key}", [], 'ar').' — '.__("statements.schedule_rows.{$row}", [], 'ar').' — '.$this->columnLabel($key, $column);
    }

    private function columnLabel(string $key, string $column): string
    {
        return $column === 'total' ? __('statements.schedule_columns.total', [], 'ar') : __("statements.categories.{$key}.{$column}", [], 'ar');
    }

    public static function neg(Figure $f): Figure
    {
        return $f->isMissing() ? $f : new Figure(-$f->amount + 0.0, $f->origin, $f->status, $f->refs);
    }

    /** A figure with the report's postings added: unchanged without any. */
    public static function plus(Figure $base, array $parts): Figure
    {
        if ($parts === []) {
            return $base;
        }
        $refs = $base->refs;
        foreach ($parts as $p) {
            foreach ($p->refs as $r) {
                if (! in_array($r, $refs, true)) {
                    $refs[] = $r;
                }
            }
        }

        return Figure::sum([$base, ...$parts], $refs);
    }
}
