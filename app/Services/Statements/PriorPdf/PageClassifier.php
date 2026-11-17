<?php

namespace App\Services\Statements\PriorPdf;

use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;

/**
 * Step 1 of reading last year's statements from a PDF (brief §8.1): the whole file goes once and the AI says what each
 * page is, its printed page number, how far it must be turned to read upright, and the notes that start on it. The
 * accountant sees the table and corrects it before anything is read. Only the AI's words are asked for; which pages
 * are read, and how, is PHP's (PriorPdfReader).
 */
final class PageClassifier
{
    /** What a page can be. The auditor's report is named so it is never read (R9). */
    public const KINDS = ['cover', 'contents', 'auditor_report', 'financial_position', 'comprehensive_income', 'changes_in_equity',
        'cash_flows', 'notes', 'other'];

    /** The statements, in their usual order. */
    public const STATEMENTS = ['financial_position', 'comprehensive_income', 'changes_in_equity', 'cash_flows'];

    /** The kinds whose pages are read (brief §8.2): the statements and the notes. */
    public const READ = [...self::STATEMENTS, 'notes'];

    public const ROTATIONS = [0, 90, 180, 270];

    public function __construct(private Llm $llm) {}

    /**
     * @return array{pages: list<array{page: int, kind: string, printed_page: ?string, rotation: int, notes: list<array{number: ?string, title: string}>}>, usage: ?array}
     *         one entry per page of the file, in order; a page the AI left out is `other`
     */
    public function classify(string $path, string $pdf, int $pageCount): array
    {
        $answer = $this->llm->ask($path, 'classify', 0, 0, fn () => new ToolCall([['text' => self::RULES]], self::TOOL,
            "The file has {$pageCount} pages. Describe every page, from 1 to {$pageCount}.", 8000, false, $pdf));

        return ['pages' => self::pages($answer->input, $pageCount), 'usage' => $answer->usage];
    }

    /** The AI's answer as one entry per page; nothing outside the file, each page once (the first answer kept). */
    public static function pages(array $input, int $pageCount): array
    {
        $out = [];
        foreach ($input['pages'] ?? [] as $p) {
            $n = (int) ($p['page'] ?? 0);
            if ($n < 1 || $n > $pageCount || isset($out[$n])) {
                continue;
            }
            $out[$n] = [
                'page' => $n,
                'kind' => in_array($p['kind'] ?? null, self::KINDS, true) ? $p['kind'] : 'other',
                'printed_page' => self::text($p['printed_page'] ?? null),
                'rotation' => in_array((int) ($p['rotation'] ?? 0), self::ROTATIONS, true) ? (int) $p['rotation'] : 0,
                'notes' => array_values(array_filter(array_map(fn ($n) => ['number' => self::text($n['number'] ?? null), 'title' => (string) self::text($n['title'] ?? null)],
                    (array) ($p['notes'] ?? [])), fn ($n) => $n['title'] !== '')),
            ];
        }
        for ($n = 1; $n <= $pageCount; $n++) {
            $out[$n] ??= ['page' => $n, 'kind' => 'other', 'printed_page' => null, 'rotation' => 0, 'notes' => []];
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Each statement's pages from the page kinds: its first and last page (a statement continued on the next page keeps
     * its kind there).
     *
     * @return array<string, array{from: int, to: int}>
     */
    public static function ranges(array $pages): array
    {
        $out = [];
        foreach ($pages as $p) {
            if (in_array($p['kind'], self::STATEMENTS, true)) {
                $out[$p['kind']] = ['from' => $out[$p['kind']]['from'] ?? $p['page'], 'to' => $p['page']];
            }
        }

        return array_replace(array_intersect_key(array_flip(self::STATEMENTS), $out), $out);
    }

    /** Every page from statements' page ranges (a file uploaded before page kinds): the other pages are `other`. */
    public static function fromRanges(array $ranges, int $pageCount): array
    {
        $kinds = [];
        foreach ($ranges as $statement => $r) {
            for ($n = $r['from']; $n <= $r['to']; $n++) {
                $kinds[$n] ??= $statement;
            }
        }

        return array_map(fn ($n) => ['page' => $n, 'kind' => $kinds[$n] ?? 'other', 'printed_page' => null, 'rotation' => 0, 'notes' => []], range(1, $pageCount));
    }

    /** The page kinds with the statements' pages as given: each page of a range takes its statement, others lose theirs. */
    public static function withRanges(array $pages, array $ranges): array
    {
        $by = array_column(self::fromRanges($ranges, count($pages)), 'kind', 'page');

        return array_map(fn ($p) => ['kind' => $by[$p['page']] !== 'other' ? $by[$p['page']] : (in_array($p['kind'], self::STATEMENTS, true) ? 'other' : $p['kind'])] + $p, $pages);
    }

    /** The pages of a kind, as a range each (the auditor's report). */
    public static function skipped(array $pages): array
    {
        $at = array_column(array_filter($pages, fn ($p) => $p['kind'] === 'auditor_report'), 'page');

        return $at ? ['auditor_report' => ['from' => min($at), 'to' => max($at)]] : [];
    }

    private static function text(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }

    private const TOOL = [
        'name' => 'submit_pages', 'description' => 'What each page of the file is.', 'strict' => true,
        'schema' => ['type' => 'object', 'required' => ['pages'], 'additionalProperties' => false, 'properties' => [
            'pages' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['page', 'kind', 'printed_page', 'rotation', 'notes'], 'properties' => [
                    'page'         => ['type' => 'integer'],
                    'kind'         => ['type' => 'string', 'enum' => self::KINDS],
                    'printed_page' => ['type' => ['string', 'null']],
                    'rotation'     => ['type' => 'integer', 'enum' => self::ROTATIONS],
                    'notes'        => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['number', 'title'],
                        'properties' => ['number' => ['type' => ['string', 'null']], 'title' => ['type' => 'string']]]],
                ]]],
        ]],
    ];

    private const RULES = <<<'TXT'
        You are given a company's issued financial statements as a PDF, usually in Arabic, often scanned. Describe every
        page, in order, the first page of the file being 1 (not the number printed on it):
        - kind: cover; contents (the index); auditor_report (the independent auditor's report, تقرير المراجع المستقل,
          all its pages); financial_position (قائمة المركز المالي، الميزانية العمومية); comprehensive_income (قائمة
          الدخل، قائمة الأرباح أو الخسائر والدخل الشامل الآخر); changes_in_equity (قائمة التغيرات في حقوق الملكية /
          حقوق الشركاء); cash_flows (قائمة التدفقات النقدية); notes (الإيضاحات, text or tables); other.
          A statement continued on the next page keeps its kind on that page.
        - printed_page: the page number printed on the page, as printed; null when none.
        - rotation: how many degrees the page must be turned clockwise for its text to read upright: 0 when it already
          does, 90 or 270 for a landscape table printed sideways, 180 when upside down.
        - notes: on a notes page, every numbered note or sub-note that starts on it, in order: its number as printed
          (such as «5», «4.1», «4.1.2», «15/أ»; null when unnumbered) and its title as printed. Empty on other pages.
        Copy numbers and titles exactly as printed; never correct them.
        TXT;
}
