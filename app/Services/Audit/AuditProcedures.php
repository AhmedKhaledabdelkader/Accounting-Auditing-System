<?php

namespace App\Services\Audit;

use App\Models\AuditPlan;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\User;

/**
 * 14. The audit program (برنامج المراجعة): each area of the audit (config/audit_procedures.php: acceptance, planning, risk,
 * the balance sheet's and the income statement's areas, completion) with its procedures, as the procedures tab of each working
 * paper. For each procedure the accountant says done, an exception found, or not applicable, with the reference to the work
 * (a paper, a sample) and a note; adds procedures of their own; writes the area's conclusion; then the preparer and the
 * reviewer sign it off. An area with no balance this year can be marked not applicable as a whole, with a reason.
 *
 * The area's balance comes from the statements' lines (Materiality's lines: the financial statements, or the trial balance when
 * that is the materiality's source), and it is significant at or above the performance materiality. Kept on the year's plan
 * (`audit_plans.procedures`): {areas: {key: {items: {code: {status, ref, note, by, at}}, custom: [{id, text, assertions, type}],
 * conclusion, na, na_reason, prepared: {by, name, at}, reviewed: {by, name, at}}}}. A change to an area signed off drops its
 * sign-offs: the area is signed again on what it now says.
 *
 * Each area is tied to the requirements (step 8): the documents asked of the client for that year in the area's sections, and
 * the working papers and recalculations it fills. The area shows them with their status; a procedure takes one of them as its
 * evidence (`request`); the preparer can't sign while a required one is still awaited (asked, sent back, or uploaded and not yet
 * accepted): the accountant accepts it, or marks it not applicable, in the requirements.
 */
final class AuditProcedures
{
    /** A requirement the area still waits for (a required one: an optional «إن وجد» never holds the sign-off). */
    public const AWAITED = [DocumentRequest::PENDING, DocumentRequest::RETURNED, DocumentRequest::UPLOADED];

    public function __construct(private readonly Materiality $materiality) {}

    /**
     * The year's requests of the client tied to an area: in one of its sections (all of it, or «section::word»: the ones whose
     * title holds the word), or one of its papers.
     *
     * @param  iterable<DocumentRequest>  $requests
     * @return list<array{id: int, number: ?int, title: string, section: ?string, status: string, optional: bool, files: int, awaited: bool}>
     */
    public static function requirementsOf(array $area, iterable $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            $hit = in_array($r->paper, $area['papers'] ?? [], true);
            foreach ($area['requirements'] ?? [] as $rule) {
                [$section, $word] = array_pad(explode('::', $rule, 2), 2, null);
                $hit = $hit || ($r->paper === null && $r->section === $section && ($word === null || mb_strpos((string) $r->title, $word) !== false));
            }
            if ($hit) {
                $out[] = ['id' => (int) $r->id, 'number' => $r->number, 'title' => (string) $r->title, 'section' => $r->section, 'status' => $r->status,
                    'optional' => (bool) $r->optional, 'files' => (int) ($r->files_count ?? 0),
                    'awaited' => ! $r->optional && in_array($r->status, self::AWAITED, true)];
            }
        }

        return $out;
    }

    /**
     * The areas of the program a request is tied to (shown on the requirements page, linking to the area).
     *
     * @return list<array{key: string, code: string, title: string}>
     */
    public static function areasOf(DocumentRequest $request): array
    {
        $out = [];
        foreach (self::catalogue() as $key => $a) {
            if (self::requirementsOf($a, [$request]) !== []) {
                $out[] = ['key' => $key, 'code' => $a['code'], 'title' => $a['title']];
            }
        }

        return $out;
    }

    /** The client's requests of the year, with how many files each holds. */
    public static function requests(Company $company, int $year)
    {
        return DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->withCount('files')
            ->orderBy('position')->get(['id', 'number', 'title', 'section', 'paper', 'status', 'optional']);
    }

    /** @return array<string, array{code: string, title: string, lines: list<string>, procedures: array<string, array>}> */
    public static function catalogue(): array
    {
        return config('audit_procedures.areas', []);
    }

    /**
     * The year's program: each area with its procedures and answers, its balance, its progress and its status; the totals.
     *
     * @return array{areas: list<array>, totals: array, m: array}
     */
    public function build(Company $company, int $year, ?array $m = null): array
    {
        $m ??= $this->materiality->build($company, $year);
        $plan = AuditPlan::of($company, $year);
        $saved = $plan->procedures['areas'] ?? [];
        // The approved materiality in force, else the working paper's draft.
        $pm = $m['approved']['performance'] ?? $m['performance'];
        $revenueSamples = count(array_filter(RevenueSampling::samples($plan->sampling), fn ($x) => ! str_starts_with((string) ($x['account'] ?? ''), 'tb:')));
        $allSamples = count(RevenueSampling::samples($plan->sampling));
        $totals = ['areas' => 0, 'procedures' => 0, 'done' => 0, 'exception' => 0, 'na' => 0, 'pending' => 0, 'prepared' => 0, 'reviewed' => 0, 'areas_na' => 0,
            'requirements' => 0, 'awaited' => 0];
        $requests = self::requests($company, $year);

        $areas = [];
        foreach (self::catalogue() as $key => $a) {
            $s = $saved[$key] ?? [];
            $reqs = self::requirementsOf($a, $requests);
            $byId = array_column($reqs, null, 'id');
            $items = [];
            foreach ($a['procedures'] as $code => [$text, $assertions, $type]) {
                $items[] = self::item((string) $code, $text, $assertions, $type, false, $s['items'][$code] ?? [], $byId);
            }
            foreach ($s['custom'] ?? [] as $c) {
                $items[] = self::item($c['id'], $c['text'], $c['assertions'] ?? [], $c['type'] ?? 'detail', true, $s['items'][$c['id']] ?? [], $byId);
            }

            $lines = [];
            $balance = $prior = null;
            foreach ($a['lines'] as $line) {
                if (isset($m['lines'][$line])) {
                    $l = $m['lines'][$line];
                    $lines[] = ['key' => $line, 'label' => $l['label'], 'amount' => $l['amount'], 'prior' => $l['prior']];
                    $balance = ($balance ?? 0) + $l['amount'];
                    $prior = $l['prior'] === null ? $prior : ($prior ?? 0) + $l['prior'];
                }
            }
            $count = array_count_values(array_column($items, 'status')) + array_fill_keys(config('audit_procedures.statuses'), 0);
            $na = ! empty($s['na']);
            $prepared = $s['prepared'] ?? null;
            $reviewed = $s['reviewed'] ?? null;
            $status = match (true) {
                $na => 'na',
                $reviewed !== null => 'reviewed',
                $prepared !== null => 'prepared',
                $count['pending'] === count($items) => 'not_started',
                $count['pending'] === 0 => 'completed',
                default => 'in_progress',
            };
            $answered = count($items) - $count['pending'];
            $areas[] = ['key' => $key, 'code' => $a['code'], 'title' => $a['title'], 'items' => $items, 'lines' => $lines, 'balance' => $balance, 'prior' => $prior,
                'has_lines' => $a['lines'] !== [], 'significant' => $pm !== null && $pm > 0 && $balance !== null && abs($balance) >= $pm,
                'count' => $count, 'answered' => $answered, 'progress' => $items === [] ? 0 : (int) round($answered * 100 / count($items)),
                'na' => $na, 'na_reason' => $s['na_reason'] ?? null, 'conclusion' => $s['conclusion'] ?? null, 'prepared' => $prepared, 'reviewed' => $reviewed,
                'status' => $status, 'samples' => $key === 'revenue' ? $revenueSamples : null, 'has_sample_step' => in_array('sample', array_column($items, 'type'), true),
                'all_samples' => $allSamples, 'standards' => $a['standards'] ?? [], 'framework' => $a['framework'] ?? [],
                'requirements' => $reqs, 'awaited' => count(array_filter($reqs, fn ($r) => $r['awaited'])),
                'received' => count(array_filter($reqs, fn ($r) => in_array($r['status'], [DocumentRequest::UPLOADED, DocumentRequest::ACCEPTED], true)))];

            $totals['areas']++;
            if ($na) {
                $totals['areas_na']++;

                continue;
            }
            $totals['requirements'] += count($reqs);
            $totals['awaited'] += $areas[array_key_last($areas)]['awaited'];
            $totals['procedures'] += count($items);
            foreach (['done', 'exception', 'na', 'pending'] as $st) {
                $totals[$st] += $count[$st];
            }
            $totals['prepared'] += $prepared !== null ? 1 : 0;
            $totals['reviewed'] += $reviewed !== null ? 1 : 0;
        }
        $totals['progress'] = $totals['procedures'] === 0 ? 0 : (int) round(($totals['procedures'] - $totals['pending']) * 100 / $totals['procedures']);

        return ['areas' => $areas, 'totals' => $totals, 'm' => $m];
    }

    /** Whether every area of the year is signed off by its preparer, or not applicable (the step is then done). */
    public static function complete(?array $procedures): bool
    {
        $saved = $procedures['areas'] ?? [];
        foreach (array_keys(self::catalogue()) as $key) {
            if (empty($saved[$key]['na']) && empty($saved[$key]['prepared'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The area's answers as the page sent them (status, ref and note by procedure code; the conclusion; not applicable and why).
     * A code the area doesn't have is ignored. Returns whether anything changed (the sign-offs are then dropped).
     */
    public static function saveArea(AuditPlan $plan, string $key, array $input, User $user, array $requestIds = []): bool
    {
        $all = $plan->procedures ?? [];
        $s = $all['areas'][$key] ?? [];
        $before = self::content($s);
        $codes = self::codes($key, $s);
        $statuses = config('audit_procedures.statuses');
        foreach ($codes as $code) {
            $old = $s['items'][$code] ?? [];
            $status = in_array($input['status'][$code] ?? null, $statuses, true) ? $input['status'][$code] : ($old['status'] ?? 'pending');
            $ref = self::text($input['ref'][$code] ?? null);
            $note = self::text($input['note'][$code] ?? null);
            // The evidence: one of the area's requirements (anything else is dropped).
            $request = (int) ($input['request'][$code] ?? 0);
            $request = in_array($request, $requestIds, true) ? $request : null;
            if ($status === 'pending' && $ref === null && $note === null && $request === null) {
                unset($s['items'][$code]);

                continue;
            }
            $same = ($old['status'] ?? 'pending') === $status && ($old['ref'] ?? null) === $ref && ($old['note'] ?? null) === $note && ($old['request'] ?? null) === $request;
            $s['items'][$code] = ['status' => $status, 'ref' => $ref, 'note' => $note, 'request' => $request,
                'by' => $same ? ($old['by'] ?? $user->name) : $user->name, 'at' => $same ? ($old['at'] ?? now()->toDateString()) : now()->toDateString()];
        }
        $s['conclusion'] = self::text($input['conclusion'] ?? null);
        $s['na'] = ! empty($input['na']);
        $s['na_reason'] = $s['na'] ? self::text($input['na_reason'] ?? null) : null;
        $changed = self::content($s) !== $before;
        if ($changed) {
            unset($s['prepared'], $s['reviewed']);
        }
        $all['areas'][$key] = $s;
        $plan->procedures = $all;

        return $changed;
    }

    /** A procedure of the accountant's own, at the end of the area. */
    public static function addProcedure(AuditPlan $plan, string $key, string $text, array $assertions, string $type): string
    {
        $all = $plan->procedures ?? [];
        $s = $all['areas'][$key] ?? [];
        $ids = array_map(fn ($c) => (int) substr($c['id'], 1), $s['custom'] ?? []);
        $id = 'c'.($ids === [] ? 1 : max($ids) + 1);
        $s['custom'][] = ['id' => $id, 'text' => trim($text), 'type' => $type,
            'assertions' => array_values(array_intersect(config('audit_procedures.assertions'), $assertions))];
        unset($s['prepared'], $s['reviewed']);
        $all['areas'][$key] = $s;
        $plan->procedures = $all;

        return $id;
    }

    /** Removes one of the accountant's own procedures (the program's own stay: they are marked not applicable instead). */
    public static function deleteProcedure(AuditPlan $plan, string $key, string $id): bool
    {
        $all = $plan->procedures ?? [];
        $s = $all['areas'][$key] ?? [];
        $kept = array_values(array_filter($s['custom'] ?? [], fn ($c) => $c['id'] !== $id));
        if (count($kept) === count($s['custom'] ?? [])) {
            return false;
        }
        $s['custom'] = $kept;
        unset($s['items'][$id], $s['prepared'], $s['reviewed']);
        $all['areas'][$key] = $s;
        $plan->procedures = $all;

        return true;
    }

    /**
     * Signs the area off (or takes the sign-off back). The preparer signs once no procedure is left pending (or the area is not
     * applicable); the reviewer after the preparer. Taking the preparer's back takes the reviewer's too.
     *
     * @return ?string the reason it can't be signed (a key of audit_plan.procedures.errors), null when done
     */
    public static function sign(AuditPlan $plan, string $key, string $role, User $user, bool $off = false, int $awaited = 0): ?string
    {
        $all = $plan->procedures ?? [];
        $s = $all['areas'][$key] ?? [];
        if ($off) {
            unset($s[$role]);
            if ($role === 'prepared') {
                unset($s['reviewed']);
            }
        } elseif ($role === 'prepared') {
            if (empty($s['na'])) {
                foreach (self::codes($key, $s) as $code) {
                    if (($s['items'][$code]['status'] ?? 'pending') === 'pending') {
                        return 'pending_left';
                    }
                }
                if ($awaited > 0) {
                    return 'requirements_awaited';
                }
            }
            $s['prepared'] = ['by' => $user->id, 'name' => $user->name, 'at' => now()->toDateString()];
        } else {
            if (empty($s['prepared'])) {
                return 'not_prepared';
            }
            // The reviewer is someone else than the preparer.
            if ((int) ($s['prepared']['by'] ?? 0) === (int) $user->id) {
                return 'same_person';
            }
            $s['reviewed'] = ['by' => $user->id, 'name' => $user->name, 'at' => now()->toDateString()];
        }
        $all['areas'][$key] = $s;
        $plan->procedures = $all;

        return null;
    }

    /** @return list<string> the area's procedure codes: the program's, then the accountant's own */
    private static function codes(string $key, array $s): array
    {
        return [...array_map('strval', array_keys(self::catalogue()[$key]['procedures'] ?? [])), ...array_column($s['custom'] ?? [], 'id')];
    }

    private static function item(string $code, string $text, array $assertions, string $type, bool $custom, array $answer, array $requests = []): array
    {
        $evidence = isset($answer['request']) ? ($requests[$answer['request']] ?? null) : null;

        return ['code' => $code, 'text' => $text, 'assertions' => $assertions, 'type' => $type, 'custom' => $custom,
            'status' => $answer['status'] ?? 'pending', 'ref' => $answer['ref'] ?? null, 'note' => $answer['note'] ?? null,
            'by' => $answer['by'] ?? null, 'at' => $answer['at'] ?? null, 'request' => $evidence === null ? null : $evidence['id'], 'evidence' => $evidence,
            // Said done on a document the office hasn't accepted yet.
            'evidence_unsettled' => $evidence !== null && ($answer['status'] ?? 'pending') === 'done' && ! in_array($evidence['status'], [DocumentRequest::ACCEPTED, DocumentRequest::NOT_APPLICABLE], true)];
    }

    /** What an area says, without who answered and when nor its sign-offs: compared to know whether it changed. */
    private static function content(array $s): string
    {
        $items = array_map(fn ($i) => [$i['status'] ?? 'pending', $i['ref'] ?? null, $i['note'] ?? null, $i['request'] ?? null], $s['items'] ?? []);
        ksort($items);

        return json_encode([$items, $s['custom'] ?? [], $s['conclusion'] ?? null, ! empty($s['na']), $s['na_reason'] ?? null]);
    }

    private static function text(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
