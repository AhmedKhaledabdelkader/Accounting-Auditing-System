<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\ClassificationCache;
use App\Services\ClassificationService;
use App\Services\TrialBalanceTotals;
use App\Support\ClassificationKey;
use App\Support\ClassificationResponse;
use App\Support\Phrase;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A finished run's rows changed by hand after the classification (the accountant's notes, 2026-10-20): an account added,
 * its number, name or amounts edited, or a row removed. The run's result changes (a report built on it sees run_changed);
 * nothing reaches the classification memory except a class, through the corrections. Each answer carries the
 * trial balance's totals after the change (`balance`): an edit may unbalance it, and the page says so.
 */
class ClassificationRowController extends Controller
{
    private const GROUPS = ['opening', 'period', 'adjustment', 'closing'];

    public function __construct(private TaxonomyRepositoryInterface $taxonomy, private ClassificationService $classifier)
    {
    }

    /** A new account, after the last row. Its amounts are given by group; a class given is kept as a manual correction. */
    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return $this->change($id, function (array &$result) use ($data) {
            $template = $result['data'][0] ?? [];
            $n   = (int) collect($result['data'])->max('row') + 1;
            $row = array_fill_keys(array_keys($template), null);
            $row = array_merge($row, [
                'row' => $n, 'account_number' => (string) ($data['account_number'] ?? ''), 'account_name' => $data['account_name'],
                'financial_statement' => null, 'main_class' => null, 'sub_class' => null,
                'classification_confidence' => 0, 'confidence' => 0, 'confidence_label' => '0%',
                'classification_reason' => null, 'classification_reason_phrase' => null, 'classification_source' => null, 'classification_rule' => null,
                'data_flags' => [], 'source_note' => null, 'note' => '', 'note_phrase' => ['join' => [], 'sep' => ' | '],
                'added' => true,
            ]);
            foreach (self::GROUPS as $g) {
                $row["{$g}_debit"]  = (float) ($data["{$g}_debit"] ?? 0);
                $row["{$g}_credit"] = (float) ($data["{$g}_credit"] ?? 0);
            }
            $row = $this->classify($row, $data['sub_class'] ?? null, $result['meta']['groups'] ?? self::GROUPS);
            $row['cache_key'] = ClassificationKey::make($row['account_number'], $row['account_name'], null, '');
            $result['data'][] = $row;

            return $row;
        }, 201);
    }

    /** A row's number, name or amounts, as given; the rest stays. */
    public function update(Request $request, string $id, int $row): JsonResponse
    {
        $data = $request->validate($this->rules(false));

        return $this->change($id, function (array &$result) use ($data, $row) {
            $i = $this->index($result, $row);
            if ($i === null) {
                return null;
            }
            $r = $result['data'][$i];
            foreach (['account_number', 'account_name'] as $f) {
                if (array_key_exists($f, $data)) {
                    $r[$f] = (string) ($data[$f] ?? '');
                }
            }
            foreach (self::GROUPS as $g) {
                foreach (['debit', 'credit'] as $s) {
                    if (array_key_exists("{$g}_{$s}", $data)) {
                        $r["{$g}_{$s}"] = (float) ($data["{$g}_{$s}"] ?? 0);
                    }
                }
            }
            $r['edited'] = true;
            // A new number or name is another account for the memory: its key follows (a generic key keeps its parent).
            if (array_intersect_key($data, array_flip(['account_number', 'account_name']))) {
                $budget = $r['existing_classification'] ?? null;
                $parent = preg_match('/\|parent:(.*)$/u', (string) ($r['cache_key'] ?? ''), $m) ? $m[1] : '';
                $r['cache_key'] = ClassificationKey::make($r['account_number'], $r['account_name'], $budget, $parent);
            }

            return $result['data'][$i] = $r;
        });
    }

    /** A row taken out of the run (kept in meta.removed_rows, so the change can be seen). */
    public function destroy(string $id, int $row): JsonResponse
    {
        return $this->change($id, function (array &$result) use ($row) {
            $i = $this->index($result, $row);
            if ($i === null) {
                return null;
            }
            $removed = $result['data'][$i];
            $result['meta']['removed_rows'][] = ['row' => $removed['row'], 'account_number' => $removed['account_number'], 'account_name' => $removed['account_name']];
            array_splice($result['data'], $i, 1);

            return $removed;
        });
    }

    /** The run locked, the change made, the totals redone; 404 for a run or row not found, 409 while the run isn't done. */
    private function change(string $id, Closure $fn, int $status = 200): JsonResponse
    {
        return DB::transaction(function () use ($id, $fn, $status) {
            $run = ClassificationRun::whereKey($id)->lockForUpdate()->first();
            if (! $run) {
                return response()->json(['message' => __('classification_messages.errors.job_not_found')], 404);
            }
            if ($run->status !== 'done') {
                return response()->json(['message' => __('classification_messages.errors.only_done')], 409);
            }
            $result = $run->result;
            $row    = $fn($result);
            if ($row === null) {
                return response()->json(['message' => __('classification_messages.errors.row_not_found')], 404);
            }
            $data = collect($result['data']);
            $result['meta']['total']          = $data->count();
            $result['meta']['needs_review']   = $data->where('needs_review', true)->count();
            $result['meta']['avg_confidence'] = round((float) $data->avg('classification_confidence'), 1);
            $result['meta']['edited']         = true;
            $result['meta']['balance']        = $this->balance($result);
            $run->result = $result;
            $run->save();

            return response()->json(['row' => ClassificationResponse::row($row), 'meta' => [
                'total' => $result['meta']['total'], 'needs_review' => $result['meta']['needs_review'],
                'avg_confidence' => $result['meta']['avg_confidence'], 'balance' => $result['meta']['balance'],
            ]], $status);
        });
    }

    /** Debit − credit of the accounts per group the run has (parents left out, as in the run's own check). */
    private function balance(array $result): array
    {
        $groups = array_values(array_intersect(self::GROUPS, $result['meta']['groups'] ?? self::GROUPS));
        $sums   = TrialBalanceTotals::sums($result['data'], $groups);

        return array_map(fn ($s) => round($s[0] - $s[1], 2), $sums);
    }

    /** A new row's class as the user gave it: its balance checked against it, a wrong side flagged for review. */
    private function classify(array $row, ?string $subClass, array $groups): array
    {
        if ($subClass === null) {
            return array_merge($row, ['needs_review' => true]);
        }
        [$statement, $main] = $this->taxonomy->find($subClass);
        $reason = Phrase::make('classification_messages.reasons.manual_correction');

        $row = array_merge($row, ['financial_statement' => $statement, 'main_class' => $main, 'sub_class' => $subClass,
            'classification_confidence' => 100, 'confidence' => 100, 'confidence_label' => '100%', 'classification_source' => ClassificationCache::MANUAL,
            'classification_reason' => $reason->ar(), 'classification_reason_phrase' => $reason->toArray(), 'needs_review' => false]);
        return $this->classifier->reviewForChosenClass($row, $groups);
    }

    private function index(array $result, int $row): ?int
    {
        foreach ($result['data'] ?? [] as $i => $r) {
            if (($r['row'] ?? null) === $row) {
                return $i;
            }
        }

        return null;
    }

    private function rules(bool $new): array
    {
        $amount = ['nullable', 'numeric', 'min:0', 'max:999999999999.99'];
        $rules  = [
            'account_number' => ['nullable', 'string', 'max:100'],
            'account_name'   => [$new ? 'required' : 'sometimes', 'string', 'max:255'],
        ];
        foreach (self::GROUPS as $g) {
            $rules["{$g}_debit"]  = $amount;
            $rules["{$g}_credit"] = $amount;
        }
        if ($new) {
            $rules['sub_class'] = ['nullable', 'string', Rule::in($this->taxonomy->subClasses())];
        }

        return $rules;
    }
}
