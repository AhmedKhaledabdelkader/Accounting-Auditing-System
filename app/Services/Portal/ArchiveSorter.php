<?php

namespace App\Services\Portal;

use App\Jobs\ReviewClientFile;
use App\Models\ClientArchive;
use App\Models\ClientFile;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Services\Statements\PriorPdf\Llm;
use App\Services\WorkingPapers\Papers;
use App\Support\Llm\ToolCall;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A client sends one compressed file with everything in it; this unpacks it (ArchiveExtractor), the AI reads the files' names and
 * the folders they sit in and says which of the client's requests each answers (a «ميزان مراجعة 2025.xlsx» is the trial balance), and
 * every file is put in its request as if the client had uploaded it there. Files the names don't place go to a request of their own,
 * «unsorted», where the content review (DocumentReviewer: it reads the file itself) moves each to the request it answers when it can.
 * The AI only names the request; PHP files the document and keeps every count.
 */
final class ArchiveSorter
{
    /** The request that holds what could not be placed by its name. */
    public const UNSORTED = 'unsorted';

    private const CHUNK = 40;

    private const MIN_BATCH = 5;

    public function __construct(private ArchiveExtractor $extractor, private Llm $llm) {}

    public function sort(ClientArchive $archive): void
    {
        $archive->update(['status' => ClientArchive::SORTING, 'error' => null]);
        $dir = storage_path('app/private/tmp-archives/'.Str::uuid());
        try {
            $company = Company::withoutGlobalScopes()->findOrFail($archive->company_id);
            $path = Storage::disk('local')->path($archive->path);
            ['files' => $files, 'skipped' => $skipped] = $this->extractor->extract($path, pathinfo($archive->original_name, PATHINFO_EXTENSION), $dir);
            if ($files === []) {
                throw new RuntimeException('empty');
            }
            $requests = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $archive->year)
                ->where(fn ($q) => $q->whereNull('paper')->orWhere('paper', '<>', self::UNSORTED))->orderBy('position')->get(['id', 'title', 'section']);
            $assigned = $this->assign($path, $files, $requests->mapWithKeys(fn ($r) => [$r->id => "{$r->title} ({$r->section})"])->all());
            $summary = $this->file($archive, $company, $files, $assigned, $skipped);
            $archive->update(['status' => ClientArchive::DONE, 'summary' => $summary]);
        } catch (RuntimeException $e) {
            // Only the reasons the page has words for; anything else is the AI being unavailable, never a raw message on the page.
            $known = ['unreadable', 'encrypted', 'no_tool', 'too_big', 'too_many_files', 'empty'];
            $archive->update(['status' => ClientArchive::FAILED, 'error' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'ai_unavailable']);
        } catch (\Throwable $e) {
            report($e);
            $archive->update(['status' => ClientArchive::FAILED, 'error' => 'ai_unavailable']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Which request each file answers, from its name and folders: the AI names an id of the client's requests, never invents one;
     * a file it is not sure of is left unplaced.
     *
     * @param list<array{path: string}> $files
     * @param array<int, string> $catalogue id → «title (section)»
     * @return array<int, ?int> file index → request id
     */
    public function assign(string $archivePath, array $files, array $catalogue): array
    {
        $list = implode("\n", array_map(fn ($id, $t) => "[{$id}] {$t}", array_keys($catalogue), $catalogue));
        $out = array_fill_keys(array_keys($files), null);
        foreach (array_chunk(array_keys($files), self::CHUNK) as $indexes) {
            foreach ($this->assignChunk($archivePath, $files, $indexes, $list, $catalogue) as $i => $id) {
                $out[$i] = $id;
            }
        }

        return $out;
    }

    /**
     * One batch of files. The answer can be cut off (the model's limit, its reasoning crowding out the list) or come back empty: the
     * batch is then split in two and each half asked again, down to a few files; what still cannot be answered is left unplaced (it goes to
     * the unsorted request for the accountant), never failing the whole archive. A provider failure (key, credit) still stops it.
     *
     * @return array<int, ?int> file index → request id
     */
    private function assignChunk(string $archivePath, array $files, array $indexes, string $list, array $catalogue): array
    {
        $out = array_fill_keys($indexes, null);
        $lines = implode("\n", array_map(fn ($i) => "[{$i}] {$files[$i]['path']} ({$files[$i]['ext']}, ".round($files[$i]['size'] / 1024).' KB)', $indexes));
        $page = crc32(implode(',', $indexes));   // each batch (and each half of a split one) is kept under its own key
        try {
            $answer = $this->llm->ask($archivePath, 'archive-names', $page, crc32($list) % 997, fn () => new ToolCall([['text' => self::RULES."\n\nThe client's requests:\n{$list}"]], self::TOOL,
                "The files in the archive:\n{$lines}", 12000));
        } catch (RuntimeException $e) {
            if (count($indexes) <= self::MIN_BATCH) {
                return $out;
            }
            $half = intdiv(count($indexes), 2);

            return $this->assignChunk($archivePath, $files, array_slice($indexes, 0, $half), $list, $catalogue)
                + $this->assignChunk($archivePath, $files, array_slice($indexes, $half), $list, $catalogue);
        }
        foreach ((array) ($answer->input['assignments'] ?? []) as $a) {
            $i = (int) ($a['index'] ?? -1);
            if (array_key_exists($i, $out)) {
                $id = isset($a['request_id']) ? (int) $a['request_id'] : null;
                $out[$i] = $id !== null && isset($catalogue[$id]) && ($a['confident'] ?? false) === true ? $id : null;
            }
        }

        return $out;
    }

    /** Puts every file in its request (or the unsorted one), says so in the chats, starts the content reviews. */
    private function file(ClientArchive $archive, Company $company, array $files, array $assigned, array $skipped): array
    {
        $placed = [];
        $unsorted = [];
        $created = [];
        $limit = (int) config('portal.max_file_kb') * 1024;
        foreach ($files as $i => $f) {
            if ($f['size'] > $limit) {
                $skipped[] = ['name' => $f['path'], 'reason' => 'too_big'];
                continue;
            }
            $request = isset($assigned[$i]) ? DocumentRequest::withoutGlobalScopes()->find($assigned[$i]) : null;
            $request ??= $this->unsortedRequest($company, $archive->year);
            $stored = "client-files/{$company->office_id}/{$company->id}/".Str::uuid().'.'.$f['ext'];
            $stream = fopen($f['abs'], 'rb');
            Storage::disk('local')->put($stored, $stream);
            is_resource($stream) && fclose($stream);
            $file = ClientFile::withoutGlobalScopes()->create(['office_id' => $company->office_id, 'company_id' => $company->id, 'document_request_id' => $request->id,
                'uploaded_by' => $archive->uploaded_by, 'from_client' => $archive->from_client, 'original_name' => Str::limit($f['name'], 200, ''), 'path' => $stored, 'size' => $f['size']]);
            $archive->from_client && $request->update(['status' => DocumentRequest::UPLOADED]);
            $this->say($request, $archive, __('portal.archive.filed', ['file' => $f['path'], 'archive' => $archive->original_name]));
            // A working paper in the archive is read like one uploaded to its request.
            if ($request->paper !== null && $request->paper !== self::UNSORTED && $archive->from_client) {
                app(Papers::class)->analyse($request, $stored);
            }
            $request->paper === self::UNSORTED ? $unsorted[] = $f['path'] : $placed[$request->id][] = $f['path'];
            $created[] = $file->id;
        }
        $summary = ['total' => count($files), 'placed' => array_sum(array_map('count', $placed)), 'unsorted' => count($unsorted), 'skipped' => $skipped,
            'by_request' => array_map(fn ($id, $names) => ['request_id' => $id, 'files' => $names], array_keys($placed), $placed), 'unsorted_files' => $unsorted];
        $this->say(null, $archive, __('portal.archive.done', ['archive' => $archive->original_name, 'total' => $summary['total'], 'placed' => $summary['placed'],
            'unsorted' => $summary['unsorted'], 'skipped' => count($skipped)]), $company);
        if ($archive->from_client && config('portal.ai_review') !== 'off') {
            foreach ($created as $id) {
                ReviewClientFile::dispatch($id);
            }
        }

        return $summary;
    }

    /** The request holding the files that could not be placed: not asked of the client, open only while it has files. */
    public function unsortedRequest(Company $company, int $year): DocumentRequest
    {
        $request = DocumentRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->where('paper', self::UNSORTED)->first();
        if ($request === null) {
            $period = Workspace::periodOf($company, $year);
            $request = DocumentRequest::withoutGlobalScopes()->create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year,
                'period_start' => $period['start'], 'period_end' => $period['end'], 'section' => __('portal.archive.unsorted_section'), 'title' => __('portal.archive.unsorted_title'),
                'paper' => self::UNSORTED, 'optional' => true, 'status' => DocumentRequest::NOT_APPLICABLE,
                'position' => 100000]);
        }

        return $request;
    }

    private function say(?DocumentRequest $request, ClientArchive $archive, string $body, ?Company $company = null): void
    {
        ClientMessage::withoutGlobalScopes()->create(['office_id' => $archive->office_id, 'company_id' => $request?->company_id ?? $company->id, 'document_request_id' => $request?->id,
            'user_id' => $archive->uploaded_by, 'from_client' => $archive->from_client, 'kind' => ClientMessage::EVENT, 'body' => $body]);
    }

    private const TOOL = [
        'name' => 'submit_assignments', 'description' => 'For each file of the archive, the client request it answers by its name.', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['assignments'], 'properties' => [
            'assignments' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['index', 'request_id', 'confident'],
                'properties' => ['index' => ['type' => 'integer'], 'request_id' => ['type' => ['integer', 'null']], 'confident' => ['type' => 'boolean']]]],
        ]],
    ];

    private const RULES = <<<'TXT'
        A client of an audit firm sent one compressed file with all their documents. You get the files in it, each with the folders it sat
        in and its name (Arabic, Saudi), and the list of requests the firm made to this client (id, title, section). For every file say which
        request it answers, judging by the words in its name and its folders (a name with «ميزان مراجعة» is the trial balance; «سجل
        تجاري» the commercial registration; «كشف حساب بنك» a bank statement; «عقد إيجار» a lease...). Use only ids from the list. If the
        name does not tell, or it could be several, give request_id null. confident is true only when the name clearly says it; a guess is
        false. Never invent an id; never judge the content, you only see names.
        TXT;
}
