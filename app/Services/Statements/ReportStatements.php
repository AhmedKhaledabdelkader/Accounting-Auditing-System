<?php

namespace App\Services\Statements;

use App\Models\FinancialReport;

/**
 * A report's statements: its run, its own part, its prior-year comparatives, and its notes (the API state and the
 * preview read this). Plus whether it can be final (PR 12, Q20): each check keyed and its acknowledgement, and what
 * blocks a final version.
 */
final class ReportStatements
{
    public function __construct(private CurrentYearStatements $statements, private PriorYearResolver $prior, private ReportChecks $checks,
        private NotesBuilder $notes, private ReportIntake $intake, private ReportSource $source)
    {
    }

    /** @param array $options replaces parts of the report's own data (e.g. inputs being validated, `notes` choices) */
    public function build(FinancialReport $report, array $options = []): array
    {
        $options = $options + $report->statementOptions() + ['notes' => $report->notes ?? []];
        $prior   = $this->prior->resolve($report);
        $built   = $this->statements->build($report->run, $report->template_version, $options + ['prior' => $prior]);
        $built['checks'] = [...$built['checks'], ...$this->checks->period($report->period_start, $report->period_end,
            $report->comparative_start, $report->comparative_end, $options['inputs']['approval.date'] ?? null),
            ...$this->checks->priorFileTotals($prior['file_totals'] ?? [], $built['statements'], ($report->prior_figures['expenses_shown_positive'] ?? false) === true)];

        // Profit or loss in a label by its figures' sign (R11 keeps last year's lines, not a wrong sign).
        $built = ProfitLossWording::apply($built);

        // Notes (PR 11): visibility, numbers, wording, their questions and checks; the statements' note column.
        $notes = $this->notes->build($report, $built, $options['inputs'], $options['notes']);
        $built['notes']           = $notes['notes'];
        $built['narrative']       = $notes['narrative'];
        $built['going_concern']   = $notes['going_concern'];
        $built['zakat_base']      = $notes['zakat_base'];
        $built['intake']          = [...$built['intake'], ...$notes['intake']];
        $built['required_inputs'] = $this->intake->requiredMissing($built['intake']);
        $built['checks']          = [...$built['checks'], ...$notes['checks'], ...$this->checks->inputs($notes['intake'], [], []),
            ...$this->checks->runChanged($report->run_result_hash, $this->source->hash($report->run))];
        $built['missing']         = [...$built['missing'], ...$notes['missing']];
        $codes = [];
        foreach ($notes['checks'] as $c) {
            // The going-concern paragraph is added by itself (nothing to fix): no red mark on the line.
            if ($c['line_key'] !== null && $c['code'] !== 'going_concern_indicator') {
                $codes[$c['line_key']][$c['code']] = true;
            }
        }
        foreach (['financial_position', 'comprehensive_income'] as $statement) {
            $built['statements'][$statement] = array_map(fn ($r) => $r['type'] === 'line'
                ? ['note' => $notes['references'][$r['key']] ?? null, 'check_codes' => array_values(array_unique([...$r['check_codes'], ...array_keys($codes[$r['key']] ?? [])]))] + $r
                : $r, $built['statements'][$statement]);
        }

        $acknowledged = array_column($report->acknowledgements ?? [], null, 'key');
        foreach ($built['checks'] as $i => $c) {
            $key = ReportChecks::key($c);
            // An answer to confirm is never acknowledged away: saving it is the confirmation.
            $built['checks'][$i] = ['key' => $key] + $c + ['acknowledged' => $c['severity'] === 'warning' && $c['code'] !== 'input_to_confirm' && isset($acknowledged[$key])];
        }
        $built['blocking'] = $this->blocking($built);

        return $built;
    }

    /**
     * What stops a final version (Q20): errors, warnings not acknowledged, missing figures (a final never shows
     * «[ناقص]»), and anything still pending: movement suggestions and account mappings waiting for the user.
     *
     * @return list<array{reason: string, label_ar: string, count: int, label: string}> `label` in the request's language
     */
    private function blocking(array $built): array
    {
        $counts = [
            'errors'                  => count(array_filter($built['checks'], fn ($c) => $c['severity'] === 'error')),
            'warnings_unacknowledged' => count(array_filter($built['checks'], fn ($c) => $c['severity'] === 'warning' && ! $c['acknowledged'])),
            'missing'                 => count($built['missing']),
            'pending_suggestions'     => count(array_filter($built['suggestions'], fn ($s) => $s['status'] === 'pending')),
            'pending_mappings'        => count(array_filter($built['mapping']['rows'], fn ($r) => $r['status'] === 'pending_confirmation')),
        ];
        $out = [];
        foreach (array_filter($counts) as $reason => $count) {
            $out[] = ['reason' => $reason, 'label_ar' => __("statements.blocking.{$reason}", [], 'ar'), 'count' => $count, 'label' => __("statements.blocking.{$reason}")];
        }

        return $out;
    }
}
