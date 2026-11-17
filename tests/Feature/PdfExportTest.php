<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Services\Exports\StatementsPdf;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ClassifiedRows;
use Tests\Support\DocxFiles;
use Tests\TestCase;

/**
 * PR 16: a version's statements and notes as one PDF, from its stored payload (Q16): what the wizard shows (the same
 * statement lines and figures, the same notes, paragraphs and tables), right to left, with an open Arabic font embedded;
 * a draft marked «مسودة» and listing what's missing. Made-up names and amounts.
 */
class PdfExportTest extends TestCase
{
    use ClassifiedRows, DocxFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** cash 150 = capital 100 + suppliers 30 + profit 20 (sales 200 − rent 180), closing only. */
    private function rows(array $extra = []): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 150.4]),
            $this->account(3, 'رأس المال', ['closing' => -100]),
            $this->account(4, 'موردون', ['closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['closing' => -200.4]),
            $this->account(6, 'إيجارات', ['closing' => 180]),
            ...$extra,
        ];
    }

    private function report(array $rows, array $groups = ['closing']): string
    {
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة الطباعة', 'city' => 'جدة', 'cr_number' => '1010'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'cr_date_hijri' => '1440/01/01', 'unified_number' => '7000000000', 'activity' => 'التجارة', 'head_office' => 'جدة'])->json('id');
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'company_id' => $company, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated()->json('id');
    }

    /** First year, every question answered: a final. */
    private function finalReport(): string
    {
        $id = $this->report($this->rows());
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk();
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => [
            'zakat.zero_reason' => 'الشركة في أول سنة ولا زكاة عليها.', 'oci.has_items' => false, 'approval.body' => 'الشركاء',
            'approval.date' => '2026-02-15', 'partners' => [['name' => 'الشريك الأول', 'shares' => 10, 'share_value' => 10]],
            'first_application.this_period' => false, 'policies.revenue' => ['goods'],
        ]])->assertOk();
        $state = $this->getJson(self::URL."/{$id}")->json();
        $pending = array_filter($state['suggestions'], fn ($s) => $s['status'] === 'pending');
        if ($pending) {
            $this->postJson(self::URL."/{$id}/suggestions", ['decisions' => array_values(array_map(fn ($s) => ['key' => $s['key'], 'action' => 'confirm'], $pending))])->assertOk();
        }
        $warnings = array_filter($this->getJson(self::URL."/{$id}")->json('checks'), fn ($c) => $c['severity'] === 'warning');
        $this->putJson(self::URL."/{$id}/acknowledgements", ['acknowledgements' => array_values(array_map(fn ($c) => ['key' => $c['key']], $warnings))])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'final'])->assertCreated();

        return $id;
    }

    /** A draft with two years (opening columns) and a building: the PPE note shows, the income statement's comparative is missing. */
    private function draftReport(): string
    {
        $id = $this->report([
            ...$this->rows(),
            $this->account(7, 'مباني وإنشاءات', ['opening' => 500, 'closing' => 500]),
            $this->account(8, 'رأس المال', ['opening' => -500, 'closing' => -500]),
        ], ['opening', 'closing']);
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();

        return $id;
    }

    private function download(string $id, int $version = 1): string
    {
        $response = $this->get(self::URL."/{$id}/versions/{$version}/export.pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        return $response->getContent();
    }

    /** The print view of a version, before mPDF lays it out. */
    private function html(string $id, int $version = 1): DOMXPath
    {
        $v = $this->getJson(self::URL."/{$id}/versions/{$version}")->assertOk()->json();
        $html = app(StatementsPdf::class)->html($v['payload'], $v['created_at']);
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8">'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function cellText(\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /**
     * The statement rows to print (Q13, the rule of the xlsx and the wizard): a line or total zero in both years is hidden,
     * and a heading with no line showing under it.
     */
    private function printRows(array $payload, string $statement): array
    {
        $cmp = $payload['comparative']['source'] !== 'first_year';
        $zero = fn ($f, $shown) => is_array($f) && $f['amount'] !== null && round($shown ?? $f['amount']) == 0;
        $money = fn ($f, $shown) => $this->docxMoney(($f['amount'] ?? null) === null ? null : ($shown ?? $f['amount']));
        $rows = $open = $scopes = [];
        foreach ($payload['statements'][$statement] as $r) {
            if ($r['type'] === 'heading') {
                $open[] = count($rows);
                $scopes[count($rows)] = [];
                $rows[] = [[$r['label_ar']], false];
                continue;
            }
            // Zero in both years (a missing figure isn't zero), whatever the accounts under it.
            $hidden = $zero($r['current'] ?? null, $r['shown']['current'] ?? null) && (! $cmp || $zero($r['comparative'] ?? null, $r['shown']['comparative'] ?? null));
            if ($r['type'] === 'line') {
                foreach ($open as $h) {
                    $scopes[$h][] = count($rows);
                }
            } elseif ($r['type'] === 'total' && $open) {
                array_pop($open);
            }
            $label = $r['label_ar'].(($r['current']['status'] ?? null) === 'pending_confirmation' ? ' (معلّق)' : '')
                .implode('', array_map(fn ($c) => ' ('.__("statements.checks.{$c}", [], 'ar').')', $r['check_codes'] ?? []));
            $rows[] = [array_values(array_filter([$label, $statement !== 'cash_flows' ? (string) ($r['note'] ?? '') : null,
                $money($r['current'] ?? null, $r['shown']['current'] ?? null), $cmp ? $money($r['comparative'] ?? null, $r['shown']['comparative'] ?? null) : null], fn ($x) => $x !== null)), $hidden];
        }
        foreach ($scopes as $h => $lines) {
            $rows[$h][1] = array_filter($lines, fn ($i) => ! $rows[$i][1]) === [];
        }

        return array_values(array_map(fn ($r) => $r[0], array_filter($rows, fn ($r) => ! $r[1])));
    }

    private function printedLines(DOMXPath $xp, string $statement): array
    {
        $table = $xp->query("//h2[@data-statement='{$statement}']/following-sibling::table[1]")->item(0);
        $this->assertNotNull($table, $statement);

        return array_map(fn ($tr) => array_map(fn ($td) => $this->cellText($td), iterator_to_array($xp->query('./td', $tr))), iterator_to_array($xp->query('./tr', $table)));
    }

    /** A wizard table for print: the columns empty in every row go. */
    private function withoutEmptyColumns(array $rows): array
    {
        $width = max(array_map('count', $rows ?: [[]]));
        $keep = [];
        for ($i = 0; $i < $width; $i++) {
            $column = array_filter(array_map(fn ($r) => count($r) === $width ? $r[$i] : null, $rows), fn ($c) => $c !== null);
            $keep[$i] = $column === [] || array_filter($column, fn ($c) => trim($c) !== '') !== [];
        }

        return array_map(fn ($r) => count($r) === $width ? array_values(array_filter($r, fn ($i) => $keep[$i], ARRAY_FILTER_USE_KEY)) : $r, $rows);
    }

    public function test_the_statements_and_notes_are_what_the_version_shows(): void
    {
        foreach ([$this->finalReport(), $this->draftReport()] as $id) {
            $xp = $this->html($id);
            $shown = $this->versionShows($id);
            $payload = $shown['payload'];

            // Every statement, line for line, figure for figure.
            foreach (['financial_position', 'comprehensive_income', 'cash_flows'] as $statement) {
                $this->assertSame($this->printRows($payload, $statement), $this->printedLines($xp, $statement), $statement);
            }
            // The changes in equity: both years' tables without an empty line or an empty column (ShownRows::equity, note 3).
            $soce = $payload['statements']['changes_in_equity'];
            $show = \App\Services\Exports\ShownRows::equity($soce['columns'], ['current' => $soce['current'], 'comparative' => $soce['comparative'] ?? []], ['opening', 'net_profit', 'total_comprehensive_income', 'closing']);
            $columns = array_values(array_filter($soce['columns'], fn ($c) => in_array($c['key'], $show['columns'], true)));
            $tables = $xp->query("//h2[@data-statement='changes_in_equity']/following-sibling::table[contains(@class, 'equity')]");
            $this->assertCount($soce['comparative'] ? 2 : 1, $tables);
            foreach (array_values(array_filter(['current' => $soce['current'], 'comparative' => $soce['comparative']])) as $i => $rows) {
                $scope = $i === 0 ? 'current' : 'comparative';
                $rows = array_values(array_filter($rows, fn ($r) => in_array($r['key'], $show['rows'][$scope] ?? [], true)));
                $expected = array_map(fn ($r) => [$r['label_ar'], ...array_map(fn ($c) => $this->docxMoney($r['cells'][$c['key']]['amount'] ?? null), $columns)], $rows);
                $printed = array_map(fn ($tr) => array_map(fn ($td) => $this->cellText($td), iterator_to_array($xp->query('./td', $tr))), iterator_to_array($xp->query('./tr', $tables->item($i))));
                $this->assertSame($expected, $printed);
            }

            // The notes: numbered as the version, every paragraph and table.
            $titles = array_map(fn ($h) => $this->cellText($h), iterator_to_array($xp->query('//h3[@data-note]')));
            $this->assertSame(array_map(fn ($t) => "{$t[0]}- {$t[1]}", $shown['titles']), $titles);
            $paragraphs = array_map(fn ($p) => $this->cellText($p), iterator_to_array($xp->query('//h3[@data-note][1]/following-sibling::p')));
            $this->assertSame(array_map(fn ($t) => trim(preg_replace('/\s+/u', ' ', $t)), $shown['printed']), $paragraphs);
            $noteTables = iterator_to_array($xp->query('//h3[@data-note][1]/following-sibling::table'));
            $this->assertCount(count($shown['tables']), $noteTables);
            foreach ($noteTables as $i => $table) {
                $printed = array_map(fn ($tr) => array_map(fn ($td) => $this->cellText($td), iterator_to_array($xp->query('./td', $tr))), iterator_to_array($xp->query('./tr', $table)));
                $this->assertSame($this->withoutEmptyColumns($shown['tables'][$i]), $printed, "note table {$i}");
            }
        }
    }

    public function test_zero_totals_are_hidden_as_in_the_excel_file_and_so_are_empty_headings(): void
    {
        $id = $this->draftReport();
        $xp = $this->html($id);
        $payload = $this->getJson(self::URL."/{$id}/versions/1")->json('payload');

        // No non-current liability: its total (zero in both years) and its heading are hidden.
        $total = collect($payload['statements']['financial_position'])->firstWhere('key', 'sofp.total_non_current_liabilities');
        $this->assertEquals([0, 0], [$total['current']['amount'], $total['comparative']['amount']]);
        $this->assertSame(0, $xp->query("//tr[@data-line='sofp.total_non_current_liabilities']")->length);
        $printed = array_column($this->printedLines($xp, 'financial_position'), 0);
        $this->assertNotContains('الالتزامات غير المتداولة', $printed);
        $this->assertContains('الالتزامات المتداولة', $printed);

        // Every total of the three statements, in a draft and a final: shown in the PDF exactly when it shows in the xlsx.
        foreach ([$id, $this->finalReport()] as $report) {
            $xp = $this->html($report);
            $payload = $this->getJson(self::URL."/{$report}/versions/1")->json('payload');
            $path = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($path, $this->get(self::URL."/{$report}/versions/1/export.xlsx")->assertOk()->getContent());
            $book = IOFactory::load($path);
            unlink($path);
            foreach (config('financial_statements.templates.sa-ifrs-smes-3.xlsx.statements') as $statement => $layout) {
                $sheet = $book->getSheetByName($layout['sheet']);
                $types = array_column($payload['statements'][$statement], 'type', 'key');
                $excel = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $n = $row->getRowIndex();
                    $key = $sheet->getCell($layout['key'].$n)->getValue();
                    if (($types[$key] ?? null) === 'total' && $sheet->getRowDimension($n)->getVisible()) {
                        $excel[] = $key;
                    }
                }
                $pdf = array_map(fn ($tr) => $tr->getAttribute('data-line'), iterator_to_array($xp->query("//h2[@data-statement='{$statement}']/following-sibling::table[1]/tr[contains(@class, 'total')]")));
                $this->assertSame($excel, $pdf, $statement);
            }
            $book->disconnectWorksheets();
        }
    }

    public function test_totals_are_the_sum_of_the_lines_shown_and_the_pdf_matches_the_excel_file(): void
    {
        // Cash and customers 100.40 each: 100 + 100 under a total of 200.80; the riyal goes on the customers.
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100.4]),
            $this->account(3, 'عملاء', ['closing' => 100.4]),
            $this->account(4, 'رأس المال', ['closing' => -150.8]),
            $this->account(5, 'موردون', ['closing' => -50]),
        ]);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $xp = $this->html($id);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $this->get(self::URL."/{$id}/versions/1/export.xlsx")->assertOk()->getContent());
        $book = IOFactory::load($path);
        unlink($path);
        $layout = config('financial_statements.templates.sa-ifrs-smes-3.xlsx.statements.financial_position');
        $sheet = $book->getSheetByName($layout['sheet']);
        $excel = [];
        foreach ($sheet->getRowIterator() as $row) {
            $key = $sheet->getCell($layout['key'].$row->getRowIndex())->getValue();
            if (is_string($key) && $sheet->getRowDimension($row->getRowIndex())->getVisible()) {
                $excel[$key] = $sheet->getCell($layout['current'].$row->getRowIndex())->getCalculatedValue();
            }
        }
        $book->disconnectWorksheets();
        $pdf = [];
        foreach ($xp->query("//h2[@data-statement='financial_position']/following-sibling::table[1]/tr[@data-line]") as $tr) {
            $cells = iterator_to_array($xp->query('./td', $tr));
            $pdf[$tr->getAttribute('data-line')] = $this->cellText(end($cells));
        }

        $this->assertSame(['101', '100', '201', '201', '201'], [$pdf['sofp.trade_receivables'], $pdf['sofp.cash'], $pdf['sofp.total_current_assets'],
            $pdf['sofp.total_assets'], $pdf['sofp.total_equity_and_liabilities']]);
        foreach ($pdf as $key => $text) {
            $this->assertSame($text, $this->docxMoney((float) ($excel[$key] ?? 0)), $key);
        }
    }

    public function test_a_first_year_has_one_year_and_whole_riyals(): void
    {
        $xp = $this->html($this->finalReport());

        $headers = array_map(fn ($th) => $this->cellText($th), iterator_to_array($xp->query("//h2[@data-statement='financial_position']/following-sibling::table[1]//th")));
        $this->assertSame(['', 'إيضاح', '31 ديسمبر 2025م'], $headers);
        $this->assertSame(['النقد ومعادلات النقد', '4', '150'], collect($this->printedLines($xp, 'financial_position'))->first(fn ($r) => $r[0] === 'النقد ومعادلات النقد'));
        $this->assertDoesNotMatchRegularExpression('/[٠-٩۰-۹]/u', $xp->document->textContent);
        $this->assertStringNotContainsString('مسودة', $xp->document->textContent);
    }

    public function test_the_file_embeds_its_arabic_font_and_turns_wide_pages(): void
    {
        $pdf = $this->download($this->draftReport());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/\/BaseFont \/[A-Z]{6}\+XBRiyaz/', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf);
        // Portrait pages, and a landscape one for the changes in equity.
        $this->assertGreaterThan(5, substr_count($pdf, '/MediaBox [0 0 595.280 841.890]'));
        $this->assertGreaterThanOrEqual(1, substr_count($pdf, '/MediaBox [0 0 841.890 595.280]'));
    }

    public function test_the_notes_run_on_in_portrait_with_their_amounts_aligned(): void
    {
        $id = $this->draftReport();
        $xp = $this->html($id);

        // One break into the notes, portrait; none between them, so no page is left part empty (the accountant's review).
        $this->assertSame(['P'], array_map(fn ($b) => $b->getAttribute('orientation'),
            iterator_to_array($xp->query("//h3[@data-note][1]/preceding::pagebreak[1] | //h3[@data-note][1]/following::pagebreak"))));
        $tables = iterator_to_array($xp->query('//h3[@data-note]/following-sibling::table'));
        $this->assertNotEmpty($tables);
        foreach ($tables as $table) {
            // Every amount column one width; the heading row and the totals bold with a rule, as in the statements.
            $widths = array_unique(array_map(fn ($td) => $td->getAttribute('style'), iterator_to_array($xp->query(".//td[contains(@class, 'num')]", $table))));
            if ($widths === []) {
                continue; // a table of words only
            }
            $this->assertCount(1, $widths);
            $this->assertMatchesRegularExpression('/^width: \d+mm$/', reset($widths));
            $last = $xp->query('./tr[last()]', $table)->item(0);
            if ($xp->query("./td[contains(@class, 'num') and not(contains(@class, 'num-head'))]", $last)->length > 0
                && trim($xp->query('./td[1]', $last)->item(0)->textContent) === '') {
                $this->assertSame('total', $last->getAttribute('class'));
            }
        }
        // The policies' headings numbered under their note, «5-1» … (the accountant's review).
        $policies = $xp->query("//h3[@data-note][contains(., 'السياسات المحاسبية الهامة')]")->item(0);
        $first = $xp->query("following-sibling::p[contains(@class, 'heading')][starts-with(., '{$policies->getAttribute('data-note')}-1 ')]", $policies)->item(0);
        $this->assertSame("{$policies->getAttribute('data-note')}-1 تصنيف ما هو متداول مقابل ما هو غير متداول", trim($first?->textContent ?? ''));
        // A year heading over amounts is words: not turned left to right («2025م», not «م2025»).
        foreach ($xp->query("//td[contains(@class, 'num-head')]") as $td) {
            $this->assertStringNotContainsString('num ', $td->getAttribute('class').' ');
        }
    }

    public function test_a_draft_says_so_and_lists_what_is_missing(): void
    {
        $id = $this->draftReport();
        $xp = $this->html($id);
        $text = $xp->document->textContent;

        $this->assertStringContainsString('مسودة — للمراجعة فقط، غير معتمدة', $text);
        $this->assertStringContainsString('مسودة: هذه القوائم غير نهائية بعد', $text);
        $this->assertStringContainsString('أرقام ناقصة في الإيضاحات (كُتب مكانها «[ناقص]»)', $text);
        $this->assertSame('مسودة', $this->cellText($xp->query("//htmlpageheader//div[@class='draft']")->item(0)));
        // A missing figure is «[ناقص]», never 0: the income statement's comparative.
        $revenue = $xp->query("//tr[@data-line='is.revenue']/td[last()]")->item(0);
        $this->assertSame(['200', '[ناقص]'], [$this->cellText($xp->query("//tr[@data-line='is.revenue']/td[last()-1]")->item(0)), $this->cellText($revenue)]);
        $this->assertStringContainsString('missing', $revenue->getAttribute('class'));
        // The watermark.
        $this->assertStringContainsString('/GS', $this->download($id));
    }

    public function test_the_same_version_gives_the_same_file(): void
    {
        $id = $this->finalReport();
        $this->assertSame(md5($this->download($id)), md5($this->download($id)));

        $disposition = $this->get(self::URL."/{$id}/versions/1/export.pdf")->headers->get('Content-Disposition');
        $this->assertStringContainsString('filename="financial-statements-2025-12-31-v1-final.pdf"', $disposition);
        $this->assertStringContainsString(rawurlencode('القوائم المالية والإيضاحات المتممة لها - شركة الطباعة - 2025-12-31 - نسخة 1 (نهائي).pdf'), $disposition);
    }

    public function test_unknown_report_or_version(): void
    {
        $this->get(self::URL.'/'.Str::uuid().'/versions/1/export.pdf')->assertNotFound()->assertJson(['message' => 'القوائم المالية هذه غير موجودة.']);
        $id = $this->report($this->rows());
        $this->get(self::URL."/{$id}/versions/3/export.pdf")->assertNotFound()->assertJson(['message' => 'هذا الإصدار غير موجود.']);
    }
}
