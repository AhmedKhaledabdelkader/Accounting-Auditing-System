<?php

namespace App\Services\Statements;

/**
 * The prior year's figures of the notes' tables (PR 11; the `_py` columns deferred from PR 9), by the report's prior-year
 * source (Q7). Never guessed: a figure no source gives is missing.
 *
 * Item tables (NoteBreakdowns), by class:
 * - `none`: the opening columns; missing without them;
 * - `report` / `run`: the prior report's or prior trial balance's own table; a class it doesn't have is 0;
 * - `manual`: typed from last year's issued notes (`PUT …/prior-year` `notes`: note → class → amount; a class not given
 *   is 0 once the note is given); missing until then.
 *
 * Movement tables (asset notes, provisions):
 * - `report`: the prior report's own schedules;
 * - `run` / `manual`: typed from last year's notes (`schedules`: cell key → amount, as the year's own keys:
 *   `<schedule>.<row>` or `<schedule>.<row>.<column>`; a cell not given is 0 once any is given); missing until then;
 * - the closing rows are this year's opening rows whatever the source (the same balance), else missing; with last year's
 *   approved statements, a provision's closing is its approved line;
 * - `none`: only those closing rows.
 */
final class NoteComparatives
{
    public const CLOSING = ['cost_closing' => 'cost_opening', 'acc_closing' => 'acc_opening', 'nbv_closing' => 'nbv_opening', 'closing' => 'opening'];

    private const ORIGINS = ['report' => 'prior_year_report', 'run' => 'prior_year_trial_balance', 'manual' => 'prior_year_manual'];

    /**
     * @param array $breakdowns NoteBreakdowns::build()
     * @param array $prior      CurrentYearStatements' `prior` (breakdowns of the prior source, notes typed)
     * @return array the breakdowns, each row with `comparative` (null in a first year) and the build-up's too
     */
    public function breakdowns(array $breakdowns, string $source, array $prior): array
    {
        $source = $source === 'statements' ? 'manual' : $source;   // the notes are typed with a file of statements too (PR 17)
        foreach ($breakdowns as $note => $b) {
            $given = match ($source) {
                'first_year', 'none' => null,
                'manual'             => isset($prior['notes_entered'][$note]) ? $this->typed($prior['notes_entered'][$note], 'manual', "prior.notes.{$note}") : null,
                default              => isset($prior['breakdowns'][$note])
                    ? array_column(array_map(fn ($r) => ['key' => $r['key'], 'f' => $this->relabel($r['current'], $source, $prior)], $prior['breakdowns'][$note]['rows']), 'f', 'key')
                    : (isset($prior['breakdowns']) ? [] : null),   // the prior source has no such accounts: all 0
            };
            $rows = $b['rows'];
            $balanceSheet = str_starts_with($b['lines'][0], 'sofp.');
            if ($given !== null) {
                foreach (array_diff_key($given, array_flip(array_column($rows, 'key'))) as $key => $f) {
                    $rows[] = ['key' => $key, 'label_ar' => (string) $key, 'current' => new Figure(0.0, 'trial_balance'), 'opening' => null, 'rows' => []];
                }
            }
            foreach ($rows as $i => $r) {
                $rows[$i]['comparative'] = match (true) {
                    $source === 'first_year' => null,
                    // Opening balances are last year's closing for the balance sheet; the income statement's start from 0.
                    $source === 'none'       => $balanceSheet ? ($r['opening'] ?? Figure::missing()) : Figure::missing(),
                    $given === null          => Figure::missing(),
                    default                  => $given[$r['key']] ?? new Figure(0.0, self::ORIGINS[$source]),
                };
            }
            $breakdowns[$note]['rows'] = $rows;

            if ($b['build_up'] !== null || ($source !== 'none' && isset($prior['breakdowns'][$note]['build_up']))) {
                $priorUp = $prior['breakdowns'][$note]['build_up'] ?? null;
                $typedUp = $source === 'manual' && isset($prior['notes_entered']["{$note}_build_up"])
                    ? $this->typed($prior['notes_entered']["{$note}_build_up"], 'manual', "prior.notes.{$note}_build_up") : null;
                $up = [];
                foreach ([...NoteBreakdowns::BUILD_UP, 'cogs'] as $part) {
                    $current = $b['build_up'][$part]['current'] ?? new Figure(0.0, 'trial_balance');
                    $up[$part] = ['current' => $current, 'comparative' => match (true) {
                        $source === 'first_year' => null,
                        $source === 'none'       => Figure::missing(),
                        $source === 'manual'     => $typedUp === null ? Figure::missing() : ($part === 'cogs'
                            ? Figure::sum(array_map(fn ($p) => $typedUp[$p] ?? new Figure(0.0, 'prior_year_manual'), NoteBreakdowns::BUILD_UP))
                            : $typedUp[$part] ?? new Figure(0.0, 'prior_year_manual')),
                        isset($prior['breakdowns'])   => $priorUp !== null ? $this->relabel($priorUp[$part]['current'], $source, $prior) : new Figure(0.0, self::ORIGINS[$source]),
                        default                  => Figure::missing(),
                    }];
                }
                $breakdowns[$note]['build_up'] = $up;
            }
        }

        return $breakdowns;
    }

    /**
     * @param list<array> $schedules this year's movement schedules (MovementSchedules)
     * @return ?array<string, Figure> cell key (`<schedule>.<row>.<column>`) → the prior year's figure; null in a first year
     */
    public function schedules(array $schedules, string $source, array $prior, array $approved = []): ?array
    {
        if ($source === 'first_year') {
            return null;
        }
        $out = [];
        if ($source === 'report' && isset($prior['schedules'])) {
            foreach ($prior['schedules'] as $s) {
                foreach ($s['rows'] as $r) {
                    foreach ($r['cells'] as $col => $f) {
                        $out["{$s['key']}.{$r['key']}.{$col}"] = $this->relabel($f, $source, $prior);
                    }
                }
            }
            return $out;
        }

        $typed = in_array($source, ['run', 'manual', 'statements'], true) ? ($prior['schedules_entered'] ?? null) : null;
        foreach ($schedules as $s) {
            if (! in_array($s['kind'], ['asset', 'provision'], true)) {
                continue;
            }
            $rows = array_column($s['rows'], null, 'key');
            foreach ($s['rows'] as $r) {
                foreach (array_keys($r['cells']) as $col) {
                    $key = "{$s['key']}.{$r['key']}.{$col}";
                    // With last year's approved statements: a provision's closing is its approved line, not this year's
                    // opening (which keeps the trial balance's while an account's move is only proposed).
                    $line = $source === 'statements' && $s['kind'] === 'provision' && $r['key'] === 'closing' ? ($approved[$s['line_key'] ?? ''] ?? null) : null;
                    if ($line !== null && ! $line->isMissing()) {
                        $out[$key] = new Figure($line->amount, 'prior_year_statements', 'ok', [['type' => 'line', 'key' => $s['line_key']]]);
                        continue;
                    }
                    if (isset(self::CLOSING[$r['key']])) {
                        $opening = $rows[self::CLOSING[$r['key']]]['cells'][$col] ?? null;
                        $out[$key] = $opening !== null && ! $opening->isMissing() ? $opening : Figure::missing();
                        continue;
                    }
                    if ($r['key'] === 'nbv_opening') {
                        continue;   // two years back: not shown
                    }
                    $typedKey = $s['kind'] === 'provision' ? "{$s['key']}.{$r['key']}" : $key;
                    $out[$key] = match (true) {
                        $typed === null              => Figure::missing(),
                        isset($typed[$typedKey])     => new Figure((float) $typed[$typedKey], 'prior_year_manual', 'ok', [['type' => 'input', 'key' => "prior.schedules.{$typedKey}"]]),
                        default                      => new Figure(0.0, 'prior_year_manual'),
                    };
                }
            }
        }

        return $out;
    }

    /** Typed cells of the schedules that the user may give for the prior year: every row but the closing ones. */
    public static function typedScheduleKeys(array $schedules): array
    {
        $keys = [];
        foreach ($schedules as $s) {
            if (! in_array($s['kind'], ['asset', 'provision'], true)) {
                continue;
            }
            foreach ($s['rows'] as $r) {
                if (isset(self::CLOSING[$r['key']]) || $r['key'] === 'nbv_opening') {
                    continue;
                }
                foreach (array_keys($r['cells']) as $col) {
                    $keys[] = $s['kind'] === 'provision' ? "{$s['key']}.{$r['key']}" : "{$s['key']}.{$r['key']}.{$col}";
                }
            }
        }

        return $keys;
    }

    private function typed(array $values, string $source, string $refKey): array
    {
        $out = [];
        foreach ($values as $key => $amount) {
            $out[(string) $key] = new Figure((float) $amount, self::ORIGINS[$source], 'ok', [['type' => 'input', 'key' => "{$refKey}.{$key}"]]);
        }
        return $out;
    }

    private function relabel(Figure $f, string $source, array $prior): Figure
    {
        $ref = $prior['ref'] ?? null;
        return $f->isMissing() ? Figure::missing() : new Figure($f->amount, self::ORIGINS[$source], $f->status, $ref ? [$ref] : []);
    }
}
