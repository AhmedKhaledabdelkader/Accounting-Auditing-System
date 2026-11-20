<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PreparedPaper;
use App\Services\WorkingPapers\PaperMaker;
use App\Services\WorkingPapers\Papers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The working papers and recalculations the AI prepares from a client's documents and trial balance (PaperMaker), over the API: each paper
 * with where it is, starting the AI on one or all, and the prepared workbook. Neither the accountant nor the client fills them.
 */
class PreparedPaperController extends Controller
{
    public function index(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound('الشركة غير موجودة.');
        }
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'kind' => ['nullable', Rule::in(['paper', 'recalc'])]]);
        $year = (int) ($data['year'] ?? 0) ?: ($company->periodYear() ?? now()->year - 1);

        return response()->json(['year' => $year, 'papers' => $this->papers($company, $year, $data['kind'] ?? null)]);
    }

    /** Starts the AI on one paper (`key`) or every paper of a kind (`key: all`); the work is queued, `index` follows it. */
    public function prepare(Request $request, string $id): JsonResponse
    {
        $company = $this->company($id);
        if ($company === null) {
            return $this->notFound('الشركة غير موجودة.');
        }
        $catalogue = collect(PaperMaker::catalogue());
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'kind' => ['required', Rule::in(['paper', 'recalc'])],
            'key' => ['required', 'string', Rule::in(['all', ...$catalogue->pluck('key')->all()])]]);
        $chosen = $catalogue->where('kind', $data['kind'])->filter(fn ($p) => $data['key'] === 'all' || $p['key'] === $data['key']);
        if ($chosen->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['key' => __('validation.in', ['attribute' => 'key'])]);
        }
        foreach ($chosen as $p) {
            PaperMaker::queue($company, (int) $data['year'], $p['kind'], $p['key']);
        }

        return response()->json(['queued' => $chosen->count(), 'papers' => $this->papers($company, (int) $data['year'], $data['kind'])], 202);
    }

    public function download(string $id, string $paper)
    {
        $company = $this->company($id);
        $p = $company && ctype_digit($paper) ? PreparedPaper::where('company_id', $company->id)->find((int) $paper) : null;
        if ($p === null || ! $p->path || ! Storage::disk('local')->exists($p->path)) {
            return $this->notFound(__('client_file.prepared.none'));
        }
        $title = PaperMaker::catalogue()["{$p->kind}.{$p->key}"]['title'] ?? $p->key;

        return Storage::disk('local')->download($p->path, $title.' - '.$p->year.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return list<array> every paper of the catalogue (of a kind), with the state of its preparation for the year */
    private function papers(Company $company, int $year, ?string $kind): array
    {
        $maker = app(PaperMaker::class);
        $done = PreparedPaper::where('company_id', $company->id)->where('year', $year)->get()->keyBy(fn ($p) => "{$p->kind}.{$p->key}");

        return collect(PaperMaker::catalogue())->filter(fn ($p) => $kind === null || $p['kind'] === $kind)->map(function ($p, $k) use ($done, $maker, $company, $year) {
            $paper = $done->get($k);

            return ['id' => $paper?->id, 'kind' => $p['kind'], 'key' => $p['key'], 'title' => $p['title'], 'documents' => count($maker->sources($company, $year, $k)),
                'status' => $paper?->status ?? 'none', 'progress' => ['done' => (int) ($paper?->progress_done ?? 0), 'total' => (int) ($paper?->progress_total ?? 0)],
                'summary' => $paper?->analysis ? Papers::summary($paper->analysis) : null, 'notes' => $paper?->notes ?? [], 'sources' => $paper?->sources ?? [],
                'error' => $paper?->error, 'prepared_at' => $paper?->prepared_at?->toIso8601String(),
                'download_url' => $paper?->status === PreparedPaper::DONE && $paper->path ? "/api/v1/clients/{$company->id}/prepared-papers/{$paper->id}/download" : null];
        })->values()->all();
    }

    private function company(string $id): ?Company
    {
        return Str::isUuid($id) ? Company::find($id) : null;
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }
}
