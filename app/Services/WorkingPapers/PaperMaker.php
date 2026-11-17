<?php

namespace App\Services\WorkingPapers;

use App\Models\ClassificationRun;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\PreparedPaper;
use App\Services\Portal\DocumentReviewer;
use App\Services\Portal\Workspace;
use App\Services\Statements\PriorPdf\Llm;
use App\Support\Llm\ToolCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The AI prepares the working papers and the recalculations, not the accountant and not the client (the user's rule). The client only
 * sends the documents (the requirements); for each paper the AI reads the documents that belong to it (config/paper_sources.php), one
 * by one, and COPIES from them what the paper asks for, row by row (an employee, an asset, a contract, a customer…), and copies the
 * figure of the trial balance the paper is compared with. It never calculates and never invents: what a document does not show stays
 * empty and is said in the notes. PHP then writes the rows into the firm's form (the recalculation papers' formulas are the same ones
 * that recalculate in PHP), and `analyse` recalculates and compares with the books exactly as it does for a paper filled by hand.
 */
final class PaperMaker
{
    public const VERSION = 1;

    public function __construct(private Llm $llm, private DocumentReviewer $reader) {}

    /** @return array<string, array{kind: string, key: string, title: string}> «kind.key» → the paper: the recalculations first, then the working papers */
    public static function catalogue(): array
    {
        $out = [];
        foreach ((array) config('recalculations.papers') as $key => $title) {
            $out["recalc.{$key}"] = ['kind' => 'recalc', 'key' => $key, 'title' => (string) $title];
        }
        foreach (array_keys((array) config('working_papers.papers')) as $key) {
            if (($path = Papers::path($key)) !== null) {
                $out["paper.{$key}"] = ['kind' => 'paper', 'key' => $key, 'title' => WorkingPaperReader::read($path)['title'] ?: $key];
            }
        }

        return $out;
    }

    /** Puts a paper in the queue (again, if it was prepared before): the AI starts at once on a queue worker. */
    public static function queue(Company $company, int $year, string $kind, string $key): PreparedPaper
    {
        $paper = PreparedPaper::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'year' => $year, 'kind' => $kind, 'key' => $key],
            ['office_id' => $company->office_id, 'status' => PreparedPaper::QUEUED, 'progress_done' => 0, 'progress_total' => 0, 'error' => null],
        );
        \App\Jobs\PreparePaper::dispatch($paper->id);

        return $paper;
    }

    /** The client's documents the paper is prepared from: the uploads (not the office's) of the requirements in its sections, for the year. */
    public function sources(Company $company, int $year, string $key): array
    {
        $words = (array) (config('paper_sources.sources')[$key] ?? []);   // not a dot path: the keys have dots
        $files = ClientFile::withoutGlobalScopes()->where('company_id', $company->id)->where('from_client', true)
            ->whereHas('documentRequest', fn ($q) => $q->where('year', $year)->where('status', '<>', 'returned')->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $q->orWhere('section', 'like', "%{$w}%");
                }
            }))->orderBy('id')->get();

        return $files->filter(fn ($f) => in_array(strtolower(pathinfo($f->path, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'csv', 'docx', 'txt'], true))
            ->take((int) config('paper_sources.max_files', 12))->values()->all();
    }

    public function make(PreparedPaper $paper): void
    {
        $company = Company::withoutGlobalScopes()->findOrFail($paper->company_id);
        $files = $this->sources($company, $paper->year, "{$paper->kind}.{$paper->key}");
        if ($files === []) {
            $paper->update(['status' => PreparedPaper::BLOCKED, 'sources' => [], 'notes' => null, 'path' => null, 'analysis' => null, 'error' => null]);

            return;
        }
        $paper->update(['status' => PreparedPaper::WORKING, 'progress_done' => 0, 'progress_total' => count($files), 'sources' => array_map(fn ($f) => $f->original_name, $files), 'error' => null]);

        $spec = $this->spec($paper);
        $period = Workspace::periodOf($company, $paper->year);
        $end = Carbon::parse($period['end'])->toDateString();
        $system = $this->rules($spec, $paper, $end).$this->trialBalance($company);
        $rows = [];
        $notes = [];
        $tbTotal = null;
        foreach ($files as $i => $file) {
            $answer = $this->read($file, $spec, $system, $paper, $end);
            foreach ((array) ($answer['rows'] ?? []) as $row) {
                is_array($row) && trim((string) ($row[$spec['key']] ?? '')) !== '' && $rows[] = $row;
            }
            $tbTotal ??= is_numeric($answer['trial_balance_total'] ?? null) ? (float) $answer['trial_balance_total'] : null;
            foreach ((array) ($answer['notes'] ?? []) as $n) {
                is_string($n) && trim($n) !== '' && $notes[] = "{$file->original_name}: ".trim($n);
            }
            $paper->update(['progress_done' => $i + 1]);
        }
        $capacity = $spec['capacity'];
        if (count($rows) > $capacity) {
            $notes[] = __('portal.maker.rows_over', ['kept' => $capacity, 'found' => count($rows)]);
            $rows = array_slice($rows, 0, $capacity);
        }

        $stored = "papers/{$company->office_id}/{$company->id}/{$paper->year}/{$paper->kind}-{$paper->key}.xlsx";
        $full = Storage::disk('local')->path($stored);
        @mkdir(dirname($full), 0777, true);
        $this->write($paper, $spec, $company, $end, $rows, $tbTotal, $full);
        $analysis = $paper->kind === 'recalc' ? (new Recalculations())->analyse($paper->key, $full) : WorkingPaperReader::analyse($full);

        $paper->update(['status' => PreparedPaper::DONE, 'path' => $stored, 'analysis' => $analysis, 'notes' => $notes ?: null, 'prepared_at' => now(), 'error' => null]);
    }

    /**
     * What the paper asks for.
     *
     * @return array{key: string, columns: list<array{id: string, label: string, type: string, letter: string}>, capacity: int, title: string}
     */
    private function spec(PreparedPaper $paper): array
    {
        if ($paper->kind === 'recalc') {
            return ['key' => 'name', 'title' => (string) config("recalculations.papers.{$paper->key}"), 'capacity' => Recalculations::ROWS,
                'columns' => array_map(fn ($c) => ['id' => $c[0], 'label' => $c[1], 'type' => $c[2], 'letter' => $c[3]], Recalculations::inputColumns($paper->key))];
        }
        $read = WorkingPaperReader::read((string) Papers::path($paper->key));
        $inputs = array_values(array_filter($read['columns'], fn ($c) => $c['input']));

        return ['key' => $inputs[0]['column'] ?? 'C', 'title' => $read['title'], 'capacity' => max(1, $read['sample_rows']),
            'columns' => array_map(fn ($c) => ['id' => $c['column'], 'label' => $c['label'], 'type' => preg_match('/تاريخ|date/iu', $c['label']) ? 'date' : 'any', 'letter' => $c['column']], $inputs)];
    }

    private function rules(array $spec, PreparedPaper $paper, string $end): string
    {
        $cols = implode("\n", array_map(fn ($c) => "- {$c['id']}: {$c['label']}".($c['type'] === 'date' ? ' (date, YYYY-MM-DD)' : ($c['type'] === 'num' ? ' (number)' : '')), $spec['columns']));

        return "You prepare an audit working paper from a client's document: «{$spec['title']}». The financial period ends {$end}.\n"
            ."RULES. Copy only what the document shows, row by row: one row for each employee, asset, contract, customer, account or item the paper is about. "
            ."Never calculate, estimate or invent a figure or a date; a field the document does not show is null. Dates as Gregorian YYYY-MM-DD (convert a Hijri date). "
            ."Amounts as plain numbers (no currency, no thousands separators), in the document's own currency and unit. Text exactly as printed. "
            ."If this document has nothing for this paper, return no rows and say so in notes. Put anything the accountant should know (a column missing, unreadable parts, "
            ."a doubt) in notes, in Arabic. trial_balance_total: copy from the trial balance below the closing balance of the accounts this paper is compared with "
            ."(the sum of those accounts), or null if you cannot tell. The columns of each row:\n{$cols}\n";
    }

    /** The closing balances of the client's latest classified trial balance, for the AI to copy the books' figure from. */
    private function trialBalance(Company $company): string
    {
        $run = ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'done')->latest()->first();
        if ($run === null) {
            return "\nThere is no trial balance for this client yet: trial_balance_total is null and the books' column stays null.\n";
        }
        $lines = [];
        foreach (array_slice((array) ($run->result['data'] ?? []), 0, (int) config('paper_sources.tb_rows', 600)) as $r) {
            $net = (float) ($r['closing_debit'] ?? 0) - (float) ($r['closing_credit'] ?? 0);
            if (abs($net) > 0.004 && ($r['row_type'] ?? 'account') === 'account') {
                $lines[] = ($r['account_number'] ?? '').' | '.($r['account_name'] ?? '').' | '.($r['sub_class'] ?? '').' | '.round($net, 2);
            }
        }

        return "\nThe trial balance (closing balance, debit positive, credit negative): number | account | class | balance\n".implode("\n", $lines)."\n";
    }

    /** One document, read by the AI (kept by the file's hash: the same document and the same paper are not asked twice). */
    private function read(ClientFile $file, array $spec, string $system, PreparedPaper $paper, string $end): array
    {
        $path = Storage::disk('local')->path($file->path);
        $ext = strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
        [$bytes, $mime, $text] = [null, null, ''];
        switch ($ext) {
            case 'pdf':
                $bytes = (string) file_get_contents($path);
                break;
            case 'jpg': case 'jpeg': case 'png':
                $bytes = (string) file_get_contents($path);
                $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
                break;
            case 'docx':
                $text = $this->reader->docxText($path);
                break;
            case 'txt':
                $text = mb_substr((string) file_get_contents($path), 0, (int) config('portal.review_text_chars'));
                break;
            default:
                $text = $this->reader->spreadsheetText($path, (int) config('paper_sources.sheet_rows', 400));
        }
        $content = "Document «{$file->original_name}»".($file->documentRequest ? " (the client's answer to: {$file->documentRequest->title})" : '').".\n"
            .($text !== '' ? "Its content, as text:\n{$text}\n" : '')."Give the rows of the paper «{$spec['title']}» this document shows.";
        $variant = self::VERSION * 1000 + (crc32($system) % 997);

        return $this->llm->ask($path, "paper-{$paper->kind}-{$paper->key}", 0, $variant, fn () => new ToolCall([['text' => $system, 'cache' => true]], $this->tool($spec), $content, 8000, false, $bytes, $mime))->input;
    }

    private function tool(array $spec): array
    {
        $types = ['text' => ['string', 'null'], 'date' => ['string', 'null'], 'num' => ['number', 'null'], 'choice' => ['string', 'null'], 'any' => ['string', 'number', 'null']];
        $props = [];
        foreach ($spec['columns'] as $c) {
            $props[$c['id']] = ['type' => $types[$c['type']] ?? ['string', 'null'], 'description' => $c['label']];
        }

        return ['name' => 'submit_paper_rows', 'description' => 'The rows of the working paper that this document shows, copied from it.', 'strict' => true, 'schema' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['rows', 'trial_balance_total', 'notes'], 'properties' => [
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props]],
                'trial_balance_total' => ['type' => ['number', 'null']],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]]];
    }

    /** The rows go into the firm's form (the recalculation paper's own, or the blank of the working paper), the entity and the period with them. */
    private function write(PreparedPaper $paper, array $spec, Company $company, string $end, array $rows, ?float $tbTotal, string $target): void
    {
        $source = $paper->kind === 'recalc' ? tempnam(sys_get_temp_dir(), 'pm') : (string) Papers::path($paper->key);
        if ($paper->kind === 'recalc') {
            file_put_contents($source, (new Recalculations())->cachedTemplate($paper->key));
        }
        $book = IOFactory::load($source);
        $sheet = $book->getSheet(0);
        $date = fn (string $v) => ($t = strtotime($v)) === false ? null : Date::PHPToExcel($t);
        $put = function (string $coord, mixed $value, string $type) use ($sheet, $date) {
            if ($value === null || $value === '') {
                return;
            }
            if ($type === 'date' && ($serial = $date((string) $value)) !== null) {
                $sheet->setCellValue($coord, $serial);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            } elseif ($type === 'num' || (is_numeric($value) && $type === 'any')) {
                $sheet->setCellValue($coord, (float) $value);
            } else {
                $sheet->setCellValue($coord, (string) $value);
            }
        };
        if ($paper->kind === 'recalc') {
            $sheet->setCellValue('D3', (string) $company->name_ar);
            $put('D4', $end, 'date');
            $tbTotal !== null && $sheet->setCellValue('D5', $tbTotal);
            $first = Recalculations::FIRST_ROW;
        } else {
            $read = WorkingPaperReader::read($source);
            foreach ($read['entity_cells'] as $label => $coord) {
                if (preg_match('/المنشأة|الشركة|العميل/u', $label)) {
                    $put($coord, $company->name_ar, 'text');
                } elseif (preg_match('/الفترة|تاريخ|السنة/u', $label)) {
                    $put($coord, $end, 'text');
                }
            }
            $first = $read['first_row'] ?? 1;
        }
        foreach ($rows as $i => $row) {
            foreach ($spec['columns'] as $c) {
                $put($c['letter'].($first + $i), $row[$c['id']] ?? null, $c['type']);
            }
        }
        (new Xlsx($book))->save($target);
        $paper->kind === 'recalc' && @unlink($source);
    }
}
