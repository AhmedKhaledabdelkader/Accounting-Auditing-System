<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\Support\DocxFiles;
use Tests\TestCase;

/**
 * PR 15: a version's notes as a docx, from its stored payload (Q16): the template filled, showing what the wizard shows
 * (the same notes, numbers, paragraphs, tables and figures), right to left, no placeholder or review comment left; a
 * draft marked and listing what's missing. Made-up names and amounts.
 */
class DocxExportTest extends TestCase
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
        $company = $this->postJson('/api/v1/companies', ['name_ar' => 'شركة الإيضاحات', 'city' => 'جدة', 'cr_number' => '1010000000',
            'cr_date_hijri' => '1440/01/01', 'unified_number' => '7000000000', 'activity' => 'التجارة', 'head_office' => 'جدة'])->json('id');
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'company_id' => $company, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])
            ->assertCreated()->json('id');
    }

    /** First year, every question answered: a final. */
    private function finalReport(array $rows = []): string
    {
        $id = $this->report($rows ?: $this->rows());
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

    private function download(string $id, int $version = 1): string
    {
        $response = $this->get(self::URL."/{$id}/versions/{$version}/export.docx")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $response->headers->get('Content-Type'));

        return $response->getContent();
    }

    public function test_the_notes_are_what_the_version_shows(): void
    {
        $id = $this->finalReport();
        $file = $this->download($id);
        $shown = $this->versionShows($id);
        $docx = $this->docxBody($file);

        // The same notes, in the same order: Word numbers them 1…N as the version did.
        $titles = array_map(fn ($t) => $t[1], $shown['titles']);
        $this->assertSame(range(1, count($titles)), array_column($shown['titles'], 0));
        $this->assertNotContains('الممتلكات والآلات والمعدات', $docx['paragraphs']);   // no PPE: its note and policy are hidden
        // Every title and paragraph the version shows, in order, and nothing else.
        $this->assertSame($shown['sequence'], $docx['paragraphs']);
        $this->assertContains('رأس المال', $titles);
        // Every table, cell for cell, read right to left.
        $this->assertCount(count($shown['tables']), $docx['tables']);
        foreach ($docx['tables'] as $i => $rows) {
            $this->assertSame($shown['tables'][$i], $this->docxReadingOrder($docx['xp'], $i, $rows), "table {$i}");
        }
    }

    public function test_figures_are_whole_riyals_and_a_first_year_has_one_year(): void
    {
        $docx = $this->docxBody($this->download($this->finalReport()));
        $all = json_encode($docx['tables'], JSON_UNESCAPED_UNICODE);

        // Capital: one partner, 10 shares of 10.
        $this->assertStringContainsString('يبلغ رأس مال الشركة 100 ريال سعودي', implode("\n", $docx['paragraphs']));
        $this->assertStringContainsString('"الشريك الأول"', $all);
        // Trade payables: 30, this year's column only (the prior year's and its spacer gone).
        $payables = collect($docx['tables'])->first(fn ($t) => in_array('موردون', array_merge(...$t), true) || str_contains(json_encode($t, JSON_UNESCAPED_UNICODE), '"30"'));
        $this->assertNotNull($payables);
        foreach ($payables as $row) {
            $this->assertCount(2, $row);
        }
        $this->assertStringNotContainsString('2024م', $all);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩۰-۹]/u', $all.implode('', $docx['paragraphs']));
    }

    public function test_no_zakat_prints_its_reason_in_the_zakat_note(): void
    {
        // A zakat provision of 5 (cash 155) and no zakat for the year: the reason given is the note's sentence.
        $rows = $this->rows([$this->account(7, 'مخصص الزكاة الشرعية', ['closing' => -5])]);
        $rows[0] = $this->account(2, 'نقدية لدى البنوك', ['closing' => 155.4]);
        $id = $this->report($rows);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk();
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['zakat.zero_reason' => 'أن الوعاء الزكوي للشركة سالب.']])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $paragraphs = implode("\n", $this->docxBody($this->download($id))['paragraphs']);
        $this->assertStringContainsString('لم يُكوَّن مخصص للزكاة عن السنة المنتهية في 31 ديسمبر 2025م، ويرجع ذلك إلى أن الوعاء الزكوي للشركة سالب.', $paragraphs);
        $this->assertStringNotContainsString('سالب..', $paragraphs);
    }

    public function test_no_placeholder_comment_or_review_mark_is_left(): void
    {
        $parts = $this->docxParts($this->download($this->finalReport()));

        foreach ($parts as $name => $content) {
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $this->assertStringNotContainsString('${', $content, $name);
                $this->assertStringNotContainsString('[review]', $content, $name);
                $this->assertStringNotContainsString('comments', $name === '[Content_Types].xml' || str_ends_with($name, '.rels') ? $content : '', $name);
            }
        }
        $this->assertArrayNotHasKey('word/comments.xml', $parts);
        $this->assertStringNotContainsString('commentReference', $parts['word/document.xml']);
        // The header names the company and the period.
        $this->assertStringContainsString('شركة الإيضاحات', $parts['word/header1.xml']);
        $this->assertStringContainsString('31 ديسمبر 2025م', $parts['word/header1.xml']);
        $this->assertStringNotContainsString('مسودة', $parts['word/header1.xml']);
        $this->assertStringContainsString('<dc:title>الإيضاحات المتممة للقوائم المالية</dc:title>', $parts['docProps/core.xml']);
    }

    public function test_right_to_left_and_one_portrait_section_without_ppe(): void
    {
        $file = $this->download($this->finalReport());
        $xp = $this->docxBody($file)['xp'];
        $styles = $this->docxXpath($this->docxParts($file)['word/styles.xml']);

        // No PPE: the landscape section is gone; page numbers run on from the first page.
        $sections = $xp->query('//w:sectPr');
        $this->assertSame(0, $xp->query('//w:sectPr/w:pgSz[@w:orient="landscape"]')->length);
        $starts = $xp->query('//w:sectPr/w:pgNumType[@w:start]');
        $this->assertSame(1, $starts->length);
        $this->assertSame($sections->item(0), $starts->item(0)->parentNode);
        // Every paragraph with text runs right to left: its own setting, else its style's, else Normal's.
        $styleBidi = function (?string $id) use ($styles, &$styleBidi): bool {
            $s = $styles->query("//w:style[@w:styleId='".($id ?? 'Normal')."']")->item(0);
            $b = $s ? $styles->query('./w:pPr/w:bidi', $s)->item(0) : null;
            if ($b) {
                return ! in_array($b->getAttributeNS(self::WORD, 'val'), ['0', 'false'], true);
            }
            $base = $s ? $styles->query('./w:basedOn/@w:val', $s)->item(0)?->value : null;
            return $base !== null && $styleBidi($base);
        };
        foreach ($xp->query('/w:document/w:body/w:p') as $p) {
            if (trim($p->textContent) === '') {
                continue;
            }
            $own = $xp->query('./w:pPr/w:bidi', $p)->item(0);
            $rtl = $own ? ! in_array($own->getAttributeNS(self::WORD, 'val'), ['0', 'false'], true) : $styleBidi($xp->query('./w:pPr/w:pStyle/@w:val', $p)->item(0)?->value);
            $this->assertTrue($rtl, $p->textContent);
        }
    }

    public function test_the_templates_yellow_marks_are_not_in_the_file(): void
    {
        $id = $this->report($this->rows(), ['opening', 'closing']);
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $this->assertSame(0, $this->docxBody($this->download($id))['xp']->query('//w:highlight')->length);
    }

    public function test_word_numbers_the_policies_headings_under_their_note(): void
    {
        $id = $this->finalReport();
        $parts = $this->docxParts($this->download($id));
        $xp = $this->docxXpath($parts['word/document.xml']);
        $titles = $xp->query('//w:p[w:pPr/w:numPr/w:ilvl/@w:val="0"]/w:pPr/w:numPr/w:numId/@w:val')->item(0)->value;

        // The headings the version numbers «N-k» are on the notes' own list, level 1, in order.
        $shown = collect($this->versionShows($id)['payload']['notes'])
            ->flatMap(fn ($n) => $n['visible'] ? array_filter($n['blocks'], fn ($b) => ($b['number'] ?? null) !== null) : [])
            ->pluck('text_ar')->all();
        $numbered = array_map(fn ($p) => $this->docxText($xp, $p), iterator_to_array($xp->query("//w:p[w:pPr/w:numPr[w:ilvl/@w:val='1' and w:numId/@w:val='{$titles}']]")));
        $this->assertNotEmpty($shown);
        $this->assertSame($shown, $numbered);
        $this->assertContains('تصنيف ما هو متداول مقابل ما هو غير متداول', $numbered);

        // That level reads «5-1»: the note's number, then the heading's.
        $nx = $this->docxXpath($parts['word/numbering.xml']);
        $abstract = $nx->query("//w:num[@w:numId='{$titles}']/w:abstractNumId/@w:val")->item(0)->value;
        $level = $nx->query("//w:abstractNum[@w:abstractNumId='{$abstract}']/w:lvl[@w:ilvl='1']")->item(0);
        $this->assertSame(['decimal', '%1-%2'], [$nx->query('./w:numFmt/@w:val', $level)->item(0)->value, $nx->query('./w:lvlText/@w:val', $level)->item(0)->value]);
    }

    public function test_a_tables_words_and_figures_are_one_size_and_weight(): void
    {
        $id = $this->report([
            ...$this->rows(),
            $this->account(7, 'مباني وإنشاءات', ['opening' => 500, 'closing' => 500]),
            $this->account(8, 'رأس المال', ['opening' => -500, 'closing' => -500]),
        ], ['opening', 'closing']);
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $xp = $this->docxBody($this->download($id))['xp'];

        $tables = iterator_to_array($xp->query('//w:tbl'));
        $this->assertNotEmpty($tables);
        foreach ($tables as $i => $tbl) {
            $runs = array_filter(iterator_to_array($xp->query('.//w:r[w:t]', $tbl)), fn ($r) => trim($r->textContent) !== '');
            $type = array_unique(array_map(fn ($r) => implode('/', [
                $xp->query('./w:rPr/w:sz/@w:val', $r)->item(0)?->value,
                $xp->query('./w:rPr/w:szCs/@w:val', $r)->item(0)?->value,
                $xp->query('./w:rPr/w:b', $r)->length,
            ]), $runs));
            $this->assertCount(1, $type, "table {$i}: ".implode(', ', $type));
            [$sz, $szCs] = explode('/', reset($type));
            $this->assertSame($sz, $szCs);
        }
    }

    public function test_the_ppe_note_keeps_its_landscape_section_and_hides_empty_categories(): void
    {
        // Buildings 500 (opening and closing; no movement), so the PPE note shows with one category.
        $id = $this->report([
            ...$this->rows(),
            $this->account(7, 'مباني وإنشاءات', ['opening' => 500, 'closing' => 500]),
            $this->account(8, 'رأس المال', ['opening' => -500, 'closing' => -500]),
        ], ['opening', 'closing']);
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $file = $this->download($id);
        $docx = $this->docxBody($file);
        $xp = $docx['xp'];

        $this->assertContains('الممتلكات والآلات والمعدات', $docx['paragraphs']);
        $this->assertSame(1, $xp->query('//w:sectPr/w:pgSz[@w:orient="landscape"]')->length);
        $ppe = collect($docx['tables'])->first(fn ($t) => str_starts_with($t[0][0] ?? '', 'الممتلكات والآلات والمعدات - 2025'));
        $this->assertNotNull($ppe);
        // Label, buildings, total: the other categories are 0 and hidden, as in the wizard.
        $this->assertSame(['', 'مباني', 'الإجمالي'], $ppe[1]);
        // The grid follows the cells, and the title still spans the table.
        $tbl = $xp->query('/w:document/w:body/w:tbl')->item(array_search($ppe, $docx['tables'], true));
        $cells = $xp->query('./w:tr[2]/w:tc', $tbl)->length;
        $edges = $xp->query('./w:tr[2]/w:trPr/w:gridBefore', $tbl)->length + $xp->query('./w:tr[2]/w:trPr/w:gridAfter', $tbl)->length;
        $this->assertSame($cells + $edges, $xp->query('./w:tblGrid/w:gridCol', $tbl)->length);
        $this->assertSame((string) ($cells + $edges), $xp->query('./w:tr[1]/w:tc/w:tcPr/w:gridSpan/@w:val', $tbl)->item(0)->value);
        // Same as the wizard, table for table.
        $shown = $this->versionShows($id);
        foreach ($docx['tables'] as $i => $rows) {
            $this->assertSame($shown['tables'][$i], $this->docxReadingOrder($xp, $i, $rows), "table {$i}");
        }
    }

    public function test_a_draft_says_so_and_lists_what_is_missing(): void
    {
        // No prior year chosen: the comparatives are missing.
        $id = $this->report($this->rows());
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $parts = $this->docxParts($this->download($id));
        $docx = $this->docxBody($this->download($id));
        $shown = $this->versionShows($id);

        $this->assertSame('مسودة: هذه الإيضاحات غير نهائية بعد', $docx['paragraphs'][0]);
        $this->assertContains('أرقام ناقصة في الإيضاحات (كُتب مكانها «[ناقص]»)', $docx['paragraphs']);
        $this->assertNotEmpty($shown['payload']['missing']);
        foreach (['header1.xml', 'header2.xml', 'header3.xml'] as $h) {
            $this->assertStringContainsString('مسودة', $parts["word/{$h}"]);
        }
        // A missing figure is «[ناقص]», never 0.
        $this->assertStringContainsString('[ناقص]', json_encode($docx['tables'], JSON_UNESCAPED_UNICODE));
        // The draft's page is a section of its own, without a header; the notes follow, as the wizard shows them.
        $first = $this->docxXpath($parts['word/document.xml'])->query('//w:sectPr')->item(0);
        $this->assertSame(0, $first->getElementsByTagNameNS(self::WORD, 'headerReference')->length);
        foreach ($docx['tables'] as $i => $rows) {
            $this->assertSame($shown['tables'][$i], $this->docxReadingOrder($docx['xp'], $i, $rows), "table {$i}");
        }
    }

    public function test_the_users_wording_replaces_the_templates(): void
    {
        $id = $this->report($this->rows());
        $key = collect($this->getJson(self::URL."/{$id}")->json('notes'))->firstWhere('key', 'incorporation')['blocks'][1]['key'];
        $this->patchJson(self::URL."/{$id}/notes", ['texts' => [$key => "نص معدّل لنشاط الشركة.\nسطر تاني."]])->assertOk();
        $this->postJson(self::URL."/{$id}/versions", ['kind' => 'draft'])->assertCreated();
        $docx = $this->docxBody($this->download($id));

        $this->assertContains('نص معدّل لنشاط الشركة.سطر تاني.', $docx['paragraphs']);
        $this->assertNotContains('يتمثل نشاط الشركة في التجارة.', $docx['paragraphs']);
        $this->assertStringContainsString('<w:br/>', $this->docxParts($this->download($id))['word/document.xml']);
    }

    public function test_the_same_version_gives_the_same_file(): void
    {
        $id = $this->finalReport();
        $this->assertSame(md5($this->download($id)), md5($this->download($id)));

        $disposition = $this->get(self::URL."/{$id}/versions/1/export.docx")->headers->get('Content-Disposition');
        $this->assertStringContainsString('filename="financial-statements-notes-2025-12-31-v1-final.docx"', $disposition);
        $this->assertStringContainsString(rawurlencode('الإيضاحات المتممة للقوائم المالية - شركة الإيضاحات - 2025-12-31 - نسخة 1 (نهائي).docx'), $disposition);
    }

    public function test_unknown_report_or_version(): void
    {
        $this->get(self::URL.'/'.Str::uuid().'/versions/1/export.docx')->assertNotFound()->assertJson(['message' => 'القوائم المالية هذه غير موجودة.']);
        $id = $this->report($this->rows());
        $this->get(self::URL."/{$id}/versions/3/export.docx")->assertNotFound()->assertJson(['message' => 'هذا الإصدار غير موجود.']);
    }
}
