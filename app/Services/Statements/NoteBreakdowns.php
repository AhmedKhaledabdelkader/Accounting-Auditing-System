<?php

namespace App\Services\Statements;

/**
 * The notes' item tables (PR 11, plan §3.6): a note's lines split by class, current year from the closing balances and
 * the report's postings, with the opening balances when the file has them. An expense note lists one row per nature
 * (main class); the others one row per sub-class. Entries made by line (not by account) are a row of their own.
 * The cost of revenue also gets the periodic-inventory build-up (Q32) when it has opening or closing stock.
 * Rows are keyed by their class name (Arabic), so a prior year's table lines up with this one by key.
 */
final class NoteBreakdowns
{
    public const ADJUSTMENTS = 'قيود التسوية';
    public const BUILD_UP = ['opening', 'purchases', 'purchase_costs', 'closing'];

    /**
     * @return array<string, array{lines: list<string>, rows: list<array{key: string, label_ar: string, current: Figure, opening: ?Figure}>, build_up: ?array<string, array{current: Figure, opening: ?Figure}>}>
     */
    public function build(ScheduleContext $ctx): array
    {
        $out = [];
        foreach ($ctx->catalogue->notes() as $note => $cfg) {
            if (! isset($cfg['breakdown'])) {
                continue;
            }
            $lines   = $cfg['breakdown_lines'] ?? $cfg['lines'];
            $groups  = $this->groups($ctx, $lines, $cfg['group'] ?? 'sub_class');
            $buildUp = null;
            if ($note === 'cost_of_revenue') {
                [$groups, $buildUp] = $this->buildUp($ctx, $groups);
            }
            if ($note === 'other_assets') {
                // The cost first, its accumulated amortisation (a contra) after it.
                uasort($groups, fn ($a, $b) => ($a['current']->amount < 0) <=> ($b['current']->amount < 0));
            }
            $out[$note] = ['lines' => $lines, 'rows' => array_values($groups), 'build_up' => $buildUp];
        }

        return $out;
    }

    /**
     * A note's lines account by account (for last year's note items, PriorNoteItems): each trial-balance row on the
     * lines with its share, and the report's postings (by account in its row; by line in one «قيود التسوية» entry).
     *
     * @return array<string, array{ref: string, label: string, class: string, current: float, opening: ?float, refs: list<array>, rows: list<int>}> by ref (row number or `adjustments`)
     */
    public function accounts(ScheduleContext $ctx, array $lines): array
    {
        $out = [];
        $add = function (string $ref, string $label, string $class, string $col, float $amount, array $r, ?int $row) use (&$out, $ctx) {
            $out[$ref] ??= ['ref' => $ref, 'label' => $label, 'class' => $class, 'current' => 0.0, 'opening' => $ctx->profile->has('opening') ? 0.0 : null, 'refs' => [], 'rows' => []];
            $out[$ref][$col] += $amount;
            $out[$ref]['refs'][] = $r;
            $row !== null && $out[$ref]['rows'] = array_values(array_unique([...$out[$ref]['rows'], $row]));
        };
        foreach ($ctx->mapping['rows'] as $n => $m) {
            $row = $ctx->rows[$n];
            foreach ($lines as $line) {
                $sign = $ctx->catalogue->side($line) === 'debit' ? 1 : -1;
                $label = (string) ($row['account_name'] ?? $n);
                $class = (string) ($row['sub_class'] ?? $label);
                if (isset($m['lines']['closing'][$line])) {
                    $share = $m['lines']['closing'][$line];
                    $add((string) $n, $label, $class, 'current', $sign * ($ctx->profile->closing($row) ?? 0.0) * $share, ['type' => 'row', 'row' => $n] + ($share < 1.0 ? ['share' => $share] : []), $n);
                }
                if ($ctx->profile->has('opening') && isset($m['lines']['opening'][$line])) {
                    $share = $m['lines']['opening'][$line];
                    $add((string) $n, $label, $class, 'opening', $sign * ($ctx->profile->opening($row) ?? 0.0) * $share, ['type' => 'row', 'row' => $n], $n);
                }
            }
        }
        foreach ($ctx->postings as $p) {
            if (! in_array($p['line'], $lines, true)) {
                continue;
            }
            $sign = $ctx->catalogue->side($p['line']) === 'debit' ? 1 : -1;
            $row = $p['row'] !== null && isset($ctx->rows[$p['row']]) ? $p['row'] : null;
            $ref = $row !== null ? (string) $row : 'adjustments';
            $label = $row !== null ? (string) ($ctx->rows[$row]['account_name'] ?? $row) : self::ADJUSTMENTS;
            $add($ref, $label, $row !== null ? (string) ($ctx->rows[$row]['sub_class'] ?? $label) : self::ADJUSTMENTS, 'current', $sign * $p['net'], $p['ref'], $row);
        }

        return array_map(fn ($a) => ['current' => round($a['current'], 2) + 0.0, 'opening' => $a['opening'] === null ? null : round($a['opening'], 2) + 0.0] + $a, $out);
    }

    /** @return array<string, array> key → {key, label_ar, current, opening, rows, sub_classes, main_class} */
    private function groups(ScheduleContext $ctx, array $lines, string $by): array
    {
        $acc = [];
        $add = function (string $key, string $group, float $amount, array $ref, bool $pending, ?int $row) use (&$acc) {
            $acc[$key] ??= ['key' => $key, 'closing' => 0.0, 'opening' => 0.0, 'refs' => [], 'pending' => false, 'origin' => 'trial_balance', 'rows' => []];
            $acc[$key][$group] += $amount;
            $acc[$key]['refs'][] = $ref;
            $acc[$key]['pending'] = $acc[$key]['pending'] || $pending;
            if ($row !== null) {
                $acc[$key]['rows'][$row] = true;
            }
        };
        $mainRules = $ctx->catalogue->rules()['main_classes'];
        foreach ($ctx->mapping['rows'] as $n => $m) {
            $row = $ctx->rows[$n];
            foreach ($lines as $line) {
                $sign = $ctx->catalogue->side($line) === 'debit' ? 1 : -1;
                // An expense note lists natures; the class the line belongs to itself (e.g. cost of goods sold) by sub-class.
                $key = $by === 'main_class' && ($mainRules[$row['main_class']] ?? null) !== $line ? $row['main_class'] : $row['sub_class'];
                $key ??= (string) ($row['account_name'] ?? $n);
                $pending = $m['status'] === 'pending_confirmation';
                $ref = fn (float $share) => ['type' => 'row', 'row' => $n] + ($share < 1.0 ? ['share' => $share] : []);
                if (isset($m['lines']['closing'][$line])) {
                    $share = $m['lines']['closing'][$line];
                    $add($key, 'closing', $sign * ($ctx->profile->closing($row) ?? 0.0) * $share, $ref($share), $pending, $n);
                }
                if ($ctx->profile->has('opening') && isset($m['lines']['opening'][$line])) {
                    $share = $m['lines']['opening'][$line];
                    $add($key, 'opening', $sign * ($ctx->profile->opening($row) ?? 0.0) * $share, $ref($share), $pending, $n);
                }
            }
        }
        // The report's postings: by account in the account's row, by line in a row of their own.
        foreach ($ctx->postings as $p) {
            if (! in_array($p['line'], $lines, true)) {
                continue;
            }
            $sign = $ctx->catalogue->side($p['line']) === 'debit' ? 1 : -1;
            if ($p['row'] !== null && isset($ctx->rows[$p['row']])) {
                $row = $ctx->rows[$p['row']];
                $key = $by === 'main_class' && ($mainRules[$row['main_class']] ?? null) !== $p['line'] ? $row['main_class'] : $row['sub_class'];
            } else {
                $key = self::ADJUSTMENTS;
            }
            $key ??= self::ADJUSTMENTS;
            $add($key, 'closing', $sign * $p['net'], $p['ref'], false, $p['row']);
            $acc[$key]['origin'] = 'derived';
        }

        $out = [];
        foreach ($acc as $key => $a) {
            $status = $a['pending'] ? 'pending_confirmation' : 'ok';
            $out[$key] = [
                'key' => $key, 'label_ar' => $key,
                'current' => new Figure(round($a['closing'], 2) + 0.0, $a['origin'], $status, $a['refs']),
                'opening' => $ctx->profile->has('opening') ? new Figure(round($a['opening'], 2) + 0.0, 'trial_balance_opening', $status, $a['refs']) : null,
                'rows' => array_keys($a['rows']),
            ];
        }

        return $out;
    }

    /**
     * The periodic build-up: opening stock + purchases + purchase costs − closing stock = cost of goods sold. Its rows
     * leave the item table, which gets one «تكلفة البضاعة المباعة» row for them. Only when there is opening or closing stock.
     *
     * @return array{0: array, 1: ?array}
     */
    private function buildUp(ScheduleContext $ctx, array $groups): array
    {
        $cfg    = $ctx->catalogue->periodicCost();
        $family = $cfg['inventory_family'];
        $parts  = array_fill_keys(self::BUILD_UP, []);
        foreach ($groups as $key => $g) {
            $part = null;
            foreach (self::BUILD_UP as $p) {
                if (in_array($key, $cfg[$p], true)) {
                    $part = $p;
                }
            }
            // A stock account the user moved to cost of revenue (the inventory family): by its side.
            if ($part === null && $g['rows'] !== [] && $ctx->rows[$g['rows'][0]]['main_class'] === $family) {
                $part = $g['current']->amount >= 0 ? 'opening' : 'closing';
            }
            if ($part !== null) {
                $parts[$part][$key] = $g;
            }
        }
        $stock = array_merge($parts['opening'], $parts['closing']);
        $nonZero = array_filter($stock, fn ($g) => abs($g['current']->amount) > 0.005 || abs($g['opening']?->amount ?? 0.0) > 0.005);
        if ($nonZero === []) {
            return [$groups, null];
        }

        $sum = fn (array $gs, string $col) => $col === 'opening' && ! $ctx->profile->has('opening') ? null
            : ($gs === [] ? new Figure(0.0, $col === 'opening' ? 'trial_balance_opening' : 'trial_balance') : Figure::sum(array_map(fn ($g) => $g[$col], $gs), array_merge(...array_map(fn ($g) => $g[$col]->refs, array_values($gs)))));
        $buildUp = [];
        foreach (self::BUILD_UP as $p) {
            $buildUp[$p] = ['current' => $sum($parts[$p], 'current'), 'opening' => $sum($parts[$p], 'opening')];
        }
        $all = array_merge(...array_values($parts));
        $buildUp['cogs'] = ['current' => $sum($all, 'current'), 'opening' => $sum($all, 'opening')];

        $rest = array_diff_key($groups, $all);
        $name = $cfg['cost_of_goods_sold'];   // the sub-class's current name (config, rewritten after a rename)
        $cogs = ['key' => $name, 'label_ar' => $name, 'current' => $buildUp['cogs']['current'], 'opening' => $buildUp['cogs']['opening'],
            'rows' => array_merge(...array_map(fn ($g) => $g['rows'], array_values($all)))];
        // A plain «تكلفة البضاعة المباعة» sub-class row stays, under another name; the combined row goes first.
        if (isset($rest[$name])) {
            $other = $name.' - أخرى';
            $rest  = array_combine(array_map(fn ($k) => $k === $name ? $other : $k, array_keys($rest)), $rest);
            $rest[$other]['key'] = $rest[$other]['label_ar'] = $other;
        }

        return [[$name => $cogs] + $rest, $buildUp];
    }
}
