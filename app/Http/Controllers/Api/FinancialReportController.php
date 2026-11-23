<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReadPriorStatementsPdf;
use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use App\Models\ClassificationRun as Run;
use App\Services\Statements\CurrentYearStatements;
use App\Services\Statements\EquityStatementBuilder;
use App\Services\Statements\PriorYearDefaults;
use App\Services\Statements\PriorStatementsFile;
use App\Services\Statements\PriorPdf\PageClassifier;
use App\Services\Statements\PriorPdf\PriorPdfIssues;
use App\Services\Statements\PriorStatementsPdf;
use App\Support\Pdf\Qpdf;
use App\Services\Statements\PriorYearResolver;
use App\Services\Statements\StatementSynonyms;
use App\Services\Statements\ReportStatements;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\MovementSuggester;
use App\Services\Statements\NarrativeRenderer;
use App\Services\Statements\NoteComparatives;
use App\Services\Statements\OpeningCheck;
use App\Services\Statements\ArticlesOfAssociation;
use App\Services\Statements\ZakatCalculator;
use App\Services\Statements\ComparableLines;
use App\Services\Statements\ScheduleContext;
use App\Services\Statements\PriorPdf;
use App\Models\KnownWord;
use App\Services\Statements\NotesTemplate;
use App\Services\Statements\Figure;
use App\Services\Statements\ReportIntake;
use App\Services\Statements\ReportChecks;
use App\Services\Statements\ReportSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Validation\ValidationException;

/**
 * Financial reports (plan §4.5): one set of statements built from a finished classification run. Its state is read
 * from the run as it is now; `run_changed` says the run was corrected after the report last took it in. Versions freeze
 * the state as a draft or a final (PR 12, Q19, Q20); the report stays editable and a later final supersedes the earlier.
 *
 */
class FinancialReportController extends Controller
{
    private const SHARE_TOLERANCE = 0.0001;

    public function __construct(private ReportSource $source, private ReportStatements $statements, private ReportIntake $intake) {}

    /** ?company_id=, ?classification_run_id=; newest first. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['company_id' => ['nullable', 'uuid'], 'classification_run_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = FinancialReport::query()
            ->when($request->input('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->input('classification_run_id'), fn ($q, $id) => $q->where('classification_run_id', $id))
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate((int) $request->input('per_page', 25))->withQueryString();
        $page->getCollection()->transform(fn (FinancialReport $r) => $this->summary($r));

        return response()->json($page);
    }

    /** 409 when the run is not finished: nothing is built from a run still waiting, failed, cancelled or expired. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classification_run_id' => ['required', 'uuid', 'exists:classification_runs,id'],
            'company_id'            => ['nullable', 'uuid', 'exists:companies,id'],
            'period_start'          => ['required', 'date_format:Y-m-d'],
            'period_end'            => ['required', 'date_format:Y-m-d', 'after:period_start'],
        ]);
        $run = ClassificationRun::find($data['classification_run_id']);
        if ($reason = $this->source->unusable($run)) {
            return response()->json(['message' => $reason, 'run_status' => $run->status], 409);
        }

        // The run's company (chosen at upload) unless the request names one.
        $data['company_id'] ??= $run->company_id;
        $report = FinancialReport::create($data + [
            'template_version' => config('financial_statements.template_version'),
            'run_result_hash'  => $this->source->hash($run),
            'last_computed_at' => now(),
        ]);

        return response()->json($this->state($report->fresh(), $run), 201);
    }

    public function show(string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        return response()->json($this->state($report, $report->run));
    }

    /**
     * Changes the report's company or period (the wizard's first step): {company_id?, period_start?, period_end?}, partial.
     * The period keeps ending after it starts, and after the comparative period when one is set; a prior report keeps
     * being the same company's. Change the prior year first when it no longer fits.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'company_id'   => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
            'period_start' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'period_end'   => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $start  = $data['period_start'] ?? $report->period_start->format('Y-m-d');
        $end    = $data['period_end'] ?? $report->period_end->format('Y-m-d');
        $errors = [];
        if ($end <= $start) {
            $errors['period_end'][] = __('report_messages.errors.period_end_before_start');
        }
        if ($report->comparative_end && $report->comparative_end->format('Y-m-d') >= $start) {
            $errors['period_start'][] = __('report_messages.errors.period_start_overlaps', ['date' => $report->comparative_end->format('Y-m-d')]);
        }
        $company = array_key_exists('company_id', $data) ? $data['company_id'] : $report->company_id;
        if ($report->priorReport && $report->priorReport->company_id && $company !== $report->priorReport->company_id) {
            $errors['company_id'][] = __('report_messages.errors.prior_report_other_company');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $report->update($data + ['last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /** The fields this report asks the user, driven by the run's layout and balances. */
    public function intake(string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        return response()->json(['fields' => $this->statements->build($report)['intake']]);
    }

    /** Partial update of the inputs: only the keys sent change; null removes one. Values of fields no longer asked are dropped. */
    public function updateInputs(Request $request, string $id): JsonResponse
    {
        $request->validate(['inputs' => ['present', 'array']]);
        $patch = $request->input('inputs');
        if (array_is_list($patch) && $patch !== []) {
            throw ValidationException::withMessages(['inputs' => [__('report_messages.errors.inputs_by_key')]]);
        }
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $patch = array_diff_key($patch, array_flip(ReportIntake::RETIRED));   // accepted and ignored (deprecated)

        $merged = array_filter(array_merge($report->inputs ?? [], $patch), fn ($v) => $v !== null);
        // Which fields exist depends on the stored inputs and the answers that open other questions (ReportIntake::GATES),
        // taken from the request when they have the right type; nothing unvalidated reaches the build.
        $probe = $report->inputs ?? [];
        foreach (ReportIntake::GATES as $gate => $type) {
            if (array_key_exists($gate, $patch) && ($patch[$gate] === null || ($type === 'boolean' ? is_bool($patch[$gate]) : is_string($patch[$gate])))) {
                $probe[$gate] = $patch[$gate];
            }
        }
        $probe = array_filter($probe, fn ($v) => $v !== null);
        $fields = $this->statements->build($report, ['inputs' => $probe])['intake'];
        $errors = $this->intake->errors($patch, $fields, $merged);
        // Laravel turns '' and blanks into null (a removal); a blank text sent on purpose is refused instead (a zero zakat needs a reason).
        foreach ((array) (json_decode($request->getContent(), true)['inputs'] ?? []) as $key => $raw) {
            if (is_string($raw) && trim($raw) === '' && isset(array_column($fields, null, 'key')[$key])) {
                $errors[$key] = [__('report_messages.errors.text_length', ['max' => ReportIntake::maxLength($key)])];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(array_combine(array_map(fn ($k) => "inputs.{$k}", array_keys($errors)), $errors));
        }
        $asked = array_column($fields, 'key');
        $report->update(['inputs' => array_intersect_key($merged, array_flip($asked)), 'last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * The zakat and income tax dialog: the accountant's answers to the calculation (`inputs`, a key set to null goes back to
     * its default) and the charges to take into the statements (`apply`: `zakat` and `income_tax`, true to charge what is
     * calculated now, false to take the charge out). A charge is taken only where nothing else charges it: the trial
     * balance, an entry or the intake's own zakat charge. The answer is the report's state.
     */
    public function updateZakatTax(Request $request, string $id): JsonResponse
    {
        $request->validate(['inputs' => ['sometimes', 'array'], 'apply' => ['sometimes', 'array:zakat,income_tax'], 'apply.*' => ['boolean'],
            'adopt' => ['sometimes', 'array:zakat,income_tax'], 'adopt.*' => ['nullable', 'in:calculated,booked,manual'],
            'manual' => ['sometimes', 'array:zakat,income_tax'], 'manual.*' => ['nullable', 'numeric', 'min:0', 'max:999999999999']]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $patch = (array) $request->input('inputs', []);
        if ($patch !== [] && array_is_list($patch)) {
            throw ValidationException::withMessages(['inputs' => [__('report_messages.errors.inputs_by_key')]]);
        }
        $errors = [];
        foreach (ZakatCalculator::errors($patch) as $key => $code) {
            $errors["inputs.{$key}"] = [ReportIntake::error($code)];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $saved = array_filter(array_merge((array) ($report->zakat_tax['inputs'] ?? []), $patch), fn ($v) => $v !== null);
        $applied = (array) ($report->zakat_tax['applied'] ?? []);
        $off = (array) ($report->zakat_tax['off'] ?? []);
        // Which amount the statements carry (the calculated, the books', or the accountant's own), and that own amount: null resets.
        $adopt = array_filter(array_merge((array) ($report->zakat_tax['adopt'] ?? []), (array) $request->input('adopt', [])), fn ($v) => $v !== null);
        $manual = array_filter(array_merge((array) ($report->zakat_tax['manual'] ?? []), array_map(fn ($v) => $v === null ? null : round((float) $v, 2), (array) $request->input('manual', []))), fn ($v) => $v !== null);
        foreach ($adopt as $charge => $choice) {
            if ($choice === 'manual' && ! isset($manual[$charge])) {
                throw ValidationException::withMessages(["manual.{$charge}" => [ReportIntake::error('zakat_manual_required')]]);
            }
        }
        $report->update(['zakat_tax' => self::zakatState($report, ['inputs' => $saved, 'applied' => $applied, 'off' => $off, 'adopt' => $adopt, 'manual' => $manual]), 'last_computed_at' => now()]);

        if ($request->has('apply')) {
            $report = $report->fresh();
            $calc = $this->zakatTax($report, $this->statements->build($report));
            foreach ((array) $request->input('apply') as $charge => $on) {
                if (! $on) {
                    unset($applied[$charge]);
                    $off[$charge] = true;   // taken out by the accountant: not charged again by itself
                } elseif ($calc['can_apply'][$charge] ?? false) {
                    unset($off[$charge]);
                    $applied[$charge] = $charge === 'zakat' ? $calc['zakat']['amount'] : $calc['income_tax']['amount'];
                } else {
                    throw ValidationException::withMessages(["apply.{$charge}" => [ReportIntake::error('zakat_not_applicable')]]);
                }
            }
            $report->update(['zakat_tax' => self::zakatState($report, ['inputs' => $saved, 'applied' => $applied, 'off' => $off])]);
        }

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /** The report's zakat state with the given parts changed: every other part kept (an empty one dropped). */
    private static function zakatState(FinancialReport $report, array $parts): array
    {
        $state = array_merge((array) $report->zakat_tax, $parts);

        return array_filter($state, fn ($v, $k) => ! in_array($k, ['off', 'adopt', 'manual'], true) || $v !== [], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The year's zakat and income tax go into the statements and the notes by themselves (the accountant's review): what the
     * calculation gives is charged wherever nothing else charges it (the trial balance, an entry, the intake's own charge),
     * and follows the calculation when the answers or the figures change. A charge the accountant took out in the zakat
     * step stays out (`zakat_tax.off`) until it is charged again there.
     *
     * @return array{0: FinancialReport, 1: array} the report and its build, rebuilt when a charge moved
     */
    private function autoCharge(FinancialReport $report, array $built): array
    {
        $adopt = (array) ($report->zakat_tax['adopt'] ?? []);
        $auto = (bool) config('financial_statements.auto_charge');
        // The calculation adopted by the accountant is carried whatever the setting; otherwise only with auto-charging on.
        if ($built['profile']->postClosing || (! $auto && ! in_array('calculated', $adopt, true))) {
            return [$report, $built];
        }
        $calc = $this->zakatTax($report, $built);
        $off = (array) ($report->zakat_tax['off'] ?? []);
        $applied = (array) ($report->zakat_tax['applied'] ?? []);
        $before = $applied;
        foreach (['zakat' => $calc['zakat']['amount'], 'income_tax' => $calc['income_tax']['amount']] as $charge => $amount) {
            $source = $calc['charged'][$charge === 'zakat' ? 'zakat_source' : 'tax_source'];
            if (($adopt[$charge] ?? null) === 'calculated') {
                $applied[$charge] = $amount;   // adopted: the statements carry the calculation, the books' figure corrected to it
                continue;
            }
            if (! $auto || isset($adopt[$charge]) || isset($off[$charge]) || ! in_array($source, [null, 'dialog'], true)) {
                continue;
            }
            if ($calc['can_apply'][$charge]) {
                $applied[$charge] = $amount;
            } elseif ($source === 'dialog') {
                unset($applied[$charge]);   // nothing is due any more: the earlier charge goes
            }
        }
        if ($applied == $before) {
            return [$report, $built];
        }
        $report->update(['zakat_tax' => self::zakatState($report, ['applied' => $applied, 'off' => $off])]);
        $report = $report->fresh();

        return [$report, $this->statements->build($report)];
    }

    /**
     * The calculation of the year's zakat and income tax for the report's statements (ZakatCalculator), and where each
     * charge is booked now: the trial balance or an entry (more than the dialog and the intake put in), the intake's own
     * zakat charge, or the dialog.
     */
    private function zakatTax(FinancialReport $report, array $built): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $applied = (array) ($report->zakat_tax['applied'] ?? []);
        $figure = fn (string $key) => collect($built['statements']['comprehensive_income'])->firstWhere('key', $key)['current'] ?? null;
        $booked = fn (string $key) => ($f = $figure($key)) !== null && ! $f->isMissing() ? -$f->amount : 0.0;
        $typed = $report->inputs['zakat.charge'] ?? null;
        $tbZakat = abs($built['line_totals']['is.zakat']['amount'] ?? 0.0) > $tolerance;
        $tbTax = abs($built['line_totals']['is.income_tax']['amount'] ?? 0.0) > $tolerance;
        $zakatSource = match (true) {
            $tbZakat || abs($booked('is.zakat') - ($typed ?? $applied['zakat'] ?? 0.0)) > $tolerance => 'trial_balance',
            $typed !== null => 'input',
            isset($applied['zakat']) => 'dialog',
            default => null,
        };
        $taxSource = match (true) {
            $tbTax || abs($booked('is.income_tax') - ($applied['income_tax'] ?? 0.0)) > $tolerance => 'trial_balance',
            isset($applied['income_tax']) => 'dialog',
            default => null,
        };
        // The Saudi and Gulf owners' share: the accountant's answer, else the owners' (the articles of association's partners, notes 10–12;
        // a sole proprietorship is a Saudi's: all of it).
        $inputs = (array) ($report->zakat_tax['inputs'] ?? []);
        $share = isset($inputs['saudi_share']) ? null : ArticlesOfAssociation::zakatShare($report->company?->owners() ?? []);
        if ($share !== null) {
            $inputs['saudi_share'] = $share;
        }
        $calc = ZakatCalculator::of($built['statements'], $inputs,
            ['start' => $report->period_start->format('Y-m-d'), 'end' => $report->period_end->format('Y-m-d')],
            ['zakat_source' => $zakatSource, 'tax_source' => $taxSource, 'applied' => $applied]);
        $adopt = (array) ($report->zakat_tax['adopt'] ?? []);
        $manual = (array) ($report->zakat_tax['manual'] ?? []);
        $books = (array) ($built['zakat_booked'] ?? ['zakat' => 0.0, 'income_tax' => 0.0]);
        $calc['adopt'] = ['zakat' => $adopt['zakat'] ?? 'auto', 'income_tax' => $adopt['income_tax'] ?? 'auto'];
        $calc['candidates'] = [
            'zakat' => ['calculated' => $calc['zakat']['amount'], 'booked' => round((float) ($books['zakat'] ?? 0.0), 2), 'manual' => isset($manual['zakat']) ? (float) $manual['zakat'] : null],
            'income_tax' => ['calculated' => $calc['income_tax']['amount'], 'booked' => round((float) ($books['income_tax'] ?? 0.0), 2), 'manual' => isset($manual['income_tax']) ? (float) $manual['income_tax'] : null],
        ];
        $calc['in_statements'] = ['zakat' => round($booked('is.zakat'), 2) + 0.0, 'income_tax' => round($booked('is.income_tax'), 2) + 0.0];
        $calc['saudi_share_from'] = isset($report->zakat_tax['inputs']['saudi_share']) ? 'user' : ($share !== null ? $report->company->ownersSource() : 'default');
        if ($built['profile']->postClosing) {   // the result is closed into retained earnings already: nothing to charge by here
            $calc['can_apply'] = ['zakat' => false, 'income_tax' => false];
            $calc['warnings'][] = 'post_closing_tb';
        }
        $calc['entry'] = $this->zakatEntry($report, $built, $calc);

        return $calc;
    }

    /**
     * The entry after the zakat (القيد بعد الزكاة): for the zakat and the income tax, what the books carry, what the statements
     * carry (the amount adopted), and the entry that takes the one to the other (Dr expense, Cr provision; reversed when the
     * books carry more), on the trial balance's own accounts where it has them, else on the statement lines. Then the year's
     * result before and after the charge, and the provision's movement with it.
     *
     * @return array{lines: list<array>, profit: array, provision: ?array}
     */
    private function zakatEntry(FinancialReport $report, array $built, array $calc): array
    {
        $names = array_column(array_map(fn ($r) => [$r['row'], $r], $report->run->result['data']), 1, 0);
        $catalogue = $built['context']->catalogue;
        $labels = [];
        foreach (['financial_position', 'comprehensive_income'] as $st) {
            foreach ($catalogue->statement($st) as $i) {
                if (isset($i['key'])) {
                    $labels[$i['key']] = $i['label_ar'];
                }
            }
        }
        // The accounts on a line (by their share), the largest first: the account the entry goes to.
        $account = function (string $line) use ($built, $names, $labels) {
            $rows = [];
            foreach ($built['mapping']['rows'] as $n => $m) {
                if (isset($m['lines']['closing'][$line])) {
                    $rows[] = $n;
                }
            }
            $n = $rows[0] ?? null;

            return ['line' => $line, 'line_label' => $labels[$line] ?? $line, 'row' => $n,
                'number' => $n === null ? null : ($names[$n]['account_number'] ?? null), 'name' => $n === null ? ($labels[$line] ?? $line) : ($names[$n]['account_name'] ?? null)];
        };
        $lines = [];
        foreach (['zakat' => ['is.zakat', 'sofp.zakat_provision'], 'income_tax' => ['is.income_tax', 'sofp.income_tax_provision']] as $kind => [$expense, $provision]) {
            $carried = (float) ($calc['in_statements'][$kind] ?? 0.0);
            $booked = (float) ($calc['candidates'][$kind]['booked'] ?? 0.0);
            if (abs($carried) < 0.005 && abs($booked) < 0.005) {
                continue;
            }
            if (! $catalogue->has($provision)) {
                $provision = 'sofp.zakat_provision';
            }
            $adjust = round($carried - $booked, 2);
            $lines[] = ['kind' => $kind, 'carried' => round($carried, 2), 'booked' => round($booked, 2), 'adjustment' => $adjust,
                // The adjusting entry: debit the expense, credit the provision; the books carrying more: the other way round.
                'debit' => $adjust >= 0 ? $account($expense) : $account($provision), 'credit' => $adjust >= 0 ? $account($provision) : $account($expense),
                'amount' => abs($adjust),
                // The year's whole charge as one entry (what the statements carry).
                'full' => ['debit' => $account($expense), 'credit' => $account($provision), 'amount' => round($carried, 2)]];
        }
        $figure = fn (string $key) => collect($built['statements']['comprehensive_income'])->firstWhere('key', $key)['current'] ?? null;
        $amount = fn (string $key) => ($f = $figure($key)) !== null && ! $f->isMissing() ? round($f->amount, 2) : null;
        $schedule = collect($built['schedules'])->firstWhere('key', 'zakat');
        $cell = fn (string $row) => ($c = collect($schedule['rows'] ?? [])->firstWhere('key', $row)['cells']['amount'] ?? null) !== null && ! $c->isMissing() ? round($c->amount, 2) : null;

        return [
            'lines' => $lines,
            'profit' => ['before' => $amount('is.profit_before_zakat'), 'zakat' => $amount('is.zakat'), 'income_tax' => $amount('is.income_tax'), 'after' => $amount('is.net_profit')],
            'provision' => $schedule === null ? null : ['opening' => $cell('opening'), 'charge' => $cell('charge'), 'paid' => $cell('paid'),
                'prior_year_adjustments' => $cell('prior_year_adjustments'), 'closing' => $cell('closing')],
        ];
    }

    /**
     * Replaces the per-report account overrides: {row} with a line ({line_key} or {shares: {line_key: share}}, adding up to
     * 1), a note category ({category}, asset notes, Q8) and tags ({tags}: `deferred_finance_cost` nets the row against the
     * loans, Q36; `provision_charge` makes it the P&L counterpart of the other provisions), at least one of them.
     * An override is the user's decision: it wins over every rule and settles a pending row (e.g. a periodic stock proposal).
     */
    public function updateOverrides(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'overrides'            => ['present', 'array', 'max:2000'],
            'overrides.*'          => ['array'],
            'overrides.*.row'      => ['required', 'integer', 'min:1', 'distinct'],
            'overrides.*.line_key' => ['nullable', 'string', 'required_without_all:overrides.*.shares,overrides.*.category,overrides.*.tags,overrides.*.exclude', 'prohibits:overrides.*.shares'],
            // Left out of the statements (a parent counted twice, …): nothing else on the row, and always a reason.
            'overrides.*.exclude'  => ['sometimes', 'accepted', 'prohibits:overrides.*.line_key,overrides.*.shares,overrides.*.category,overrides.*.tags'],
            'overrides.*.reason'   => ['nullable', 'required_with:overrides.*.exclude', 'prohibits:overrides.*.line_key,overrides.*.shares', 'string', 'min:3', 'max:500'],
            'overrides.*.shares'   => ['nullable', 'array', 'min:2'],
            'overrides.*.shares.*' => ['numeric', 'gt:0', 'lt:1'],
            'overrides.*.category' => ['nullable', 'string'],
            'overrides.*.tags'     => ['nullable', 'array', 'max:5'],
            'overrides.*.tags.*'   => ['string', 'distinct'],
        ]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $catalogue = new LineCatalogue($report->template_version);
        $rows      = array_flip(array_column($report->run->result['data'], 'row'));
        $errors    = [];
        $stored    = [];
        foreach ($request->input('overrides') as $i => $o) {
            if (! isset($rows[$o['row']])) {
                $errors["overrides.{$i}.row"][] = __('report_messages.errors.account_not_in_tb');
            }
            $lines = isset($o['line_key']) ? [$o['line_key'] => 1.0] : ($o['shares'] ?? null);
            foreach (array_keys($lines ?? []) as $line) {
                if (! $catalogue->has((string) $line)) {
                    $errors["overrides.{$i}"][] = __('report_messages.errors.line_not_found', ['line' => $line]);
                }
            }
            if ($lines !== null && abs(array_sum($lines) - 1.0) > self::SHARE_TOLERANCE) {
                $errors["overrides.{$i}.shares"][] = __('report_messages.errors.shares_total');
            }
            foreach ($o['tags'] ?? [] as $tag) {
                if (! array_key_exists($tag, $catalogue->tags())) {
                    $errors["overrides.{$i}.tags"][] = __('report_messages.errors.tag_unknown');
                }
            }
            if ($o['exclude'] ?? false) {
                $stored[] = ['row' => (int) $o['row'], 'exclude' => true, 'reason' => trim((string) $o['reason'])];
                continue;
            }
            $stored[] = ['row' => (int) $o['row']]
                + (isset($o['line_key']) ? ['line_key' => $o['line_key']] : [])
                + (isset($o['shares']) ? ['shares' => array_map('floatval', $o['shares'])] : [])
                + (isset($o['category']) ? ['category' => $o['category']] : [])
                + (! empty($o['tags']) ? ['tags' => array_values($o['tags'])] : []);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        // A category belongs to the asset note of the line the row is on, once these overrides apply.
        $probe   = (clone $report)->forceFill(['account_overrides' => $stored])->statementOptions();
        $mapping = $this->source->read($report->run, $report->template_version, ['overrides' => $probe['overrides'], 'excluded' => $probe['excluded'], 'tags' => $probe['tags']])['mapping'];
        foreach ($stored as $i => $o) {
            if (! isset($o['category'])) {
                continue;
            }
            $lines = array_keys($mapping['rows'][$o['row']]['lines']['closing'] ?? []);
            $ok    = array_filter($catalogue->schedules(), fn ($s) => $s['kind'] === 'asset' && in_array($s['line'], $lines, true) && isset($s['categories'][$o['category']]));
            if ($ok === []) {
                $errors["overrides.{$i}.category"][] = __('report_messages.errors.category_not_of_line');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $report->update(['account_overrides' => $stored, 'last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * Replaces the report's adjusting entries (Q39): each balanced, with a reason; a line is an account (row) or a statement
     * line (line_key), with a debit or a credit. Entries are numbered in the order sent. None is ever made by the system.
     */
    public function updateAdjustments(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'entries'                    => ['present', 'array', 'max:200'],
            'entries.*.reason'           => ['required', 'string', 'min:3', 'max:500'],
            'entries.*.lines'            => ['required', 'array', 'min:2', 'max:50'],
            'entries.*.lines.*.row'      => ['nullable', 'integer', 'min:1', 'required_without:entries.*.lines.*.line_key', 'prohibits:entries.*.lines.*.line_key'],
            'entries.*.lines.*.line_key' => ['nullable', 'string', 'required_without:entries.*.lines.*.row'],
            'entries.*.lines.*.debit'    => ['required', 'numeric', 'min:0'],
            'entries.*.lines.*.credit'   => ['required', 'numeric', 'min:0'],
        ]);
        $errors  = [];
        $entries = [];
        foreach ($request->input('entries') as $i => $entry) {
            $lines = [];
            foreach ($entry['lines'] as $j => $l) {
                [$debit, $credit] = [round((float) $l['debit'], 2), round((float) $l['credit'], 2)];
                if (($debit > 0) === ($credit > 0)) {
                    $errors["entries.{$i}.lines.{$j}"][] = __('report_messages.errors.entry_line_side');
                }
                $lines[] = ['row' => isset($l['row']) ? (int) $l['row'] : null, 'line_key' => $l['line_key'] ?? null, 'debit' => $debit, 'credit' => $credit];
            }
            $difference = round(array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit')), 2);
            if ($difference != 0) {
                $errors["entries.{$i}.lines"][] = __('report_messages.errors.entry_unbalanced', ['difference' => number_format($difference, 2)]);
            }
            $entries[] = ['number' => $i + 1, 'reason' => trim($entry['reason']), 'lines' => $lines];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $catalogue = new LineCatalogue($report->template_version);
        $rows      = array_flip(array_column($report->run->result['data'], 'row'));
        foreach ($entries as $i => $entry) {
            foreach ($entry['lines'] as $j => $l) {
                if ($l['row'] !== null && ! isset($rows[$l['row']])) {
                    $errors["entries.{$i}.lines.{$j}.row"][] = __('report_messages.errors.account_not_in_tb');
                }
                if ($l['line_key'] !== null && ! $catalogue->has($l['line_key'])) {
                    $errors["entries.{$i}.lines.{$j}.line_key"][] = __('report_messages.errors.line_not_found', ['line' => $l['line_key']]);
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $report->update(['adjustments' => $entries, 'last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * Decides the movement suggestions and enters the movements the trial balance can't give (PR 9): {decisions: [{key,
     * action: confirm | edit | reject | reset, amount?}]}. `confirm` and `reject` need a suggestion; `edit` takes the
     * amount (any cell of the schedules the user may give); `reset` drops the decision. Applied in order.
     */
    public function decideSuggestions(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'decisions'          => ['required', 'array', 'min:1', 'max:500'],
            'decisions.*.key'    => ['required', 'string'],
            'decisions.*.action' => ['required', 'string', 'in:'.implode(',', MovementSuggester::ACTIONS)],
            'decisions.*.amount' => ['nullable', 'numeric', 'required_if:decisions.*.action,edit', 'prohibited_unless:decisions.*.action,edit'],
        ]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $cells     = $this->statements->build($report)['cells'];
        $decisions = $report->movement_decisions ?? [];
        $errors    = [];
        foreach ($request->input('decisions') as $i => $d) {
            $cell = $cells[$d['key']] ?? null;
            if ($cell === null && ! ($d['action'] === 'reset' && isset($decisions[$d['key']]))) {
                $errors["decisions.{$i}.key"][] = __('report_messages.errors.decision_unknown_cell');
                continue;
            }
            if (in_array($d['action'], ['confirm', 'reject'], true) && ($cell['suggested'] === null || abs($cell['suggested']) < 0.005)) {
                $errors["decisions.{$i}.action"][] = __('report_messages.errors.decision_no_suggestion');
                continue;
            }
            $decisions[$d['key']] = match ($d['action']) {
                'confirm' => ['action' => 'confirm', 'amount' => $cell['suggested']],
                'edit'    => ['action' => 'edit', 'amount' => round((float) $d['amount'], 2)],
                'reject'  => ['action' => 'reject'],
                'reset'   => null,
            };
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $report->update(['movement_decisions' => array_filter($decisions), 'last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * Sets where the comparatives come from (Q7, Q9, Q10): `first_year` (none), `report` (a previous report), `run` (a classified
     * prior-year trial balance, with OCI figures if any), `manual` (figures per line, as displayed), or `none` (no source yet:
     * the balance sheet from the opening columns). `restated` marks the comparatives as restated, with a mandatory note.
     */
    public function updatePriorYear(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'source'            => ['required', 'string', 'in:'.implode(',', CurrentYearStatements::SOURCES)],
            'prior_report_id'   => ['nullable', 'uuid', 'required_if:source,report', 'prohibited_unless:source,report'],
            'prior_run_id'      => ['nullable', 'uuid', 'required_if:source,run', 'prohibited_unless:source,run'],
            'figures'           => ['nullable', 'array', 'required_if:source,manual', 'prohibited_unless:source,manual,run'],   // run: OCI only, {} for none
            'figures.*'         => ['numeric'],
            'comparative_start' => ['nullable', 'date_format:Y-m-d', 'prohibited_unless:source,run,manual,statements'],
            'comparative_end'   => ['nullable', 'date_format:Y-m-d', 'prohibited_unless:source,run,manual,statements', 'after:comparative_start'],
            'restated'          => ['nullable', 'boolean', 'prohibited_unless:source,report,run,manual,statements'],
            // A first year although the trial balance has opening balances: the user's override of R8.
            'skip_opening'      => ['nullable', 'boolean', 'prohibited_unless:source,first_year'],
            'restatement_note'  => ['nullable', 'string', 'min:3', 'max:2000', 'required_if_accepted:restated', 'prohibited_unless:restated,true,1'],
            // The comparative changes in equity and cash flow, typed from last year's issued statements (run / manual).
            'changes_in_equity'     => ['nullable', 'array', 'prohibited_unless:source,run,manual,statements'],
            'changes_in_equity.*'   => ['array'],
            'changes_in_equity.*.*' => ['numeric'],
            'cash_flows'            => ['nullable', 'array', 'prohibited_unless:source,run,manual,statements'],
            'cash_flows.*'          => ['numeric'],
            // The notes' prior year, typed from last year's issued notes (PR 11): item tables by class (manual; a prior
            // trial balance gives them), and the movement tables' cells (run / manual).
            'notes'                 => ['nullable', 'array', 'prohibited_unless:source,manual,statements'],
            'notes.*'               => ['array'],
            'notes.*.*'             => ['numeric'],
            'schedules'             => ['nullable', 'array', 'prohibited_unless:source,run,manual,statements'],
            'schedules.*'           => ['numeric'],
            // A file of last year's issued statements (PR 17), uploaded with POST …/prior-year/statements-file: the line
            // (or total, or `ignore`) of each row the file couldn't place exactly, the year column where the file doesn't say,
            // and whether its expenses, shown positive, are turned negative.
            'rows'                    => ['nullable', 'array', 'prohibited_unless:source,statements'],
            'rows.*'                  => ['string'],
            'columns'                 => ['nullable', 'array', 'prohibited_unless:source,statements'],
            'columns.*'               => ['string'],
            'expenses_shown_positive' => ['nullable', 'boolean', 'prohibited_unless:source,statements'],
        ]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $source  = $request->input('source');
        $figures = $request->input('figures');
        $errors  = [];
        $keys    = PriorYearResolver::manualKeys(new LineCatalogue($report->template_version));
        foreach (array_keys($figures ?? []) as $key) {
            $allowed = $source === 'manual' ? in_array($key, $keys, true) : str_starts_with((string) $key, 'is.oci_') && in_array($key, $keys, true);
            if (! $allowed) {
                $errors["figures.{$key}"][] = $source === 'manual' ? __('report_messages.errors.line_not_found', ['line' => $key]) : __('report_messages.errors.prior_oci_only');
            }
        }
        $equity = $request->input('changes_in_equity');
        $cash   = $request->input('cash_flows');
        $soce   = (new LineCatalogue($report->template_version))->changesInEquity();
        foreach ($equity ?? [] as $row => $cells) {
            if (! in_array($row, EquityStatementBuilder::ENTERED, true)) {
                $errors["changes_in_equity.{$row}"][] = __('report_messages.errors.soce_row_not_entered');
                continue;
            }
            foreach (array_keys($cells) as $col) {
                if (! isset($soce['columns'][$col])) {
                    $errors["changes_in_equity.{$row}.{$col}"][] = __('report_messages.errors.soce_column_unknown');
                }
            }
        }
        $cfLines = array_column(array_filter((new LineCatalogue($report->template_version))->statement('cash_flows'), fn ($i) => $i['type'] === 'line'), 'key');
        foreach (array_keys($cash ?? []) as $key) {
            if (! in_array($key, $cfLines, true)) {
                $errors["cash_flows.{$key}"][] = __('report_messages.errors.cash_flow_line_unknown', ['line' => $key]);
            }
        }
        $notes     = $request->input('notes');
        $schedules = $request->input('schedules');
        if ($notes !== null || $schedules !== null) {
            $catalogue = new LineCatalogue($report->template_version);
            $breakdown = array_keys(array_filter($catalogue->notes(), fn ($n) => isset($n['breakdown'])));
            foreach ($notes ?? [] as $note => $classes) {
                if (! in_array($note, [...$breakdown, 'cost_of_revenue_build_up'], true)) {
                    $errors["notes.{$note}"][] = __('report_messages.errors.note_no_table');
                } elseif ($note === 'cost_of_revenue_build_up' && array_diff(array_keys($classes), \App\Services\Statements\NoteBreakdowns::BUILD_UP)) {
                    $errors["notes.{$note}"][] = __('report_messages.errors.build_up_parts');
                }
            }
            if ($schedules !== null) {
                $allowed = NoteComparatives::typedScheduleKeys($this->statements->build($report)['schedules']);
                foreach (array_keys($schedules) as $key) {
                    if (! in_array($key, $allowed, true)) {
                        $errors["schedules.{$key}"][] = __('report_messages.errors.schedule_cell_unknown');
                    }
                }
            }
        }
        $dates = [null, null];
        if ($source === 'report') {
            $prior = FinancialReport::find($request->input('prior_report_id'));
            if (! $prior || $prior->id === $report->id || ! $prior->period_end->lt($report->period_start)
                || ($prior->company_id && $report->company_id && $prior->company_id !== $report->company_id)) {
                $errors['prior_report_id'][] = __('report_messages.errors.prior_report_invalid');
            } else {
                $dates = [$prior->period_start->format('Y-m-d'), $prior->period_end->format('Y-m-d')];
            }
        }
        if ($source === 'run') {
            $run = Run::find($request->input('prior_run_id'));
            if (! $run || $run->id === $report->classification_run_id || $this->source->unusable($run)) {
                $errors['prior_run_id'][] = __('report_messages.errors.prior_run_invalid');
            }
        }
        $file = null;
        if ($source === 'statements') {
            [$file, $fileErrors] = $this->priorFile($report, $request);
            $errors += $fileErrors;
        }
        if (in_array($source, ['run', 'manual', 'statements'], true)) {
            // The year before the period by default; the user's dates when given (a short or long prior period).
            $dates = [$request->input('comparative_start') ?? $report->period_start->copy()->subYear()->format('Y-m-d'),
                $request->input('comparative_end') ?? $report->period_start->copy()->subDay()->format('Y-m-d')];
            if ($dates[1] >= $report->period_start->format('Y-m-d') || $dates[0] >= $dates[1]) {
                $errors['comparative_end'][] = __('report_messages.errors.comparative_end');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $report->update([
            'prior_report_id'   => $source === 'report' ? $request->input('prior_report_id') : null,
            'prior_run_id'      => $source === 'run' ? $request->input('prior_run_id') : null,
            'comparative_start' => $dates[0],
            'comparative_end'   => $dates[1],
            'prior_figures'     => ['source' => $source, 'figures' => $figures === null ? null : array_map('floatval', $figures),
                'changes_in_equity' => $equity === null ? null : array_map(fn ($cells) => array_map('floatval', $cells), $equity),
                'cash_flows' => $cash === null ? null : array_map('floatval', $cash),
                'notes' => $notes === null ? null : array_map(fn ($classes) => array_map('floatval', $classes), $notes),
                'schedules' => $schedules === null ? null : array_map('floatval', $schedules),
                'restated' => $request->boolean('restated'), 'restatement_note' => $request->boolean('restated') ? trim($request->input('restatement_note')) : null]
                + ($source === 'first_year' && $request->boolean('skip_opening') ? ['skip_opening' => true] : [])
                // The approved openings' setting (Step B) is the report's: kept whatever the source.
                + (isset($report->prior_figures['approved_opening']) ? ['approved_opening' => $report->prior_figures['approved_opening']] : [])
                + ($file === null ? [] : ['file' => $file, 'rows' => (array) $request->input('rows', []), 'columns' => (array) $request->input('columns', []),
                    'expenses_shown_positive' => $request->has('expenses_shown_positive') ? $request->boolean('expenses_shown_positive') : null]),
            // The file confirmed is in use; nothing waits for review any more.
            'prior_upload'      => $file === null ? $report->prior_upload : null,
            'last_computed_at'  => now(),
        ]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * Last year's issued statements as a file, kept to be reviewed in step 2; nothing changes the comparatives until the
     * user confirms it with PUT …/prior-year (source `statements`). A new file replaces the one waiting.
     * - Excel (PR 17): read by PHP (PriorStatementsFile) at once, a line proposed for each row (200).
     * - PDF: kept, and the AI looks for the statements' pages in the background (202, upload status `locating` → `pages`);
     *   the user confirms or corrects them with PUT …/statements-file/pages, then the tables are read (PriorStatementsPdf).
     */
    public function uploadPriorStatements(Request $request, string $id, PriorStatementsFile $reader, Qpdf $qpdf): JsonResponse
    {
        \App\Support\ClientFileSource::attach($request);   // or a file the client uploaded in the portal (`client_file_id`)
        $isPdf = strtolower((string) $request->file('file')?->getClientOriginalExtension()) === 'pdf';
        $request->validate(['file' => ['required', 'file', $isPdf ? 'mimes:pdf' : \App\Support\SpreadsheetFile::rule(), 'max:'.($isPdf ? config('financial_statements.prior_pdf.max_kb') : 10240)]]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $year = ($report->comparative_end ?? $report->period_start->copy()->subDay())->year;
        $file = $request->file('file');
        $upload = ['name' => $file->getClientOriginalName(), 'uploaded_at' => now()->toIso8601String(), 'year' => $year];
        if ($isPdf) {
            try {
                $pages = $qpdf->pages($file->getRealPath());
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['file' => __(str_starts_with($e->getMessage(), \App\Support\Pdf\Qpdf::MISSING) ? 'report_messages.errors.pdf_tool_missing' : 'report_messages.errors.pdf_unreadable')]);
            }
            if ($pages > config('financial_statements.prior_pdf.max_pages')) {
                throw ValidationException::withMessages(['file' => __('report_messages.errors.pdf_too_many_pages', ['max' => config('financial_statements.prior_pdf.max_pages')])]);
            }
            $uploadId = (string) Str::uuid();
            $path = $file->storeAs("prior-statements/{$report->id}", "{$uploadId}.pdf", 'local');
            $this->dropWaitingPdf($report);
            $report->update(['prior_upload' => $upload + ['kind' => 'pdf', 'id' => $uploadId, 'path' => $path, 'pages_total' => $pages,
                'status' => 'locating', 'pages' => [], 'found' => [], 'blocks' => [], 'expenses_shown_positive' => null]]);
            ReadPriorStatementsPdf::dispatch($report->id, $uploadId, 'locate');

            return response()->json($this->state($report->fresh(), $report->run), 202);
        }
        try {
            $read = $reader->read($file->getRealPath(), $year, new LineCatalogue($report->template_version), StatementSynonyms::map());
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => __('report_messages.errors.file_unreadable')]);
        }
        if ($read['blocks'] === []) {
            throw ValidationException::withMessages(['file' => __('report_messages.errors.file_no_statements')]);
        }
        // No independent auditor's report in the file: not accepted as last year's approved statements.
        if (! PriorStatementsPdf::hasAuditorReport($read)) {
            throw ValidationException::withMessages(['file' => __('report_messages.errors.file_no_auditor_report')]);
        }
        $this->dropWaitingPdf($report);
        $report->update(['prior_upload' => $upload + $read]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * The pages of each statement in the PDF waiting, as the accountant confirms or corrects them (what the AI found is
     * only proposed); its tables are then read in the background (202, upload status `reading` → `ready`). Allowed once
     * the pages are found, and again to read with other pages; 409 while the file is still being worked on.
     */
    public function priorStatementPages(Request $request, string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $upload = $report->prior_upload;
        if (($upload['kind'] ?? null) !== 'pdf') {
            return response()->json(['message' => __('report_messages.errors.pdf_none')], 409);
        }
        if (! in_array($upload['status'], ['pages', 'ready', 'failed'], true)) {
            return response()->json(['message' => __('report_messages.errors.pdf_busy')], 409);
        }
        $max = $upload['pages_total'];
        $data = $request->validate([
            // Each statement's pages, or (brief §8.1) what each page is, from which they follow.
            'pages'          => ['required_without:page_kinds', 'array:'.implode(',', PriorStatementsPdf::FOUND)],
            'pages.*'        => ['array:from,to'],
            'pages.*.from'   => ['required', 'integer', 'min:1', "max:{$max}"],
            'pages.*.to'     => ['required', 'integer', 'min:1', "max:{$max}"],
            'page_kinds'            => ['nullable', 'array', "size:{$max}"],
            'page_kinds.*'          => ['array:page,kind,rotation'],
            'page_kinds.*.page'     => ['required', 'integer', 'min:1', "max:{$max}", 'distinct'],
            'page_kinds.*.kind'     => ['required', 'string', 'in:'.implode(',', PageClassifier::KINDS)],
            'page_kinds.*.rotation' => ['nullable', 'integer', 'in:'.implode(',', PageClassifier::ROTATIONS)],
        ]);
        $kinds = null;
        if (isset($data['page_kinds'])) {
            // The accountant's kinds and rotations over what the AI said (its printed numbers and notes stay).
            $said = array_column($upload['page_kinds'] ?? [], null, 'page');
            $kinds = array_map(fn ($p) => ['page' => $p['page'], 'kind' => $p['kind'], 'printed_page' => $said[$p['page']]['printed_page'] ?? null,
                'rotation' => $p['rotation'] ?? ($said[$p['page']]['rotation'] ?? 0), 'notes' => $said[$p['page']]['notes'] ?? []], $data['page_kinds']);
            usort($kinds, fn ($a, $b) => $a['page'] <=> $b['page']);
            $data['pages'] = PageClassifier::ranges($kinds);
        } elseif (($upload['page_kinds'] ?? []) !== []) {
            // Statements' pages sent (an older page): the pages' kinds follow them; a statement's page no longer one is `other`.
            $kinds = PageClassifier::withRanges($upload['page_kinds'], $data['pages']);
        }
        $errors = [];
        // Last year's approved statements come with the auditor's report (the accountant's rule): a page of it, as the AI
        // said or the accountant corrects in the pages' kinds; the only way past.
        if (! PriorStatementsPdf::hasAuditorReport(['kind' => 'pdf', 'page_kinds' => $kinds ?? []])) {
            $errors[isset($data['page_kinds']) ? 'page_kinds' : 'pages'] = [__('report_messages.errors.pdf_no_auditor_report')];
        }
        foreach ($data['pages'] as $statement => $range) {
            if ($range['to'] < $range['from']) {
                $errors["pages.{$statement}.to"] = [__('report_messages.errors.pdf_pages_order')];
            }
        }
        if (! array_intersect_key($data['pages'], array_flip(PriorStatementsFile::STATEMENTS))) {
            $errors['pages'] = [__('report_messages.errors.pdf_pages_none')];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $pages = array_replace(array_intersect_key(array_flip(PriorStatementsPdf::FOUND), $data['pages']), $data['pages']);
        $report->update(['prior_upload' => array_merge(array_diff_key($upload, ['error' => 1, 'error_key' => 1]), [
            'status' => 'reading', 'pages' => $pages, 'blocks' => [], 'expenses_shown_positive' => null, 'document' => null],
            $kinds === null ? [] : ['page_kinds' => $kinds, 'skipped' => PageClassifier::skipped($kinds)])]);
        ReadPriorStatementsPdf::dispatch($report->id, $upload['id'], 'read');

        return response()->json($this->state($report->fresh(), $report->run), 202);
    }

    /**
     * The wording read from the PDF, as the accountant settles it (R11, R16): `known_words` marks words as right (a name,
     * a rare word): never flagged again, for any file; `keep_original` keeps a corrected word as printed (true) or takes
     * the correction again (false), by the correction's id; a word kept is also marked as right. The file waiting, else
     * the one in use; 409 before it is read.
     */
    public function priorStatementsWording(Request $request, string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $inUse = ($report->prior_figures['source'] ?? null) === 'statements' ? $report->prior_figures['file'] ?? null : null;
        $file = $report->prior_upload ?? $inUse;
        if (($file['kind'] ?? null) !== 'pdf' || ! isset($file['document'])) {
            return response()->json(['message' => __('report_messages.errors.pdf_not_ready')], 409);
        }
        $data = $request->validate([
            'known_words'     => ['required_without:keep_original', 'array', 'max:50'],
            'known_words.*'   => ['string', 'max:100', 'regex:/\p{Arabic}/u'],
            'keep_original'   => ['required_without:known_words', 'array'],
            'keep_original.*' => ['boolean'],
        ]);
        $corrections = array_column($file['document']['corrections'] ?? [], null, 'id');
        $unknown = array_diff(array_keys($data['keep_original'] ?? []), array_keys($corrections));
        if ($unknown) {
            throw ValidationException::withMessages(['keep_original' => __('report_messages.errors.correction_unknown')]);
        }
        $words = $data['known_words'] ?? [];
        $choices = $file['wording_choices'] ?? [];
        foreach ($data['keep_original'] ?? [] as $cid => $keep) {
            if ($keep) {
                $choices[$cid] = 'original';
                $words[] = $corrections[$cid]['original'];
            } else {
                unset($choices[$cid]);
            }
        }
        foreach ($words as $word) {
            $key = implode(' ', PriorPdf\Wording::normalised($word));
            if ($key !== '') {
                KnownWord::firstOrCreate(['word' => $key], ['label' => trim($word)]);
            }
        }
        if ($report->prior_upload !== null) {
            $report->update(['prior_upload' => ['wording_choices' => $choices] + $report->prior_upload]);
        } else {
            $report->update(['prior_figures' => ['file' => ['wording_choices' => $choices] + $inUse] + $report->prior_figures]);
        }

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * A printed total that differs from its columns' sum (PriorPdf\Totals), as the accountant settles it: `use`, by the
     * total's id, `printed` or `sum`; by a year end's balances' id (a group), the index of an option that agrees; null goes
     * back to the default. The file waiting, else the
     * one in use; 409 before it is read.
     */
    /**
     * Step B's setting: this year's openings from last year's approved statements (on by default) or the trial
     * balance's own. The comparative column is last year's approved statements either way.
     */
    public function approvedOpening(Request $request, string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $report->update(['prior_figures' => ['approved_opening' => (bool) $data['enabled']] + ($report->prior_figures ?? []), 'last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    public function priorStatementsTotals(Request $request, string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }
        $inUse = ($report->prior_figures['source'] ?? null) === 'statements' ? $report->prior_figures['file'] ?? null : null;
        $file = $report->prior_upload ?? $inUse;
        if (($file['kind'] ?? null) !== 'pdf' || ! isset($file['document'])) {
            return response()->json(['message' => __('report_messages.errors.pdf_not_ready')], 409);
        }
        $data = $request->validate([
            'use'   => ['required', 'array', 'min:1'],
            'use.*' => ['nullable', 'string', 'max:10'],
        ]);
        $totals = array_column($file['document']['totals'] ?? [], null, 'id');
        $groups = array_column($file['document']['total_groups'] ?? [], null, 'id');
        foreach ($data['use'] as $tid => $use) {
            // A total: printed or sum; a year end's balances (a group): one of its options, by its index.
            $ok = isset($groups[$tid]) ? $use === null || (ctype_digit($use) && isset($groups[$tid]['options'][(int) $use]))
                : isset($totals[$tid]) && in_array($use, [null, 'printed', 'sum'], true);
            if (! $ok) {
                throw ValidationException::withMessages(['use' => __('report_messages.errors.total_unknown')]);
            }
        }
        $choices = $file['total_choices'] ?? [];
        foreach ($data['use'] as $tid => $use) {
            if ($use === null) {
                unset($choices[$tid]);
            } else {
                $choices[$tid] = $use;
            }
        }
        if ($report->prior_upload !== null) {
            $report->update(['prior_upload' => ['total_choices' => $choices] + $report->prior_upload]);
        } else {
            $report->update(['prior_figures' => ['file' => ['total_choices' => $choices] + $inUse] + $report->prior_figures]);
        }

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /** The PDF of last year's statements (the one waiting, else the one in use), to look at its pages. */
    public function priorStatementsPdf(string $id): BinaryFileResponse|JsonResponse
    {
        $report = FinancialReport::find($id);
        $file = $report?->prior_upload ?? (($report?->prior_figures['source'] ?? null) === 'statements' ? $report->prior_figures['file'] ?? null : null);
        if (! $report || ($file['kind'] ?? null) !== 'pdf' || ! Storage::disk('local')->exists($file['path'])) {
            return $this->notFound();
        }

        return response()->file(Storage::disk('local')->path($file['path']), [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="prior-statements.pdf"']);
    }

    /** A PDF waiting for review is replaced: its file goes, unless the statements in use came from it. */
    private function dropWaitingPdf(FinancialReport $report): void
    {
        $waiting = $report->prior_upload;
        $inUse = $report->prior_figures['file']['path'] ?? null;
        if (($waiting['kind'] ?? null) === 'pdf' && $waiting['path'] !== $inUse) {
            Storage::disk('local')->delete($waiting['path']);
        }
    }

    /**
     * The file a `statements` source uses: the one waiting for review, else the one in use; and what the user still has to
     * decide on it: every row with an amount that isn't an exact match, the year column where the file doesn't name it,
     * and expenses shown positive.
     *
     * @return array{0: ?array, 1: array<string, list<string>>}
     */
    private function priorFile(FinancialReport $report, Request $request): array
    {
        $catalogue = new LineCatalogue($report->template_version);
        // A file waiting is matched with the synonyms as they are now; the one in use keeps its matches.
        $file = $report->prior_upload !== null ? PriorStatementsFile::rematch($report->prior_upload, $catalogue, StatementSynonyms::map())
            : (($report->prior_figures['source'] ?? null) === 'statements' ? $report->prior_figures['file'] ?? null : null);
        if ($file === null) {
            return [null, ['source' => [__('report_messages.errors.file_missing')]]];
        }
        if (($file['status'] ?? 'ready') !== 'ready') {   // a PDF whose tables aren't read yet
            return [null, ['source' => [__('report_messages.errors.pdf_not_ready')]]];
        }
        if (! PriorStatementsPdf::hasAuditorReport($file)) {
            return [null, ['source' => [__('report_messages.errors.pdf_no_auditor_report')]]];
        }
        $rows = (array) $request->input('rows', []);
        $columns = (array) $request->input('columns', []);
        $errors = [];
        $known = [];
        foreach ($file['blocks'] as $b => $block) {
            $targets = array_column(array_filter($catalogue->statement($block['statement']), fn ($i) => in_array($i['type'], ['line', 'total'], true)), 'key');
            $column = $columns[$b] ?? null;
            if ($column !== null && ! in_array($column, array_column($block['columns'], 'column'), true)) {
                $errors["columns.{$b}"][] = __('report_messages.errors.file_column_unknown');
            }
            if ($column === null && ! $block['column_certain']) {
                $errors["columns.{$b}"][] = __('report_messages.errors.file_column_choose', ['statement' => __("statements.statements.{$block['statement']}")]);
            }
            $column ??= $block['column'];
            foreach ($block['rows'] as $r) {
                $ref = "{$b}:{$r['id']}";
                $known[$ref] = true;
                $choice = $rows[$ref] ?? null;
                if ($choice !== null && $choice !== 'ignore' && ! in_array($choice, $targets, true)) {
                    $errors["rows.{$ref}"][] = __('report_messages.errors.file_row_line', ['statement' => __("statements.statements.{$block['statement']}")]);
                }
                // Waits for a choice: a row with an amount the file doesn't place, unless the amount is nothing or the row is a
                // total by its arithmetic (a PDF's: a check, never an item, R10).
                $value = $r['values'][$column] ?? ['status' => 'empty'];
                if ($choice === null && ! in_array($r['certainty'], ['exact', 'synonym'], true) && $value['status'] !== 'empty'
                    && ! ($value['status'] === 'ok' && abs((float) $value['amount']) < 0.005) && ($r['kind'] ?? null) !== 'total') {
                    $errors["rows.{$ref}"][] = __('report_messages.errors.file_row_choose', ['row' => $r['row'], 'label' => $r['label']]);
                }
            }
        }
        foreach (array_keys($rows) as $ref) {
            if (! isset($known[$ref])) {
                $errors["rows.{$ref}"][] = __('report_messages.errors.file_row_unknown');
            }
        }
        if (($file['expenses_shown_positive'] ?? null) === true && ! $request->has('expenses_shown_positive')) {
            $errors['expenses_shown_positive'][] = __('report_messages.errors.file_expenses_positive');
        }

        return [$file, $errors];
    }

    /**
     * The user's choices on the notes (PR 11, Q11, Q13): {visibility: {note or policy: on | off | auto}, texts: {paragraph:
     * text | null}}. Partial: only the keys sent change; `auto` and null drop a choice. A text replaces a shown paragraph's
     * wording; the wording it replaced is kept, so `edited_text_outdated` can say when the template's text moves later.
     */
    public function updateNotes(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'visibility'   => ['nullable', 'array'],
            'visibility.*' => ['string', 'in:on,off,auto'],
            'texts'        => ['nullable', 'array'],
            'texts.*'      => ['nullable', 'string', 'max:5000'],
            // Last year's note lines (R12): note → account (row number, or `adjustments`) → a line's key, `new`, or null
            // (back to the line proposed).
            'items'        => ['nullable', 'array'],
            'items.*'      => ['array'],
            'items.*.*'    => ['nullable', 'string', 'max:300'],
            // Last year's PPE categories (R13): account (row number) → a category's key, `none`, or null (back to the proposal).
            'ppe'          => ['nullable', 'array'],
            'ppe.*'        => ['nullable', 'string', 'max:20'],
            // The notes' order (the freedom to arrange them): the note keys in the order wanted; [] back to the template's.
            'order'        => ['nullable', 'array', 'max:100'],
            'order.*'      => ['string', 'max:60'],
            // An account moved to any note (the freedom to choose its note): account (row number) => {note, line?, item?}, or
            // null (back to where the classification puts it). Its statement line becomes the note's line.
            'moves'        => ['nullable', 'array', 'max:2000'],
            'moves.*'      => ['nullable', 'array'],
            'moves.*.note' => ['required_with:moves.*', 'string', 'max:60'],
            'moves.*.line' => ['nullable', 'string', 'max:100'],
            'moves.*.item' => ['nullable', 'string', 'max:300'],
        ]);
        if (! $request->has('visibility') && ! $request->has('texts') && ! $request->has('items') && ! $request->has('ppe') && ! $request->has('order') && ! $request->has('moves')) {
            throw ValidationException::withMessages(['visibility' => [__('report_messages.errors.notes_nothing_sent')]]);
        }
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $catalogue = new LineCatalogue($report->template_version);
        $allowed   = [...array_keys(array_filter($catalogue->notes(), fn ($n) => isset($n['block']))), ...array_keys(__('statements.policies', [], 'ar'))];
        $counts    = NarrativeRenderer::paragraphCounts(NotesTemplate::load($report->template_version), array_keys($catalogue->notes()));
        $options   = $report->notes ?? [];
        $shown     = [];
        $built     = $this->statements->build($report);
        foreach ($built['notes'] as $n) {
            foreach ($n['blocks'] as $b) {
                if ($b['type'] === 'text') {
                    $shown[$b['key']] = $b['template_ar'];
                }
            }
        }
        $errors = [];
        // Laravel turns blanks into null (back to the template); a blank sent on purpose is refused instead.
        foreach ((array) (json_decode($request->getContent(), true)['texts'] ?? []) as $key => $raw) {
            if (is_string($raw) && trim($raw) === '') {
                $errors["texts.{$key}"][] = __('report_messages.errors.note_text_short');
            }
        }
        foreach ((array) $request->input('visibility', []) as $key => $choice) {
            if (! in_array($key, $allowed, true)) {
                $errors["visibility.{$key}"][] = __('report_messages.errors.note_visibility_fixed');
                continue;
            }
            if ($choice === 'auto') {
                unset($options['visibility'][$key]);
            } else {
                $options['visibility'][$key] = $choice;
            }
        }
        foreach ((array) $request->input('texts', []) as $key => $text) {
            [$note, $n] = array_pad(explode('.', (string) $key, 2), 2, null);
            if (! isset($counts[$note]) || ! ctype_digit((string) $n) || (int) $n < 1 || (int) $n > $counts[$note]) {
                $errors["texts.{$key}"][] = __('report_messages.errors.note_paragraph_unknown');
                continue;
            }
            if (isset($errors["texts.{$key}"])) {
                continue;
            }
            if ($text === null) {
                unset($options['texts'][$key]);
                continue;
            }
            if (mb_strlen(trim($text)) < 3) {
                $errors["texts.{$key}"][] = __('report_messages.errors.note_text_short');
            } elseif (! isset($shown[$key])) {
                $errors["texts.{$key}"][] = __('report_messages.errors.note_paragraph_hidden');
            } else {
                $options['texts'][$key] = ['text' => trim($text), 'base' => $options['texts'][$key]['base'] ?? $shown[$key]];
            }
        }
        foreach ((array) $request->input('items', []) as $note => $accounts) {
            $known = $built['note_items'][$note] ?? null;
            if ($known === null) {
                $errors["items.{$note}"][] = __('report_messages.errors.note_items_none');
                continue;
            }
            foreach ($accounts as $ref => $item) {
                if (! in_array((string) $ref, array_column($known['accounts'], 'ref'), true)) {
                    $errors["items.{$note}.{$ref}"][] = __('report_messages.errors.note_items_account');
                } elseif ($item !== null && $item !== 'new' && ! in_array($item, array_column($known['items'], 'key'), true)) {
                    $errors["items.{$note}.{$ref}"][] = __('report_messages.errors.note_items_line');
                } elseif ($item === null) {
                    unset($options['items'][$note][$ref]);
                } else {
                    $options['items'][$note][(string) $ref] = $item;
                }
            }
        }
        $ppe = $built['ppe_prior'] ?? null;
        foreach ((array) $request->input('ppe', []) as $row => $category) {
            if ($ppe === null || ! in_array((int) $row, array_column($ppe['accounts'], 'row'), true)) {
                $errors["ppe.{$row}"][] = __('report_messages.errors.ppe_account');
            } elseif ($category !== null && $category !== 'none' && ! in_array($category, array_column($ppe['categories'], 'key'), true)) {
                $errors["ppe.{$row}"][] = __('report_messages.errors.ppe_category');
            } elseif ($category === null) {
                unset($options['ppe'][$row]);
            } else {
                $options['ppe'][(string) $row] = $category;
            }
        }
        if ($request->has('order')) {
            $order = array_values((array) $request->input('order', []));
            foreach ($order as $i => $key) {
                if (! isset($catalogue->notes()[$key])) {
                    $errors["order.{$i}"][] = __('report_messages.errors.note_unknown');
                }
            }
            $options['order'] = $order;
        }
        $overrides = $report->account_overrides ?? [];
        $rows = array_flip(array_column($report->run->result['data'], 'row'));
        foreach ((array) $request->input('moves', []) as $row => $move) {
            $was = $options['moves'][(string) $row] ?? null;
            if ($move === null) {
                // Back: the override the move made goes, and its line's choice.
                if ($was !== null) {
                    $overrides = array_values(array_filter($overrides, fn ($o) => ! ((int) $o['row'] === (int) $row && ($o['line_key'] ?? null) === $was['line'])));
                    unset($options['moves'][(string) $row], $options['items'][$was['note']][(string) $row]);
                }
                continue;
            }
            $note = $catalogue->notes()[$move['note']] ?? null;
            $line = $move['line'] ?? ($note['lines'][0] ?? null);
            if (! isset($rows[(int) $row])) {
                $errors["moves.{$row}"][] = __('report_messages.errors.account_not_in_tb');
            } elseif ($note === null || $line === null || ! in_array($line, $note['lines'] ?? [], true)) {
                $errors["moves.{$row}.note"][] = __('report_messages.errors.note_move_target');
            } else {
                if ($was !== null) {
                    unset($options['items'][$was['note']][(string) $row]);
                }
                $overrides = array_values(array_filter($overrides, fn ($o) => (int) $o['row'] !== (int) $row || isset($o['exclude'])));
                $overrides[] = ['row' => (int) $row, 'line_key' => $line];
                $options['moves'][(string) $row] = ['note' => $move['note'], 'line' => $line];
                if (($move['item'] ?? null) !== null) {
                    $options['items'][$move['note']][(string) $row] = $move['item'];
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $options = array_filter(['visibility' => $options['visibility'] ?? [], 'texts' => $options['texts'] ?? [], 'items' => array_filter($options['items'] ?? []),
            'ppe' => $options['ppe'] ?? [], 'order' => $options['order'] ?? [], 'moves' => $options['moves'] ?? []]);
        $report->update(['notes' => $options ?: null, 'account_overrides' => $overrides, 'last_computed_at' => now()]);

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * The notes an account can be moved to: each note with an item table (a breakdown), as numbered now, with its statement
     * lines and, with last year's statements from a PDF, its lines as printed there.
     */
    private function noteTargets(array $built): array
    {
        $catalogue = $built['context']->catalogue;
        $numbers = array_column($built['notes'], 'number', 'key');
        $titles = array_column($built['notes'], 'title_ar', 'key');
        $labels = [];
        foreach (['financial_position', 'comprehensive_income'] as $st) {
            foreach ($catalogue->statement($st) as $i) {
                if (isset($i['key'])) {
                    $labels[$i['key']] = $i['label_ar'];
                }
            }
        }
        $ctx = $built['context'];
        $out = [];
        foreach ($catalogue->notes() as $key => $n) {
            if (! isset($n['breakdown']) || ($n['lines'] ?? []) === []) {
                continue;
            }
            // The trial balance's accounts on the note's lines now, with their balance (as the line shows it).
            $accounts = [];
            foreach ($ctx->mapping['rows'] as $row => $m) {
                $on = array_values(array_intersect(array_keys($m['lines']['closing']), $n['lines']));
                if ($on === []) {
                    continue;
                }
                $sum = $ctx->sum([$row => 1.0], $catalogue->side($on[0]));
                if (abs((float) ($sum['closing'] ?? 0)) < 0.005 && abs((float) ($sum['opening'] ?? 0)) < 0.005) {
                    continue;
                }
                $accounts[] = ['row' => $row, 'number' => $ctx->rows[$row]['account_number'] ?? null, 'name' => $ctx->rows[$row]['account_name'] ?? (string) $row,
                    'line' => $on[0], 'amount' => round((float) ($sum['closing'] ?? 0), 2)];
            }
            $out[] = ['note' => $key, 'number' => $numbers[$key] ?? null, 'title' => $titles[$key] ?? $key,
                'lines' => array_map(fn ($l) => ['key' => $l, 'label' => $labels[$l] ?? $l], $n['lines']),
                'items' => array_map(fn ($i) => ['key' => $i['key'], 'label' => $i['label']], $built['note_items'][$key]['items'] ?? []),
                'accounts' => $accounts];
        }

        return $out;
    }

    /**
     * Replaces the acknowledged warnings: [{key, note?}], each key a warning the report has now. Acknowledging
     * `run_changed` takes the run as it is now instead of being stored.
     */
    public function updateAcknowledgements(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'acknowledgements'        => ['present', 'array', 'list'],
            'acknowledgements.*.key'  => ['required', 'string', 'max:100'],
            'acknowledgements.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $warnings = array_column(array_filter($this->statements->build($report)['checks'], fn ($c) => $c['severity'] === 'warning'), null, 'key');
        $stored = $errors = [];
        $takeRun = false;
        foreach ($request->input('acknowledgements') as $i => $a) {
            $check = $warnings[$a['key']] ?? null;
            if ($check === null) {
                $errors["acknowledgements.{$i}.key"] = [__('report_messages.errors.acknowledgement_unknown')];
                continue;
            }
            if ($check['code'] === 'input_to_confirm') {
                $errors["acknowledgements.{$i}.key"] = [__('report_messages.errors.acknowledgement_confirm_input')];
                continue;
            }
            if ($check['code'] === 'run_changed') {
                $takeRun = true;
                continue;
            }
            $previous = collect($report->acknowledgements ?? [])->firstWhere('key', $a['key']);
            $stored[$a['key']] = ['key' => $a['key'], 'note' => $a['note'] ?? null,
                'acknowledged_at' => $previous['acknowledged_at'] ?? now()->toIso8601String()];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $report->update(['acknowledgements' => array_values($stored) ?: null, 'last_computed_at' => now()]
            + ($takeRun ? ['run_result_hash' => $this->source->hash($report->run)] : []));

        return response()->json($this->state($report->fresh(), $report->run));
    }

    /**
     * Freezes the report's state as a new version. A draft always can; a final only when nothing blocks it (Q20):
     * 422 {message, checks, blocking} otherwise.
     */
    public function storeVersion(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:draft,final']]);
        if ($data['kind'] === FinancialReport::FINAL) {
            Gate::authorize('reports.finalize');
        }
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        $state = $this->state($report, $report->run);
        if ($data['kind'] === FinancialReport::FINAL && ! $state['can_finalize']) {
            return response()->json([
                'message'  => __('report_messages.final_blocked', ['items' => implode(__('report_messages.list_separator'), array_map(fn ($b) => __('report_messages.blocking_item', ['label' => $b['label'], 'count' => $b['count']]), $state['blocking']))]),
                'checks'   => array_values(array_filter($state['checks'], fn ($c) => $c['severity'] === 'error' || ($c['severity'] === 'warning' && ! $c['acknowledged']))),
                'blocking' => $state['blocking'],
            ], 422);
        }

        // A version is a record, like the exports: its messages are kept in Arabic whatever language the saver uses.
        if (app()->getLocale() !== 'ar') {
            $locale = app()->getLocale();
            app()->setLocale('ar');
            $state = $this->state($report, $report->run);
            app()->setLocale($locale);
        }

        $version = DB::transaction(function () use ($report, $state, $data) {
            FinancialReport::whereKey($report->id)->lockForUpdate()->first();
            $number = (int) FinancialReportVersion::where('report_id', $report->id)->max('version') + 1;
            $now = now();
            $payload = ['report_id' => $report->id, 'version' => $number, 'kind' => $data['kind']] + $state;
            if ($data['kind'] === FinancialReport::FINAL) {
                $report->update(['status' => FinancialReport::FINAL, 'finalized_at' => $now]);
                $payload = ['status' => FinancialReport::FINAL, 'finalized_at' => $now->toIso8601String()] + $payload;
            }
            unset($payload['versions'], $payload['changed_since_final']);

            return FinancialReportVersion::create(['report_id' => $report->id, 'version' => $number, 'kind' => $data['kind'],
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'inputs_hash' => $report->inputsHash(), 'run_result_hash' => (string) $this->source->hash($report->run),
                'content_hash' => self::contentHash($state)]);
        });

        return response()->json($this->presentVersion($version, true), 201);
    }

    /** The report's versions, newest first, without their payloads. */
    public function versions(string $id): JsonResponse
    {
        $report = FinancialReport::find($id);
        if (! $report) {
            return $this->notFound();
        }

        return response()->json(['data' => array_map(fn ($v) => $this->presentVersion($v, false), $this->versionList($report))]);
    }

    public function showVersion(string $id, int $version): JsonResponse
    {
        $report = FinancialReport::find($id);
        $found  = $report ? collect($this->versionList($report))->firstWhere('version', $version) : null;
        if (! $found) {
            return response()->json(['message' => $report ? __('report_messages.errors.version_not_found') : __('report_messages.errors.report_not_found')], 404);
        }

        return response()->json($this->presentVersion($found, true));
    }

    /** @return list<FinancialReportVersion> newest first, each final knowing the final that superseded it */
    private function versionList(FinancialReport $report): array
    {
        $versions = FinancialReportVersion::where('report_id', $report->id)->orderByDesc('version')->get()->all();
        $later = null;
        foreach ($versions as $v) {
            $v->superseded_by = $v->kind === FinancialReport::FINAL ? $later : null;
            if ($v->kind === FinancialReport::FINAL) {
                $later = $v->version;
            }
        }

        return $versions;
    }

    private function presentVersion(FinancialReportVersion $v, bool $payload): array
    {
        return ['report_id' => $v->report_id, 'version' => $v->version, 'kind' => $v->kind, 'superseded_by' => $v->superseded_by ?? null,
            'created_at' => $v->created_at?->toIso8601String(), 'inputs_hash' => $v->inputs_hash, 'run_result_hash' => $v->run_result_hash,
            'content_hash' => $v->content_hash] + ($payload ? ['payload' => $v->payloadJson()] : []);
    }

    /** sha1 of what the statements and notes show: a change here, and only here, is a change since the final. */
    private static function contentHash(array $state): string
    {
        $state['company'] = $state['company'] ? array_diff_key($state['company'], ['created_at' => 1, 'updated_at' => 1]) : null;
        // `defaulted` only restates the comparatives' figures: added later, it must not make an earlier final look changed.
        unset($state['comparative']['defaulted'], $state['comparative']['approved_opening']);

        return sha1(json_encode(array_intersect_key($state, array_flip(['company', 'period_start', 'period_end', 'comparative_start',
            'comparative_end', 'template_version', 'statements', 'schedules', 'notes', 'narrative', 'going_concern', 'comparative']))));
    }

    /**
     * What `PUT …/prior-year` accepts as typed from last year's statements, as this report stands (the wizard's grids):
     * statement lines, the changes in equity's rows and columns, cash-flow lines, each item note's classes (this year's;
     * others may be typed too) with the cost of revenue's build-up, and the movement tables' cells.
     */
    private function priorUpload(FinancialReport $report, ?array $opening = null, ?ScheduleContext $context = null): ?array
    {
        $inUse = ($report->prior_figures['source'] ?? null) === 'statements' ? $report->prior_figures : null;
        $file = $report->prior_upload !== null
            ? PriorStatementsFile::rematch($report->prior_upload, new LineCatalogue($report->template_version), StatementSynonyms::map())
            : ($inUse['file'] ?? null);
        if ($file === null) {
            return null;
        }
        $mine = $report->prior_upload === null;
        $pdf = ($file['kind'] ?? null) === 'pdf';

        return ['name' => $file['name'], 'uploaded_at' => $file['uploaded_at'], 'year' => $file['year'], 'in_use' => $mine,
            'expenses_shown_positive' => $file['expenses_shown_positive'],
            'blocks' => array_map(fn ($b) => ['statement' => $b['statement'], 'sheet_name' => $b['sheet_name'], 'columns' => $b['columns'],
                'column' => $b['column'], 'column_certain' => $b['column_certain'],
                'rows' => array_map(fn ($r) => ['id' => $r['id'], 'row' => $r['row'], 'label' => $r['label'], 'values' => (object) $r['values'],
                    'match' => $r['match'], 'certainty' => $r['certainty']]
                    + ($pdf ? ['page' => $r['page'] ?? null, 'check' => $r['check'] ?? [], 'kind' => $r['kind'] ?? null] : []), $b['rows'])], $file['blocks']),
            'kind' => $pdf ? 'pdf' : 'excel',
            // This year's opening balances against the file's closing figures, line by line (every difference shown, a
            // riyal either way labelled probably rounding).
            'opening_check' => $check = OpeningCheck::lines(new LineCatalogue($report->template_version), $opening, $file,
                $mine ? array_intersect_key($inUse ?? [], array_flip(['rows', 'columns', 'expenses_shown_positive'])) : null),
            // Accounts this year's trial balance puts on another line than last year's statements: proposed back on last
            // year's line (one click each), and the accounts a line didn't have last year, for the accountant to send.
            'comparable' => $context === null ? ['proposals' => [], 'strays' => []] : (function () use ($report, $context, $check, $file, $mine, $inUse) {
                $catalogue = new LineCatalogue($report->template_version);
                $proposals = ComparableLines::proposals($catalogue, $context, $check, $file, $mine ? ($inUse['rows'] ?? []) : []);

                return ['proposals' => $proposals, 'strays' => ComparableLines::strays($catalogue, $context, $check, $proposals)];
            })(),
        ] + ($pdf ? [
            // A PDF: where its reading stands, the statements' pages (as found, and as confirmed), and its AI cost.
            'status' => $file['status'], 'pages_total' => $file['pages_total'], 'pages' => (object) $file['pages'], 'found' => (object) $file['found'],
            // No page of the auditor's report among the pages' kinds: not accepted as approved statements until one is.
            'rejected' => isset($file['page_kinds']) && ! PriorStatementsPdf::hasAuditorReport($file) ? __('report_messages.errors.pdf_no_auditor_report') : null,
            'skipped' => (object) ($file['skipped'] ?? []),
            // What each page is (brief §8.1): as the AI said until the accountant corrects it, then as corrected.
            'page_kinds' => $file['page_kinds'] ?? [],
            // What the reading couldn't settle (P3), each with its page: checks still failing after reading again, years
            // printed wrong, numbering slips, years in the text to check.
            'issues' => PriorPdfIssues::of($file['document'] ?? null, $file['wording_choices'] ?? [],
                array_fill_keys(KnownWord::query()->pluck('word')->all(), true), $file['total_choices'] ?? []),
            // Last year's changes in equity read from the file (PriorPdf\EquityBlock), to prefill the comparative grid.
            'equity' => $file['equity'] ?? null,
            // The company as last year's notes describe it (CompanyFacts, the corrections as the accountant keeps them).
            'company' => (object) (isset($file['document']) ? PriorPdf\CompanyFacts::of($file['document'], $file['document']['corrections'] ?? [], $file['wording_choices'] ?? []) : []),
            // Whether that company is the report's: the name or registration differing from its profile, and the app's other
            // companies the file names (the same registration, or name). Said only; the user chooses the company.
            'company_identity' => PriorPdf\CompanyIdentity::of($report->company, isset($file['document'])
                ? PriorPdf\CompanyFacts::of($file['document'], $file['document']['corrections'] ?? [], $file['wording_choices'] ?? []) : []),
            'error' => isset($file['error_key']) ? __("report_messages.errors.{$file['error_key']}", ['message' => (string) ($file['error'] ?? '')]) : null,
            'cost_usd' => $file['cost_usd'] ?? null,
        ] : []) + [
            'choices' => $mine ? ['rows' => (object) ($inUse['rows'] ?? []), 'columns' => (object) ($inUse['columns'] ?? []),
                'expenses_shown_positive' => $inUse['expenses_shown_positive'] ?? null] : null];
    }

    private function priorYearEntry(FinancialReport $report, array $built): array
    {
        $catalogue = new LineCatalogue($report->template_version);
        $notes = $inUse = [];
        foreach ($built['breakdowns'] ?? [] as $note => $b) {
            $notes[$note] = array_column($b['rows'], 'key');
            // Last year's figures the note uses: read from the PDF (read-only: typing there would change nothing, its page
            // given), else typed or from the approved line (to check or correct); a figure not known left out.
            $read = ($b['prior_items'] ?? false) === true;
            $inUse[$note] = ['read_only' => $read, 'page' => $read ? ($built['note_items'][$note]['page'] ?? null) : null,
                'values' => (object) array_map(fn ($r) => round($r['comparative']->amount, 2) + 0.0,
                    array_column(array_filter($b['rows'], fn ($r) => isset($r['comparative']) && ! $r['comparative']->isMissing()), null, 'key'))];
        }

        // The movement tables' cells as in use now (typed, or last year's PPE note), by the keys typed: shown in the grid, a
        // cell shown saved only when changed.
        $schedulesPrior = [];
        foreach ($built['schedules'] as $s) {
            foreach (in_array($s['kind'], ['asset', 'provision'], true) ? $s['rows'] : [] as $r) {
                foreach (array_keys($r['cells']) as $col) {
                    $f = $built['schedules_comparative']["{$s['key']}.{$r['key']}.{$col}"] ?? null;
                    if ($f !== null && ! $f->isMissing()) {
                        $schedulesPrior[$s['kind'] === 'provision' ? "{$s['key']}.{$r['key']}" : "{$s['key']}.{$r['key']}.{$col}"] = round($f->amount, 2) + 0.0;
                    }
                }
            }
        }
        // Last year's PPE movement read from the PDF by its own categories (R13), the note's comparative: nothing to type,
        // unless its categories can't be told apart in this year's (then the note's own categories, typed or as read).
        $ppeRead = ($built['ppe_prior']['categories'] ?? []) !== [] && ! in_array('ppe_columns_own', array_column($built['checks'], 'code'), true);

        return [
            'figures'           => PriorYearResolver::manualKeys($catalogue),
            'changes_in_equity' => ['rows' => EquityStatementBuilder::ENTERED, 'columns' => array_keys($catalogue->changesInEquity()['columns'])],
            'cash_flows'        => array_column(array_filter($catalogue->statement('cash_flows'), fn ($i) => $i['type'] === 'line'), 'key'),
            'notes'             => (object) $notes,
            'notes_prior'       => (object) $inUse,
            'build_up'          => \App\Services\Statements\NoteBreakdowns::BUILD_UP,
            'schedules'         => NoteComparatives::typedScheduleKeys($built['schedules']),
            'schedules_prior'   => (object) array_intersect_key($schedulesPrior, array_flip(NoteComparatives::typedScheduleKeys($built['schedules']))),
            'ppe_from_pdf'      => $ppeRead ? ['page' => $built['ppe_prior']['categories'][0]['page'] ?? null] : null,
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('report_messages.errors.report_not_found')], 404);
    }

    private function summary(FinancialReport $r): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'company_id' => $r->company_id, 'classification_run_id' => $r->classification_run_id,
            'template_version' => $r->template_version,
            'period_start' => $r->period_start->format('Y-m-d'), 'period_end' => $r->period_end->format('Y-m-d'),
            'comparative_start' => $r->comparative_start?->format('Y-m-d'), 'comparative_end' => $r->comparative_end?->format('Y-m-d'),
            'last_computed_at' => $r->last_computed_at?->toIso8601String(), 'finalized_at' => $r->finalized_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(), 'updated_at' => $r->updated_at?->toIso8601String(),
            'status_url' => '/api/v1/financial-reports/'.$r->id,
        ];
    }

    /**
     * ReportState: the summary, the company, the run's layout and line mapping, the current year's statements with their
     * checks and missing figures, and whether the run changed since.
     */
    private function state(FinancialReport $report, ClassificationRun $run): array
    {
        $built = $this->statements->build($report);
        [$report, $built] = $this->autoCharge($report, $built);
        $names = array_column(array_map(fn ($r) => [$r['row'], ['account_number' => $r['account_number'] ?? null, 'account_name' => $r['account_name'] ?? null]], $run->result['data']), 1, 0);
        $named = fn (array $items) => array_values(array_map(fn ($i) => $i + ($names[$i['row']] ?? ['account_number' => null, 'account_name' => null]), $items));
        // A message the classification side stored in Arabic, also in the request's language (message_text); old runs: as stored.
        $texts = fn (array $items) => array_map(function ($i) {
            if (array_key_exists('message', $i)) {
                $i['message_text'] = \App\Support\Phrase::localize($i['message'], $i['message_phrase'] ?? null);
            }
            unset($i['message_phrase']);

            return $i;
        }, $items);
        $rows  = fn (array $items) => array_map(fn ($i) => isset($i['current']) ? ['current' => $i['current']->toArray()] + $i : $i, $items);
        $schedules = array_map(fn ($s) => array_diff_key($s, ['facts' => 1]) + ['rows' => []], $built['schedules']);
        foreach ($schedules as $k => $s) {
            $schedules[$k]['rows'] = array_map(fn ($r) => ['cells' => array_map(fn ($f) => $f->toArray(), $r['cells'])] + $r, $built['schedules'][$k]['rows']);
        }

        $state = $this->summary($report) + [
            'company'    => $report->company ? CompanyController::present($report->company) : null,
            'layout'     => $built['profile']->toArray(),
            'mapping'    => [
                'mapped'    => count($built['mapping']['rows']),
                'unmapped'  => $texts($named($built['mapping']['unmapped'])),
                'excluded'  => $named($built['mapping']['excluded']),
                'proposals' => $built['mapping']['proposals'],
                // Probable parents (their balances are the rows' below them): the user excludes or keeps each.
                'parent_proposals' => $texts($named($built['mapping']['parent_proposals'])),
                // Each line's accounts from the trial balance, before the report's entries, as the statement shows them (D1).
                'line_totals' => (object) ($built['line_totals'] ?? []),
                // Rows the user moved (a line or shares, or the loans' contra tag): where they are now and where the
                // classification alone puts them (PR 13c, the review).
                'overridden' => $named(array_map(fn ($m) => ['row' => $m['row'], 'lines' => (object) $m['lines']['closing'],
                    'original' => ['lines' => $m['original']['lines'] === null ? null : (object) $m['original']['lines'], 'reason' => $m['original']['reason']]],
                    array_filter($built['mapping']['rows'], fn ($m) => isset($m['original'])))),
            ],
            'statements' => array_map($rows, $built['statements']),
            'checks'     => $built['checks'],
            'missing'    => $built['missing'],
            'inputs'            => (object) ($report->inputs ?? []),
            'required_inputs'   => $built['required_inputs'],
            'account_overrides' => $report->account_overrides ?? [],
            // Each entry line on an account carries its number and name (PR 14: a version's journal entries need no run).
            'adjustments'       => array_map(fn ($e) => ['lines' => array_map(fn ($l) => isset($l['row']) ? $l + ($names[$l['row']] ?? ['account_number' => null, 'account_name' => null]) : $l, $e['lines'])] + $e,
                $report->adjustments ?? []),
            'schedules'         => $schedules,
            'suggestions'       => $built['suggestions'],
            'movement_decisions' => (object) ($report->movement_decisions ?? []),
            'notes'             => array_map(fn ($n) => ['blocks' => array_map(fn ($b) => $b['type'] === 'table'
                ? ['rows' => array_map(fn ($r) => ['cells' => array_map(fn ($c) => isset($c['amount']) ? ['amount' => $c['amount']->toArray()] : $c, $r['cells'])], $b['rows'])] + $b
                : $b, $n['blocks'])] + $n, $built['notes']),
            'note_options'      => ['visibility' => (object) ($report->notes['visibility'] ?? []), 'texts' => (object) ($report->notes['texts'] ?? []),
                'items' => (object) ($report->notes['items'] ?? []), 'ppe' => (object) ($report->notes['ppe'] ?? []),
                'order' => $report->notes['order'] ?? [], 'moves' => (object) ($report->notes['moves'] ?? [])],
            // Where an account can be moved (the freedom to choose its note): each note with an item table, its lines, and its
            // last year's lines when the PDF gives them.
            'note_targets'      => $this->noteTargets($built),
            // Last year's PPE note (R13) with a PDF of last year's statements: its categories (rate, useful life, last
            // year's cells, this year's from the accounts placed on them, the opening check) and the accounts.
            'ppe_prior'         => $built['ppe_prior'] ?? null,
            // Last year's note lines (R11, R12) with a PDF of last year's statements: by note, its lines and this
            // year's accounts, each on a line (or new), and why.
            'note_items'        => (object) ($built['note_items'] ?? []),
            'narrative'         => $built['narrative'],
            'going_concern'     => $built['going_concern'],
            // The zakat base as last year's zakat note computed it (with last year's statements from a PDF), else null.
            'zakat_base'        => $built['zakat_base'] ?? null,
            // The zakat and income tax of the year, calculated from the statements (ZakatCalculator) with the accountant's answers.
            'zakat_tax'         => $this->zakatTax($report, $built),
            'comparative'       => [
                'source'           => $built['comparative']['source'],
                'prior_report_id'  => $report->prior_report_id,
                'prior_run_id'     => $report->prior_run_id,
                'restated'         => $built['comparative']['restated'],
                // R8: whether the trial balance has opening balances (present / absent / unknown: no opening column), so
                // whether last year's statements are needed; `automatic`: a first year because there are none.
                'opening_balances' => $built['comparative']['opening_balances'] ?? 'unknown',
                'opening_signal'   => $built['comparative']['opening_signal'] ?? null,
                'prior_needed'     => $built['comparative']['prior_needed'] ?? true,
                'automatic'        => $built['comparative']['automatic'] ?? false,
                'skip_opening'     => (bool) ($report->prior_figures['skip_opening'] ?? false),
                // Step B: this year's openings from last year's approved statements (a setting, on by default), and the
                // reconciliation of each line with the trial balance (null without last year's statements from a file).
                'approved_opening' => ['enabled' => ($report->prior_figures['approved_opening'] ?? true) !== false] + ($built['approved_opening'] ?? ['status' => null]),
                'restatement_note' => $report->prior_figures['restatement_note'] ?? null,
                'figures'          => (object) ($report->prior_figures['figures'] ?? []),
                'changes_in_equity' => (object) ($report->prior_figures['changes_in_equity'] ?? []),
                'cash_flows'       => (object) ($report->prior_figures['cash_flows'] ?? []),
                'notes'            => (object) ($report->prior_figures['notes'] ?? []),
                'schedules'        => (object) ($report->prior_figures['schedules'] ?? []),
                // A file of last year's issued statements (PR 17): the one waiting for review, else the one in use, and the
                // user's choices on it.
                'upload'           => $this->priorUpload($report, $built['opening'] ?? null, $built['context'] ?? null),
                'entry'            => $this->priorYearEntry($report, $built),
                // Typed by hand and left empty: counted as 0 (PR 13c), shown on the review step.
                'defaulted'        => (fn ($d) => ['notes' => (object) $d['notes']] + $d)(PriorYearDefaults::of($built)),
            ],
            'run_changed' => $report->run_result_hash !== $this->source->hash($run),
            'acknowledgements' => $report->acknowledgements ?? [],
            'can_finalize'     => $built['blocking'] === [],
            'blocking'         => $built['blocking'],
        ];
        $versions = FinancialReportVersion::where('report_id', $report->id)->orderByDesc('version')->get(['version', 'kind', 'created_at', 'content_hash']);
        $final    = $versions->firstWhere('kind', FinancialReport::FINAL);
        $at       = fn ($v) => $v ? ['version' => $v->version, 'kind' => $v->kind, 'created_at' => $v->created_at?->toIso8601String()] : null;
        $state['versions']            = ['count' => $versions->count(), 'latest' => $at($versions->first()), 'final' => $at($final)];
        $state['changed_since_final'] = $final ? $final->content_hash !== self::contentHash($state) : null;

        return $state;
    }
}
