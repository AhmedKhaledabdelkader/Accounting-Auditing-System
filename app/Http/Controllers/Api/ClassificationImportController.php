<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessClassificationImport;
use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping as Mapping;
use App\Models\ClassificationImportRow;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\CacheImport;
use App\Exceptions\LocalizedException;
use App\Services\ClassificationCache;
use App\Support\Phrase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Importing accountant-labelled trial balances into the classification cache: upload (queued: read + map, nothing in the
 * cache), review the mappings, then apply the approved ones (again as more get approved).
 */
class ClassificationImportController extends Controller
{
    public function __construct(private CacheImport $imports, private TaxonomyRepositoryInterface $taxonomy) {}

    /** Previous uploads, newest first. */
    public function index(): JsonResponse
    {
        return response()->json(ClassificationImport::where('kind', ClassificationImport::FILE)->latest('id')->limit(100)->get()
            ->map(fn (ClassificationImport $i) => $this->summary($i))->all());
    }

    /**
     * Checks the file's columns at once (Arabic errors, 422), warns about a file uploaded before (409, unless force=true/1),
     * then queues the read + mapping: 202 with the URL to poll.
     */
    public function store(Request $request): JsonResponse
    {
        // Form-data sends booleans as the strings "true"/"false", which Laravel's boolean rule rejects.
        if ($request->has('force')) {
            $force = filter_var($request->input('force'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $request->merge(['force' => $force ?? $request->input('force')]);
        }
        $request->validate(['file' =>['required', 'file', \App\Support\SpreadsheetFile::rule(), 'max:20480'], 'force' => ['nullable', 'boolean']], [
            'file.required' => __('classification_messages.import.file_required'),
            'file.mimes'    => __('classification_messages.import.file_mimes'),
            'file.max'      => __('classification_messages.import.file_max'),
        ]);
        $file  = $request->file('file');
        $check = $this->imports->check($file->getRealPath());
        if ($check['errors']) {
            $errors = array_map(fn (Phrase $p) => $p->current(), $check['errors']);

            return response()->json(['message' => $errors[0], 'errors' => ['file' => $errors]], 422);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        // A failed import does not count: uploading the file again is the way to retry it.
        $same = ClassificationImport::where('file_hash', $hash)->where('kind', ClassificationImport::FILE)
            ->where('status', '!=', ClassificationImport::FAILED)->latest('id')->first();
        if ($same && ! $request->boolean('force')) {
            return response()->json([
                'message'      => __('classification_messages.import.duplicate', ['file' => $same->file_name, 'date' => $same->created_at->format('Y-m-d'), 'id' => $same->id]),
                'duplicate_of' => $this->summary($same),
            ], 409);
        }

        $path   = $file->storeAs('classification-imports', Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'xlsx'), 'local');
        $import = ClassificationImport::create([
            'file_name' => $file->getClientOriginalName(), 'status' => ClassificationImport::QUEUED, 'stage' => null,
            'file_path' => $path, 'file_hash' => $hash,
        ]);
        ProcessClassificationImport::dispatch($import->id);

        // `warnings` Arabic as always; `warnings_text` in the request's language.
        return response()->json($this->summary($import->fresh()) + ['warnings' => array_map(fn (Phrase $p) => $p->ar(), $check['warnings']),
            'warnings_text' => array_map(fn (Phrase $p) => $p->current(), $check['warnings'])], 202);
    }

    public function show(int $id): JsonResponse
    {
        $import = ClassificationImport::find($id);
        if (! $import) {
            return response()->json(['message' => __('classification_messages.import.not_found')], 404);
        }
        // A report saved before rows.ready existed is rebuilt once, so the page's counters are right.
        if ($this->reviewable($import) && is_array($import->report['rows'] ?? null) && ! array_key_exists('ready', $import->report['rows'])) {
            $import->update(['report' => $this->imports->report($import)]);
        }

        return response()->json($this->summary($import) + ['report' => $import->reportForApi()]);
    }

    private function summary(ClassificationImport $i): array
    {
        $stale = $i->liveStatus() === ClassificationImport::FAILED && ! $i->error;

        return [
            'id'         => $i->id,
            'file_name'  => $i->file_name,
            'status'     => $i->liveStatus(),
            'stage'      => $i->stage,
            'progress'   => ['done' => $i->progress_done, 'total' => $i->progress_total],
            'error'      => $stale ? Phrase::make('classification_messages.import.stale')->ar() : $i->error,
            // The same failure in the request's language (an import from before phrases: as stored).
            'error_text' => $stale ? __('classification_messages.import.stale') : Phrase::localize($i->error, $i->error_phrase),
            'rows'       => $i->report['rows'] ?? null,
            // The AI provider and model the mapping used (null: no value needed the AI, or not mapped yet).
            'provider'   => $i->provider,
            'model'      => $i->model,
            'created_at' => $i->created_at?->toIso8601String(),
            'applied_at' => $i->applied_at?->toIso8601String(),
            'status_url' => '/api/v1/classification-cache/imports/'.$i->id,
            'review_url' => '/classification-cache/imports/'.$i->id.'/review',
        ];
    }

    /** The mappings to review, most rows first: ?kind=budget|note, ?decision=, ?flagged=1, ?unreviewed=1, ?low=1 (below the cache cutoff), ?q=. */
    public function mappings(Request $request, int $id): JsonResponse
    {
        $request->validate(self::FILTER_RULES + ['per_page' => ['nullable', 'integer', 'min:1', 'max:500']]);
        if (! ClassificationImport::whereKey($id)->exists()) {
            return response()->json(['message' => __('classification_messages.import.not_found')], 404);
        }

        $page = $this->filtered($request, $id)
            ->orderByDesc('rows')->orderBy('id')
            ->paginate((int) $request->input('per_page', 100))->withQueryString();

        // Up to 3 account names per mapping, and the sub-class its rows get today (a policy's mode decides for notes).
        $ids     = $page->getCollection()->pluck('id')->all();
        $samples = ClassificationImportRow::where('import_id', $id)
            ->where(fn ($q) => $q->whereIn('budget_mapping_id', $ids)->orWhereIn('note_mapping_id', $ids))
            ->get(['account_name', 'company', 'budget_mapping_id', 'note_mapping_id']);
        $page->getCollection()->transform(function (Mapping $m) use ($samples) {
            $field = $m->kind === Mapping::BUDGET ? 'budget_mapping_id' : 'note_mapping_id';
            $active = $m->kind === Mapping::NOTE ? CacheImport::activeSub($m) : $m->sub_class;

            $out = $m->toArray();
            unset($out['reason_phrase'], $out['flag_phrase']);

            return $out + self::texts($m) + [
                'samples'     => $samples->where($field, $m->id)->unique('account_name')->take(3)->map(fn ($r) => ['name' => $r->account_name, 'company' => $r->company])->values()->all(),
                'active_sub'  => $active,
                'active_main' => $active ? ($this->taxonomy->find($active)[1] ?? null) : $m->main_class,
                'policy_mode' => $m->policy ? config("classification.import.policies.{$m->policy}.mode") : null,
            ];
        });

        return response()->json($page);
    }

    /** The mapping list's filters (GET …/mappings), also used by approve with all = true. */
    private const FILTER_RULES = [
        'kind'     => ['nullable', 'in:'.Mapping::BUDGET.','.Mapping::NOTE],
        'decision' => ['nullable', 'in:map,'.CacheImport::ROUTE.',skip'],
        'q'        => ['nullable', 'string', 'max:200'],
    ];

    private function filtered(Request $request, int $id)
    {
        return Mapping::where('import_id', $id)
            ->when($request->input('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when($request->input('decision'), fn ($q, $d) => $q->where('decision', $d))
            ->when($request->boolean('flagged'), fn ($q) => $q->whereNotNull('flag'))
            ->when($request->boolean('unreviewed'), fn ($q) => $q->where('reviewed', false))
            ->when($request->boolean('low'), fn ($q) => $q->where('confidence', '<', ClassificationCache::minConfidence()))
            ->when($request->input('q'), fn ($q, $text) => $q->where('value', 'like', "%{$text}%"))
            ->where('rows', '>', 0);
    }

    /** Approves several mappings as they are: {ids: [...]}. */
    public function approve(Request $request, int $id): JsonResponse
    {
        // ids: the ones picked on the page; or all = every not-yet-reviewed mapping matching the list's filters (all pages).
        $data   = $request->validate(self::FILTER_RULES + [
            'all' => ['sometimes', 'boolean'],
            'ids' => [Rule::requiredIf(! $request->boolean('all')), 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer'],
        ]);
        $import = ClassificationImport::find($id);
        if (! $import) {
            return response()->json(['message' => __('classification_messages.import.not_found')], 404);
        }
        if (! $this->reviewable($import)) {
            return response()->json(['message' => __('classification_messages.import.not_reviewable')], 409);
        }

        $ids = $request->boolean('all') ? $this->filtered($request, $id)->where('reviewed', false)->pluck('id')->all() : $data['ids'];

        return response()->json(['approved' => $this->imports->approve($import, $ids), 'report' => $import->fresh()->reportForApi()]);
    }

    /** A mapping's reason and flag in the request's language (a mapping from before phrases, or an AI's reason: as stored). */
    private static function texts(Mapping $m): array
    {
        return ['reason_text' => Phrase::localize($m->reason, $m->reason_phrase), 'flag_text' => Phrase::localize($m->flag, $m->flag_phrase)];
    }

    private function reviewable(?ClassificationImport $import): bool
    {
        return $import && in_array($import->status, [ClassificationImport::PENDING, ClassificationImport::APPLIED], true);
    }

    /**
     * The accountant's decision on a mapping. Budget: {decision: map|route|skip, main_class?, sub_class?};
     * note: {decision: map|skip, sub_class?}; {} only marks it reviewed.
     */
    public function review(Request $request, int $mappingId): JsonResponse
    {
        $mapping = Mapping::find($mappingId);
        if (! $mapping) {
            return response()->json(['message' => __('classification_messages.import.mapping_not_found')], 404);
        }
        if (! $this->reviewable(ClassificationImport::find($mapping->import_id))) {
            return response()->json(['message' => __('classification_messages.import.not_reviewable')], 409);
        }
        $budget = $mapping->kind === Mapping::BUDGET;
        $function    = ! $budget && $mapping->policy && config("classification.import.policies.{$mapping->policy}.mode") === 'function';
        $allowedSubs = match (true) {
            $budget   => $request->input('main_class') ? $this->imports->subsOf((string) $request->input('main_class')) : [],
            $function => $this->imports->subsOf(config("classification.import.policies.{$mapping->policy}.function_main")),
            default   => $this->taxonomy->subClasses(),
        };
        $data = $request->validate([
            'decision'   => ['nullable', Rule::in($budget ? ['map', CacheImport::ROUTE, 'skip'] : ['map', 'skip'])],
            'main_class' => [Rule::requiredIf($budget && $request->input('decision') === 'map'), 'nullable', Rule::in($this->taxonomy->mainClasses())],
            'sub_class'  => [Rule::requiredIf(! $budget && $request->input('decision') === 'map'), 'nullable', Rule::in($allowedSubs)],
        ]);

        $reviewed = $this->imports->review($mapping, array_filter($data, fn ($v) => $v !== null));
        $out      = $reviewed->toArray();
        unset($out['reason_phrase'], $out['flag_phrase']);

        return response()->json(['mapping' => $out + self::texts($reviewed),
            'report' => ClassificationImport::find($mapping->import_id)->reportForApi()]);
    }

    /** «تطبيق»: writes the rows whose mappings are all approved; the rest stays pending. Can run again. */
    public function apply(int $id): JsonResponse
    {
        $import = ClassificationImport::find($id);
        if (! $import) {
            return response()->json(['message' => __('classification_messages.import.not_found')], 404);
        }
        try {
            $result = $this->imports->apply($import);
        } catch (RuntimeException $e) {
            return response()->json(['message' => LocalizedException::phraseOf($e)->current()], 409);
        }

        return response()->json(['applied' => $result, 'report' => $import->fresh()->reportForApi()]);
    }
}
