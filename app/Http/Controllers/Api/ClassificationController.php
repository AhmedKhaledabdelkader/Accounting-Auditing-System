<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LocalizedException;
use App\Exceptions\StructureNotDetected;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassifyTrialBalanceRequest;
use App\Http\Requests\ConfirmStructureRequest;
use App\Jobs\ClassifyTrialBalance;
use App\Models\ClassificationRun;
use App\Services\ClassificationService;
use App\Services\StructureChecks;
use App\Support\Llm\ProviderException;
use App\Support\ClassificationResponse;
use App\Support\Phrase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ClassificationController extends Controller
{
    public function __construct(private ClassificationService $service) {}

    /** Synchronous: fine for small files, may hit the request time limit on large ones. */
    public function classify(ClassifyTrialBalanceRequest $request): JsonResponse
    {
        set_time_limit(300);

        try {
            $result = $this->service->classifyFile($request->file('file'), companyId: $request->input('company_id'));
        } catch (StructureNotDetected $e) {
            // `message` and `problems` Arabic as always; `problems_text` in the request's language.
            return response()->json(['message' => $e->getMessage(), 'problems' => $e->problems,
                'problems_text' => array_map(fn (Phrase $p) => $p->current(), $e->problemPhrases()), 'structure' => $e->structure], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => LocalizedException::phraseOf($e)->current()], 422);
        } catch (ProviderException $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(ClassificationResponse::present(ClassificationResponse::make($result)));
    }

    /** Queued: stores the file, starts a background job, returns 202 with a URL to poll. */
    public function submit(ClassifyTrialBalanceRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $id   = (string) Str::uuid();
        $path = $file->storeAs('classification', $id.'.'.($file->getClientOriginalExtension() ?: 'xlsx'), 'local');

        $run = ClassificationRun::create([
            'id'            => $id,
            'status'        => 'queued',
            'original_name' => $file->getClientOriginalName(),
            'company_id'    => $request->input('company_id'),
            'file_path'     => $path,
        ]);

        ClassifyTrialBalance::dispatch($run->id);

        return response()->json($this->status($run->fresh()), 202);
    }

    /**
     * Runs, newest first, without their results: ?status= (e.g. `done`, to pick a trial balance for a report or its
     * prior year), ?per_page=.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', 'in:queued,running,needs_confirmation,done,failed,cancelled,expired'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = ClassificationRun::query()
            ->select(['id', 'status', 'original_name', 'company_id', 'progress_total', 'created_at', 'updated_at'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate((int) $request->input('per_page', 25))->withQueryString();
        $page->getCollection()->transform(fn (ClassificationRun $r) => [
            'id' => $r->id, 'status' => $r->status, 'original_name' => $r->original_name, 'company_id' => $r->company_id, 'rows' => $r->progress_total,
            'created_at' => $r->created_at?->toIso8601String(), 'updated_at' => $r->updated_at?->toIso8601String(),
            'status_url' => route('classify.jobs.show', $r->id, false),
        ]);

        return response()->json($page);
    }

    public function show(string $id): JsonResponse
    {
        $run = ClassificationRun::find($id);

        return $run
            ? response()->json($this->status($run))
            : response()->json(['message' => __('classification_messages.errors.job_not_found')], 404);
    }

    /** The user confirmed or corrected the mapping of a run waiting for it: check it, then classify with it. */
    public function confirm(ConfirmStructureRequest $request, string $id): JsonResponse
    {
        $run = ClassificationRun::find($id);
        if (! $run) {
            return response()->json(['message' => __('classification_messages.errors.job_not_found')], 404);
        }
        if ($run->status !== 'needs_confirmation' || ! $run->file_path || ! Storage::disk('local')->exists($run->file_path)) {
            return response()->json(['message' => __('classification_messages.errors.not_waiting')], 409);
        }

        $detected  = $run->structure;
        $structure = [
            'sheet'          => $detected['sheet'],
            'header_rows'    => array_map('intval', $request->input('header_rows', [])),
            'first_data_row' => (int) $request->input('first_data_row'),
            'columns'        => $request->input('columns'),
            'other_sheets'   => $detected['other_sheets'] ?? [],
        ];
        $file      = new UploadedFile(Storage::disk('local')->path($run->file_path), $run->original_name, null, null, true);
        $detection = $this->service->withStructure($file, $structure);
        $errors    = array_filter($detection['issues'], fn ($i) => $i['severity'] === 'error');
        $blocking  = array_filter($errors, fn ($i) => ! in_array($i['check'], ClassificationService::ACKNOWLEDGEABLE, true));
        if ($problems = ClassificationService::problems($blocking)) {
            return response()->json(['message' => __('classification_messages.errors.still_problems'), 'problems' => $problems,
                'problems_text' => array_map(fn ($p, $text) => Phrase::localize($text, $p), ClassificationService::problemPhrases($blocking), $problems)], 422);
        }
        // Measurement errors on a mapping the user confirmed never block: they need an explicit "confirm anyway".
        $override = array_values(array_diff_key($errors, $blocking));

        // Pausing doubts the user hasn't been shown (raised by their own edit) need an explicit second confirm.
        $key     = [ClassificationService::class, 'issueKey'];
        $shown   = array_map($key, $run->structure_issues ?? []);
        $pausing = (array) config('classification.structure_checks.pause_on', []);
        $new     = array_values(array_filter($detection['issues'], fn ($i) => $i['severity'] === 'doubt'
            && in_array($i['check'], $pausing, true) && ! in_array($key($i), $shown, true)));
        if (($new || $override) && ! $request->boolean('acknowledge_doubts')) {
            $doubts = [];   // Arabic text => the same message in the request's language
            foreach (array_merge($override, $new) as $i) {
                $doubts[$i['user']] ??= ClassificationService::issuePhrase($i)->current();
            }

            return response()->json([
                'message'               => __('classification_messages.errors.needs_acknowledgement'),
                'doubts'                => array_keys($doubts),
                'doubts_text'           => array_values($doubts),
                'needs_acknowledgement' => true,
            ], 422);
        }
        // Acknowledged: rejected other sheets are left out (the panel can't edit them); the rest is kept for the run.
        $acknowledged = array_map(fn ($i) => array_intersect_key($i, array_flip(['check', 'field', 'column', 'user', 'user_phrase'])), $override);
        if (array_filter($override, fn ($i) => $i['check'] === 'other_sheets')) {
            $detection['structure'] = $this->service->withoutInvalidOtherSheets($detection);
        }

        // One step from waiting to queued: a second confirm (or a cancel in between) changes nothing.
        $confirmed = ClassificationRun::whereKey($id)->where('status', 'needs_confirmation')->update([
            'status' => 'queued', 'stage' => null, 'confirmed_at' => now(),
            'structure' => json_encode($detection['structure'] + ($acknowledged ? ['acknowledged' => $acknowledged] : [])),
        ] + ClassificationRun::WAITING_DATA);
        if (! $confirmed) {
            return response()->json(['message' => __('classification_messages.errors.not_waiting')], 409);
        }

        // What the user changed from the AI's mapping: the best evidence for tuning the checks.
        $changed = [];
        foreach ($detection['structure']['columns'] as $field => $letter) {
            if (($detected['columns'][$field] ?? null) !== $letter) {
                $changed[$field] = ['ai' => $detected['columns'][$field] ?? null, 'user' => $letter];
            }
        }
        foreach (['header_rows', 'first_data_row'] as $key) {
            if ($detected[$key] !== $detection['structure'][$key]) {
                $changed[$key] = ['ai' => $detected[$key], 'user' => $detection['structure'][$key]];
            }
        }
        Log::channel(config('classification.log_channel', 'classification'))->info('structure_confirmed', [
            'run_id' => $id, 'changed' => $changed, 'issues_shown' => array_column($run->structure_issues ?? [], 'check'),
            'doubts_acknowledged' => array_values(array_unique(array_column($new, 'check'))),
            'errors_overridden'   => array_values(array_unique(array_column($override, 'check'))),
        ]);

        ClassifyTrialBalance::dispatch($id);

        return response()->json($this->status($run->fresh()), 202);
    }

    /** Cancels a run that is queued or waiting for confirmation (not one already processing); its file is deleted at once. */
    public function cancel(string $id): JsonResponse
    {
        $run = ClassificationRun::find($id);
        if (! $run) {
            return response()->json(['message' => __('classification_messages.errors.job_not_found')], 404);
        }
        $cancelled = ClassificationRun::whereKey($id)->whereIn('status', ClassificationRun::CANCELLABLE)
            ->update(['status' => 'cancelled', 'stage' => null, 'file_path' => null] + ClassificationRun::WAITING_DATA);
        if (! $cancelled) {
            return response()->json(['message' => __('classification_messages.errors.cannot_cancel')], 409);
        }
        if ($run->file_path) {
            Storage::disk('local')->delete($run->file_path);
        }

        return response()->json($this->status($run->fresh()));
    }

    private function status(ClassificationRun $run): array
    {
        return [
            'id'         => $run->id,
            'status'     => $run->status,
            'company_id' => $run->company_id,   // chosen at upload (null: none)
            'stage'      => $run->stage,
            'progress'   => ['done' => $run->progress_done, 'total' => $run->progress_total],
            'error'      => $run->error,
            // The same failure in the request's language (a run from before phrases: as stored).
            'error_text' => Phrase::localize($run->error, $run->error_phrase),
            // The AI provider and model the run started with (null: not started yet, or a run from before they were recorded).
            'provider'   => $run->provider,
            'model'      => $run->model,
            'status_url' => route('classify.jobs.show', $run->id, false),
            'cancel_url' => in_array($run->status, ClassificationRun::CANCELLABLE, true) ? route('classify.jobs.cancel', $run->id, false) : null,
            'result'     => $run->status === 'done' ? ClassificationResponse::present($run->result) : null,
            // Rows answered by the classification cache vs sent to the AI (null for runs before the cache).
            // The savings are an ESTIMATE (cost per row sent × rows from cache), never an amount actually billed.
            'cache'      => $run->status === 'done' && $run->rows_from_cache !== null ? [
                'rows_from_cache'       => $run->rows_from_cache,
                'rows_sent_to_llm'      => $run->rows_sent_to_llm,
                'estimated_savings_usd' => $run->estimated_savings_usd === null ? null : (float) $run->estimated_savings_usd,
                'savings_is_estimate'   => true,
                'savings_basis'         => $run->savings_basis,   // this_run | recent_runs | none (nothing from cache) | unknown
            ] : null,
            // What this run's AI calls actually cost, from the provider's usage (null cost_usd: no price for the model).
            'cost'       => in_array($run->status, ['done', 'failed'], true) && $run->llm_requests !== null ? [
                'cost_usd'           => $run->cost_usd === null ? null : (float) $run->cost_usd,
                'input_tokens'       => $run->input_tokens,
                'output_tokens'      => $run->output_tokens,
                'cache_read_tokens'  => $run->cache_read_tokens,
                'cache_write_tokens' => $run->cache_write_tokens,
                'llm_requests'       => $run->llm_requests,
            ] : null,
            'confirmation' => $run->status === 'needs_confirmation' ? [
                'structure'   => array_intersect_key($run->structure ?? [], array_flip(['sheet', 'header_rows', 'first_data_row', 'columns'])),
                // `user` Arabic as always, `user_text` in the request's language.
                'issues'      => array_map(fn ($i) => array_diff_key($i, ['user_phrase' => 1]) + ['user_text' => Phrase::localize($i['user'] ?? '', $i['user_phrase'] ?? null)],
                    $run->structure_issues ?? []),
                'sheet'       => $run->confirmation['sheet'] ?? null,
                'columns'     => $run->confirmation['columns'] ?? [],
                'rows'        => $run->confirmation['rows'] ?? [],
                'fields'      => StructureChecks::FIELD_LABELS,
                'fields_text' => array_map(fn ($f) => StructureChecks::fieldLabel($f)->current(), array_combine(array_keys(StructureChecks::FIELD_LABELS), array_keys(StructureChecks::FIELD_LABELS))),
                'expires_at'  => $run->expires_at?->toIso8601String(),
                'confirm_url' => route('classify.jobs.structure', $run->id, false),
            ] : null,
        ];
    }
}
