<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Models\StatementSynonym;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPdf\CompanyFacts;
use App\Services\Statements\PriorPdf\Llm;
use App\Services\Statements\PriorPdf\PageClassifier;
use App\Services\Statements\PriorPdf\Partners;
use App\Services\Statements\PriorPdf\PriorPdfReader;
use App\Services\Statements\PriorPdf\RelatedParties;
use App\Services\Statements\PriorPdf\Sections;
use App\Services\Statements\PriorPdf\Totals;
use App\Services\Statements\PriorPdf\Vocabulary;
use App\Services\Statements\PriorPdf\Wording;
use App\Services\Statements\PriorPpe;
use App\Services\Statements\PriorStatementsPdf;
use App\Services\Statements\StatementSynonyms;
use App\Support\ClassificationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PrintedLabels;
use Tests\TestCase;

/**
 * A real client's file (brief §10): last year's approved statements, read offline from the AI's answers kept by
 * `php artisan prior-pdf:extract`, against the values expected of them. The file, the answers and the expected values
 * are client data: they live in docs/reference/ (gitignored; docs/reference/fixtures/*.json), never in git. Without them
 * (CI) every test is skipped, and a skipped run is not a pass.
 *
 * Phase 3: what the reading itself must get right (pages, years, amounts as printed, totals, notes, links, flags, and
 * this year's opening balances against last year's closing). Phase 4: the statements mapped to lines, checked against
 * each other, the changes in equity for the grid. The note items: later phases.
 */
class ReferencePdfFixtureTest extends TestCase
{
    use PrintedLabels, RefreshDatabase;   // the synonyms the users keep are part of the known words; labels compared as words (PrintedLabels)

    /** @var array<string, array{kinds: list<array>, doc: array}> by fixture */
    private static array $read = [];

    private array $x;

    protected function setUp(): void
    {
        parent::setUp();
        $files = glob(base_path('docs/reference/fixtures/prior-pdf-*.json')) ?: [];
        $x = $files ? json_decode((string) file_get_contents($files[0]), true) : null;
        if (! $x || ! is_file($this->path($x['pdf'])) || ! is_dir($this->path($x['answers']))) {
            $this->markTestSkipped('No reference client fixture on this machine (docs/reference/fixtures).');
        }
        $this->x = $x;
        // The answers were kept with this provider and model; nothing may be sent.
        config(['classification.provider' => $x['provider'], "classification.providers.{$x['provider']}.model" => $x['model']]);
        Http::preventStrayRequests();
        app(Llm::class)->cacheIn($this->path($x['answers']));
    }

    private function path(string $file): string
    {
        return base_path("docs/reference/{$file}");
    }

    /** @return array{kinds: list<array>, doc: array} */
    private function read(): array
    {
        $pdf = $this->path($this->x['pdf']);
        if (! isset(self::$read[$pdf])) {
            $kinds = app(PageClassifier::class)->classify($pdf, (string) file_get_contents($pdf), $this->x['pages'])['pages'];
            self::$read[$pdf] = ['kinds' => $kinds, 'doc' => app(PriorPdfReader::class)->read($pdf, $kinds)];
        }

        return self::$read[$pdf];
    }

    /** The client's own wordings the accountant mapped once, kept as their synonyms (never seeded). */
    private function learnSynonyms(): void
    {
        foreach ($this->x['learned_synonyms'] ?? [] as [$statement, $key, $label]) {
            StatementSynonym::create(['statement' => $statement, 'line_key' => $key, 'label' => $label, 'wording' => StatementSynonyms::wording($label)]);
        }
    }

    /** A statement's rows by label: the amount of the file's current year (the report's comparative year). */
    private function amounts(string $statement): array
    {
        $s = $this->read()['doc']['statements'][$statement];
        $at = array_search('current', $s['roles']['roles'], true);
        $out = [];
        foreach ($s['rows'] as $r) {
            if ($r['kind'] !== 'heading') {
                $out[$r['label']] = $r['cells'][$at]['amount'];
            }
        }

        return $out;
    }

    private function section(string $printed): array
    {
        return collect($this->read()['doc']['sections'])->first(fn ($s) => $s['printed'] === $printed && ! $s['slip']);
    }

    private function tables(string $note): array
    {
        $doc = $this->read()['doc'];

        return Sections::tablesOf($this->section($note)['id'], $doc['sections'], $doc['tables']);
    }

    /** A note table's lines by label: [current, comparative]. */
    private function lines(string $note, int $table = 0): array
    {
        $t = $this->tables($note)[$table];
        $cur = array_search('current', $t['roles']['roles'], true);
        $cmp = array_search('comparative', $t['roles']['roles'], true);
        $out = [];
        foreach ($t['rows'] as $r) {
            if ($r['kind'] === 'line') {
                $out[$r['label']] = [$r['cells'][$cur]['amount'], $r['cells'][$cmp]['amount']];
            }
        }

        return $out;
    }

    private function bodyRows(array $table): array
    {
        return array_values(array_filter($table['rows'], fn ($r) => $r['kind'] !== 'heading'));
    }

    public function test_every_page_is_described_and_the_sideways_ones_turned(): void
    {
        $kinds = $this->read()['kinds'];
        $this->assertSame($this->x['kinds'], array_column($kinds, 'kind'));
        $this->assertSame($this->x['rotated'], array_values(array_column(array_filter($kinds, fn ($p) => $p['rotation'] !== 0), 'page')));
        foreach ($this->x['printed'] as $page => $printed) {
            $this->assertSame($printed, $kinds[$page - 1]['printed_page']);
        }
        $this->assertSame($this->x['years'], $this->read()['doc']['years']);
    }

    public function test_the_statements_amounts_as_printed(): void
    {
        foreach ($this->x['statements'] as $statement => $expected) {
            $this->assertEquals(self::labels($expected), array_intersect_key(self::labels($this->amounts($statement)), self::labels($expected)), $statement);
        }
        $equity = $this->read()['doc']['statements']['changes_in_equity']['rows'];
        foreach ($this->x['equity']['rows'] as $i => [$label, $total]) {
            $this->assertSame(self::label($label), self::label($equity[$i]['label']));
            $this->assertEquals($total, end($equity[$i]['cells'])['amount']);
        }
    }

    public function test_totals_are_found_by_their_arithmetic(): void
    {
        foreach ($this->x['totals'] as $statement => $labels) {
            $this->assertSame(self::labels($labels), self::labels(array_values(array_column(array_filter($this->read()['doc']['statements'][$statement]['rows'], fn ($r) => $r['kind'] === 'total'), 'label'))));
        }
        $k = $this->x['kinds_of_rows'];
        $this->assertSame(self::labels($k['rows']), self::labels(array_map(fn ($r) => [$r['label'], $r['kind']], $this->bodyRows($this->tables($k['note'])[$k['table']]))));
    }

    public function test_the_notes_items_as_printed_with_the_years_typed_wrong_flagged(): void
    {
        foreach ($this->x['note_lines'] as $note => $labels) {
            $this->assertSame(self::labels($labels), array_keys(self::labels($this->lines($note))), "note {$note}");
        }
        foreach ($this->x['note_values'] as $note => $values) {
            $this->assertEquals(self::labels($values), self::labels($this->lines($note)), "note {$note}");
        }
        $flags = $this->read()['doc']['flags'];
        $typos = array_values(array_filter($flags, fn ($f) => $f['code'] === 'year_typo'));
        $this->assertSame($this->x['year_typo_notes'], array_values(array_unique(array_column($typos, 'note'))));
        $this->assertSame($this->x['year_typo_example'], ['printed' => $typos[0]['printed'], 'used' => $typos[0]['used']]);
        $this->assertSame($this->x['year_in_text'], array_values(array_map(fn ($f) => [(int) $f['note'], $f['year']], array_filter($flags, fn ($f) => $f['code'] === 'year_in_text'))));
        $this->assertSame(self::labels($this->x['slips']), self::labels(array_values(array_map(fn ($f) => [$f['printed'], $f['title']], array_filter($flags, fn ($f) => $f['code'] === 'note_number_slip')))));
        foreach ($this->x['nested'] as $note => $title) {
            $this->assertSame(self::label($title), self::label(collect($this->read()['doc']['sections'])->firstWhere('parent', $this->section($note)['id'])['title']));
        }
    }

    public function test_notes_line_by_line(): void
    {
        // Notes 5, 6, 10, 12 and 13 as printed (checked against the page images): each table's rows in order, its
        // label (normalised spelling), whether its arithmetic makes it a total, and its amount under each column's
        // header (empty = 0). Zakat's two «إجمالي» rows stay lines (R10); its movement table is one; note 13's nested
        // part keeps its own table.
        foreach ($this->x['note_tables'] as $note => $expected) {
            $got = array_map(fn ($t) => ['movement' => (bool) ($t['movement'] ?? false), 'rows' => array_values(array_map(function ($r) use ($t) {
                $cells = [];
                foreach ($t['columns'] as $i => $c) {
                    $cells[trim(explode(' / ', $c['header'])[0])] = $r['cells'][$i]['amount'] ?? 0;
                }

                return [ClassificationKey::name($r['label']), $r['kind'] === 'total', $cells];
            }, array_filter($t['rows'], fn ($r) => ! ($r['kind'] === 'heading' && preg_match('/[اإ]ستبعاد/u', $r['label']) !== 1))))], $this->tables((string) $note));
            $this->assertEquals(self::labels($expected), self::labels($got), "note {$note}");
        }
        foreach ($this->x['nested_tables'] as $note => [$title, $count]) {
            $part = collect($this->read()['doc']['sections'])->firstWhere('parent', $this->section($note)['id']);
            $this->assertSame([self::label($title), $count], [self::label($part['title']), count($part['tables'])]);
        }
    }

    public function test_ppe_other_assets_and_partners(): void
    {
        $p = $this->x['ppe'];
        $ppe = $this->tables($p['note'])[0];
        $rows = $this->bodyRows($ppe);
        $this->assertSame(self::label($p['acc_label']), self::label($rows[$p['acc_row']]['label']));
        $this->assertEquals($p['acc'], array_map(fn ($c) => $c['amount'], $rows[$p['acc_row']]['cells']));
        $this->assertEquals($p['cost_total'], end($rows[$p['cost_row']]['cells'])['amount']);
        $this->assertEquals($p['dep_first'], $rows[$p['dep_row']]['cells'][0]['amount']);
        $this->assertCount($p['columns'], $ppe['columns']);

        $o = $this->x['other_assets'];
        $this->assertEquals($o['nbv'], $this->tables($o['note'])[$o['nbv_table']]['rows'][0]['cells'][0]['amount']);

        // Phase 7's rule: as many names as share rows; shares × the share value = each amount; the shares add up to the capital.
        $pt = $this->x['partners'];
        $partners = array_values(array_filter($this->tables($pt['note'])[0]['rows'], fn ($r) => $r['kind'] === 'line'));
        $this->assertCount($pt['count'], $partners);
        $this->assertEquals($pt['shares'], array_map(fn ($r) => $r['cells'][$pt['shares_column']]['amount'], $partners));
        foreach ($partners as $r) {
            $this->assertEquals($r['cells'][$pt['shares_column']]['amount'] * $pt['share_value'], $r['cells'][$pt['amount_column']]['amount']);
        }
        $this->assertEquals($pt['capital'], array_sum(array_map(fn ($r) => $r['cells'][$pt['amount_column']]['amount'], $partners)));
    }

    public function test_ppe_categories_rates_and_last_years_cells(): void
    {
        // Phase 6: last year's categories as printed (the AI's slip corrected), their rates and lives, last year's cells.
        $this->learnSynonyms();
        $pdf = $this->path($this->x['pdf']);
        $names = [];
        $cells = IOFactory::load($this->path($this->x['tb']))->getActiveSheet()->toArray(null, false, false, false);
        array_walk_recursive($cells, function ($v) use (&$names) {
            is_string($v) && $names[] = $v;
        });
        $read = app(PriorStatementsPdf::class)->read($pdf, $this->read()['kinds'], $this->x['years']['current'], new LineCatalogue('sa-ifrs-smes-3'), StatementSynonyms::map(),
            Vocabulary::of($names, 'sa-ifrs-smes-3'), null, Vocabulary::surfaces($names, 'sa-ifrs-smes-3'));
        $categories = PriorPpe::categories(['blocks' => $read['blocks'], 'document' => $read['document']]);
        $e = $this->x['ppe_categories'];
        $this->assertCount($e['labels_count'], $categories);
        $this->assertEquals($e['rates'], array_column($categories, 'rate'));
        $this->assertEquals($e['lives'], array_column($categories, 'life'));
        $this->assertSame(self::label($e['label_8']), self::label($categories[7]['label']));
        $this->assertEquals($e['acc_closing_total'], array_sum(array_map(fn ($c) => $c['last']['acc_closing'], $categories)));
        $this->assertEquals($e['sofp_ppe'], $e['cost_closing_total'] - array_sum(array_map(fn ($c) => $c['last']['acc_closing'], $categories)));
        $this->assertEquals($e['cost_closing_total'], array_sum(array_map(fn ($c) => $c['last']['cost_closing'], $categories)));
        // Where a printed total differs from its categories' sum, each year end's balances are chosen together, only in
        // combinations that agree (cost − accumulated depreciation = net book value), the one tying to the balance sheet
        // first; one flag per year end.
        $doc = $read['document'];
        $this->assertEquals($e['year_ends'], array_map(fn ($g) => [$g['year'], $g['status'], array_map(fn ($o) => [$o['cost'], $o['accumulated'], $o['nbv']], $g['options']),
            Totals::option($g)], $doc['total_groups']));
        $this->assertCount(count($e['year_ends']), array_filter($doc['flags'], fn ($f) => str_starts_with($f['code'], 'total_')));
        $this->assertEquals($e['note_totals'], array_intersect_key(PriorPpe::totals(['blocks' => $read['blocks'], 'document' => $doc]), $e['note_totals']));
    }

    public function test_partners_read_from_the_capital_note(): void
    {
        // Phase 7: as many names as share rows, read by the columns' arithmetic, adding up to the capital; who is on which
        // row the trial balance can't confirm here (one capital account), so the table waits for the accountant's review.
        $pt = $this->x['partners'];
        $this->learnSynonyms();
        $read = app(PriorStatementsPdf::class)->read($this->path($this->x['pdf']), $this->read()['kinds'], $this->x['years']['current'], new LineCatalogue('sa-ifrs-smes-3'));
        $partners = Partners::read(['blocks' => $read['blocks'], 'document' => $read['document']]);
        $this->assertSame([$pt['note'], 'read', true], [$partners['note'], $partners['status'], $partners['sum_ok']]);
        $this->assertCount($pt['count'], $partners['rows']);
        $this->assertCount($pt['count'], array_filter(array_column($partners['rows'], 'name')));
        $this->assertEquals($pt['shares'], array_column($partners['rows'], 'shares'));
        $this->assertEquals(array_fill(0, $pt['count'], $pt['share_value']), array_column($partners['rows'], 'share_value'));
        $this->assertEquals($pt['capital'], array_sum(array_column($partners['rows'], 'amount')));
        $this->assertSame('unconfirmed', Partners::confirm($partners, null)['status']);
    }

    public function test_related_parties_note_or_none(): void
    {
        // Phase 8: a related parties' note read when the file has one; none here (only the balance-sheet line).
        $this->learnSynonyms();
        $read = app(PriorStatementsPdf::class)->read($this->path($this->x['pdf']), $this->read()['kinds'], $this->x['years']['current'], new LineCatalogue('sa-ifrs-smes-3'));
        $this->assertSame($this->x['related_parties_note'], RelatedParties::read(['blocks' => $read['blocks'], 'document' => $read['document']]) !== null);
    }

    public function test_company_information_in_the_notes_text(): void
    {
        $text = implode("\n", array_merge(...array_column($this->read()['doc']['sections'], 'paragraphs')));
        foreach ($this->x['company_facts'] as $fact) {
            $this->assertStringContainsString($fact, $text);
        }
        $this->assertMatchesRegularExpression('/'.$this->x['approval_pattern'].'/u', $text);
        // Read as the company's facts (Phase 5): the registry, the city, the form, the framework, the approving body.
        $facts = array_map(fn ($f) => $f['value'], CompanyFacts::of($this->read()['doc']));
        $this->assertEquals($this->x['company'], array_intersect_key($facts, $this->x['company']));
        $this->assertArrayHasKey('name_ar', $facts);
        $this->assertArrayHasKey('activity', $facts);
        // The policy choices of last year's policies note.
        $this->assertSame($this->x['policies'], array_filter(\App\Services\Statements\PriorPdf\PdfDefaults::policies($this->read()['doc']), fn ($v) => $v !== null));
    }

    public function test_links_and_checks(): void
    {
        $doc = $this->read()['doc'];
        $linked = [];
        foreach ($doc['links'] as $l) {
            if ($l['section'] !== null) {
                $linked[$doc['statements'][$l['statement']]['rows'][$l['row']]['label']] = $doc['sections'][$l['section']]['printed'];
            }
        }
        $this->assertEquals($this->x['links'], $linked);
        $failing = array_values(array_filter($doc['checks'], fn ($c) => ! $c['ok']));
        $this->assertSame($this->x['failing_checks'], array_map(fn ($c) => [$c['code'], $c['pages']], $failing));
        // Amounts printed off their lines, placed by the statement's arithmetic (the only placement that holds).
        $this->assertSame($this->x['aligned'], array_values(array_map(fn ($f) => [$f['statement'], $f['page']], array_filter($doc['flags'], fn ($f) => $f['code'] === 'aligned_by_arithmetic'))));
        $this->assertSame(0, $doc['usage']['requests']);   // all from the kept answers
    }

    public function test_the_statements_mapped_to_lines_checked_against_each_other_and_the_equity_grid(): void
    {
        // Phase 4: every row placed on its line (exactly, by a starting synonym, or by the client's own wording the
        // accountant mapped once: kept as their synonym, never seeded), checked across statements; the changes in
        // equity's block for the comparative grid.
        $this->learnSynonyms();
        $pdf = $this->path($this->x['pdf']);
        $read = app(PriorStatementsPdf::class)->read($pdf, $this->read()['kinds'], $this->x['years']['current'], new LineCatalogue('sa-ifrs-smes-3'), StatementSynonyms::map());
        $waiting = 0;
        foreach ($read['blocks'] as $block) {
            $placed = [];
            foreach ($block['rows'] as $r) {
                $v = $r['values'][$block['column']] ?? ['status' => 'empty'];
                if (in_array($r['certainty'], ['exact', 'synonym'], true) && $v['status'] === 'ok') {
                    $placed[$r['match']] = $v['amount'];
                } elseif ($v['status'] !== 'empty' && abs((float) $v['amount']) > 0.005 && $r['kind'] !== 'total') {
                    $waiting++;   // a row the accountant would have to place
                }
            }
            $this->assertEquals($this->x['mapped'][$block['statement']], array_intersect_key($placed, $this->x['mapped'][$block['statement']]), $block['statement']);
        }
        $this->assertSame($this->x['rows_waiting'], $waiting);
        $this->assertSame($this->x['cross_checks'], array_values(array_map(fn ($c) => [$c['code'], $c['ok']],
            array_filter($read['document']['checks'], fn ($c) => str_starts_with($c['code'], 'cross_')))));
        $this->assertEquals($this->x['equity_grid'], $read['equity']['rows']);
    }

    public function test_wording_read_two_ways_or_misprinted_is_corrected(): void
    {
        $doc = $this->read()['doc'];
        $this->assertSame($this->x['wording_differs'], array_map(fn ($f) => [$f['printed'], $f['other'], $f['pages']], Wording::flags($doc)));
        $names = [];
        $cells = IOFactory::load($this->path($this->x['tb']))->getActiveSheet()->toArray(null, false, false, false);
        array_walk_recursive($cells, function ($v) use (&$names) {
            is_string($v) && $names[] = $v;
        });
        // With the trial balance, templates and taxonomy known: the AI's slip and the file's misprint corrected, nothing else.
        $check = Wording::check($doc, Vocabulary::of($names, 'sa-ifrs-smes-3'), Vocabulary::surfaces($names, 'sa-ifrs-smes-3'));
        $this->assertSame($this->x['corrections'], array_map(fn ($c) => [$c['kind'], $c['page'], $c['original'], $c['corrected']], $check['corrections']));
        // With the general words, and the partners' names left to the table's review: what is still flagged.
        $names = Partners::read(['document' => $doc])['names'];
        $check = Wording::check($doc, Vocabulary::of($this->tbNames(), 'sa-ifrs-smes-3'), Vocabulary::surfaces($this->tbNames(), 'sa-ifrs-smes-3'),
            Vocabulary::general(), $names);
        $this->assertSame($this->x['corrections'], array_map(fn ($c) => [$c['kind'], $c['page'], $c['original'], $c['corrected']], $check['corrections']));
        $this->assertSame($this->x['wording_unknown_with_general'], array_merge(...array_map(fn ($f) => $f['words'], array_filter($check['flags'], fn ($f) => $f['code'] === 'wording_unknown')) ?: [[]]));
    }

    /** The reference trial balance's texts. */
    private function tbNames(): array
    {
        $names = [];
        $cells = IOFactory::load($this->path($this->x['tb']))->getActiveSheet()->toArray(null, false, false, false);
        array_walk_recursive($cells, function ($v) use (&$names) {
            is_string($v) && $names[] = $v;
        });

        return $names;
    }

    public function test_this_years_opening_equals_last_years_closing_by_amount(): void
    {
        // The user's cross-source check: the trial balance's opening balances = last year's closing figures read from the
        // PDF, its accounts found by amount as well as by name (the names differ). Here: accumulated depreciation by category.
        $t = $this->x['tb_opening'];
        $tb = IOFactory::load($this->path($this->x['tb']))->getActiveSheet()->toArray(null, true, false, false);
        $opening = [];
        foreach ($tb as $row) {
            if (is_string($row[$t['name_column']] ?? null) && preg_match('/'.$t['pattern'].'/u', $row[$t['name_column']])) {
                $opening[trim(preg_replace('/^[\s.]+/u', '', $row[$t['name_column']]))] = (float) ($row[$t['credit_column']] ?? 0);
            }
        }
        $closing = $this->bodyRows($this->tables($this->x['ppe']['note'])[0])[$this->x['ppe']['acc_row']]['cells'];
        foreach ($t['categories'] as [$account, $category, $difference]) {
            $this->assertEquals($difference, abs($opening[$account] - $closing[$category]['amount']), $account);   // 0: equal; else a real difference to flag
        }
        $o = $this->x['other_assets'];
        $this->assertEquals($opening[$t['other_assets_account']], end($this->tables($o['note'])[$o['acc_table']]['rows'])['cells'][0]['amount']);
    }

    /**
     * The reference client's report as the user's draft has it (Step B): its trial balance as classified, its accounts'
     * overrides, last year's approved statements attached and read from the kept answers. Skipped without them.
     */
    private function referenceReport(): string
    {
        $e = $this->x['approved_opening'] ?? null;
        if ($e === null || ! is_file($this->path("fixtures/{$e['run']}"))) {
            $this->markTestSkipped('No reference trial balance fixture on this machine (docs/reference/fixtures).');
        }
        $f = json_decode((string) file_get_contents($this->path("fixtures/{$e['run']}")), true);
        foreach ($this->x['learned_synonyms'] ?? [] as [$statement, $key, $label]) {
            StatementSynonym::create(['statement' => $statement, 'line_key' => $key, 'label' => $label, 'wording' => StatementSynonyms::wording($label)]);
        }
        $catalogue = new LineCatalogue($f['template_version']);
        $read = app(PriorStatementsPdf::class)->read($this->path($this->x['pdf']), $this->read()['kinds'], $this->x['years']['current'], $catalogue, StatementSynonyms::map(),
            Vocabulary::of($this->tbNames(), $f['template_version']), null, Vocabulary::surfaces($this->tbNames(), $f['template_version']));
        $file = ['name' => basename($this->x['pdf']), 'kind' => 'pdf', 'status' => 'ready', 'year' => $this->x['years']['current'], 'uploaded_at' => now()->toIso8601String(),
            'pages_total' => count($this->read()['kinds']), 'pages' => [], 'found' => [], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null,
            'equity' => $read['equity']];
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $f['run']]);
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => $f['period'][0], 'period_end' => $f['period'][1]])->json('id');
        FinancialReport::find($id)->update(['comparative_start' => $f['comparative'][0], 'comparative_end' => $f['comparative'][1], 'account_overrides' => $f['account_overrides'],
            'inputs' => ['oci.has_items' => false], 'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false,
                // Step 2's grid as saved: last year's changes in equity as read (EquityBlock).
                'changes_in_equity' => $read['equity']['rows'] ?? null]]);

        return $id;
    }

    public function test_this_years_openings_are_last_years_approved_closing_reconciled_with_the_trial_balance(): void
    {
        // Step B: the reference client's trial balance (as classified, its accounts mapped by the rules)
        // with last year's approved statements: each line's closing = the approved closing + the year's movement; the
        // trial balance's openings reconciled line by line and in total; the notes, the PPE table and the related parties
        // from the approved figures, every note tying to its line in both years but for the file's own misprint.
        $e = $this->x['approved_opening'];
        $id = $this->referenceReport();
        $state = $this->getJson("/api/v1/financial-reports/{$id}")->assertOk()->json();
        $codes = array_column($state['checks'], 'code');
        $a = $state['comparative']['approved_opening'];

        $this->assertSame('applied', $a['status']);
        // Every account on a line by the rules alone (the accruals class respelled in the client's taxonomy included).
        $this->assertSame([], $state['mapping']['unmapped']);
        $this->assertEquals($e['totals'], array_column($a['totals'], 'difference', 'part'));
        $this->assertEquals($e['lines'], array_intersect_key(array_column($a['lines'], 'difference', 'line'), $e['lines']));
        // Each line: the closing presented = the trial balance's closing − its difference − the openings of the accounts
        // on another line last year (their difference follows them).
        foreach ($a['lines'] as $l) {
            $this->assertEqualsWithDelta($l['tb_closing'] - $l['difference'] - $l['moved_total'] - $l['rounding_kept'], $l['presented'], 0.01, $l['line']);
        }
        $this->assertSame($e['remainder'], [collect($a['lines'])->firstWhere('remainder', '!=', null)['line'], collect($a['lines'])->firstWhere('remainder', '!=', null)['remainder']['amount']]);
        // The balance sheet balances; the changes in equity roll from the approved closing to the equity presented.
        $this->assertNotContains('sofp_unbalanced', $codes);
        $equity = collect($state['statements']['changes_in_equity']['current'])->keyBy('key');
        $line = fn (string $key) => collect($state['statements']['financial_position'])->firstWhere('key', $key);
        // As printed, last year's closing; exactly, less the sub-riyal differences the lines tied to an outside source left on
        // retained earnings (their own amounts kept).
        $this->assertEquals($e['equity_opening'], $equity['opening']['shown']['total']);
        $this->assertEqualsWithDelta($e['equity_opening'] - collect($a['lines'])->firstWhere('line', 'sofp.retained_earnings')['rounding_kept'],
            $equity['opening']['cells']['total']['amount'], 0.01);
        $this->assertEqualsWithDelta($line('sofp.total_equity')['current']['amount'], $equity['closing']['cells']['total']['amount'], 0.01);
        $this->assertEmpty(array_intersect(['soce_opening_mismatch', 're_roll', 'cash_flow_opening_mismatch', 'opening_vs_prior_closing'], $codes));
        // Other income the trial balance classed as a gain on disposal, nothing disposed (the accountant: other income for
        // now): kept as other income by the user's tag, flagged until confirmed; the cash flow closes on the balance sheet.
        $this->assertSame([[$e['disposal_pending'], 'warning']], array_map(fn ($c) => [$c['amount'], $c['severity']], array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'disposal_pending'))));
        $this->assertNotContains('disposal_unmatched', $codes);
        $this->assertNotContains('cash_flow_closing_mismatch', $codes);
        $cf = fn (string $key) => collect($state['statements']['cash_flows'])->firstWhere('key', $key)['current']['amount'];
        $this->assertEquals($e['cash_flow'], ['net_change' => $cf('cf.net_change'), 'opening_cash' => $cf('cf.opening_cash'), 'closing_cash' => $cf('cf.closing_cash')]);
        $this->assertEqualsWithDelta($cf('cf.closing_cash') - $cf('cf.opening_cash'), $cf('cf.net_change'), 0.01);
        $this->assertEqualsWithDelta($line('sofp.cash')['current']['amount'], $cf('cf.closing_cash'), 0.01);
        // Retained earnings lost more than the year's result, nothing says what: unexplained (not dividends) until named.
        $this->assertEquals([$e['retained_unexplained']], array_column(array_filter($state['checks'], fn ($c) => $c['code'] === 'retained_unexplained'), 'amount'));
        $this->assertEquals([0, -$e['retained_unexplained']], [$cf('cf.dividends_paid'), $cf('cf.other_retained_movements')]);
        // The related parties at last year's approved balance (the line: no note), this year's = it + the movement.
        $this->assertEquals([$e['related_parties_prior'], $e['related_parties_prior']], [$line('sofp.due_to_related_parties')['comparative']['amount'], $line('sofp.due_to_related_parties')['current']['amount']]);
        // The PPE table opens at the approved closing, by category; only the year's depreciation moves it.
        $ppe = collect($state['schedules'])->firstWhere('key', 'ppe');
        $cell = fn (string $row) => collect($ppe['rows'])->firstWhere('key', $row)['cells']['total']['amount'];
        $this->assertEquals($e['ppe'], ['cost_opening' => $cell('cost_opening'), 'acc_opening' => $cell('acc_opening'), 'nbv_opening' => $cell('nbv_opening'), 'depreciation' => $cell('depreciation')]);
        // The accounts proposed back on last year's lines: never by an amount alone to a line of another nature (the
        // accountant: a deferred revenue equal to last year's VAT by chance); a match by name, not marked amount-only.
        $accounts = array_merge([], ...array_column($state['comparative']['upload']['comparable']['proposals'], 'accounts'));
        $this->assertSame([], array_values(array_filter($accounts, fn ($x) => str_contains($x['name'], $e['not_proposed'][0]) && $x['item'] === $e['not_proposed'][1])));
        foreach ($e['proposed'] as [$name, $item, $amountOnly]) {
            $this->assertSame([[$item, $amountOnly]], array_values(array_map(fn ($x) => [$x['item'], $x['amount_only']], array_filter($accounts, fn ($x) => str_contains($x['name'], $name)))));
        }
        // Every note ties to its line in both years; last year's own note printed a riyal off its line takes it on its largest line, said.
        $this->assertEquals($e['note_mismatches'], array_values(array_map(fn ($c) => [$c['code'], $c['scope'], $c['line_key']],
            array_filter($state['checks'], fn ($c) => in_array($c['code'], ['note_total_mismatch', 'asset_rollforward'], true)))));
        $this->assertEquals($e['note_remainders'], array_values(array_map(fn ($c) => [$c['line_key']],
            array_filter($state['checks'], fn ($c) => $c['code'] === 'note_comparative_remainder'))));
    }

    /**
     * Phase 9: the reference client's files, as a draft with the current defaults (the retained earnings' movement
     * unexplained, the gain on disposal as other income), saved in docs/reference/output/ (gitignored) for the
     * accountant's review and the render: the company from last year's notes as one click in step 2 gives it.
     */
    public function test_the_reference_clients_files(): void
    {
        $id = $this->referenceReport();
        $facts = $this->getJson("/api/v1/financial-reports/{$id}")->assertOk()->json('comparative.upload.company');
        $company = $this->postJson('/api/v1/companies', ['name_ar' => $facts['name_ar']['value'], 'legal_form' => 'llc'])->assertCreated()->json('id');
        $this->patchJson("/api/v1/companies/{$company}", array_map(fn ($f) => $f['value'], array_diff_key($facts, ['name_ar' => 1])))->assertOk();
        $this->patchJson("/api/v1/financial-reports/{$id}", ['company_id' => $company])->assertOk();
        $version = $this->postJson("/api/v1/financial-reports/{$id}/versions", ['kind' => 'draft'])->assertCreated()->json('version');

        $out = $this->path('output');
        is_dir($out) || mkdir($out, 0777, true);
        $xlsx = $this->get("/api/v1/financial-reports/{$id}/versions/{$version}/export.xlsx")->assertOk()->getContent();
        $docx = $this->get("/api/v1/financial-reports/{$id}/versions/{$version}/export.docx")->assertOk()->getContent();
        file_put_contents("{$out}/reference-statements.xlsx", $xlsx);
        file_put_contents("{$out}/reference-notes.docx", $docx);
        file_put_contents("{$out}/reference-state.json", json_encode($this->getJson("/api/v1/financial-reports/{$id}")->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // The template's sheets in its order, then the draft's notes and the journal; no formula gives an error.
        $book = IOFactory::load("{$out}/reference-statements.xlsx");
        $this->assertSame(['data', 'الغلاف', 'الفهرس', 'المركز المالى', 'قائمة الدخل الشامل', 'قائمة التغيرات', 'قائمة التدفقات النقدية', 'ايضاح الاصول والممتلكات', 'ملاحظات المسودة', 'قيود التسوية'],
            $book->getSheetNames());
        $errors = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCoordinates() as $c) {
                $v = $sheet->getCell($c)->isFormula() ? $sheet->getCell($c)->getCalculatedValue() : $sheet->getCell($c)->getValue();
                if (is_string($v) && preg_match('/^#(REF!|VALUE!|NAME\?|DIV\/0!|N\/A|NUM!|NULL!)/', $v)) {
                    $errors[] = $sheet->getTitle().'!'.$c.' '.$v;
                }
            }
        }
        $this->assertSame([], $errors);
    }
}
