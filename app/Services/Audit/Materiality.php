<?php

namespace App\Services\Audit;

use App\Models\AuditPlan;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\FinancialReport;
use App\Services\Statements\CurrentYearStatements;
use App\Services\Statements\Figure;
use App\Services\Statements\ReportStatements;
use Throwable;

/**
 * The materiality of a client's year (ISA 320, الأهمية النسبية), in the four steps of the firm's working paper (2003):
 *  1. the measures, this year and last (total assets, gross profit, profit before zakat, net profit, revenue; total
 *     liabilities and equity when wanted), from the year's financial statements and their comparatives;
 *  2. a percentage on each basis taken (by default total assets 1%, revenue 1%, profit before zakat 10%), its materiality, and
 *     two more rows: the highest and the average of them;
 *  3. the preliminary materiality: one of those rows (a basis, the highest or the average), the overall materiality;
 *  4. the levels under it: the performance materiality (50%) and the clearly trivial threshold (5%), both of the overall.
 * With the justification the accountant writes. Then the statement lines at or above the performance materiality (to test).
 * A basis that is nil or negative (a loss) gives no materiality and is left out of the highest and the average.
 * Config: audit.materiality.
 */
final class Materiality
{
    /** The measures and the statement line each is. */
    public const MEASURES = [
        'total_assets' => 'sofp.total_assets', 'gross_profit' => 'is.gross_profit', 'profit_before_zakat' => 'is.profit_before_zakat',
        'net_profit' => 'is.net_profit', 'revenue' => 'is.revenue', 'total_liabilities' => 'sofp.total_liabilities', 'total_equity' => 'sofp.total_equity',
    ];

    public const AGGREGATES = ['highest', 'average'];

    public function __construct(private readonly ReportStatements $statements) {}

    /**
     * @return array{report: ?FinancialReport, figures: array<string, ?float>, prior: array<string, ?float>, benchmarks: list<array>, aggregates: array,
     *               chosen: array, overall: ?float, overall_rounded: ?float, performance: ?float, trivial: ?float, prior_overall: ?float,
     *               significant: list<array>, proposed: ?string, saved: bool, lines: array}
     */
    public const SOURCES = ['statements', 'trial_balance'];

    /**
     * @param ?string $source the measures' source to show (`statements`: the year's financial statements and their comparatives;
     *                        `trial_balance`: the year's classified trial balance as it is, before the report's entries and the
     *                        zakat charge, last year's balance sheet from its opening columns); null: the one saved, else statements
     */
    public function build(Company $company, int $year, ?string $source = null): array
    {
        $report = self::report($company, $year);
        $plan0 = AuditPlan::of($company, $year);
        $source = in_array($source, self::SOURCES, true) ? $source : ($plan0->materiality['source'] ?? 'statements');
        $run = $source === 'trial_balance' ? self::run($company, $report) : null;
        $lines = match (true) {
            $source === 'trial_balance' => $run === null ? [] : $this->linesFromTrialBalance($run, $report?->template_version ?? config('financial_statements.template_version', 'sa-ifrs-smes-3')),
            default => $report === null ? [] : $this->lines($report),
        };
        $figures = $prior = [];
        foreach (self::MEASURES as $key => $line) {
            $figures[$key] = $lines[$line]['amount'] ?? null;
            $prior[$key] = $lines[$line]['prior'] ?? null;
        }

        $cfg = (array) config('audit.materiality');
        $plan = $plan0;
        $saved = (array) ($plan->materiality ?? []);
        $percents = (array) ($saved['percents'] ?? []);
        $taken = (array) ($saved['bases'] ?? array_keys(array_filter($cfg['bases'], fn ($b) => $b['taken'])));

        // 2. Each basis: its percentage and the materiality it gives, this year and last.
        $benchmarks = [];
        foreach ($cfg['bases'] as $key => $b) {
            $pct = (float) ($percents[$key] ?? $b['percent']);
            $amount = $figures[$key] ?? null;
            $usable = $amount !== null && $amount > 0.005;
            $benchmarks[] = ['key' => $key, 'amount' => $amount, 'prior' => $prior[$key] ?? null, 'percent' => $pct, 'taken' => in_array($key, $taken, true),
                'usable' => $usable, 'materiality' => $usable ? round($amount * $pct / 100, 2) : null,
                'prior_materiality' => ($prior[$key] ?? 0) > 0.005 ? round($prior[$key] * $pct / 100, 2) : null];
        }
        $of = fn (string $field) => array_values(array_filter(array_map(fn ($b) => $b['taken'] ? $b[$field] : null, $benchmarks), fn ($v) => $v !== null));
        $aggregates = [];
        foreach (['materiality', 'prior_materiality'] as $field) {
            $values = $of($field);
            $aggregates['highest'][$field] = $values === [] ? null : max($values);
            $aggregates['average'][$field] = $values === [] ? null : round(array_sum($values) / count($values), 2);
        }

        // 3. The preliminary materiality: the basis, the highest or the average chosen.
        $proposed = $this->propose($benchmarks);
        $choice = $saved['choice'] ?? $saved['benchmark'] ?? $proposed;
        $pick = function (?string $choice, string $field) use ($benchmarks, $aggregates) {
            if ($choice === null) {
                return null;
            }
            if (in_array($choice, self::AGGREGATES, true)) {
                return $aggregates[$choice][$field];
            }
            foreach ($benchmarks as $b) {
                if ($b['key'] === $choice) {
                    return $b[$field];
                }
            }

            return null;
        };
        $overall = $pick($choice, 'materiality');
        $round = (bool) ($saved['round'] ?? false);
        $final = $overall === null ? null : ($round ? self::roundDown($overall) : $overall);

        // 4. The levels under it.
        $pmPercent = (float) ($saved['performance_percent'] ?? $cfg['performance_default']);
        $trivialPercent = (float) ($saved['trivial_percent'] ?? $cfg['trivial_default']);
        $performance = $final === null ? null : round($final * $pmPercent / 100, 2);
        $trivial = $final === null ? null : round($final * $trivialPercent / 100, 2);

        // The lines at or above the performance materiality: significant, to be tested (the totals left out).
        $significant = [];
        if ($performance !== null && $performance > 0) {
            foreach ($lines as $key => $l) {
                if (! $l['total'] && abs($l['amount']) >= $performance) {
                    $significant[] = ['key' => $key, 'label' => $l['label'], 'amount' => $l['amount'], 'times' => round(abs($l['amount']) / $performance, 1)];
                }
            }
            usort($significant, fn ($a, $b) => abs($b['amount']) <=> abs($a['amount']));
        }

        return ['report' => $report, 'source' => $source, 'run' => $run, 'figures' => $figures, 'prior' => $prior, 'benchmarks' => $benchmarks, 'aggregates' => $aggregates,
            'proposed' => $proposed, 'saved' => $saved !== [],
            'chosen' => ['benchmark' => $choice, 'choice' => $choice, 'percent' => in_array($choice, self::AGGREGATES, true) ? null : collect($benchmarks)->firstWhere('key', $choice)['percent'] ?? null,
                'performance_percent' => $pmPercent, 'trivial_percent' => $trivialPercent, 'round' => $round, 'reason' => $saved['reason'] ?? null],
            'overall' => $overall, 'overall_rounded' => $final, 'performance' => $performance, 'trivial' => $trivial,
            'prior_overall' => $pick($choice, 'prior_materiality'), 'significant' => $significant, 'lines' => $lines,
            // The version approved (in force): what the samples, the plan and the misstatements rest on.
            'approved' => ($v = \App\Models\MaterialityVersion::current($company, $year)) === null ? null
                : ['id' => $v->id, 'version' => $v->version, 'benchmark' => $v->benchmark, 'overall' => $v->overall, 'performance' => $v->performance,
                    'trivial' => $v->trivial, 'rationale' => $v->rationale, 'approved_name' => $v->approved_name, 'approved_at' => $v->approved_at?->toDateString(),
                    'specific' => $v->specific ?? []]];
    }

    /** The accountant's choice, kept on the year's plan. */
    public function save(Company $company, int $year, array $choice, ?int $userId): void
    {
        $plan = AuditPlan::of($company, $year);
        $plan->materiality = $choice;
        $plan->updated_by = $userId;
        $plan->save();
    }

    /**
     * The row proposed: the profit before zakat when the year made a profit of a steady size (at least the configured share of
     * the revenue), else the revenue, else the total assets; only a basis taken and usable.
     */
    private function propose(array $benchmarks): ?string
    {
        $b = array_column($benchmarks, null, 'key');
        $ok = fn (string $k) => ($b[$k]['taken'] ?? false) && ($b[$k]['usable'] ?? false);
        $share = (float) config('audit.materiality.steady_profit_share', 3) / 100;
        if ($ok('profit_before_zakat') && ($b['revenue']['amount'] ?? 0) > 0 && $b['profit_before_zakat']['amount'] >= $share * $b['revenue']['amount']) {
            return 'profit_before_zakat';
        }
        foreach (['revenue', 'total_assets', 'gross_profit', 'net_profit', 'total_equity', 'total_liabilities'] as $k) {
            if ($ok($k)) {
                return $k;
            }
        }

        return null;
    }

    /** The year's trial balance: the run of the year's statements, else the client's latest classified one; null without one. */
    public static function run(Company $company, ?FinancialReport $report): ?ClassificationRun
    {
        $run = $report ? ClassificationRun::withoutGlobalScopes()->where('status', 'done')->find($report->classification_run_id) : null;

        return $run ?? ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'done')->latest()->first();
    }

    /**
     * The lines as the classified trial balance gives them, with nothing of the report's (no entries, no zakat charge, no
     * overrides): this year's closing balances; last year's balance sheet from the opening columns (the income statement
     * has no prior year in one year's trial balance).
     */
    private function linesFromTrialBalance(ClassificationRun $run, string $template): array
    {
        try {
            $built = app(CurrentYearStatements::class)->build($run, $template, ['prior' => ['source' => 'none']]);
        } catch (Throwable) {
            return [];
        }

        return $this->fromStatements($built['statements']);
    }

    /** Down to a round figure: to the thousand from 10,000, to the hundred below it (materiality is never rounded up). */
    public static function roundDown(float $amount): float
    {
        $step = $amount >= 10000 ? 1000 : ($amount >= 1000 ? 100 : 10);

        return floor($amount / $step) * $step;
    }

    /** The client's report of the year: the one most recently worked on whose period ends in it; null without one. */
    public static function report(Company $company, int $year): ?FinancialReport
    {
        return FinancialReport::withoutGlobalScopes()->where('company_id', $company->id)->whereYear('period_end', $year)->orderByDesc('updated_at')->first();
    }

    /**
     * The statements' lines of the year (balance sheet and income statement): key → label, amount and last year's (as the
     * statement shows them, expenses negative), whether it is a total. Missing figures are left out.
     *
     * @return array<string, array{label: string, amount: float, prior: ?float, total: bool}>
     */
    private function lines(FinancialReport $report): array
    {
        try {
            $built = $this->statements->build($report);
        } catch (Throwable) {
            return [];
        }
        return $this->fromStatements($built['statements']);
    }

    /** The balance sheet's and income statement's lines of built statements, with last year's where they have it. */
    private function fromStatements(array $statements): array
    {
        $out = [];
        foreach (['financial_position', 'comprehensive_income'] as $st) {
            foreach ($statements[$st] as $r) {
                $f = $r['current'] ?? null;
                if (! isset($r['key']) || ! $f instanceof Figure || $f->isMissing()) {
                    continue;
                }
                $p = $r['comparative'] ?? null;
                $out[$r['key']] = ['label' => (string) ($r['label_ar'] ?? $r['key']), 'amount' => round($f->amount, 2),
                    'prior' => $p instanceof Figure && ! $p->isMissing() ? round($p->amount, 2) : null, 'total' => ($r['type'] ?? 'line') !== 'line'];
            }
        }

        return $out;
    }
}
