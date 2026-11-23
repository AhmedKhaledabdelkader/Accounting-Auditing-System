<?php

namespace App\Services\Engagement;

use App\Models\AuditLog;
use App\Models\AuditPlan;
use App\Models\Company;
use App\Models\MaterialityVersion;
use App\Models\User;
use App\Services\Audit\Materiality;

/**
 * The materiality's versions (ISA 320.12–14): the working paper (step 6) is a draft the accountant may change; approving it
 * keeps its figures as a version (1, 2 …) that is never changed nor deleted. The justification is required; a basis must be
 * chosen (the highest and the average are shown to compare, never kept as the basis); a version after the first says why it
 * changed. The samples drawn on an earlier version are then to be reassessed (Engagement: SAMPLES_REASSESS).
 */
final class MaterialityVersions
{
    public const CHOICE_KEYS = ['source', 'percents', 'bases', 'choice', 'performance_percent', 'trivial_percent', 'round'];

    public function __construct(private readonly Materiality $materiality) {}

    /** The working paper's choices a version is compared on (the reason left out). */
    public static function choiceOf(?array $m): array
    {
        $c = array_intersect_key((array) $m, array_flip(self::CHOICE_KEYS));
        ksort($c);
        $c['percents'] = array_map('floatval', (array) ($c['percents'] ?? []));
        ksort($c['percents']);
        foreach (['performance_percent', 'trivial_percent'] as $k) {
            $c[$k] = isset($c[$k]) ? (float) $c[$k] : null;
        }

        return $c;
    }

    /**
     * Approves the year's working paper as a new version.
     *
     * @param  list<array{area: string, amount: mixed, reason: ?string}>  $specific
     * @return MaterialityVersion|string the version, or why it can't be (a key of engagement.materiality.errors)
     */
    public function approve(Company $company, int $year, User $user, ?string $rationale, ?string $changeReason, array $specific = [], string $stage = 'planning'): MaterialityVersion|string
    {
        $plan = AuditPlan::of($company, $year);
        $m = $this->materiality->build($company, $year);
        $rationale = trim((string) ($rationale ?? $plan->materiality['reason'] ?? ''));
        $choice = $m['chosen']['choice'];
        $current = MaterialityVersion::current($company, $year);
        $benchmark = collect($m['benchmarks'])->firstWhere('key', $choice);

        $error = match (true) {
            empty($plan->materiality) => 'not_saved',
            in_array($choice, Materiality::AGGREGATES, true) => 'aggregate_choice',
            $benchmark === null || $m['overall_rounded'] === null || $m['overall_rounded'] <= 0 => 'no_amount',
            $rationale === '' => 'rationale_required',
            $current !== null && self::choiceOf($current->choice) == self::choiceOf($plan->materiality) && $current->stage === $stage => 'unchanged',
            $current !== null && trim((string) $changeReason) === '' => 'change_reason_required',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        $areas = array_keys((array) config('audit_procedures.areas'));
        $specific = array_values(array_filter(array_map(fn ($s) => ['area' => (string) ($s['area'] ?? ''), 'amount' => is_numeric($s['amount'] ?? null) ? round((float) $s['amount'], 2) : null,
            'reason' => trim((string) ($s['reason'] ?? '')) ?: null], $specific), fn ($s) => in_array($s['area'], $areas, true) && $s['amount'] !== null && $s['amount'] > 0));

        $version = MaterialityVersion::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year,
            'version' => ($current?->version ?? 0) + 1, 'stage' => $stage, 'benchmark' => $choice, 'benchmark_amount' => (float) $benchmark['amount'],
            'pct' => (float) $benchmark['percent'], 'overall' => (float) $m['overall_rounded'], 'performance_pct' => (float) $m['chosen']['performance_percent'],
            'performance' => (float) $m['performance'], 'trivial_pct' => (float) $m['chosen']['trivial_percent'], 'trivial' => (float) $m['trivial'],
            'rationale' => $rationale, 'change_reason' => $current === null ? null : trim((string) $changeReason), 'specific' => $specific,
            'choice' => self::choiceOf($plan->materiality) + ['reason' => $plan->materiality['reason'] ?? null], 'status' => 'approved',
            'approved_by' => $user->id, 'approved_name' => $user->name, 'approved_at' => now()]);
        AuditLog::record($company, $year, 'approve', 'materiality_version', $version->id, $current?->only(['version', 'overall', 'performance', 'trivial']),
            $version->only(['version', 'benchmark', 'overall', 'performance', 'trivial', 'rationale', 'change_reason']));

        return $version;
    }

    /** The versions of the year, the last first. */
    public static function of(Company $company, int $year)
    {
        return MaterialityVersion::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->orderByDesc('version')->get();
    }
}
