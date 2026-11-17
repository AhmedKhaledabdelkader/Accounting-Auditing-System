<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\FinancialReport;
use App\Services\Statements\LineCatalogue;
use App\Services\Statements\PriorPdf\PageExtractor;
use App\Services\Statements\PriorPdf\PriorPdfReader;
use App\Services\Statements\PriorPdf\RelatedParties;
use App\Services\Statements\PriorStatementsPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeWire;
use Tests\Support\PdfFiles;
use Tests\TestCase;

/**
 * The related parties from last year's note (Phase 8, R15), in its own shape: one table with each party's relationship,
 * transactions, volume and balances, or tables apart (balances, transactions) with the relationship in the text. Each
 * party given its accounts in this year's trial balance (by name, else by last year's balance); this year's figures from
 * them, the volume from their movements; last year's balance from the trial balance's opening, else the note; without a
 * note, the accounts' names and, for one party without opening balances, the statement line. Made-up parties and figures.
 */
class PriorRelatedPartiesTest extends TestCase
{
    use ClassifiedRows, PdfFiles, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    private static function h(string $text, ?string $number = null): array
    {
        return ['type' => 'heading', 'number' => $number, 'text' => $text, 'columns' => [], 'rows' => []];
    }

    private static function t(array $headers, array $rows): array
    {
        return ['type' => 'table', 'number' => null, 'text' => null, 'columns' => array_map(fn ($h) => ['header' => $h], $headers),
            'rows' => array_map(fn ($r) => ['label' => $r[0], 'note' => $r[2] ?? null, 'cells' => $r[1]], $rows)];
    }

    private static function balanceSheet(?string $note = '9'): array
    {
        return [self::h('قائمة المركز المالي'), self::t(['2024', '2023'], [['نقد', ['150', '120']], ['إجمالي الأصول', ['150', '120']],
            ['مستحق لأطراف ذات علاقة', ['150', '120'], $note], ['إجمالي المطلوبات وحقوق الملكية', ['150', '120']]])];
    }

    /** One table: each party's relationship, transactions, their volume and its balances. */
    private static function oneTable(): array
    {
        return [self::h('الإيضاحات'), self::h('أطراف ذات علاقة', '9'), self::h('مستحق لأطراف ذات علاقة'),
            self::t(['طبيعة العلاقة', 'طبيعة المعاملات', 'حجم المعاملات', '2024م', '2023م'], [
                ['شركة الواحة للتجارة', ['شركة شقيقة', 'تمويل', '70', '120', '100']], ['خالد سالم العتيبي', ['شريك', 'سداد مصاريف', '15', '30', '20']],
                ['الإجمالي', [null, null, '85', '150', '120']]])];
    }

    /** Apart: the balances, the transactions; the relationship in the text. */
    private static function twoTables(): array
    {
        return [self::h('الإيضاحات'), self::h('المعاملات والأرصدة مع الأطراف ذات العلاقة', '9'),
            ['type' => 'paragraph', 'number' => null, 'text' => 'تتعامل الشركة مع شركة الواحة للتجارة (شركة شقيقة) ومع خالد سالم العتيبي (شريك).', 'columns' => [], 'rows' => []],
            self::h('أ) المعاملات مع الأطراف ذات العلاقة'), self::t(['2024م', '2023م'], [['شركة الواحة للتجارة', ['70', '40']], ['خالد سالم العتيبي', ['15', '10']]]),
            self::h('ب) أرصدة مستحقة لأطراف ذات علاقة'), self::t(['2024م', '2023م'], [['شركة الواحة للتجارة', ['120', '100']], ['خالد سالم العتيبي', ['30', '20']], ['', ['150', '120']]])];
    }

    private static function doc(array $notes, ?string $note = '9'): array
    {
        $page = fn (int $n, string $kind, array $blocks) => ['page' => $n, 'kind' => $kind, 'rotation' => 0, 'printed_page' => null, 'blocks' => PageExtractor::blocks(['blocks' => $blocks])];

        return PriorPdfReader::process([1 => $page(1, 'financial_position', self::balanceSheet($note)), 2 => $page(2, 'notes', $notes)]);
    }

    private static function parties(array $doc): array
    {
        return array_map(fn ($p) => [$p['name'], $p['relationship'], $p['nature'], $p['volume_py'], $p['balance_py'], $p['side']], RelatedParties::read(['document' => $doc])['parties']);
    }

    public function test_last_years_note_read_in_its_own_shape(): void
    {
        $this->assertEquals([['شركة الواحة للتجارة', 'شركة شقيقة', 'تمويل', 70, 120, 'due_to'], ['خالد سالم العتيبي', 'شريك', 'سداد مصاريف', 15, 30, 'due_to']],
            self::parties(self::doc(self::oneTable())));
        // Tables apart: the transactions' table gives the volume, the balances' table the balance; the text the relationship.
        $this->assertEquals([['شركة الواحة للتجارة', 'شركة شقيقة', null, 70, 120, 'due_to'], ['خالد سالم العتيبي', 'شريك', null, 15, 30, 'due_to']],
            self::parties(self::doc(self::twoTables())));
        // Text columns are words, never an amount misread.
        $this->assertSame([], array_values(array_filter(self::doc(self::oneTable())['checks'], fn ($c) => $c['code'] === 'unread')));
    }

    /**
     * A report with last year's PDF (its notes as given) and a trial balance of related-party accounts.
     *
     * @param list<array{0: string, 1: float, 2: float, 3: array{float, float}}> $accounts name, opening, closing, [period debit, credit] (credit balances)
     */
    private function report(array $notes, array $accounts, array $groups = ['opening', 'period', 'closing'], ?string $note = '9'): string
    {
        $rows = [$this->account(2, 'نقدية لدى البنوك', ['opening' => 120, 'closing' => 150])];
        foreach ($accounts as $i => [$name, $opening, $closing, $period]) {
            $rows[] = $this->account($i + 3, 'اطراف ذات علاقة دائنة', ['opening' => -$opening, 'closing' => -$closing, 'period' => $period], ['account_name' => $name]);
        }
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);
        $id = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $answers = [2 => self::balanceSheet($note), 3 => $notes];
        Http::fake([FakeWire::URLS[config('classification.provider')] => function (Request $request) use ($answers) {
            $body = $request->data();
            preg_match('/This is page (\d+)/', FakeWire::userText($body), $m);

            return Http::response(FakeWire::answer(FakeWire::providerOf($body), FakeWire::toolName($body), ['blocks' => $answers[(int) ($m[1] ?? 0)] ?? [], 'tables' => []]));
        }]);
        $path = $this->pdfFile(['<h1>قائمة المركز المالي</h1>', '<p>الإيضاحات</p>', '<p>.</p>']);
        $kinds = [['page' => 2, 'kind' => 'financial_position', 'rotation' => 0, 'printed_page' => null], ['page' => 3, 'kind' => 'notes', 'rotation' => 0, 'printed_page' => null]];
        $read = app(PriorStatementsPdf::class)->read($path, $kinds, 2024, new LineCatalogue('sa-ifrs-smes-3'));
        $file = ['name' => 'قوائم 2024.pdf', 'kind' => 'pdf', 'status' => 'ready', 'year' => 2024, 'uploaded_at' => now()->toIso8601String(), 'pages_total' => 3,
            'pages' => [], 'found' => [], 'blocks' => $read['blocks'], 'document' => $read['document'], 'expenses_shown_positive' => null];
        FinancialReport::find($id)->update(['comparative_start' => '2024-01-01', 'comparative_end' => '2024-12-31',
            'prior_figures' => ['source' => 'statements', 'file' => $file, 'rows' => [], 'columns' => [], 'restated' => false]]);

        return $id;
    }

    private function proposed(string $id): array
    {
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'related_parties');

        return array_map(fn ($p) => [$p['name'], $p['relationship'], $p['nature'], $p['rows'], $p['volume_cy'], $p['volume_py'], $p['balance_py']], $field['default']);
    }

    public function test_last_years_parties_take_their_accounts_and_this_years_figures(): void
    {
        // The company by its name (another spelling), the partner by his; a party new this year.
        $id = $this->report(self::oneTable(), [['الواحة للتجارة', 120, 200, [20, 100]], ['خالد العتيبي', 30, 10, [20, 0]], ['مؤسسة النور', 0, 50, [0, 50]]]);

        $this->assertEquals([['شركة الواحة للتجارة', 'شركة شقيقة', 'تمويل', [3], 100, 70, -120], ['خالد سالم العتيبي', 'شريك', 'سداد مصاريف', [4], 0, 15, -30],
            ['مؤسسة النور', null, null, [5], 50, null, null]], $this->proposed($id));
        // This year's balances from the accounts; last year's from their openings (the trial balance has them).
        $rows = $this->getJson(self::URL."/{$id}")->json('narrative.items.rp_to_name');
        $this->assertSame([['شركة الواحة للتجارة', 200, 120], ['خالد سالم العتيبي', 10, 30], ['مؤسسة النور', 50, 0]],
            array_map(fn ($r) => [$r['rp_to_name'], (int) $r['rp_to_cy']['amount'], (int) $r['rp_to_py']['amount']], $rows));
    }

    public function test_a_closing_only_trial_balance_takes_last_years_balances_from_the_note_and_matches_by_them(): void
    {
        // No opening columns, no movements: the second account has no name of the note's, but last year's balance... is
        // unknown without openings; it stays a new party, and the note's party is said to have no account.
        $id = $this->report(self::twoTables(), [['الواحة للتجارة', 0, 200, [0, 0]], ['حساب جاري ٧٧', 0, 10, [0, 0]]], ['closing']);

        $this->assertEquals([['شركة الواحة للتجارة', 'شركة شقيقة', null, [3], null, 70, -120], ['خالد سالم العتيبي', 'شريك', null, [], null, 15, -30],
            ['حساب جاري ٧٧', null, null, [4], null, null, null]], $this->proposed($id));
        $state = $this->getJson(self::URL."/{$id}")->json();
        [$check] = array_values(array_filter($state['checks'], fn ($c) => $c['code'] === 'related_parties_unmatched'));
        $this->assertSame(['warning', ['خالد سالم العتيبي']], [$check['severity'], $check['names']]);
        // Last year's balances from the note (no openings): its figures in the comparative column.
        $this->assertSame([120, 30], array_map(fn ($r) => (int) $r['rp_to_py']['amount'], array_slice($state['narrative']['items']['rp_to_name'], 0, 2)));
    }

    public function test_without_a_note_one_account_takes_the_statement_lines_balance(): void
    {
        // No related parties' note: the account's name; with no openings, last year's balance is the statement line's (2024).
        $id = $this->report([self::h('الإيضاحات'), self::h('النقد', '5')], [['شركة الواحة للتجارة', 0, 150, [0, 0]]], ['closing'], null);

        $this->assertEquals([['شركة الواحة للتجارة', null, null, [3], null, null, -150]], $this->proposed($id));
    }

    public function test_a_party_named_from_an_account_is_cleaned_and_flagged(): void
    {
        // The tree's dots, a role after «*» (the relationship), other text after «*» kept in the name.
        $id = $this->report([self::h('الإيضاحات'), self::h('النقد', '5')], [[' .  .  . سالم عمر الحربي*المدير المالي', 0, 150, [0, 0]],
            ['. . مؤسسة الفجر*فرع الشمال', 0, 20, [0, 0]], ['شركة الندى', 0, 5, [0, 0]]], ['closing'], null);

        $this->assertEquals([['سالم عمر الحربي', 'المدير المالي'], ['مؤسسة الفجر فرع الشمال', null], ['شركة الندى', null]],
            array_map(fn ($p) => [$p[0], $p[1]], $this->proposed($id)));
        $checks = array_values(array_filter($this->getJson(self::URL."/{$id}")->json('checks'), fn ($c) => $c['code'] === 'related_party_name_cleaned'));
        $this->assertSame([['warning', 'سالم عمر الحربي', 'المدير المالي', [3]], ['warning', 'مؤسسة الفجر فرع الشمال', null, [4]]],
            array_map(fn ($c) => [$c['severity'], $c['name'], $c['role'], $c['rows']], $checks));
        $this->assertStringContainsString('«سالم عمر الحربي»', $checks[0]['message_ar']);
        $this->assertStringContainsString('«المدير المالي»', $checks[0]['message_ar']);

        // Once the accountant saves the list: no longer flagged.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['related_parties' => [['name' => 'سالم عمر الحربي', 'relationship' => 'المدير المالي', 'nature' => 'تمويل', 'rows' => [3], 'volume_cy' => 0, 'volume_py' => 0]]]])->assertOk();
        $this->assertNotContains('related_party_name_cleaned', array_column($this->getJson(self::URL."/{$id}")->json('checks'), 'code'));
    }

    public function test_the_proposal_is_accepted_as_proposed_and_the_note_ties_to_its_line(): void
    {
        // No note last year; this year's openings from the approved statements (the line 150, the account 20): the party
        // is proposed with last year's balance owed to it (negative), and saving it as proposed is accepted.
        $id = $this->report([self::h('الإيضاحات'), self::h('النقد', '5')], [['شركة الواحة للتجارة', 20, 20, [0, 0]]], ['opening', 'closing'], null);
        $proposed = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'related_parties')['default'];
        $this->assertSame(-150, (int) $proposed[0]['balance_py']);

        // What the accountant adds: the relationship, the nature and the volumes (no movement columns to propose them from).
        $party = array_intersect_key($proposed[0], array_flip(['name', 'rows', 'balance_py'])) + ['relationship' => 'شركة شقيقة', 'nature' => 'تمويل', 'volume_cy' => 0, 'volume_py' => 0];
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['related_parties' => [$party]]])->assertOk()->json();

        // The note ties to its line in both years.
        $line = collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.due_to_related_parties');
        $rows = $state['narrative']['items']['rp_to_name'];
        $this->assertEquals([[150, 150]], array_map(fn ($r) => [$r['rp_to_cy']['amount'], $r['rp_to_py']['amount']], $rows));
        $this->assertEquals([150, 150], [$line['current']['amount'], $line['comparative']['amount']]);
        $this->assertNotContains('note_total_mismatch', array_column($state['checks'], 'code'));
    }

    public function test_a_partys_balance_last_year_not_given_is_missing_never_zero(): void
    {
        // No note last year and two parties: last year's line (150) can't be put on one, and neither party's share of it is
        // known. Their accounts had balances, so last year's figure and this year's (built on it) are missing, not 0 and
        // the year's movement alone.
        $id = $this->report([self::h('الإيضاحات'), self::h('النقد', '5')],
            [['شركة الواحة للتجارة', 100, 60, [40, 0]], ['شركة الندى', 30, 90, [0, 60]]], ['opening', 'period', 'closing'], null);
        $state = $this->getJson(self::URL."/{$id}")->json();
        $this->assertSame([['missing', 'missing'], ['missing', 'missing']],
            array_map(fn ($r) => [$r['rp_to_cy']['status'], $r['rp_to_py']['status']], $state['narrative']['items']['rp_to_name']));
        $this->assertNotContains('note_total_mismatch', array_column($state['checks'], 'code'));
        $this->assertContains('notes.related_parties', array_column($state['missing'], 'key'));

        // The accountant gives each party's balance: this year's = it + the year's movement, and the note ties to its line.
        $parties = array_map(fn ($p, $py) => array_intersect_key($p, array_flip(['name', 'rows'])) + ['balance_py' => $py, 'relationship' => 'شركة شقيقة', 'nature' => 'تمويل', 'volume_cy' => 0, 'volume_py' => 0],
            collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'related_parties')['default'], [-100, -50]);
        $state = $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['related_parties' => $parties]])->assertOk()->json();
        $this->assertEquals([[60, 100], [110, 50]], array_map(fn ($r) => [$r['rp_to_cy']['amount'], $r['rp_to_py']['amount']], $state['narrative']['items']['rp_to_name']));
        $line = collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.due_to_related_parties');
        $this->assertEquals([170, 150], [$line['current']['amount'], $line['comparative']['amount']]);
        $this->assertNotContains('note_total_mismatch', array_column($state['checks'], 'code'));
    }
}
