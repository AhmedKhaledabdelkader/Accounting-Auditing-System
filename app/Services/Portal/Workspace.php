<?php

namespace App\Services\Portal;

use App\Models\ClientFile;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the office and a client's portal user do together on the client's company: the audit checklist as document
 * requests, the files uploaded to them, the chat, and the figures the pages show. The controllers decide who may call
 * it; this class only keeps the rules (what a status change means, what an upload does, what is unread).
 */
class Workspace
{
    /** A file size for the lists: 12 KB, 3.4 MB (no intl extension needed). */
    public static function fileSize(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /** What may be uploaded: size in KB and extensions (config/portal.php). */
    /** What an archive upload takes: a compressed file of a kind we unpack, within the size limit. */
    public static function archiveRules(): array
    {
        return ['required', 'file', 'extensions:'.implode(',', config('portal.archive_extensions')), 'max:'.config('portal.max_archive_kb')];
    }

    public static function uploadRules(): array
    {
        return ['required', 'file', 'max:'.config('portal.max_file_kb'), 'extensions:'.implode(',', config('portal.extensions'))];   // by the name: an accounting system's .xls is often XML or HTML inside
    }

    /** The checklist's lines for a year, numbered as in the engagement letter. Lines already requested for that year are kept as they are. */
    public function applyChecklist(Company $company, int $year, ?User $by): int
    {
        $have = DocumentRequest::where('company_id', $company->id)->where('year', $year)->whereNotNull('number')->pluck('number')->all();
        $period = self::periodOf($company, $year);
        $position = (int) DocumentRequest::where('company_id', $company->id)->where('year', $year)->max('position');
        $number = 0;
        $added = 0;
        $noArticles = $company->articlesRequired() === false;   // a sole proprietorship has no articles of association
        DB::transaction(function () use ($company, $year, $by, $have, $period, $noArticles, &$position, &$number, &$added) {
            foreach (config('audit_requirements') as $section => $lines) {
                foreach ($lines as [$title, $format, $optional]) {
                    $number++;
                    if (in_array($number, $have, true)) {
                        continue;
                    }
                    DocumentRequest::create([
                        'office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year, 'section' => $section, 'period_start' => $period['start'], 'period_end' => $period['end'],
                        'number' => $number, 'title' => strtr($title, ['{end}' => self::dateText($period['end']), '{year}' => (string) $year]), 'format' => $format,
                        'optional' => $optional, 'position' => ++$position, 'created_by' => $by?->id,
                    ] + ($noArticles && self::isArticles($title) ? ['status' => DocumentRequest::NOT_APPLICABLE, 'note' => __('portal.no_articles_note')] : []));
                    $added++;
                }
            }
        });

        return $added;
    }

    /** Whether a request's title asks for the articles of association. */
    public static function isArticles(string $title): bool
    {
        return (bool) preg_match('/عقد\s+(ال)?تأسيس/u', $title);
    }

    /**
     * A sole proprietorship has no articles of association: its requests for them that the client hasn't answered yet become not
     * applicable (an uploaded or accepted one is left as it is). Nothing changes for a company or while the legal form is unknown.
     */
    public static function articlesNotNeeded(Company $company): int
    {
        if ($company->articlesRequired() !== false) {
            return 0;
        }
        $requests = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('status', DocumentRequest::PENDING)->get(['id', 'title']);
        $ids = $requests->filter(fn ($r) => self::isArticles($r->title))->pluck('id');

        return $ids->isEmpty() ? 0 : DocumentRequest::withoutGlobalScopes()->whereIn('id', $ids)
            ->update(['status' => DocumentRequest::NOT_APPLICABLE, 'note' => __('portal.no_articles_note'), 'updated_at' => now()]);
    }

    public function addRequest(Company $company, int $year, string $section, string $title, ?string $format, bool $optional, ?User $by): DocumentRequest
    {
        return DocumentRequest::create([
            'office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year, 'period_start' => self::periodOf($company, $year)['start'], 'period_end' => self::periodOf($company, $year)['end'],
            'section' => $section, 'title' => $title, 'format' => $format, 'optional' => $optional, 'created_by' => $by?->id,
            'position' => (int) DocumentRequest::where('company_id', $company->id)->where('year', $year)->max('position') + 1,
        ]);
    }

    /**
     * The office accepts a document, sends it back with what to change, marks it not applicable or reopens it. Sending back
     * says why in the request's chat, so the client is told there as well.
     */
    public function review(DocumentRequest $request, string $status, ?string $note, User $by): void
    {
        $request->update(['status' => $status, 'note' => $status === DocumentRequest::RETURNED ? $note : null]);
        match ($status) {
            DocumentRequest::RETURNED => $this->say($request, $by, false, ClientMessage::TEXT, __('portal.chat.returned', ['note' => $note])),
            DocumentRequest::ACCEPTED => $this->say($request, $by, false, ClientMessage::EVENT, __('portal.chat.accepted')),
            DocumentRequest::NOT_APPLICABLE => $this->say($request, $by, false, ClientMessage::EVENT, __('portal.chat.office_na')),
            default => $this->say($request, $by, false, ClientMessage::EVENT, __('portal.chat.reopened')),
        };
    }

    /** The client says a document doesn't apply to them (or takes it back). An accepted document stays accepted. */
    public function declare(DocumentRequest $request, bool $notApplicable, User $by): bool
    {
        if ($request->status === DocumentRequest::ACCEPTED) {
            return false;
        }
        $request->update(['status' => $notApplicable ? DocumentRequest::NOT_APPLICABLE : ($request->files()->exists() ? DocumentRequest::UPLOADED : DocumentRequest::PENDING)]);
        $this->say($request, $by, true, ClientMessage::EVENT, __($notApplicable ? 'portal.chat.client_na' : 'portal.chat.client_undo_na'));

        return true;
    }

    /** @param  UploadedFile[]  $files */
    public function upload(DocumentRequest $request, array $files, User $by, bool $sync = false): int
    {
        $client = $by->isClient();
        $reviews = [];
        foreach ($files as $file) {
            $name = Str::limit(basename(str_replace(chr(92), '/', $file->getClientOriginalName())), 200, '');
            $path = $file->storeAs("client-files/{$request->office_id}/{$request->company_id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
            $stored = ClientFile::create(['office_id' => $request->office_id, 'company_id' => $request->company_id, 'document_request_id' => $request->id,
                'uploaded_by' => $by->id, 'from_client' => $client, 'original_name' => $name, 'path' => $path, 'size' => $file->getSize()]);
            $this->say($request, $by, $client, ClientMessage::EVENT, __($client ? 'portal.chat.client_uploaded' : 'portal.chat.office_uploaded', ['file' => $name]));
            // A working paper sent back is read: how far it is filled and what its formulas found.
            if ($client && ($analysis = app(\App\Services\WorkingPapers\Papers::class)->analyse($request, $path)) !== null) {
                $this->say($request, $by, true, ClientMessage::EVENT, __('portal.paper.read', ['summary' => \App\Services\WorkingPapers\Papers::summary($analysis)]));
            }
            $reviews[] = $stored->id;
        }
        if ($client && $files) {
            $request->update(['status' => DocumentRequest::UPLOADED]);   // back in the accountant's tray, even if it had been accepted or returned
            // The AI reviews what came (queued; the accountant is the fallback), after the request is in the tray: it may accept it.
            foreach (config('portal.ai_review') === 'off' ? [] : $reviews as $id) {
                // $sync: nobody is waiting on a page (the mailbox reader), so the review runs now instead of waiting for a queue worker.
                $sync ? \App\Jobs\ReviewClientFile::dispatchSync($id) : \App\Jobs\ReviewClientFile::dispatch($id);
            }
        }

        return count($files);
    }

    public function deleteFile(ClientFile $file): void
    {
        Storage::disk('local')->delete($file->path);
        $request = $file->documentRequest;
        $file->delete();
        if ($request && $request->status === DocumentRequest::UPLOADED && ! $request->files()->exists()) {
            $request->update(['status' => DocumentRequest::PENDING]);
        }
    }

    /** A line in a thread: the general chat when $request is null. */
    public function say(?DocumentRequest $request, User $by, bool $fromClient, string $kind, string $body, ?string $companyId = null): ClientMessage
    {
        return ClientMessage::create([
            'office_id' => $request?->office_id ?? $by->office_id, 'company_id' => $request?->company_id ?? $companyId,
            'document_request_id' => $request?->id, 'user_id' => $by->id, 'from_client' => $fromClient, 'kind' => $kind, 'body' => $body,
            // An event is a note in the thread, not something to be told about.
            'read_at' => $kind === ClientMessage::EVENT ? now() : null,
        ]);
    }

    /**
     * A thread's messages after $after, oldest first; those the other side wrote are marked read (the viewer has the
     * thread open). $requestId null = the general chat.
     *
     * @return array<int, array>
     */
    public function thread(Company $company, ?int $requestId, int $after, bool $viewerIsClient): array
    {
        $rows = ClientMessage::where('company_id', $company->id)->where('document_request_id', $requestId)->where('id', '>', $after)
            ->orderBy('id')->limit(200)->with('user:id,name')->get();
        $unread = $rows->filter(fn ($m) => in_array($m->kind, [ClientMessage::TEXT, ClientMessage::AI], true) && $m->from_client !== $viewerIsClient && $m->read_at === null)->pluck('id');
        if ($unread->isNotEmpty()) {
            ClientMessage::whereIn('id', $unread)->update(['read_at' => now()]);
        }

        return $rows->map(fn (ClientMessage $m) => [
            'id' => $m->id, 'mine' => $m->from_client === $viewerIsClient, 'kind' => $m->kind, 'body' => $m->body,
            'name' => $m->kind === ClientMessage::AI ? __('portal.chat.ai') : ($m->user?->name ?? __($m->from_client ? 'portal.chat.client' : 'portal.chat.office')),
            'at' => $m->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Unread messages for one side, by thread (the request's id; 'general' for the general chat).
     *
     * @return array<int|string, int>
     */
    public function unread(string $companyId, bool $viewerIsClient): array
    {
        $out = [];
        $rows = ClientMessage::where('company_id', $companyId)->whereIn('kind', [ClientMessage::TEXT, ClientMessage::AI])->where('from_client', ! $viewerIsClient)->whereNull('read_at')
            ->selectRaw('document_request_id as thread, count(*) as n')->groupBy('document_request_id')->get();
        foreach ($rows as $row) {
            $out[$row->thread ?? 'general'] = (int) $row->n;
        }

        return $out;
    }

    /** Counts of requests by status, and how far the checklist is done (accepted or not applicable). */
    public static function progress(iterable $requests): array
    {
        $by = array_fill_keys(DocumentRequest::STATUSES, 0);
        foreach ($requests as $r) {
            $by[$r->status]++;
        }
        $total = array_sum($by);
        $done = $by[DocumentRequest::ACCEPTED] + $by[DocumentRequest::NOT_APPLICABLE];

        return ['by' => $by, 'total' => $total, 'done' => $done, 'percent' => $total ? (int) round($done * 100 / $total) : 0];
    }

    /** A compressed file with everything in it: kept, and queued to be unpacked and sorted (ArchiveSorter). */
    public function receiveArchive(Company $company, \Illuminate\Http\UploadedFile $file, int $year, User $by): \App\Models\ClientArchive
    {
        $name = Str::limit(basename(str_replace(chr(92), '/', $file->getClientOriginalName())), 200, '');
        $path = $file->storeAs("client-archives/{$company->office_id}/{$company->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        $archive = \App\Models\ClientArchive::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year, 'uploaded_by' => $by->id,
            'from_client' => $by->isClient(), 'original_name' => $name, 'path' => $path, 'size' => $file->getSize()]);
        $this->say(null, $by, $by->isClient(), ClientMessage::EVENT, __('portal.archive.received', ['archive' => $name]), $company->id);
        \App\Jobs\SortClientArchive::dispatch($archive->id);

        return $archive;
    }

    /** The fiscal period (start, end) that ends in $year, for the requests of that year. */
    public static function periodOf(Company $company, int $year): array
    {
        return $company->periodEndingIn($year);
    }

    /** A date in words: «31 ديسمبر 2025». */
    public static function dateText(\DateTimeInterface $d): string
    {
        return $d->format('j').' '.__('portal.months')[(int) $d->format('n')].' '.$d->format('Y');
    }

    /** «من 1 يناير 2025 إلى 31 ديسمبر 2025» for a company's year. */
    public static function periodText(Company $company, int $year): string
    {
        $p = $company->periodEndingIn($year);

        return __('portal.period_text', ['from' => self::dateText($p['start']), 'to' => self::dateText($p['end'])]);
    }

    /** The years a company has requests for, newest first; last year when it has none yet. */
    public static function years(Company $company): array
    {
        $years = DocumentRequest::where('company_id', $company->id)->distinct()->orderByDesc('year')->pluck('year')->all();

        return $years ?: [(int) now()->year - 1];
    }
}
