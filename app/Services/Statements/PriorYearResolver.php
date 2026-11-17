<?php

namespace App\Services\Statements;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;

/**
 * The comparative figures of a report from its prior-year source (Q7), in order of preference:
 * - `report`: a previous report in this system, with its own overrides, inputs, entries, decisions and comparatives
 *   (origin `prior_year_report`); its changes in equity and cash flows become the comparatives of these (PR 10);
 * - `run`: a classified prior-year trial balance (origin `prior_year_trial_balance`); OCI is never in a trial balance, so
 *   its items stay missing until the user gives the prior year's OCI figures (`{}` for none); an item not given is then 0;
 * - `manual`: the figures the user entered per line, as displayed; a line not entered is 0 (origin `prior_year_manual`);
 * - `statements`: last year's issued statements uploaded as Excel and confirmed by the user (PR 17, PriorStatementsFile):
 *   each row the user (or an exact match) put on a line gives its amount, as shown (origin `prior_year_statements`); a row
 *   the file couldn't read makes its line missing; a line of a statement in the file with no row is 0, of a statement not
 *   in the file missing. Its totals are kept to be checked against the statements built from it.
 * Nothing is adjusted to fit the current year: differences are check 21's business.
 */
final class PriorYearResolver
{
    public function __construct(private CurrentYearStatements $statements, private ReportSource $source)
    {
    }

    /**
     * @return array{source: string, restated: bool, figures: ?array<string, Figure>, changes_in_equity?: list<array>, cash_flows?: array<string, Figure>,
     *               breakdowns?: array, schedules?: list<array>, ref?: array, notes_entered?: ?array, schedules_entered?: ?array}
     *         for CurrentYearStatements' `prior`
     */
    public function resolve(FinancialReport $report): array
    {
        $prior  = $report->prior_figures ?? [];
        $source = $prior['source'] ?? 'none';
        $out    = ['source' => $source, 'restated' => (bool) ($prior['restated'] ?? false), 'figures' => null]
            + (($prior['skip_opening'] ?? false) ? ['skip_opening' => true] : []);
        if ($source === 'report') {
            return array_merge($out, $this->fromReport($report));
        }

        return match ($source) {
            'run'        => $this->fromRun($report, $prior['figures'] ?? null),
            'manual'     => ['figures' => $this->manual($report, $prior['figures'] ?? [])],
            // The confirmed file itself too: its PDF's notes give the notes' lines (PriorNoteItems).
            'statements' => self::fromFile(new LineCatalogue($report->template_version), $prior) + ['file' => $prior['file'] ?? null, 'file_rows' => $prior['rows'] ?? [],
                // Step B: this year's openings from them (ApprovedOpening), unless the report turned it off.
                'approved_opening' => ($prior['approved_opening'] ?? true) !== false],
            default      => [],
        } + (in_array($source, ['run', 'manual', 'statements'], true) ? [
            // Typed from last year's issued statements and notes, when given (PR 10, PR 11).
            'changes_in_equity_entered' => $prior['changes_in_equity'] ?? null,
            'cash_flows_entered'        => $prior['cash_flows'] ?? null,
            'notes_entered'             => $prior['notes'] ?? null,
            'schedules_entered'         => $prior['schedules'] ?? null,
        ] : []) + $out;
    }

    /** Line keys a user may enter for the prior year: every line of both statements, OCI included. */
    public static function manualKeys(LineCatalogue $catalogue): array
    {
        return array_column(array_filter([...$catalogue->statement('financial_position'), ...$catalogue->statement('comprehensive_income')],
            fn ($i) => $i['type'] === 'line'), 'key');
    }

    /** The prior report as it stands, with its own prior year (reports only point back in time, so this ends). */
    private function fromReport(FinancialReport $report): array
    {
        $prior = $report->priorReport;
        if ($prior === null || $this->source->unusable($prior->run)) {
            return ['figures' => null];   // every comparative missing
        }
        $built = $this->statements->build($prior->run, $report->template_version, $prior->statementOptions() + ['prior' => $this->resolve($prior)]);
        $ref   = ['type' => 'prior_report', 'id' => $prior->id];
        $soce  = $built['statements']['changes_in_equity'];

        return [
            'ref'        => $ref,
            'breakdowns' => $built['breakdowns'],
            'schedules'  => $built['schedules'],
            'figures' => $this->lines($built, 'prior_year_report', $ref),
            'changes_in_equity' => array_map(fn ($r) => ['cells' => array_map(fn (Figure $f) => self::relabel($f, 'prior_year_report', $ref), $r['cells'])] + $r, $soce['current']),
            'cash_flows' => array_map(fn (Figure $f) => self::relabel($f, 'prior_year_report', $ref),
                array_column(array_map(fn ($r) => ['key' => $r['key'], 'f' => $r['current']], array_filter($built['statements']['cash_flows'], fn ($r) => $r['type'] === 'line')), 'f', 'key')),
        ];
    }

    private static function relabel(Figure $f, string $origin, array $ref): Figure
    {
        return $f->isMissing() ? Figure::missing() : new Figure($f->amount, $origin, $f->status, [$ref]);
    }

    /** @return array{figures: ?array, breakdowns?: array, ref?: array} */
    private function fromRun(FinancialReport $report, ?array $oci): array
    {
        $run = $report->prior_run_id ? ClassificationRun::find($report->prior_run_id) : null;
        if ($run === null || $this->source->unusable($run)) {
            return ['figures' => null];
        }
        $ref   = ['type' => 'prior_run', 'id' => $run->id];
        $built = $this->current($run, $report->template_version, []);
        $lines = $this->lines($built, 'prior_year_trial_balance', $ref);
        if ($oci !== null) {
            foreach (array_filter(array_keys($lines), fn ($k) => str_starts_with($k, 'is.oci_')) as $key) {
                $lines[$key] = isset($oci[$key])
                    ? new Figure((float) $oci[$key], 'prior_year_manual', 'ok', [['type' => 'input', 'key' => "prior.{$key}"]])
                    : new Figure(0.0, 'prior_year_manual');
            }
        }

        return ['figures' => $lines, 'breakdowns' => $built['breakdowns'], 'ref' => $ref];
    }

    private function manual(FinancialReport $report, array $figures): array
    {
        $lines = [];
        foreach (self::manualKeys(new LineCatalogue($report->template_version)) as $key) {
            $lines[$key] = isset($figures[$key])
                ? new Figure((float) $figures[$key], 'prior_year_manual', 'ok', [['type' => 'input', 'key' => "prior.{$key}"]])
                : new Figure(0.0, 'prior_year_manual');
        }

        return $lines;
    }

    /**
     * The figures of a confirmed file of issued statements: each row's line is the user's choice, or its exact match.
     *
     * @return array{figures: array<string, Figure>, cash_flows?: array<string, Figure>, file_totals: list<array{key: string, amount: ?float, ref: array}>, ref: array}
     */
    public static function fromFile(LineCatalogue $catalogue, array $prior): array
    {
        $file = $prior['file'] ?? ['blocks' => []];
        $flip = ($prior['expenses_shown_positive'] ?? false) === true;
        $parts = $totals = [];
        $present = [];
        foreach ($file['blocks'] as $b => $block) {
            $present[$block['statement']] = true;
            $column = $prior['columns'][$b] ?? $block['column'];
            foreach ($block['rows'] as $r) {
                $key = self::rowLine($prior, $b, $r);
                $value = $r['values'][$column] ?? ['amount' => null, 'status' => 'empty'];
                if ($key === null || $value['status'] === 'empty') {
                    continue;
                }
                $ref = ['type' => 'prior_file', 'sheet' => $block['sheet_name'], 'row' => $r['row']];
                $amount = $value['status'] === 'ok' ? $value['amount'] : null;
                if ($amount !== null && $flip && str_starts_with($key, 'is.') && PriorStatementsFile::isLine($catalogue, $key) && $catalogue->side($key) === 'debit') {
                    $amount = -$amount;
                }
                if (PriorStatementsFile::isLine($catalogue, $key)) {
                    $parts[$key][] = [$amount, $ref];
                } else {
                    $totals[] = ['key' => $key, 'amount' => $amount, 'ref' => $ref];
                }
            }
        }
        $figure = function (string $key, string $statement) use ($parts, $present) {
            if (! isset($present[$statement])) {
                return Figure::missing();
            }
            $given = $parts[$key] ?? [];
            if ($given === []) {
                return new Figure(0.0, 'prior_year_statements');
            }
            if (array_filter($given, fn ($p) => $p[0] === null)) {
                return Figure::missing();
            }

            return new Figure(round(array_sum(array_column($given, 0)), 2) + 0.0, 'prior_year_statements', 'ok', array_column($given, 1));
        };
        $out = ['figures' => [], 'file_totals' => $totals, 'ref' => ['type' => 'prior_file', 'name' => $file['name'] ?? null]];
        foreach (['financial_position', 'comprehensive_income'] as $statement) {
            foreach ($catalogue->statement($statement) as $i) {
                if ($i['type'] === 'line') {
                    $out['figures'][$i['key']] = $figure($i['key'], $statement);
                }
            }
        }
        if (isset($present['cash_flows'])) {
            foreach ($catalogue->statement('cash_flows') as $i) {
                if ($i['type'] === 'line') {
                    $out['cash_flows'][$i['key']] = $figure($i['key'], 'cash_flows');
                }
            }
        }

        return $out;
    }

    /** A row's line or total: the user's choice (`ignore`: none), else its exact or synonym match. */
    public static function rowLine(array $prior, int $block, array $row): ?string
    {
        $choice = $prior['rows']["{$block}:{$row['id']}"] ?? null;
        if ($choice !== null) {
            return $choice === 'ignore' ? null : $choice;
        }

        return in_array($row['certainty'], ['exact', 'synonym'], true) ? $row['match'] : null;
    }

    /** A prior year's own statements (no comparatives of its own). */
    private function current(ClassificationRun $run, string $version, array $options): array
    {
        return $this->statements->build($run, $version, $options + ['prior' => ['source' => 'first_year', 'fixed' => true]]);
    }

    /** Every line figure of the balance sheet and the income statement, re-labelled with the prior-year origin. */
    private function lines(array $built, string $origin, array $ref): array
    {
        $lines = [];
        foreach (['financial_position', 'comprehensive_income'] as $statement) {
            foreach ($built['statements'][$statement] as $r) {
                if ($r['type'] === 'line') {
                    $lines[$r['key']] = self::relabel($r['current'], $origin, $ref);
                }
            }
        }

        return $lines;
    }
}
