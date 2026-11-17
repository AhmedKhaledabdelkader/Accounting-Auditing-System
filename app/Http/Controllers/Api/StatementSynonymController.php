<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatementSynonym;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\StatementSynonyms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The synonyms of the statements' lines (PR 17): other wordings of a line or total that a file of last year's issued
 * statements may use. The user sees and edits the list; matching a file against it is exact (StatementSynonyms). A file
 * still waiting for review follows the list; one already confirmed keeps its matches.
 */
class StatementSynonymController extends Controller
{
    public function index(): JsonResponse
    {
        $catalogue = new LineCatalogue;

        return response()->json([
            'data' => StatementSynonym::orderBy('statement')->orderBy('line_key')->orderBy('id')->get()->map(fn ($s) => $this->present($s, $catalogue))->values(),
            // What a synonym may point to: each statement's lines and totals, in order.
            'lines' => collect(StatementSynonyms::STATEMENTS)->values()->mapWithKeys(fn ($st) => [$st => collect((new LineCatalogue)->statement($st))
                ->filter(fn ($i) => in_array($i['type'], ['line', 'total'], true))->map(fn ($i) => ['key' => $i['key'], 'type' => $i['type'], 'label_ar' => $i['label_ar']])->values()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $synonym = StatementSynonym::create($data);

        return response()->json($this->present($synonym, new LineCatalogue), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $synonym = StatementSynonym::find($id);
        if (! $synonym) {
            return $this->notFound();
        }
        // A seeded synonym the user changes is theirs: no later cleanup of the starting list touches it.
        $synonym->fill($this->validated($request, $synonym->id));
        if ($synonym->isDirty()) {
            $synonym->seeded = false;
        }
        $synonym->save();

        return response()->json($this->present($synonym->fresh(), new LineCatalogue));
    }

    public function destroy(int $id): JsonResponse
    {
        $synonym = StatementSynonym::find($id);
        if (! $synonym) {
            return $this->notFound();
        }
        $synonym->delete();

        return response()->json(null, 204);
    }

    /** @return array{statement: string, line_key: string, label: string, wording: string} */
    private function validated(Request $request, ?int $except = null): array
    {
        $request->validate(['line_key' => ['required', 'string', 'max:64'], 'label' => ['required', 'string', 'min:2', 'max:255']]);
        $key = $request->input('line_key');
        $label = preg_replace('/\s+/u', ' ', trim($request->input('label')));
        $problem = StatementSynonyms::problem(new LineCatalogue, $key, $label, $except);
        if ($problem !== null) {
            throw ValidationException::withMessages([$problem[0] => $problem[1]]);
        }

        return ['statement' => StatementSynonyms::statementOf($key), 'line_key' => $key, 'label' => $label, 'wording' => StatementSynonyms::wording($label)];
    }

    private function present(StatementSynonym $s, LineCatalogue $catalogue): array
    {
        return ['id' => $s->id, 'statement' => $s->statement, 'line_key' => $s->line_key, 'line_label_ar' => StatementSynonyms::label($catalogue, $s->line_key),
            'label' => $s->label, 'updated_at' => $s->updated_at?->toIso8601String()];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'هذا المرادف غير موجود.'], 404);
    }
}
