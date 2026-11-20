<?php

namespace App\Services\Portal;

use App\Models\ClientFile;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Services\Audit\DocumentMatch;
use App\Services\Audit\TrialBalanceBook;
use App\Services\Statements\PriorPdf\Llm;
use App\Support\ImportNormalizer;
use App\Support\Llm\ToolCall;
use App\Services\WorkingPapers\Papers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The AI reviews what the client uploads, instead of the accountant (the user's rule): it reads the document, says what it is by
 * the words printed on it (a commercial registration says «سجل تجاري»), says which of the client's requests it answers, and
 * copies what it shows: the company, the registration number, the period, the dates, the signature, the key totals. It
 * never decides. PHP decides from that copy (`judge`): the right document for this request, the client's own company, the
 * year asked, still valid, signed where it should be, readable. A good document is accepted; one with a problem goes back to
 * the client with what to fix; a document that answers another request is moved there. The accountant sees every verdict
 * (`client_files.review`) and can reopen anything.
 *
 * Working papers sent back (WorkingPaperReader / Recalculations) are judged from their own analysis in the same way.
 * `portal.ai_review`: off | suggest (the verdict is kept and said, nothing changes) | auto (accepts, returns, moves).
 */
final class DocumentReviewer
{
    public const VERSION = 2;   // 2: the balances the document shows, matched against the trial balance

    /** What a problem is called, for the client and the accountant (lang portal.ai.issue.*). */
    public const PROBLEMS = ['unreadable', 'unrecognised', 'mismatch', 'company_mismatch', 'period_mismatch', 'expired', 'unsigned', 'unsupported',
        'paper_entity', 'paper_empty', 'paper_incomplete', 'paper_questions', 'paper_difference', 'recalc_difference', 'recalc_missing', 'recalc_tb'];

    private const SIGNED_WORDS = '/معتمد|موقع|مصادق|محضر|قرار|إقرار|اقرار|تفويض/u';

    public function __construct(private Llm $llm) {}

    /** Reviews one client file (the AI reads it), keeps the verdict on it and acts on it by the office's mode. */
    public function review(ClientFile $file): void
    {
        $request = $file->documentRequest;
        if ($request === null || ! $file->from_client || config('portal.ai_review') === 'off') {
            return;
        }
        if ($request->paper !== null && is_array($request->analysis) && strtolower(pathinfo($file->path, PATHINFO_EXTENSION)) === 'xlsx') {
            $verdict = $this->judgePaper($request->analysis);
        } else {
            $catalogue = $this->catalogue($request);
            $read = $this->read($file, $request, $catalogue);
            $verdict = $read === null ? ['status' => 'unsupported', 'issues' => [['code' => 'unsupported']]] : $this->judge($read, $request, $request->company, array_keys($catalogue))
                + ['read' => $read];
            if ($read !== null && $read['balances'] !== [] && $request->company !== null) {
                $verdict['tb'] = self::matchBooks($request, $read['balances']);   // the accountant's finding, never the client's problem
            }
        }
        $file->update(['review' => $verdict + ['at' => now()->toIso8601String()]]);
        $this->act($file->fresh(), $request->fresh(), $verdict);
    }

    /** The document's balances against the trial balance of the request's year (DocumentMatch). */
    public static function matchBooks(DocumentRequest $request, array $balances): array
    {
        $company = $request->company;
        $end = \Illuminate\Support\Carbon::parse(Workspace::periodOf($company, (int) $request->year)['end'])->toDateString();

        return DocumentMatch::compare(TrialBalanceBook::for($company, (int) $request->year), $balances, $end);
    }

    /** The AI could not be asked (a provider failure): the accountant reviews it, and the client is told so. */
    public function unavailable(ClientFile $file): void
    {
        $file->update(['review' => ['status' => 'unavailable', 'issues' => [], 'at' => now()->toIso8601String()]]);
        $this->say($file->documentRequest, __('portal.ai.unavailable', ['file' => $file->original_name]));
    }

    /** @return array<int, string> id → «title» of the requests this client has for the year, what the AI picks from */
    private function catalogue(DocumentRequest $request): array
    {
        return DocumentRequest::where('company_id', $request->company_id)->where('year', $request->year)->where(fn ($q) => $q->whereNull('paper')->orWhere('paper', '<>', ArchiveSorter::UNSORTED))->orderBy('position')
            ->get(['id', 'title', 'section'])->mapWithKeys(fn ($r) => [$r->id => "{$r->title} ({$r->section})"])->all();
    }

    /** @return ?array the AI's copy of the document; null when this kind of file can't be read (a zip) */
    private function read(ClientFile $file, DocumentRequest $request, array $catalogue): ?array
    {
        $path = Storage::disk('local')->path($file->path);
        $ext = strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
        $bytes = null;
        $mime = null;
        $text = '';
        switch ($ext) {
            case 'pdf':
                $bytes = (string) file_get_contents($path);
                break;
            case 'jpg': case 'jpeg': case 'png':
                $bytes = (string) file_get_contents($path);
                $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
                break;
            case 'xlsx': case 'xls': case 'csv':
                $text = $this->spreadsheetText($path);
                break;
            case 'docx':
                $text = $this->docxText($path);
                break;
            case 'txt': case 'doc':
                $text = mb_substr((string) file_get_contents($path), 0, (int) config('portal.review_text_chars'));
                break;
            default:
                return null;
        }
        $list = implode("\n", array_map(fn ($id, $t) => "[{$id}] {$t}", array_keys($catalogue), $catalogue));
        $content = "The client uploaded this file «{$file->original_name}» to the request [{$request->id}] «{$request->title}». The financial year asked about: {$request->year}.\n"
            .($text !== '' ? "Its content, as text:\n{$text}\n" : '')."Describe it.";
        $answer = $this->llm->ask($path, 'client-doc', 0, self::VERSION * 1000 + (crc32($list) % 997), fn () => new ToolCall([['text' => self::RULES."\n\nThe client's requests:\n{$list}"]], self::TOOL,
            $content, 3000, false, $bytes, $mime));

        return self::normalise($answer->input, array_keys($catalogue));
    }

    /**
     * The verdict from what the document shows. Pure: the same copy always gives the same verdict.
     *
     * @return array{status: string, issues: list<array{code: string, params?: array}>, target?: int}
     *         status: ok | issues | other_request (target: the request it answers) | unreadable | mismatch
     */
    public static function judge(array $r, DocumentRequest $request, ?Company $company, array $catalogueIds = []): array
    {
        if (! $r['readable']) {
            return ['status' => 'unreadable', 'issues' => [['code' => 'unreadable']]];
        }
        $ids = $r['matching_request_ids'];
        if ($ids === []) {
            return ['status' => 'mismatch', 'issues' => [['code' => 'unrecognised', 'params' => ['type' => $r['document_type']]]]];
        }
        if (! in_array($request->id, $ids, true)) {
            return count($ids) === 1
                ? ['status' => 'other_request', 'target' => $ids[0], 'issues' => []]
                : ['status' => 'mismatch', 'issues' => [['code' => 'mismatch', 'params' => ['type' => $r['document_type']]]]];
        }
        $issues = [];
        if ($company !== null && self::otherCompany($r, $company)) {
            $issues[] = ['code' => 'company_mismatch', 'params' => ['name' => (string) $r['company_name']]];
        }
        // A request that names its period (its title carries a year) takes a document of that period: a year the fiscal year spans.
        if ($r['period_year'] !== null && preg_match('/\b(20\d\d)\b/', $request->title, $m)) {
            $from = $request->period_start?->year ?? (int) $m[1];
            $to = $request->period_end?->year ?? (int) $m[1];
            if ($r['period_year'] < $from || $r['period_year'] > $to) {
                $issues[] = ['code' => 'period_mismatch', 'params' => ['found' => $r['period_year'], 'asked' => $from === $to ? $from : "{$from}–{$to}"]];
            }
        }
        if ($r['expiry_date'] !== null && $r['expiry_date'] < now()->toDateString()) {
            $issues[] = ['code' => 'expired', 'params' => ['date' => $r['expiry_date']]];
        }
        if ($r['has_signature_or_stamp'] === false && preg_match(self::SIGNED_WORDS, $request->title)) {
            $issues[] = ['code' => 'unsigned'];
        }

        return ['status' => $issues === [] ? 'ok' : 'issues', 'issues' => $issues];
    }

    /** A working paper sent back, judged from its analysis: it is filled, answered, and has no difference left unexplained. */
    public static function judgePaper(array $a): array
    {
        $issues = [];
        if (($a['kind'] ?? null) === 'recalc') {
            $a['rows_used'] === 0 && $issues[] = ['code' => 'paper_empty'];
            foreach (array_slice($a['differing'], 0, 5) as $d) {
                $issues[] = ['code' => 'recalc_difference', 'params' => ['name' => $d['name'], 'computed' => number_format($d['computed'], 2), 'book' => number_format($d['book'], 2)]];
            }
            $a['missing'] !== [] && $issues[] = ['code' => 'recalc_missing', 'params' => ['items' => implode('، ', array_slice($a['missing'], 0, 5))]];
            ($a['tb_difference'] ?? null) !== null && abs($a['tb_difference']) > (float) config('recalculations.tolerance', 1.0)
                && $issues[] = ['code' => 'recalc_tb', 'params' => ['difference' => number_format($a['tb_difference'], 2)]];
        } else {
            $a['entity_missing'] !== [] && $issues[] = ['code' => 'paper_entity', 'params' => ['fields' => implode('، ', $a['entity_missing'])]];
            $a['rows_used'] === 0 && $a['rows_total'] > 0 && $issues[] = ['code' => 'paper_empty'];
            $a['rows_incomplete'] !== [] && $issues[] = ['code' => 'paper_incomplete', 'params' => ['rows' => implode('، ', array_slice($a['rows_incomplete'], 0, 10))]];
            $a['questions_open'] !== [] && $issues[] = ['code' => 'paper_questions', 'params' => ['count' => count($a['questions_open'])]];
            foreach ($a['differences'] as $d) {
                $issues[] = ['code' => 'paper_difference', 'params' => ['label' => $d['label'], 'total' => number_format($d['total'], 2), 'rows' => implode('، ', array_slice($d['rows'], 0, 10))]];
            }
        }

        return ['status' => $issues === [] ? 'ok' : 'issues', 'issues' => $issues];
    }

    /** The document names another company: its name matches neither of the client's and its registration number isn't theirs. */
    public static function otherCompany(array $r, Company $company): bool
    {
        $name = ImportNormalizer::text($r['company_name']);
        if ($name === '') {
            return false;
        }
        $digits = fn (?string $s) => preg_replace('/\D+/', '', (string) $s);
        $number = $digits($r['registration_number']);
        foreach ([$company->cr_number, $company->unified_number] as $own) {
            if ($number !== '' && $digits($own) !== '' && $number === $digits($own)) {
                return false;
            }
        }
        foreach ([$company->name_ar, $company->name_en] as $own) {
            $own = ImportNormalizer::text($own);
            if ($own === '') {
                continue;
            }
            if (str_contains($name, $own) || str_contains($own, $name)) {
                return false;
            }
            $a = array_filter(explode(' ', $own), fn ($w) => mb_strlen($w) > 2);
            $b = explode(' ', $name);
            if ($a !== [] && count(array_intersect($a, $b)) / count($a) >= 0.6) {
                return false;   // the same name written a little differently
            }
        }

        return true;
    }

    /** What the verdict does (by mode): accept, return with what to fix, move to the request it answers. */
    private function act(ClientFile $file, DocumentRequest $request, array $verdict): void
    {
        $auto = config('portal.ai_review') === 'auto';
        if ($verdict['status'] === 'other_request' && $auto && ($target = DocumentRequest::find($verdict['target'])) !== null) {
            $file->update(['document_request_id' => $target->id]);
            // Judged again against the request it answers (the same copy of the document): it may have its own problems there.
            $read = $verdict['read'];
            $again = self::judge($read, $target, $target->company, [$target->id]);
            $file->update(['review' => $again + ['read' => $read, 'moved_from' => $request->id, 'at' => now()->toIso8601String()]
                + (isset($verdict['tb']) ? ['tb' => $verdict['tb']] : [])]);
            $this->say($request, __('portal.ai.moved_from', ['file' => $file->original_name, 'to' => $target->title]));
            $this->say($target, __('portal.ai.moved_to', ['file' => $file->original_name]));
            $target->update(['status' => DocumentRequest::UPLOADED]);
            if (! $request->files()->where('from_client', true)->exists()) {
                $request->update(['status' => $request->paper === ArchiveSorter::UNSORTED ? DocumentRequest::NOT_APPLICABLE : DocumentRequest::PENDING]);
            }
            $this->settle($target->fresh());

            return;
        }
        $this->say($request, $this->describe($file, $verdict));
        // What could not be placed waits for the accountant: it is never accepted nor sent back to the client.
        $auto && $request->paper !== ArchiveSorter::UNSORTED && $this->settle($request);
    }

    /** The request's status from its files' verdicts: any problem sends it back, all good accepts it; otherwise it waits. */
    private function settle(DocumentRequest $request): void
    {
        $files = $request->files()->where('from_client', true)->get();
        $problems = [];
        foreach ($files as $f) {
            $status = $f->review['status'] ?? null;
            if ($status !== null && ! in_array($status, ['ok', 'unavailable'], true)) {
                foreach ($f->review['issues'] ?? [] as $i) {
                    $problems[] = "«{$f->original_name}»: ".self::issueText($i);
                }
                $f->review['status'] === 'other_request' && $problems[] = "«{$f->original_name}»: ".__('portal.ai.issue.mismatch', ['type' => '']);
            }
        }
        if ($problems !== []) {
            $note = implode("\n", array_unique($problems));
            $request->update(['status' => DocumentRequest::RETURNED, 'note' => $note]);
            $this->say($request, __('portal.ai.returned'));

            return;
        }
        if ($files->isNotEmpty() && $files->every(fn ($f) => ($f->review['status'] ?? null) === 'ok')) {
            $request->update(['status' => DocumentRequest::ACCEPTED, 'note' => null]);
            $this->say($request, __('portal.ai.accepted'));
        }
    }

    private function describe(ClientFile $file, array $v): string
    {
        $what = ($v['read']['document_type'] ?? '') !== '' ? __('portal.ai.is', ['type' => $v['read']['document_type']]) : '';
        $summary = $v['read']['summary'] ?? '';
        $issues = array_map(fn ($i) => self::issueText($i), $v['issues'] ?? []);

        return trim(__('portal.ai.reviewed', ['file' => $file->original_name]).' '.implode(' ', array_filter([$what, $summary, $issues === [] ? ($v['status'] === 'ok' ? __('portal.ai.no_issues') : '') : implode(' ', $issues)])));
    }

    public static function issueText(array $issue): string
    {
        return __("portal.ai.issue.{$issue['code']}", $issue['params'] ?? []);
    }

    private function say(?DocumentRequest $request, string $body): void
    {
        if ($request === null) {
            return;
        }
        ClientMessage::create(['office_id' => $request->office_id, 'company_id' => $request->company_id, 'document_request_id' => $request->id, 'user_id' => null,
            'from_client' => false, 'kind' => ClientMessage::AI, 'body' => $body]);
    }

    /** The AI's answer, cleaned: ids only of the client's requests, dates as Y-m-d, numbers as numbers. */
    public static function normalise(array $in, array $catalogueIds): array
    {
        // Day first where a date is printed with slashes or dots (31/12/2025, as Saudi documents print it), never month first.
        $date = function ($v) {
            if (! is_string($v)) {
                return null;
            }
            $v = trim(\App\Services\AmountParser::asciiDigits($v));
            if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $v, $m)) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
            }

            return ($t = strtotime($v)) !== false ? date('Y-m-d', $t) : null;
        };
        $text = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'readable' => (bool) ($in['readable'] ?? false) && ! (bool) ($in['pages_unclear'] ?? false),
            'document_type' => (string) ($text($in['document_type'] ?? null) ?? ''),
            'matching_request_ids' => array_values(array_intersect(array_map('intval', (array) ($in['matching_request_ids'] ?? [])), $catalogueIds)),
            'company_name' => $text($in['company_name'] ?? null),
            'registration_number' => $text($in['registration_number'] ?? null),
            'period_year' => isset($in['period_year']) && is_numeric($in['period_year']) && $in['period_year'] > 1990 ? (int) $in['period_year'] : null,
            'document_date' => $date($in['document_date'] ?? null),
            'expiry_date' => $date($in['expiry_date'] ?? null),
            'issuer' => $text($in['issuer'] ?? null),
            'has_signature_or_stamp' => isset($in['has_signature_or_stamp']) && is_bool($in['has_signature_or_stamp']) ? $in['has_signature_or_stamp'] : null,
            'amounts' => array_values(array_filter(array_map(fn ($a) => isset($a['label'], $a['value']) && is_numeric($a['value']) ? ['label' => (string) $a['label'], 'value' => (float) $a['value']] : null, (array) ($in['amounts'] ?? [])))),
            'summary' => (string) ($text($in['summary'] ?? null) ?? ''),
            'balances' => self::balances((array) ($in['balances'] ?? []), $date, $text),
        ];
    }

    /** The balances the document shows, cleaned: a known kind, a number, a date as Y-m-d. */
    private static function balances(array $in, \Closure $date, \Closure $text): array
    {
        $kinds = array_keys((array) config('tb_matching.documents'));
        $out = [];
        foreach (array_slice($in, 0, 20) as $b) {
            if (! is_array($b) || ! isset($b['amount']) || ! is_numeric($b['amount'])) {
                continue;
            }
            $out[] = ['kind' => in_array($b['kind'] ?? null, $kinds, true) ? $b['kind'] : 'other', 'label' => (string) ($text($b['label'] ?? null) ?? ''),
                'reference' => $text($b['reference'] ?? null), 'counterparty' => $text($b['counterparty'] ?? null), 'as_of' => $date($b['as_of'] ?? null),
                'amount' => round((float) $b['amount'], 2), 'side' => in_array($b['side'] ?? null, ['debit', 'credit'], true) ? $b['side'] : null];
        }

        return $out;
    }

    public function spreadsheetText(string $path, int $maxRows = 60): string
    {
        try {
            $book = IOFactory::load($path);
        } catch (\Throwable) {
            return '';
        }
        $out = '';
        foreach (array_slice($book->getAllSheets(), 0, 4) as $sheet) {
            $out .= "== {$sheet->getTitle()}\n";
            foreach ($sheet->getRowIterator(1, min($maxRows, $sheet->getHighestRow())) as $row) {
                $cells = [];
                foreach ($row->getCellIterator() as $cell) {
                    try {
                        $v = $cell->getCalculatedValue();
                    } catch (\Throwable) {
                        $v = $cell->getValue();
                    }
                    if ($v !== null && $v !== '') {
                        $cells[] = mb_substr((string) $v, 0, 60);
                    }
                }
                $cells && $out .= implode(' | ', $cells)."\n";
            }
            if (mb_strlen($out) > (int) config('portal.review_text_chars')) {
                break;
            }
        }

        return mb_substr($out, 0, (int) config('portal.review_text_chars'));
    }

    public function docxText(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace('</w:p>', "\n", $xml)))), 0, (int) config('portal.review_text_chars'));
    }

    private const TOOL = [
        'name' => 'submit_review', 'description' => 'What the uploaded document is and what it shows.', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['readable', 'pages_unclear', 'document_type', 'matching_request_ids', 'company_name', 'registration_number', 'period_year', 'document_date', 'expiry_date', 'issuer', 'has_signature_or_stamp', 'amounts', 'balances', 'summary'],
            'properties' => [
                'readable' => ['type' => 'boolean'],
                'pages_unclear' => ['type' => 'boolean'],
                'document_type' => ['type' => 'string'],
                'matching_request_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'company_name' => ['type' => ['string', 'null']],
                'registration_number' => ['type' => ['string', 'null']],
                'period_year' => ['type' => ['integer', 'null']],
                'document_date' => ['type' => ['string', 'null']],
                'expiry_date' => ['type' => ['string', 'null']],
                'issuer' => ['type' => ['string', 'null']],
                'has_signature_or_stamp' => ['type' => ['boolean', 'null']],
                'amounts' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'value'],
                    'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'number']]]],
                'balances' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['kind', 'label', 'reference', 'counterparty', 'as_of', 'amount', 'side'],
                    'properties' => ['kind' => ['type' => 'string', 'enum' => ['bank', 'cash', 'customer', 'supplier', 'loan', 'related_party', 'zakat', 'vat', 'gosi', 'inventory', 'other']],
                        'label' => ['type' => 'string'], 'reference' => ['type' => ['string', 'null']], 'counterparty' => ['type' => ['string', 'null']],
                        'as_of' => ['type' => ['string', 'null']], 'amount' => ['type' => 'number'], 'side' => ['type' => ['string', 'null'], 'enum' => ['debit', 'credit', null]]]]],
                'summary' => ['type' => 'string'],
            ]],
    ];

    private const RULES = <<<'TXT'
        You review a document a client of an audit firm uploaded, usually Arabic, Saudi, often a scan or a photograph. Recognise it by the
        words and headings printed on it (a commercial registration says «سجل تجاري», a bank statement «كشف حساب»...), never by guessing.
        - readable: false when the text can't be read; pages_unclear: true when part of it can't be.
        - document_type: the document's own title as printed, in Arabic.
        - matching_request_ids: the ids of the client's requests (listed below) this document answers; empty if none. Several only
          when it really answers several.
        - company_name, registration_number (commercial registration, unified or tax number as printed), period_year (the financial
          year the document is about, or the year it is dated), document_date and expiry_date (as printed, YYYY-MM-DD), issuer: copy,
          null when not printed.
        - has_signature_or_stamp: true when a signature or stamp is visible, false when a document that should carry one has none, null
          when it doesn't matter.
        - amounts: up to six key totals printed (a closing balance, a total), label as printed.
        - balances: every balance of an account the client keeps books for that the document states (a bank statement's closing balance,
          a customer's or a supplier's balance confirmation, a loan statement's outstanding amount, a zakat or VAT assessment, an inventory
          count's total): kind; label as printed; reference (the account number or IBAN as printed, else null); counterparty (the bank,
          customer, supplier or lender's name); as_of (the date of the balance, YYYY-MM-DD); amount positive the usual way (money in the
          bank, owed TO the client by a customer, owed BY the client to a supplier or lender), negative the other way (an overdraft, a
          customer in credit); side only for a related party, VAT or social insurance (debit: owed to the client, credit: owed by it),
          else null. Several accounts: one each. None: empty. Copy the printed figure, never a total you computed.
        - summary: one short Arabic sentence: what it is and what it covers.
        Copy, never correct, never decide whether it is acceptable.
        TXT;
}
