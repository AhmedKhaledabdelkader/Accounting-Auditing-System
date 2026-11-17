<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * Roll-forwards of the provisions (plan §3.6, §4.2 step 7): end-of-service benefits, zakat, other provisions (Q37), the
 * impairment of receivables and dividends payable: opening + increase (+ prior-year adjustments) − decrease − reversal
 * = closing. And the loans note (Q36): gross loans less the deferred finance cost, current and non-current.
 *
 * - Opening: the opening columns; without them, the comparative balance sheet's line; else the user's.
 * - With movement columns, credits are the increase and debits the decrease, as suggestions to confirm. A debit matched
 *   by a credit on the provision's P&L counterpart is a reversal, not a payment (check 26c): the reversal is suggested
 *   for the matched amount, the decrease for the rest.
 * - Without them, the increase is the P&L counterpart (the user's when there is none), reversals sit in it unless the
 *   P&L has a reversal class, and the decrease follows: opening + increase − reversal − closing.
 * - The report's postings on the provision (entries, the zakat charge input) are added: credits to the increase,
 *   debits to the decrease.
 */
final class ProvisionRollforwards
{
    public function build(string $key, array $cfg, ScheduleContext $ctx, MovementSuggester $s): ?array
    {
        $line  = $cfg['line'] ?? null;
        $rows  = $line !== null ? $ctx->rowsOn($line) : $ctx->rowsOf($cfg['sub_classes']);
        $sum   = $ctx->sum($rows, 'credit');
        $posts = $ctx->postingsOn($rows, 'credit', $line, $line !== null ? [$line] : []);
        if ($rows === [] && $posts['increase'] === [] && $posts['decrease'] === []) {
            return null;
        }
        [$up, $down] = [$cfg['increase'], $cfg['decrease']];
        $cellKey = fn (string $r) => "{$key}.{$r}";
        $where   = fn (string $r) => ['schedule' => $key, 'row' => $r, 'column' => 'amount',
            'label_ar' => __("statements.schedules.{$key}", [], 'ar').' — '.__("statements.schedule_rows.{$r}", [], 'ar')];
        $refs    = $sum['refs'];

        // The P&L counterpart: the charge classes (or the rows the user tagged), and the reversal classes.
        $expected   = ($cfg['counterpart'] ?? true) !== false;
        $chargeRows = match (true) {
            ! $expected                    => [],
            isset($cfg['charge']['tag'])   => $ctx->tagged($cfg['charge']['tag']),
            default                        => $ctx->rowsOf($cfg['charge']['sub_classes'] ?? [], $cfg['charge']['main_classes'] ?? []),
        };
        $reversalRows = $ctx->rowsOf($cfg['reversal']['sub_classes'] ?? []);
        $charge   = $chargeRows !== [] ? $ctx->sum($chargeRows, 'debit') : null;
        $reversal = $reversalRows !== [] ? $ctx->sum($reversalRows, 'credit') : null;
        $hasCounterpart = $expected && ($chargeRows !== [] || ! isset($cfg['charge']['tag']));

        $c = [];
        $c['opening'] = match (true) {
            $sum['opening'] !== null                                                       => new Figure($sum['opening'], 'trial_balance_opening', 'ok', $refs),
            $line !== null && $ctx->comparative !== null && ! ($ctx->comparative[$line] ?? Figure::missing())->isMissing() => $ctx->comparative[$line],
            default                                                                        => $s->entered($cellKey('opening')),
        };
        $c['closing'] = AssetNotesBuilder::plus(new Figure($sum['closing'], 'trial_balance', 'ok', $refs),
            [...$posts['increase'], ...array_map([AssetNotesBuilder::class, 'neg'], $posts['decrease'])]);
        if ($cfg['prior_year_adjustments'] ?? false) {
            $c['prior_year_adjustments'] = $s->fact($cellKey('prior_year_adjustments'), new Figure(0.0, 'derived'));
        }

        $matched = 0.0;
        if ($ctx->movements()) {
            // A debit matched by credits on the P&L counterpart is a reversal (26c).
            $credits = ($charge ? $ctx->sum($chargeRows, 'debit')['decrease'] : 0.0) + ($reversal['increase'] ?? 0.0);
            $matched = $credits > 0.005 && $credits <= $sum['decrease'] + 0.005 ? round($credits, 2) : 0.0;
            $c[$up]  = $s->suggest($cellKey($up), $sum['increase'], Phrase::make('report_messages.basis.provision_increase', ['accounts' => $ctx->accounts(array_keys($rows))]), $refs, $where($up));
            $c['reversal'] = $s->suggest($cellKey('reversal'), $matched, Phrase::make('report_messages.basis.provision_reversal'), $refs, $where('reversal'));
            $c[$down] = $s->suggest($cellKey($down), $sum['decrease'] - $matched, Phrase::make('report_messages.basis.provision_decrease'.($matched > 0 ? '_after_reversal' : ''), ['accounts' => $ctx->accounts(array_keys($rows))]), $refs, $where($down));
            $c[$up]   = AssetNotesBuilder::plus($c[$up], $posts['increase']);
            $c[$down] = AssetNotesBuilder::plus($c[$down], $posts['decrease']);
        } else {
            $c[$up] = $hasCounterpart && $charge !== null
                ? $s->fact($cellKey($up), new Figure($charge['closing'], 'trial_balance', 'ok', $charge['refs']))
                : ($hasCounterpart ? $s->fact($cellKey($up), new Figure(0.0, 'trial_balance')) : $s->entered($cellKey($up)));
            $c[$up] = AssetNotesBuilder::plus($c[$up], $posts['increase']);
            $c['reversal'] = $s->fact($cellKey('reversal'), $reversal !== null ? new Figure($reversal['closing'], 'trial_balance', 'ok', $reversal['refs']) : new Figure(0.0, 'derived'));
            $c[$down] = Figure::sum([$c['opening'], $c[$up], $c['prior_year_adjustments'] ?? new Figure(0.0, 'derived'),
                AssetNotesBuilder::neg($c['reversal']), AssetNotesBuilder::neg($c['closing'])]);
        }

        $order = ['opening', $up, ...(isset($c['prior_year_adjustments']) ? ['prior_year_adjustments'] : []), $down, 'reversal', 'closing'];

        return [
            'key' => $key, 'kind' => 'provision', 'title_ar' => __("statements.schedules.{$key}", [], 'ar'), 'line_key' => $line,
            'columns' => [['key' => 'amount', 'label_ar' => __('statements.schedule_columns.amount', [], 'ar')]],
            'rows' => array_map(fn ($r) => ['key' => $r, 'label_ar' => __("statements.schedule_rows.{$r}", [], 'ar'), 'cells' => ['amount' => $c[$r]]], $order),
            'increase' => $up, 'decrease' => $down,
            'facts' => [
                'credit' => $sum['increase'], 'debit' => $sum['decrease'], 'matched' => $matched,
                'counterpart_expected' => $expected, 'counterpart' => $hasCounterpart ? ($charge['increase'] ?? 0.0) : null,   // its debits, with movement columns
            ],
        ];
    }

    /**
     * The loans note (Q36): gross loans, the deferred finance cost netted against them (rows tagged
     * `deferred_finance_cost`), net, and the non-current and current lines; this year and at the start of it.
     */
    public function loans(array $cfg, ScheduleContext $ctx): ?array
    {
        $rows = [];
        foreach ($cfg['lines'] as $line) {
            $rows += $ctx->rowsOn($line);
        }
        if ($rows === []) {
            return null;
        }
        $deferredRows = array_intersect_key($rows, $ctx->tagged('deferred_finance_cost'));
        $grossRows    = array_diff_key($rows, $deferredRows);
        $gross        = $ctx->sum($grossRows, 'credit');
        $deferred     = $ctx->sum($deferredRows, 'debit');
        [$nonCurrent, $current] = $cfg['lines'];

        $cells = ['current' => [
            'gross'    => new Figure($gross['closing'], 'trial_balance', 'ok', $gross['refs']),
            'deferred_finance_cost' => new Figure($deferred['closing'], 'trial_balance', 'ok', $deferred['refs']),
            'non_current' => $ctx->aggregated[$nonCurrent]['closing'] ?? new Figure(0.0, 'trial_balance'),
            'current'     => $ctx->aggregated[$current]['closing'] ?? new Figure(0.0, 'trial_balance'),
        ]];
        if ($ctx->comparative !== null) {
            $cells['comparative'] = [
                'gross'    => $gross['opening'] !== null ? new Figure($gross['opening'], 'trial_balance_opening', 'ok', $gross['refs']) : Figure::missing(),
                'deferred_finance_cost' => $deferred['opening'] !== null ? new Figure($deferred['opening'], 'trial_balance_opening', 'ok', $deferred['refs']) : Figure::missing(),
                'non_current' => $ctx->comparative[$nonCurrent] ?? Figure::missing(),
                'current'     => $ctx->comparative[$current] ?? Figure::missing(),
            ];
        }
        foreach ($cells as $col => $v) {
            $cells[$col]['net'] = Figure::sum([$v['gross'], AssetNotesBuilder::neg($v['deferred_finance_cost'])]);
        }
        $order = ['gross', 'deferred_finance_cost', 'net', 'non_current', 'current'];

        return [
            'key' => 'loans', 'kind' => 'loans', 'title_ar' => __('statements.schedules.loans', [], 'ar'), 'line_key' => null,
            'columns' => array_map(fn ($col) => ['key' => $col, 'label_ar' => __("statements.scopes.{$col}", [], 'ar')], array_keys($cells)),
            'rows' => array_map(fn ($r) => ['key' => $r, 'label_ar' => __("statements.schedule_rows.{$r}", [], 'ar'),
                'cells' => array_map(fn ($col) => $cells[$col][$r], array_combine(array_keys($cells), array_keys($cells)))], $order),
            'facts' => [],
        ];
    }
}
