<?php

namespace App\Services\Exports;

use App\Support\Amounts;
use Carbon\CarbonImmutable;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The statements and their notes as one PDF (PR 16, plan §4.7, Q17): a version's payload drawn by the print view
 * (resources/views/exports/report-pdf.blade.php) and rendered by mPDF, so the same version always gives the same file
 * (Q16). It shows what the wizard shows for the version (public/js/financial-report/statements-view.js):
 *
 * - the four statements with the same rows as the wizard and the xlsx (ShownRows); the «إيضاح» column with the notes'
 *   numbers; the comparative column unless it is a first year;
 * - the changes in equity, both years' tables, every row and column (on a landscape page);
 * - the shown notes, numbered as the version numbers them, with the version's paragraphs (the user's wording kept) and
 *   tables exactly as the wizard lays them out, running on from page to page in portrait (a wide table in a smaller
 *   font), so no page is left part empty before a turned one;
 * - every table's amount columns one width, their headings over them and aligned with them; headings and totals bold
 *   with a rule, as in the statements;
 * - whole riyals, negatives in parentheses, zero «-», Western digits (Q27); «[ناقص]» for a missing figure, never 0.
 *
 * The font is XB Riyaz (SIL Open Font License, shipped with mPDF), embedded as a subset: the template's Sakkal
 * Majalla can't be embedded without a licence, and the other open Arabic fonts (Amiri, Noto Naskh Arabic) use font
 * tables mPDF can't read. A draft says «مسودة» on its cover, in every page's header and as a watermark, and its first page
 * lists what it still lacks (DraftNotes). Every word and figure is the version's; nothing is computed here but layout.
 */
final class StatementsPdf
{
    public const FONT = 'xbriyaz';

    /** A statement wider than this (cells in a row) goes on a landscape page; a note's table, in a smaller font. */
    private const PORTRAIT_COLUMNS = 6;

    /** A note's table wider than this (cells in a row) in the smallest font (the PPE note's categories). */
    private const WIDE_COLUMNS = 8;

    /** The page's text width (A4 less the margins), and the widest an amount column gets, in mm. */
    private const TEXT_WIDTH = 178;
    private const AMOUNT_WIDTH = 30;

    /**
     * @param array  $payload a version's payload (the report state, plus version and kind)
     * @param string $savedAt when the version was saved (ISO 8601): the file's dates
     * @return string the PDF file
     */
    public function build(array $payload, string $savedAt): string
    {
        $draft = ($payload['kind'] ?? 'draft') !== 'final';
        $html = $this->html($payload, $savedAt);

        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tempDir, 'default_font' => self::FONT, 'directionality' => 'rtl',
            'margin_top' => 34, 'margin_bottom' => 18, 'margin_left' => 16, 'margin_right' => 16, 'margin_header' => 8, 'margin_footer' => 8,
            'shrink_tables_to_fit' => 1.6,
        ]);
        $title = __('exports.pdf_filename', [], 'ar');
        $pdf->SetTitle($title);
        $pdf->SetAuthor('');
        $pdf->SetCreator('');
        if ($draft) {
            $pdf->SetWatermarkText(__('exports.draft.header', [], 'ar'), 0.08);
            $pdf->showWatermarkText = true;
            $pdf->watermark_font = self::FONT;
        }
        $pdf->WriteHTML($html);

        return self::fixed($pdf->Output('', Destination::STRING_RETURN), $savedAt, $payload);
    }

    /** The print view's HTML, before mPDF lays it out. */
    public function html(array $payload, string $savedAt): string
    {
        return view('exports.report-pdf', $this->model($payload, $savedAt, ($payload['kind'] ?? 'draft') !== 'final'))->render();
    }

    /** The view's data: labels, figures as shown, and what is hidden decided here so the view only draws. */
    private function model(array $p, string $savedAt, bool $draft): array
    {
        $v = $p['narrative']['values'] ?? [];
        $comparative = ($p['comparative']['source'] ?? 'none') !== 'first_year';
        $restated = (bool) ($p['comparative']['restated'] ?? false);
        $currentLabel = $v['cy_end_date'] ?? $p['period_end'];
        $comparativeLabel = ($v['py_end_date'] ?? null) ?: StateLabels::name('scopes.comparative');
        if ($restated) {
            $comparativeLabel .= __('exports.restated', [], 'ar');
        }

        $statements = [];
        foreach (['financial_position', 'comprehensive_income', 'cash_flows'] as $key) {
            $source = array_values($p['statements'][$key] ?? []);
            $shown = ShownRows::of($source, $comparative);
            $rows = [];
            foreach ($source as $i => $r) {
                if (! $shown[$i]) {
                    continue;
                }
                $rows[] = $r['type'] === 'heading' ? ['type' => 'heading', 'label' => $r['label_ar']]
                    : ['type' => $r['type'], 'key' => $r['key'] ?? '', 'label' => $r['label_ar'], 'note' => $r['note'] ?? '',
                        'current' => self::cell($r['current'] ?? null, $r['shown']['current'] ?? null),
                        'comparative' => self::cell($r['comparative'] ?? null, $r['shown']['comparative'] ?? null),
                        'checks' => array_map(fn ($c) => StateLabels::name("checks.{$c}") ?? '', $r['check_codes'] ?? [])];
            }
            $statements[$key] = ['kind' => 'statement', 'key' => $key, 'title' => StateLabels::name("statements.{$key}"), 'notes' => $key !== 'cash_flows', 'rows' => $rows, 'orientation' => 'P'];
        }

        $soce = $p['statements']['changes_in_equity'] ?? null;
        $equity = null;
        if ($soce) {
            // The same rows and columns as the Excel file (ShownRows::equity): no empty line, no empty column.
            $show = ShownRows::equity($soce['columns'], ['current' => $soce['current'], 'comparative' => $soce['comparative'] ?? []],
                (array) config("financial_statements.templates.{$p['template_version']}.xlsx.changes_in_equity.always", ['opening', 'net_profit', 'total_comprehensive_income', 'closing']));
            $columns = array_values(array_filter($soce['columns'], fn ($c) => in_array($c['key'], $show['columns'], true)));
            $table = fn (array $rows, string $scope) => array_map(fn ($r) => ['label' => $r['label_ar'],
                'total' => in_array($r['key'], ['opening', 'total_comprehensive_income', 'closing'], true),
                'cells' => array_map(fn ($c) => self::cell($r['cells'][$c['key']] ?? null, $r['shown'][$c['key']] ?? null), $columns)],
                array_values(array_filter($rows, fn ($r) => in_array($r['key'], $show['rows'][$scope] ?? [], true))));
            $equity = ['title' => StateLabels::name('statements.changes_in_equity'), 'columns' => array_column($columns, 'label_ar'),
                'years' => array_values(array_filter([
                    ['title' => __('exports.pdf.year', ['date' => $currentLabel], 'ar'), 'rows' => $table($soce['current'], 'current')],
                    $soce['comparative'] ?? null ? ['title' => __('exports.pdf.year', ['date' => $comparativeLabel], 'ar'), 'rows' => $table($soce['comparative'], 'comparative')] : null,
                ]))];
        }

        $notes = [];
        foreach ($p['notes'] ?? [] as $n) {
            if (! $n['visible']) {
                continue;
            }
            $blocks = [];
            foreach ($n['blocks'] as $b) {
                if ($b['type'] === 'table') {
                    $rows = self::noteTable($b['rows']);
                    $blocks[] = ['type' => 'table', 'rows' => $rows] + self::tableSize($rows);
                } else {
                    $blocks[] = ['type' => 'text', 'style' => $b['style'] ?? 'text', 'number' => $b['number'] ?? null, 'lines' => preg_split('/\R/u', $b['text_ar'])];
                }
            }
            $notes[] = ['number' => $n['number'], 'title' => $n['title_ar'], 'blocks' => $blocks];
        }
        // In their numbers' order: the accountant's order of the notes when one is set (NotesBuilder::order).
        usort($notes, fn ($a, $b) => (int) $a['number'] <=> (int) $b['number']);
        $pages = array_values(array_filter([$statements['financial_position'], $statements['comprehensive_income'],
            $equity ? $equity + ['kind' => 'equity', 'orientation' => count($equity['columns']) + 1 > self::PORTRAIT_COLUMNS ? 'L' : 'P'] : null,
            $statements['cash_flows']]));

        return [
            'draft' => $draft,
            'draftLines' => $draft ? DraftNotes::lines($p, $savedAt, 'title') : [],
            'company' => ($v['company_name'] ?? null) ?: ($p['company']['name_ar'] ?? __('exports.missing', [], 'ar')),
            'legalForm' => $v['legal_form'] ?? '',
            'periodEnd' => ($v['period_end_text'] ?? null) ?: $p['period_end'],
            'version' => __('exports.pdf.version', ['n' => $p['version'] ?? '', 'kind' => __('exports.version_kinds.'.($draft ? 'draft' : 'final'), [], 'ar'),
                'date' => CarbonImmutable::parse($savedAt)->format('Y-m-d')], 'ar'),
            'comparative' => $comparative,
            'currentLabel' => $currentLabel,
            'comparativeLabel' => $comparativeLabel,
            'pages' => $pages,
            'notes' => $notes,
            't' => fn (string $k, array $r = []) => __("exports.pdf.{$k}", $r, 'ar'),
        ];
    }

    /**
     * A note's table as the wizard lays it out, for print: the template's spacer columns (empty in every row) go, and a
     * column holding amounts is aligned as numbers in every row, its year heading too. A first row without amounts is
     * the heading; a row with amounts and no words, or whose words start «الإجمالي» / «إجمالي», is a total.
     *
     * @return list<array{cells: list<array{text: string, num: bool, amount: bool, missing?: bool, pending?: bool}>, head: bool, total: bool}>
     */
    private static function noteTable(array $rows): array
    {
        $cells = array_map(fn ($r) => array_map(fn ($c) => isset($c['amount']) ? self::cell($c['amount']) + ['amount' => true] : ['text' => $c['text_ar'], 'amount' => false], $r['cells']), $rows);
        $width = max(array_map('count', $cells ?: [[]]));
        $empty = $numeric = [];
        for ($i = 0; $i < $width; $i++) {
            $column = array_filter(array_map(fn ($r) => count($r) === $width ? $r[$i] : null, $cells));
            $empty[$i] = $column !== [] && array_filter($column, fn ($c) => trim($c['text']) !== '') === [];
            $numeric[$i] = array_filter($column, fn ($c) => $c['amount']) !== [];
        }

        $kept = array_map(function ($r) use ($width, $empty, $numeric) {
            if (count($r) !== $width) {
                return array_map(fn ($c) => $c + ['num' => $c['amount']], $r);
            }
            $out = [];
            foreach ($r as $i => $c) {
                if (! $empty[$i]) {
                    $out[] = $c + ['num' => $numeric[$i]];
                }
            }

            return $out;
        }, $cells);

        return array_map(function ($r, $i) {
            $amounts = array_filter($r, fn ($c) => $c['amount']) !== [];
            $words = trim(implode(' ', array_map(fn ($c) => $c['text'], array_filter($r, fn ($c) => ! $c['amount']))));

            return ['cells' => $r, 'head' => $i === 0 && ! $amounts && $words !== '',
                'total' => $amounts && ($words === '' || preg_match('/^ال?إجمالي/u', $words) === 1)];
        }, $kept, array_keys($kept));
    }

    /**
     * A note's table's print size: its font (smaller as it widens, so it stays on a portrait page) and its amount
     * columns' one width, what the page leaves after 30 mm for each column of words, at most AMOUNT_WIDTH.
     *
     * @return array{size: ?string, amountWidth: int}
     */
    private static function tableSize(array $rows): array
    {
        $widest = array_reduce($rows, fn ($w, $r) => count($r['cells']) > count($w) ? $r['cells'] : $w, []);
        $amounts = count(array_filter($widest, fn ($c) => $c['num']));
        $words = count($widest) - $amounts;

        return ['size' => match (true) { count($widest) > self::WIDE_COLUMNS => 'wide', count($widest) > self::PORTRAIT_COLUMNS => 'medium', default => null },
            'amountWidth' => $amounts === 0 ? self::AMOUNT_WIDTH : min(self::AMOUNT_WIDTH, intdiv(self::TEXT_WIDTH - 30 * $words, $amounts))];
    }

    /**
     * A figure as the wizard shows it: whole riyals, «(…)» below zero, «-» for zero, «[ناقص]» when missing; pending marked.
     * $shown: the whole riyals the state settled (WholeRiyals: totals foot), else the figure rounded.
     */
    private static function cell(?array $f, ?int $shown = null): array
    {
        if ($f === null) {
            return ['text' => '', 'pending' => false, 'missing' => false];
        }
        $amount = ($f['amount'] ?? null) === null ? null : ($shown ?? $f['amount']);
        $text = match (true) {
            $amount === null => __('exports.missing', [], 'ar'),
            default          => Amounts::whole($amount),
        };

        return ['text' => $text, 'pending' => ($f['status'] ?? null) === 'pending_confirmation', 'missing' => $amount === null];
    }

    /**
     * mPDF stamps the time of writing and an id from it; the version's own time and an id from the version take their
     * place (same lengths, so the file's offsets hold), so the same version always gives the same bytes (Q16).
     */
    private static function fixed(string $pdf, string $savedAt, array $p): string
    {
        $stamp = CarbonImmutable::parse($savedAt)->utc()->format('YmdHis');
        $pdf = preg_replace('/(\/(?:CreationDate|ModDate) \(D:)\d{14}/', '${1}'.$stamp, $pdf);
        $id = md5(($p['report_id'] ?? '').'|'.($p['version'] ?? '').'|'.$savedAt);

        return preg_replace('/\/ID \[<[0-9a-f]{32}> <[0-9a-f]{32}>\]/', "/ID [<{$id}> <{$id}>]", $pdf);
    }
}
