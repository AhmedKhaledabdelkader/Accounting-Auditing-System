<?php

namespace App\Support;

use App\Models\ClientFile;
use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A file the accountant picks from what the client already uploaded in the portal, instead of uploading it again. `attach()`
 * turns `client_file_id` in a request into the uploaded `file` the endpoint expects, so the endpoint's own validation and
 * pipeline run unchanged; `forPurpose()` lists the files of a client for a purpose, the likeliest first.
 */
final class ClientFileSource
{
    /** What each purpose asks of a file: the extensions it takes, and the words of the request it usually sits in. */
    public const PURPOSES = [
        'trial_balance'    => ['extensions' => ['xlsx', 'xls', 'csv'], 'words' => ['ميزان مراجعة', 'ميزان المراجعة']],
        'prior_statements' => ['extensions' => ['xlsx', 'xls', 'pdf'], 'words' => ['القوائم المالية المعتمدة', 'القوائم المالية للسنة السابقة']],
        'articles'         => ['extensions' => ['pdf'], 'words' => ['عقد تأسيس', 'عقد التأسيس']],
        'sales'            => ['extensions' => ['xlsx', 'xls', 'csv', 'pdf'], 'words' => ['المبيعات', 'مبيعات', 'الإيرادات', 'فواتير']],
    ];

    /** Puts the picked file in the request as `file` (a copy: the portal's own stays as it is). No `client_file_id`: nothing changes. */
    public static function attach(Request $request): void
    {
        $id = $request->input('client_file_id');
        if ($id === null || $id === '' || $request->files->has('file')) {
            return;
        }
        // Scoped to the signed-in office; only what a client uploaded.
        $file = ClientFile::where('from_client', true)->find((int) $id);
        if ($file === null || ! Storage::disk('local')->exists($file->path)) {
            abort(response()->json(['message' => __('portal.pick.gone'), 'errors' => ['client_file_id' => [__('portal.pick.gone')]]], 422));
        }
        $copy = tempnam(sys_get_temp_dir(), 'cf').'.'.strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
        copy(Storage::disk('local')->path($file->path), $copy);
        register_shutdown_function(fn () => @unlink($copy));
        $request->files->set('file', new UploadedFile($copy, $file->original_name, null, null, true));
        // The request keeps the files it already converted: forget them so $request->file() sees this one.
        $forget = fn () => $this->convertedFiles = null;
        $forget->call($request);
    }

    /**
     * The client's files that can serve a purpose (their extension fits), the likeliest first: the request the purpose names,
     * the AI's verdict (fine before remarks), the period asked for ($year: the year the fiscal period ends in), the newest.
     *
     * @return array{files: list<array>, suggested: ?int}
     */
    public static function forPurpose(string $companyId, string $purpose, ?int $year = null): array
    {
        $rule = self::PURPOSES[$purpose] ?? ['extensions' => [], 'words' => []];
        $files = ClientFile::where('company_id', $companyId)->where('from_client', true)->with('documentRequest')->orderByDesc('id')->get()
            ->filter(fn (ClientFile $f) => in_array(strtolower(pathinfo($f->path, PATHINFO_EXTENSION)), $rule['extensions'], true));
        $rows = $files->map(function (ClientFile $f) use ($rule, $year) {
            /** @var DocumentRequest|null $r */
            $r = $f->documentRequest;
            $title = (string) $r?->title;
            $named = array_filter($rule['words'], fn ($w) => str_contains($title, $w)) !== [];
            $verdict = $f->review['status'] ?? null;
            $score = ($named ? 100 : 0) + ($year !== null && $r && $r->year === $year ? 50 : 0) + ($verdict === 'ok' ? 20 : ($verdict === null || $verdict === 'unavailable' ? 5 : 0)) + ($r && $r->status === DocumentRequest::ACCEPTED ? 10 : 0);

            return ['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size, 'uploaded_at' => $f->created_at?->toIso8601String(),
                'request' => $r ? ['id' => $r->id, 'title' => $title, 'year' => $r->year, 'status' => $r->status] : null,
                'review' => $verdict, 'type' => $f->review['read']['document_type'] ?? null, 'matches' => $named, 'score' => $score];
        })->sortByDesc(fn ($r) => [$r['score'], $r['id']])->values();
        $best = $rows->first();

        return ['files' => $rows->map(fn ($r) => array_diff_key($r, ['score' => 1]))->all(), 'suggested' => $best && $best['matches'] ? $best['id'] : null];
    }
}
