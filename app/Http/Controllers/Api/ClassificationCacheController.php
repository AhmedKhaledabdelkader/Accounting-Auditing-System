<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\ClassificationCache;
use App\Services\ClassificationService;
use App\Support\ClassificationKey;
use App\Support\ClassificationResponse;
use App\Support\Phrase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The classification cache for the accountant: how it works, what it holds, and manual corrections. */
class ClassificationCacheController extends Controller
{
    public function __construct(private ClassificationCache $cache, private TaxonomyRepositoryInterface $taxonomy, private ClassificationService $classifier) {}

    public function explain(): JsonResponse
    {
        return response()->json($this->cache->explain());
    }

    /** Paginated entries, most recently changed first; optional source and text filters. */
    public function entries(Request $request): JsonResponse
    {
        $request->validate([
            'source'   => ['nullable', Rule::in([ClassificationCache::AI, ClassificationCache::MANUAL, ClassificationCache::IMPORTED])],
            'q'        => ['nullable', 'string', 'max:200'],
            'sub_class' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = ClassificationCacheEntry::query()
            ->when($request->input('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($request->input('sub_class'), fn ($q, $sub) => $q->where('sub_class', $sub))
            // Substring search (a full scan: ~17 ms at 50k entries). The key is matched normalized, so «إدارية» finds «اداريه».
            ->when($request->input('q'), fn ($q, $text) => $q->where(fn ($q) => $q
                ->where('original_name', 'like', "%{$text}%")->orWhere('normalized_key', 'like', '%'.ClassificationKey::name($text).'%')
                ->orWhere('account_code', 'like', "%{$text}%")))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 50))
            ->withQueryString()
            ->through(fn (ClassificationCacheEntry $e) => $this->entry($e));

        return response()->json($page);
    }

    /** Removes a wrong entry (AI answer or manual correction): the row goes back to the AI next time. */
    public function destroy(int $id): JsonResponse
    {
        $entry = ClassificationCacheEntry::find($id);
        if (! $entry) {
            return response()->json(['message' => __('classification_messages.errors.cache_entry_not_found')], 404);
        }
        $entry->delete();

        return response()->json(null, 204);
    }

    /**
     * An account added by hand (the memory page), as a manual correction: later files with the same code, name and budget
     * item get its class. An account already stored is replaced (`replaced`: true). With neither code nor budget item the
     * name alone is generic: its parent account's name is part of the key (none: an account with no parent).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_code' => ['nullable', 'string', 'max:100'],
            'account_name' => ['required', 'string', 'max:255'],
            'budget_item'  => ['nullable', 'string', 'max:255'],
            'parent'       => ['nullable', 'string', 'max:255'],
            'sub_class'    => ['required', 'string', Rule::in($this->taxonomy->subClasses())],
        ]);
        $key = ClassificationKey::make($data['account_code'] ?? null, $data['account_name'], $data['budget_item'] ?? null, $data['parent'] ?? '');
        if ($key === null) {
            $message = __('validation.required', ['attribute' => __('validation.attributes.account_name')]);

            return response()->json(['message' => $message, 'errors' => ['account_name' => [$message]]], 422);
        }
        $replaced = ClassificationCacheEntry::where('key_hash', ClassificationKey::hash($key))->exists();
        $entry = $this->cache->correct($key, $data['account_code'] ?? null, $data['account_name'], $data['budget_item'] ?? null, $data['sub_class'])->refresh();

        return response()->json(['data' => $this->entry($entry), 'replaced' => $replaced], 201);
    }

    /** Entries given one class by hand (the memory page): each becomes a manual correction. */
    public function reclassify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'       => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'     => ['integer', 'distinct'],
            'sub_class' => ['required', 'string', Rule::in($this->taxonomy->subClasses())],
        ]);
        $entries = ClassificationCacheEntry::whereIn('id', $data['ids'])->get();
        if ($missing = array_values(array_diff($data['ids'], $entries->pluck('id')->all()))) {
            return response()->json(['message' => __('classification_messages.errors.cache_entry_not_found'), 'ids' => $missing], 404);
        }
        DB::transaction(fn () => $entries->each(fn ($e) => $this->cache->reclassify($e, $data['sub_class'])));

        return response()->json(['data' => $entries->map(fn ($e) => $this->entry($e))->values()]);
    }

    /** Several wrong entries removed at once (the memory page): their rows go back to the AI next time. */
    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'distinct']]);

        return response()->json(['deleted' => ClassificationCacheEntry::whereIn('id', $data['ids'])->delete()]);
    }

    /**
     * A manual correction of one row of a finished run: updates the run's result and saves it to the cache, where it
     * overrides any AI answer. The row's data flags are kept as they were (they are recomputed on the next upload).
     */
    public function correct(Request $request, string $id, int $row): JsonResponse
    {
        $data = $request->validate(['sub_class' => ['required', 'string', Rule::in($this->taxonomy->subClasses())]]);
        $out  = $this->applyCorrections($id, [$row => $data['sub_class']]);
        if ($out instanceof JsonResponse) {
            return $out;
        }

        return response()->json([
            'row'         => ClassificationResponse::row($out['rows'][0]),
            'cache_entry' => $out['entries'][0] ? $this->entry($out['entries'][0]) : null,
            'message'     => $out['entries'][0] ? null : __('classification_messages.errors.not_remembered'),
        ]);
    }

    /**
     * Several rows at once (the classifier page's «حفظ التعديلات»): all or nothing. Each row is corrected in the run and
     * remembered like a single correction.
     */
    public function correctMany(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'corrections'             => ['required', 'array', 'min:1', 'max:5000'],
            'corrections.*.row'       => ['required', 'integer', 'distinct'],
            'corrections.*.sub_class' => ['required', 'string', Rule::in($this->taxonomy->subClasses())],
        ]);
        $out = $this->applyCorrections($id, array_column($data['corrections'], 'sub_class', 'row'));
        if ($out instanceof JsonResponse) {
            return $out;
        }
        $forgotten = array_values(array_map(fn ($r) => $r['row'], array_filter($out['rows'], fn ($r, $i) => ! $out['entries'][$i], ARRAY_FILTER_USE_BOTH)));

        return response()->json([
            'rows'           => array_map([ClassificationResponse::class, 'row'], $out['rows']),
            'corrected'      => count($out['rows']),
            'remembered'     => count(array_filter($out['entries'])),
            'not_remembered' => $forgotten,   // rows without a cache key: corrected in this run only
        ]);
    }

    /**
     * @param  array<int, string>  $changes  row => sub_class
     * @return JsonResponse|array{rows: list<array>, entries: list<?ClassificationCacheEntry>}  an error, or the rows in $changes order
     */
    private function applyCorrections(string $id, array $changes): JsonResponse|array
    {
        return DB::transaction(function () use ($id, $changes) {
            $run = ClassificationRun::whereKey($id)->lockForUpdate()->first();
            if (! $run) {
                return response()->json(['message' => __('classification_messages.errors.job_not_found')], 404);
            }
            if ($run->status !== 'done') {
                return response()->json(['message' => __('classification_messages.errors.only_done')], 409);
            }
            $result  = $run->result;
            $indexes = [];
            foreach ($result['data'] ?? [] as $i => $r) {
                $indexes[$r['row'] ?? null] = $i;
            }
            if ($missing = array_values(array_diff(array_keys($changes), array_keys($indexes)))) {
                return response()->json(['message' => __('classification_messages.errors.row_not_found'), 'rows' => $missing], 404);
            }

            $rows    = [];
            $entries = [];
            foreach ($changes as $row => $subClass) {
                $index = $indexes[$row];
                [$statement, $main] = $this->taxonomy->find($subClass);
                $r = array_merge($result['data'][$index], [
                    'financial_statement'       => $statement,
                    'main_class'                => $main,
                    'sub_class'                 => $subClass,
                    'classification_confidence' => 100,
                    'classification_source'     => ClassificationCache::MANUAL,
                    // «تصحيح يدوي» stays the stored marker of a saved correction (the classifier page reads it).
                    'classification_reason'     => Phrase::make('classification_messages.reasons.manual_correction')->ar(),
                    'classification_reason_phrase' => Phrase::make('classification_messages.reasons.manual_correction')->toArray(),
                    'confidence'                => 100,
                    'confidence_label'          => '100%',
                ]);
                $r = $this->classifier->reviewForChosenClass($r, $result['meta']['groups'] ?? ['opening', 'closing']);
                $result['data'][$index] = $r;
                $rows[]    = $r;
                $entries[] = $r['cache_key'] !== null
                    ? $this->cache->correct($r['cache_key'], $r['account_number'], $r['account_name'], $r['existing_classification'] ?? null, $subClass,
                        ClassificationCache::rowSide($r))
                    : null;
            }
            $result['meta']['needs_review']   = collect($result['data'])->where('needs_review', true)->count();
            $result['meta']['avg_confidence'] = round(collect($result['data'])->avg('classification_confidence'), 1);
            $run->update(['result' => $result]);

            return ['rows' => $rows, 'entries' => $entries];
        });
    }

    private function entry(ClassificationCacheEntry $e): array
    {
        return [
            'id'               => $e->id,
            'original_name'    => $e->original_name,
            'normalized_key'   => $e->normalized_key,
            'account_code'     => $e->account_code,
            'budget_item'      => $e->budget_item,
            'row_type'         => $e->row_type,
            // The main class applied today (from the taxonomy by sub-class); the stored one may be older.
            'main_class'       => $e->sub_class !== null ? ($this->taxonomy->find($e->sub_class)[1] ?? null) : null,
            'sub_class'        => $e->sub_class,
            'source'           => $e->source,
            // A side-pair member chosen against the row's side: kept as it is, never flipped by a later row's side.
            'side_locked'      => (bool) $e->side_locked,
            'confidence'       => $e->confidence,
            'taxonomy_version' => $e->taxonomy_version,
            'in_use'           => $this->cache->usable($e),   // false: kept, but ignored by the lookup (old version, low confidence, removed sub-class)
            'hit_count'        => $e->hit_count,
            'created_at'       => $e->created_at?->toIso8601String(),
            'updated_at'       => $e->updated_at?->toIso8601String(),
        ];
    }
}
