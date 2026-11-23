<?php

namespace App\Services\Engagement;

use App\Models\AdjustingEntry;
use App\Models\AuditFinding;
use App\Models\AuditPlan;
use App\Models\AuditRisk;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\EngagementSignoff;
use App\Models\FinancialReport;
use App\Models\MaterialityVersion;
use App\Models\PreparedPaper;
use App\Models\ReviewNote;
use App\Models\User;
use App\Services\Audit\AuditProcedures;
use App\Services\Audit\Materiality;
use App\Services\Audit\RevenueSampling;
use App\Support\ClientSteps;

/**
 * The engagement file of a client's year (config/engagement.php): every step with its status, its sign-offs and whether it
 * is locked, the blockers (code, message, link), the current phase and the next step. Everything is decided here, once: the
 * pages and the API only show it.
 *
 * A step's status: not_started, in_progress, waiting_client (the client owes documents), ready_for_review (its work is done:
 * and, for a step that is signed, signed by its preparer), approved (signed by a reviewer — the materiality: a version approved).
 * A sign-off holds the step's content as it was (a hash): a content changed since makes it void, so the step is signed again.
 * A step whose `requires` aren't done is locked (its page refuses); its `after` are said, not enforced.
 */
final class Engagement
{
    public const STATUSES = ['not_started', 'in_progress', 'waiting_client', 'ready_for_review', 'approved'];

    /** @var array<string, array> one build per client and year in a request */
    private array $memo = [];

    public static function steps(): array
    {
        return (array) config('engagement.steps');
    }

    public function forget(Company $company, int $year): void
    {
        unset($this->memo[spl_object_id(request())."|{$company->id}:{$year}"]);
    }

    /**
     * @return array{engagement_id: string, company_id: string, year: int, current_phase: ?string, next_step: ?string, steps: list<array>, blockers: list<array>, facts: array}
     */
    public function status(Company $company, int $year): array
    {
        // Kept for this request only (a controller, and so this service, may outlive one request).
        $key = spl_object_id(request())."|{$company->id}:{$year}";

        return $this->memo[$key] ??= $this->build($company, $year);
    }

    /** One step of the status. */
    public function step(Company $company, int $year, string $key): array
    {
        return collect($this->status($company, $year)['steps'])->firstWhere('step_key', $key);
    }

    /** Whether a step's page is locked (its requirements not done), when locks are enforced. */
    public function locked(Company $company, int $year, string $key): bool
    {
        return config('engagement.enforce_locks') && ($this->step($company, $year, $key)['locked'] ?? false);
    }

    /** The blockers of one step (the hard ones first). */
    public function blockersOf(Company $company, int $year, string $key): array
    {
        $b = array_values(array_filter($this->status($company, $year)['blockers'], fn ($x) => $x['step_key'] === $key));
        usort($b, fn ($x, $y) => $x['soft'] <=> $y['soft']);

        return $b;
    }

    private function build(Company $company, int $year): array
    {
        $f = $this->facts($company, $year);
        $steps = self::steps();
        $signoffs = EngagementSignoff::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->get()->groupBy('step_key');
        $notes = ReviewNote::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->where('status', '!=', ReviewNote::CLOSED)
            ->get(['step_key'])->countBy('step_key')->all();
        $signed = (array) config('engagement.signed');

        $out = [];
        foreach ($steps as $key => $def) {
            [$started, $complete, $waiting] = $f['work'][$key];
            $hash = $f['hash'][$key];
            $sign = [];
            foreach ($signoffs->get($key, collect()) as $s) {
                $sign[$s->role] = ['user_id' => $s->user_id, 'name' => $s->user_name, 'at' => $s->signed_at?->toDateTimeString(), 'valid' => $s->content_hash === $hash];
            }
            $prepared = ($sign['prepared']['valid'] ?? false);
            $done = $complete && (! in_array($key, $signed, true) || $prepared);
            $status = match (true) {
                $key === 'materiality' && $f['version'] !== null => 'approved',
                $key !== 'materiality' && $done && (($sign['reviewed']['valid'] ?? false) || ($sign['approved']['valid'] ?? false)) => 'approved',
                $key !== 'materiality' && $done => 'ready_for_review',
                $waiting => 'waiting_client',
                $started => 'in_progress',   // nothing done yet (an empty register isn't progress)
                default => 'not_started',
            };
            $out[$key] = ['n' => $def['n'], 'phase' => $def['phase'], 'step_key' => $key, 'status' => $status, 'complete' => $complete,
                'prepared_by' => $sign['prepared']['name'] ?? null, 'reviewed_by' => $sign['reviewed']['name'] ?? null, 'approved_by' => $sign['approved']['name'] ?? null,
                'signoffs' => $sign, 'open_notes' => $notes[$key] ?? 0, 'locked' => false, 'hash' => $hash,
                'link' => $this->link($company, $year, $key)];
        }

        $done = fn (string $k) => $k === 'materiality' ? $out[$k]['status'] === 'approved' : in_array($out[$k]['status'], ['ready_for_review', 'approved'], true);
        $blockers = [];
        $add = function (string $code, string $step, array $params = [], bool $soft = false, ?string $linkTo = null) use (&$blockers, $company, $year) {
            $blockers[] = ['code' => $code, 'step_key' => $step, 'soft' => $soft,
                'message_ar' => __("engagement.blockers.{$code}", $params, 'ar'), 'message_en' => __("engagement.blockers.{$code}", $params, 'en'),
                'link' => $this->link($company, $year, $linkTo ?? $step)];
        };
        $name = fn (string $k, string $lang) => __("engagement.steps.{$k}", [], $lang);

        foreach ($steps as $key => $def) {
            foreach ($def['requires'] as $dep) {
                if (! $done($dep)) {
                    $out[$key]['locked'] = true;
                    $dep === 'materiality' ? $add('MATERIALITY_NOT_APPROVED', $key, [], false, 'materiality')
                        : $add('STEP_NOT_DONE', $key, ['step' => $name($dep, app()->getLocale())], false, $dep);
                }
            }
            foreach ($def['after'] as $dep) {
                if (! $done($dep)) {
                    $add('STEP_BEFORE_NOT_DONE', $key, ['step' => $name($dep, app()->getLocale())], true, $dep);
                }
            }
            if ($out[$key]['open_notes'] > 0) {
                $add('OPEN_REVIEW_NOTES', $key, ['count' => $out[$key]['open_notes']], true);
            }
        }

        // The step's own blockers.
        foreach ($f['blockers'] as [$code, $step, $params, $soft]) {
            $add($code, $step, $params, $soft);
        }
        // The release: nothing open anywhere, the misstatements within the materiality for an unmodified opinion.
        $allNotes = array_sum($notes);
        if ($allNotes > 0) {
            $add('NOTES_BLOCK_RELEASE', 'release', ['count' => $allNotes]);
        }

        // The current phase: the first with a step not done; the next step: the first not done and not locked.
        $current = $next = null;
        foreach ($out as $key => $s) {
            if (! $done($key)) {
                $current ??= $s['phase'];
                if (! $s['locked'] || ! config('engagement.enforce_locks')) {
                    $next ??= $key;
                }
            }
        }

        return ['engagement_id' => "{$company->id}:{$year}", 'company_id' => $company->id, 'year' => $year, 'current_phase' => $current, 'next_step' => $next,
            'steps' => array_values($out), 'blockers' => $blockers, 'facts' => $f['summary']];
    }

    /**
     * What the steps rest on: for each, [started, complete, waiting on the client], its content's hash, its own blockers.
     */
    private function facts(Company $company, int $year): array
    {
        $cs = ClientSteps::of($company);
        $report = Materiality::report($company, $year);
        $run = Materiality::run($company, $report);
        $plan = AuditPlan::of($company, $year);
        $version = MaterialityVersion::current($company, $year);
        $risks = AuditRisk::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->get();
        $requests = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->whereNull('paper')->get(['id', 'status', 'optional']);
        $prepared = PreparedPaper::withoutGlobalScopes()->where('company_id', $company->id)->where('status', PreparedPaper::DONE)->get(['kind', 'updated_at']);
        $findings = AuditFinding::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->get(['status', 'updated_at']);
        $entries = AdjustingEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->get(['id', 'status', 'updated_at']);
        $samples = RevenueSampling::samples($plan->sampling);
        $blockers = [];

        // 1. Acceptance: the client's details, registration and articles.
        $acceptance = [$cs['details']['done'] || $cs['registration']['done'], $cs['details']['done'] && $cs['registration']['done'] && $cs['articles']['done'], false];
        // 2–4. The trial balance, last year's, the classification.
        $unclassified = $run === null ? null : count(array_filter((array) ($run->result['data'] ?? []), fn ($r) => ($r['row_type'] ?? 'account') === 'account' && ($r['sub_class'] ?? null) === null));
        $priorSource = $report?->prior_figures['source'] ?? 'none';
        $prior = [$cs['prior']['done'], in_array($priorSource, ['statements', 'report', 'run', 'manual'], true) || ($report === null && $cs['prior']['done']), false];
        if ($run !== null && $unclassified > 0) {
            $blockers[] = ['UNCLASSIFIED_ROWS', 'classification', ['count' => $unclassified], false];
        }

        // 5. The risks: none left as suggested; every significant one answered by a test of details.
        $suggested = $risks->where('status', AuditRisk::SUGGESTED)->count();
        $accepted = $risks->where('status', AuditRisk::ACCEPTED);
        if ($suggested > 0) {
            $blockers[] = ['SUGGESTED_RISKS', 'risks', ['count' => $suggested], false];
        }
        $unanswered = RiskRegister::unanswered($accepted, $plan);
        foreach ($unanswered as $r) {
            $blockers[] = ['SIGNIFICANT_RISK_UNANSWERED', 'procedures', ['risk' => mb_substr($r->description, 0, 80)], false];
        }

        // 6. The materiality: a version approved; a draft changed since is said.
        if ($version !== null && ! empty($plan->materiality) && ($version->choice ?? null) !== null && MaterialityVersions::choiceOf($plan->materiality) != MaterialityVersions::choiceOf($version->choice)) {
            $blockers[] = ['MATERIALITY_DRAFT_CHANGED', 'materiality', ['version' => $version->version], true];
        }

        // 7. The plan: every area signed or not applicable, no significant risk without its test.
        $procedures = [! empty($plan->procedures['areas']), AuditProcedures::complete($plan->procedures) && $unanswered === [], false];

        // 8. The evidence.
        $awaited = $requests->filter(fn ($r) => ! $r->optional && in_array($r->status, AuditProcedures::AWAITED, true))->count();
        $closed = $requests->whereIn('status', [DocumentRequest::ACCEPTED, DocumentRequest::NOT_APPLICABLE])->count();
        if ($awaited > 0) {
            $blockers[] = ['REQUIREMENTS_AWAITED', 'requirements', ['count' => $awaited], true];
        }
        $papers = $prepared->where('kind', 'paper');
        $recalc = $prepared->where('kind', 'recalc');

        // 9. The samples: each fully tested, on the materiality in force.
        $untested = $reassess = 0;
        foreach ($samples as $s) {
            $results = array_filter((array) ($s['results'] ?? []), fn ($r) => ($r['audited'] ?? null) !== null);
            $untested += count($results) < count($s['selected']) ? 1 : 0;
            $reassess += $version !== null && ($s['materiality_version_id'] ?? null) !== $version->id ? 1 : 0;
        }
        if ($untested > 0) {
            $blockers[] = ['SAMPLES_NOT_TESTED', 'sampling', ['count' => $untested], false];
        }
        if ($reassess > 0) {
            $blockers[] = ['SAMPLES_REASSESS', 'sampling', ['count' => $reassess], false];
        }

        // 10. The findings and the entries.
        $openFindings = $findings->where('status', AuditFinding::OPEN)->count();
        $undecided = $entries->whereIn('status', [AdjustingEntry::PROPOSED, AdjustingEntry::ACCEPTED])->count();
        if ($undecided > 0) {
            $blockers[] = ['ENTRIES_UNDECIDED', 'adjustments', ['count' => $undecided], false];
            $blockers[] = ['ENTRIES_UNDECIDED', 'zakat', ['count' => $undecided], true];
            $blockers[] = ['ENTRIES_UNDECIDED', 'statements', ['count' => $undecided], true];
        }

        // 11–12. Zakat and the statements.
        $zakat = ! empty($report?->zakat_tax['applied']) || ! empty($report?->zakat_tax['inputs']);
        $stale = $report !== null && $run !== null && $report->run_result_hash !== null && $report->run_result_hash !== app(\App\Services\Statements\ReportSource::class)->hash($run);
        if ($stale) {
            $blockers[] = ['STATEMENTS_STALE', 'statements', [], false];
        }

        // 13. Completion: the three answers.
        $completion = (array) ($plan->completion ?? []);
        $answered = count(array_filter([$completion['subsequent_events'] ?? null, $completion['going_concern'] ?? null, $completion['representations'] ?? null]));

        // 14. The release: the opinion against the misstatements and the going concern.
        $release = (array) ($plan->release ?? []);
        $summary = Misstatements::totals($company, $year, $plan, $version);
        $opinion = $release['opinion'] ?? null;
        if ($summary['above_materiality'] && in_array($opinion, [null, 'unmodified'], true)) {
            $blockers[] = ['MISSTATEMENTS_ABOVE_MATERIALITY', 'release', ['total' => number_format($summary['uncorrected'], 2), 'overall' => number_format((float) $version?->overall, 2)], false];
        }
        $gc = $completion['going_concern'] ?? null;
        if (($gc === 'uncertainty_not_disclosed' && in_array($opinion, [null, 'unmodified'], true)) || ($gc === 'not_appropriate' && $opinion !== null && $opinion !== 'adverse')) {
            $blockers[] = ['GOING_CONCERN_CONFLICT', 'release', [], false];
        }
        if ($report === null) {
            $blockers[] = ['NO_STATEMENTS', 'release', [], false];
        }

        $work = [
            'acceptance' => $acceptance,
            'trial_balance' => [$run !== null, $run !== null, false],
            'prior' => $prior,
            'classification' => [$run !== null, $run !== null && $unclassified === 0, false],
            'risks' => [$risks->isNotEmpty(), $risks->isNotEmpty() && $suggested === 0, false],
            'materiality' => [! empty($plan->materiality), $version !== null, false],
            'procedures' => $procedures,
            'requirements' => [$requests->isNotEmpty(), $requests->isNotEmpty() && $closed === $requests->count(), $awaited > 0],
            'papers' => [$papers->isNotEmpty(), $papers->isNotEmpty(), false],
            'recalc' => [$recalc->isNotEmpty(), $recalc->isNotEmpty(), false],
            'sampling' => [$samples !== [], $samples !== [] && $untested === 0 && $reassess === 0, false],
            'findings' => [$findings->isNotEmpty(), $findings->isNotEmpty() && $openFindings === 0, false],
            'adjustments' => [$entries->isNotEmpty() || $summary['count'] > 0, $undecided === 0, false],
            'zakat' => [$zakat, $zakat && $undecided === 0, false],
            'statements' => [$report !== null, $report !== null && ! $stale, false],
            'completion' => [$completion !== [], $answered === 3, false],
            // The release's work: the opinion chosen (the preparer signs it, the partner approves it, then it is issued).
            'release' => [$release !== [], isset($release['opinion']), false],
        ];
        $j = fn ($v) => sha1(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $hash = [
            'acceptance' => $j([$company->name_ar, $company->name_en, $company->client_name, $company->period_start, $company->period_end, $company->profile['registration'] ?? null, $company->profile['articles'] ?? null]),
            'trial_balance' => $j($run?->id),
            'prior' => $j([$report?->id, $report?->prior_figures]),
            'classification' => $j([$run?->id, $run?->updated_at?->toIso8601String()]),
            'risks' => $j($risks->map(fn ($r) => [$r->id, $r->status, $r->updated_at?->toIso8601String()])->values()),
            'materiality' => $j([$version?->id, $plan->materiality]),
            'procedures' => $j([$plan->procedures, $accepted->map(fn ($r) => [$r->id, $r->procedures])->values()]),
            'requirements' => $j($requests->map(fn ($r) => [$r->id, $r->status])->values()),
            'papers' => $j($papers->max('updated_at')),
            'recalc' => $j($recalc->max('updated_at')),
            'sampling' => $j([$plan->sampling, $version?->id]),
            'findings' => $j([$findings->count(), $findings->max('updated_at')]),
            'adjustments' => $j([$entries->map(fn ($e) => [$e->id, $e->status, $e->updated_at?->toIso8601String()])->values(), $summary['fingerprint']]),
            'zakat' => $j($report?->zakat_tax),
            'statements' => $j([$report?->id, $report?->updated_at?->toIso8601String(), $report?->run_result_hash]),
            'completion' => $j($completion),
            'release' => $j(array_diff_key($release, array_flip(['issued_at', 'issued_by', 'issued_name', 'snapshot']))),
        ];

        return ['work' => $work, 'hash' => $hash, 'blockers' => $blockers, 'version' => $version,
            'summary' => ['report_id' => $report?->id, 'run_id' => $run?->id, 'materiality_version' => $version?->version, 'overall' => $version?->overall,
                'performance' => $version?->performance, 'trivial' => $version?->trivial, 'misstatements' => $summary,
                'risks' => ['total' => $risks->count(), 'suggested' => $suggested, 'significant' => $accepted->where('is_significant', true)->count()],
                'entries' => ['total' => $entries->count(), 'undecided' => $undecided], 'requests_awaited' => $awaited, 'unclassified' => $unclassified]];
    }

    private function link(Company $company, int $year, string $key): string
    {
        $route = self::steps()[$key]['route'];

        return route($route, ['company' => $company, 'year' => $year]);
    }

    /**
     * Signs a step (or takes the sign-off back). The preparer signs a step whose work is done; the reviewer after the preparer,
     * someone else, with no note of the step open; the partner approves the release once nothing blocks it.
     *
     * @return ?string the reason it can't be signed (a key of engagement.sign_errors), null when done
     */
    public function sign(Company $company, int $year, string $key, string $role, User $user, bool $off = false): ?string
    {
        $s = $this->step($company, $year, $key);
        $existing = EngagementSignoff::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->where('step_key', $key);
        if ($off) {
            $roles = $role === 'prepared' ? EngagementSignoff::ROLES : ($role === 'reviewed' ? ['reviewed', 'approved'] : [$role]);
            $gone = (clone $existing)->whereIn('role', $roles)->get();
            (clone $existing)->whereIn('role', $roles)->delete();
            \App\Models\AuditLog::record($company, $year, 'unsign', 'step', $key, $gone->map->only(['role', 'user_name'])->all());
            $this->forget($company, $year);

            return null;
        }
        if (! in_array($role, EngagementSignoff::ROLES, true)) {
            return 'unknown_role';
        }
        if ($s['locked'] && config('engagement.enforce_locks')) {
            return 'locked';
        }
        $preparer = $s['signoffs']['prepared'] ?? null;
        if ($role === 'prepared') {
            if (! $s['complete']) {
                return 'not_complete';
            }
            // The step's own blockers (what it rests on is the lock's, checked above).
            $hard = array_filter($this->blockersOf($company, $year, $key), fn ($b) => ! $b['soft']
                && ! in_array($b['code'], ['NOTES_BLOCK_RELEASE', 'STEP_NOT_DONE', 'MATERIALITY_NOT_APPROVED'], true));
            if ($key !== 'release' && $hard !== []) {
                return 'blocked';
            }
        } else {
            if ($preparer === null || ! $preparer['valid']) {
                return 'not_prepared';
            }
            if ((int) $preparer['user_id'] === (int) $user->id) {
                return 'same_person';
            }
            if ($s['open_notes'] > 0) {
                return 'notes_open';
            }
            if ($role === 'approved') {
                if ($key !== 'release') {
                    return 'partner_release_only';
                }
                if (array_filter($this->blockersOf($company, $year, 'release'), fn ($b) => ! $b['soft']) !== []) {
                    return 'blocked';
                }
            }
        }
        EngagementSignoff::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id, 'year' => $year, 'step_key' => $key, 'role' => $role],
            ['office_id' => $company->office_id, 'user_id' => $user->id, 'user_name' => $user->name, 'content_hash' => $s['hash'], 'signed_at' => now()]);
        \App\Models\AuditLog::record($company, $year, 'sign', 'step', $key, null, ['role' => $role, 'hash' => $s['hash']]);
        $this->forget($company, $year);

        return null;
    }

    /** The year of the client's engagement asked for (`?year=`), else the client's period year. */
    public static function year(\Illuminate\Http\Request $request, Company $company): int
    {
        $y = (int) $request->input('year', 0);

        return $y >= 2000 && $y <= 2100 ? $y : ($company->periodYear() ?? now()->year - 1);
    }
}
