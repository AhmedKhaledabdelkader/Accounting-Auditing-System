<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Models\Company;
use App\Services\Statements\NotesTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * PR 11: the notes and their wording (plan §3.6, §4.2 steps 10–11, Q6, Q11, Q13, Q14): visibility, numbering and the
 * statements' references; the template's wording with its blocks and placeholders, and the user's own; going concern;
 * early application; the notes' tables and their prior year; checks 14, 20, 25, 26e, 27.
 */
class NotesTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** Cash, capital, payables, revenue and rent; opening and closing balances; a profit of 20. */
    private function rows(array $extra = []): array
    {
        return [
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 150]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'موردون', ['opening' => 0, 'closing' => -30]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -200]),
            $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 180]),
            ...$extra,
        ];
    }

    private function report(array $rows, array $groups = ['opening', 'closing'], array $body = []): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($rows, $groups)]);

        return $this->postJson(self::URL, $body + ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    private function state(string $id): array
    {
        return $this->getJson(self::URL."/{$id}")->assertOk()->json();
    }

    private function inputs(string $id, array $inputs): TestResponse
    {
        return $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => $inputs]);
    }

    private function notes(string $id, array $body): TestResponse
    {
        return $this->patchJson(self::URL."/{$id}/notes", $body);
    }

    private function note(array $state, string $key): array
    {
        return collect($state['notes'])->firstWhere('key', $key) ?? $this->fail("no note {$key}");
    }

    /** Shown notes: key → number. */
    private function numbers(array $state): array
    {
        return array_column(array_filter($state['notes'], fn ($n) => $n['visible']), 'number', 'key');
    }

    private function lineNote(array $state, string $line): ?int
    {
        return collect([...$state['statements']['financial_position'], ...$state['statements']['comprehensive_income']])->firstWhere('key', $line)['note'];
    }

    /** A note's text, paragraphs joined. */
    private function text(array $state, string $note): string
    {
        return implode("\n", array_column(array_filter($this->note($state, $note)['blocks'], fn ($b) => $b['type'] === 'text'), 'text_ar'));
    }

    /** A note's tables as text rows: cells joined by « | », amounts as shown (whole riyals). */
    private function tables(array $state, string $note): array
    {
        $out = [];
        foreach ($this->note($state, $note)['blocks'] as $b) {
            if ($b['type'] !== 'table') {
                continue;
            }
            foreach ($b['rows'] as $r) {
                $out[] = implode(' | ', array_map(fn ($c) => isset($c['amount'])
                    ? ($c['amount']['amount'] === null ? '[ناقص]' : (round($c['amount']['amount']) == 0 ? '-' : (string) round($c['amount']['amount'])))
                    : $c['text_ar'], $r['cells']));
            }
        }
        return $out;
    }

    private function checks(array $state, string $code): array
    {
        return array_values(array_filter($state['checks'], fn ($c) => $c['code'] === $code));
    }

    /* ---------- visibility, numbers, references (Q13) ---------- */

    public function test_zero_notes_are_hidden_and_the_rest_numbered_with_the_statements_in_step(): void
    {
        $id    = $this->report($this->rows([$this->account(7, 'مخزون بضاعة', ['opening' => 0, 'closing' => 0])]));
        $state = $this->state($id);

        $this->assertSame(['incorporation' => 1, 'basis' => 2, 'policies' => 3, 'cash' => 4, 'capital' => 5, 'trade_payables' => 6, 'revenue' => 7,
            'general_admin' => 8, 'financial_risk' => 9, 'subsequent_events' => 10, 'approval' => 11], $this->numbers($state));
        $this->assertFalse($this->note($state, 'inventory')['visible']);
        $this->assertSame([], $this->note($state, 'inventory')['blocks']);
        $this->assertSame([4, 5, 6, null], [$this->lineNote($state, 'sofp.cash'), $this->lineNote($state, 'sofp.share_capital'),
            $this->lineNote($state, 'sofp.trade_payables'), $this->lineNote($state, 'sofp.inventory')]);
        $this->assertSame([7, 8], [$this->lineNote($state, 'is.revenue'), $this->lineNote($state, 'is.general_admin')]);
        $this->assertSame(11, $state['narrative']['last_note_number']);
        $this->assertSame('4', $state['narrative']['values']['note_no_cash']);
        // The inventory policy follows its line (Q13).
        $this->assertFalse($state['narrative']['blocks']['policy_inventory']);

        // Forced on: numbered in its place, the rest move up; forced off with a balance: no number, check 27.
        $state = $this->notes($id, ['visibility' => ['inventory' => 'on', 'trade_payables' => 'off']])->assertOk()->json();
        $this->assertSame(['incorporation' => 1, 'basis' => 2, 'policies' => 3, 'inventory' => 4, 'cash' => 5, 'capital' => 6, 'revenue' => 7,
            'general_admin' => 8, 'financial_risk' => 9, 'subsequent_events' => 10, 'approval' => 11], $this->numbers($state));
        $this->assertSame(['forced_on', 'forced_off'], [$this->note($state, 'inventory')['visibility'], $this->note($state, 'trade_payables')['visibility']]);
        $this->assertSame([5, null], [$this->lineNote($state, 'sofp.cash'), $this->lineNote($state, 'sofp.trade_payables')]);
        $ref = $this->checks($state, 'note_reference_missing');
        $this->assertSame([['warning', 'sofp.trade_payables']], array_map(fn ($c) => [$c['severity'], $c['line_key']], array_values(array_filter($ref, fn ($c) => $c['line_key'] !== null))));
        $this->assertContains('note_reference_missing', collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.trade_payables')['check_codes']);

        // Back to automatic; a note shown always can't be hidden.
        $state = $this->notes($id, ['visibility' => ['inventory' => 'auto', 'trade_payables' => 'auto']])->assertOk()->json();
        $this->assertSame(6, $this->numbers($state)['trade_payables']);
        $this->assertSame(['visibility' => [], 'texts' => [], 'items' => [], 'ppe' => []], $state['note_options']);
        $this->notes($id, ['visibility' => ['capital' => 'off']])->assertStatus(422)->assertJsonValidationErrors('visibility.capital');
        $this->notes($id, ['visibility' => ['ppe' => 'maybe']])->assertStatus(422);
        $this->notes($id, [])->assertStatus(422);
        $this->notes((string) Str::uuid(), ['visibility' => ['ppe' => 'on']])->assertNotFound();
    }

    public function test_a_comparative_figure_alone_shows_a_note(): void
    {
        // Inventory sold out during the year: 0 now, 40 at the start.
        $state = $this->state($this->report([...$this->rows(), $this->account(7, 'مخزون بضاعة', ['opening' => 40, 'closing' => 0]),
            $this->account(8, 'نقدية بالصندوق', ['opening' => -40, 'closing' => 0])]));
        $this->assertTrue($this->note($state, 'inventory')['visible']);
        $this->assertSame([' | 2025م |  | 2024م', 'مخزون بضاعة | - |  | 40', ' | - |  | 40'], $this->tables($state, 'inventory'));
    }

    /* ---------- wording: placeholders, blocks, the company ---------- */

    public function test_the_wording_comes_from_the_template_and_the_company(): void
    {
        $company = Company::create(['name_ar' => 'شركة المثال التجارية', 'legal_form' => 'llc', 'city' => 'جدة', 'cr_number' => '4030000000',
            'cr_date_hijri' => '1/1/1440هـ', 'unified_number' => '7000000000', 'activity' => 'تجارة الجملة', 'head_office' => 'جدة - حي المثال',
            'branches' => ['فرع الدمام'], 'fiscal_year_end_month' => 12, 'fiscal_year_end_day' => 31]);
        $id    = $this->report($this->rows(), ['opening', 'closing'], ['company_id' => $company->id]);
        $state = $this->state($id);

        $this->assertStringContainsString('شركة المثال التجارية شركة ذات مسؤولية محدودة، مسجلة في المملكة العربية السعودية بمدينة جدة بموجب السجل التجاري رقم (4030000000) بتاريخ 1/1/1440هـ، والرقم الوطني الموحد (7000000000)، وللشركة الفروع التالية: فرع الدمام.',
            $this->text($state, 'incorporation'));
        $this->assertStringContainsString('تبدأ السنة المالية للشركة في 1 يناير وتنتهي في 31 ديسمبر.', $this->text($state, 'incorporation'));
        $this->assertNotContains('company', array_column(array_filter($state['missing'], fn ($m) => $m['key'] === 'notes.company'), 'key'));

        // Without a company: shown as missing, never made up.
        $other = $this->state($this->report($this->rows()));
        $this->assertStringContainsString('[ناقص] شركة ذات مسؤولية محدودة', $this->text($other, 'incorporation'));
        $company = collect($other['missing'])->firstWhere('key', 'notes.company');
        $this->assertSame(['key' => 'notes.company', 'reason' => 'company_required', 'input_key' => null], array_diff_key($company, ['fields' => 1]));
        $this->assertContains('name_ar', $company['fields']);

        // The approving body left empty: «الإدارة» (R3), suggested on the question and never required.
        $state = $this->inputs($id, ['approval.date' => '2026-02-15'])->assertOk()->json();
        $this->assertSame('اعتمدت هذه القوائم المالية من قبل الإدارة بتاريخ 15 فبراير 2026م.', $this->text($state, 'approval'));
        $this->assertNotContains('approval.body', $state['required_inputs']);
        $body = collect($this->getJson("/api/v1/financial-reports/{$id}/intake")->json('fields'))->firstWhere('key', 'approval.body');
        $this->assertSame(['الإدارة', false], [$body['default'] ?? null, $body['required'] ?? null]);

        // The approval note from the inputs; check 22 still reads the Gregorian date.
        $state = $this->inputs($id, ['approval.body' => 'الشركاء', 'approval.date_hijri' => '17 شعبان 1447هـ', 'approval.date' => '2026-02-15'])->assertOk()->json();
        $this->assertSame('اعتمدت هذه القوائم المالية من قبل الشركاء بتاريخ 17 شعبان 1447هـ الموافق 15 فبراير 2026م.', $this->text($state, 'approval'));
        $this->assertNotContains('approval.date', $state['required_inputs']);
        // The Hijri date is optional: without it, the Gregorian date alone, nothing missing.
        $state = $this->inputs($id, ['approval.date_hijri' => null])->assertOk()->json();
        $this->assertSame('اعتمدت هذه القوائم المالية من قبل الشركاء بتاريخ 15 فبراير 2026م.', $this->text($state, 'approval'));
        $this->assertNotContains('notes.approval', array_column($state['missing'], 'key'));
        $this->assertNotContains('approval.date_hijri', $state['required_inputs']);
    }

    public function test_early_application_before_2027_only(): void
    {
        $state = $this->state($this->report($this->rows()));
        $this->assertTrue($state['narrative']['blocks']['early_application']);
        $this->assertStringContainsString('قامت الشركة بالتطبيق المبكر للإصدار الثالث', $this->text($state, 'basis'));

        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult($this->rows(), ['opening', 'closing'])]);
        $later = $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'])->json();
        $this->assertFalse($later['narrative']['blocks']['early_application']);
        $this->assertStringNotContainsString('التطبيق المبكر', $this->text($later, 'basis'));
        $this->assertSame([' | 2027م |  | 2026م'], array_slice($this->tables($later, 'cash'), 0, 1));
    }

    public function test_first_application_note_and_its_questions(): void
    {
        $id = $this->report($this->rows());
        $state = $this->state($id);
        $this->assertFalse($this->note($state, 'first_application')['visible']);
        $this->assertContains('first_application.this_period', $state['required_inputs']);
        // While the note is hidden, the early-application paragraph doesn't refer to it (no check 27 to fix).
        $this->assertStringNotContainsString('ويبين الإيضاح رقم', $this->text($state, 'basis'));
        $this->assertSame([], $this->checks($state, 'note_reference_missing'));

        // The answer and the questions it opens, in one request.
        $state = $this->inputs($id, ['first_application.this_period' => true, 'first_application.effect' => 'none'])->assertOk()->json();
        $this->assertTrue($this->note($state, 'first_application')['visible']);
        $n = $this->numbers($state)['first_application'];
        $this->assertSame(3, $n);
        $this->assertStringContainsString("ويبين الإيضاح رقم ({$n}) أثر التطبيق.", $this->text($state, 'basis'));
        $this->assertStringContainsString('اعتبارًا من 1 يناير 2025م', $this->text($state, 'first_application'));
        $this->assertStringContainsString('ولم ينتج عن تطبيق الإصدار الثالث أثر جوهري', $this->text($state, 'first_application'));
        $this->assertSame([], $this->checks($state, 'note_reference_missing'));

        $this->inputs($id, ['first_application.effect' => 'effect'])->assertOk();
        $state = $this->state($id);
        $this->assertContains('first_application.effect_text', $state['required_inputs']);
        $state = $this->inputs($id, ['first_application.effect_text' => 'أدى تطبيق القسم 23 إلى تأجيل إيرادات بمبلغ 10 ريال.', 'first_application.reliefs' => 'عدم تعديل العقود المكتملة'])->assertOk()->json();
        $text = $this->text($state, 'first_application');
        $this->assertStringContainsString('أدى تطبيق القسم 23 إلى تأجيل إيرادات', $text);
        $this->assertStringContainsString('واستفادت الشركة من الإعفاءات التالية الواردة في أحكام التحول: عدم تعديل العقود المكتملة.', $text);
        $this->assertStringNotContainsString('ولم ينتج عن تطبيق', $text);
    }

    /* ---------- going concern (Q14) ---------- */

    public function test_going_concern_indicators_propose_the_paragraph(): void
    {
        // A loss of 300, accumulated losses of 300 on a capital of 100, liabilities above assets.
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 50]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'موردون', ['opening' => 0, 'closing' => -250]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -100]),
            $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 400]),
        ]);
        $state = $this->state($id);
        $this->assertSame([['net_loss', 300.0], ['accumulated_losses', 300.0], ['current_liabilities', 200.0], ['total_liabilities', 200.0]],
            array_map(fn ($i) => [$i['key'], (float) $i['amount']], $state['going_concern']));
        $checks = $this->checks($state, 'going_concern_indicator');
        $this->assertSame(['info', 'info', 'info', 'info'], array_column($checks, 'severity'));   // added by itself: nothing to acknowledge
        $line = collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.retained_earnings');
        $this->assertNotContains('going_concern_indicator', $line['check_codes']);   // no red mark on the line
        $this->assertNotContains('going_concern_indicator', array_column(array_filter($state['checks'], fn ($c) => $c['severity'] === 'warning'), 'code'));
        $this->assertStringContainsString('نظام الشركات', $checks[1]['message_ar']);
        // Included by itself with the amounts, flagged; the accountant may still exclude it (never blocking).
        $this->assertNotContains('going_concern.decision', $state['required_inputs']);
        $this->assertStringContainsString('أُضيفت فقرة الاستمرارية تلقائيًا', $checks[0]['message_ar']);
        $this->assertTrue($this->note($state, 'going_concern')['visible']);
        $this->assertStringStartsWith('خلال السنة، تكبدت الشركة صافي خسارة بمبلغ (300) ريال سعودي، وبلغت الخسائر المتراكمة كما في 31 ديسمبر 2025م مبلغ (300) ريال سعودي بما تجاوز نصف رأس المال، كما تجاوزت الالتزامات المتداولة الأصول المتداولة بمبلغ (200) ريال سعودي، وتجاوز إجمالي الالتزامات إجمالي الأصول بمبلغ (200) ريال سعودي. وتشير',
            $this->text($state, 'going_concern'));

        // Included, with the partners' own mitigation sentence.
        $state = $this->inputs($id, ['going_concern.decision' => 'include', 'going_concern.mitigation' => 'وقد التزم الشركاء بتقديم الدعم المالي اللازم،'])->assertOk()->json();
        $this->assertStringContainsString('وقد التزم الشركاء بتقديم الدعم المالي اللازم، وبناءً عليه', $this->text($state, 'going_concern'));

        // Excluded: a reason is required, and the note goes.
        $state = $this->inputs($id, ['going_concern.decision' => 'exclude'])->assertOk()->json();
        $this->assertFalse($this->note($state, 'going_concern')['visible']);
        $this->assertContains('going_concern.exclude_reason', $state['required_inputs']);
        $state = $this->inputs($id, ['going_concern.exclude_reason' => 'الشركاء ضخوا رأس مال بعد نهاية السنة.'])->assertOk()->json();
        $this->assertNotContains('going_concern.exclude_reason', $state['required_inputs']);

        // Accumulated losses alone (no loss this year): the paragraph starts «بلغت», not «وبلغت».
        $profit = $this->state($this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 500, 'closing' => 600]),
            $this->account(3, 'رأس المال', ['opening' => -1000, 'closing' => -1000]),
            $this->account(4, 'أرباح أو خسائر سنوات سابقة', ['opening' => 700, 'closing' => 700]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -100]),
            $this->account(6, 'موردون', ['opening' => -200, 'closing' => -200]),
        ]));
        $this->assertSame(['accumulated_losses'], array_column($profit['going_concern'], 'key'));
        $this->assertStringStartsWith('بلغت الخسائر المتراكمة كما في 31 ديسمبر 2025م', $this->text($profit, 'going_concern'));

        // No indicator: no question, no note.
        $healthy = $this->state($this->report($this->rows()));
        $this->assertSame([], $healthy['going_concern']);
        $this->assertFalse($this->note($healthy, 'going_concern')['visible']);
        $this->assertNotContains('going_concern.decision', array_column($this->getJson(self::URL."/{$healthy['id']}/intake")->json('fields'), 'key'));
    }

    /* ---------- tables, check 14 ---------- */

    public function test_item_tables_by_class_match_their_lines_both_years(): void
    {
        $state = $this->state($this->report($this->rows([
            $this->account(7, 'نقدية بالصندوق', ['opening' => 5, 'closing' => 10]),
            $this->account(8, 'أوراق دفع', ['opening' => -5, 'closing' => -10]),
        ])));
        $this->assertSame([' | 2025م |  | 2024م', 'نقدية لدى البنوك | 150 |  | 100', 'نقدية بالصندوق | 10 |  | 5', ' | 160 |  | 105'], $this->tables($state, 'cash'));
        $this->assertSame([' | 2025م |  | 2024م', 'موردون | 30 |  | -', 'أوراق دفع | 10 |  | 5', ' | 40 |  | 5'], $this->tables($state, 'trade_payables'));
        // An expense note lists natures (main classes); the income statement's comparative waits for a prior source.
        $this->assertSame([' | 2025م |  | 2024م', 'إيجارات | 180 |  | [ناقص]', 'الإجمالي | 180 |  | [ناقص]'], $this->tables($state, 'general_admin'));
        $this->assertSame([], $this->checks($state, 'note_total_mismatch'));
    }

    public function test_a_supplier_finance_account_with_a_balance_suggests_yes(): void
    {
        $field = fn (string $id) => collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'supplier_finance.has');
        // Payables only: «لا» suggested.
        $this->assertFalse($field($this->report($this->rows()))['default']);
        // An account of supplier finance with a balance: «نعم» suggested, nothing saved.
        $id = $this->report($this->rows([
            $this->account(7, 'موردون', ['opening' => 0, 'closing' => -40], ['account_name' => '. . تسهيلات موردين']),
            $this->account(8, 'نقدية لدى البنوك', ['opening' => 0, 'closing' => 40]),
        ]));
        $this->assertTrue($field($id)['default']);
        $this->assertArrayNotHasKey('supplier_finance.has', (array) $this->state($id)['inputs']);
    }

    public function test_other_assets_have_a_note_cost_less_amortisation(): void
    {
        $state = $this->state($this->report($this->rows([
            $this->account(7, 'مجمع إطفاء أصول أخرى', ['opening' => -40, 'closing' => -60]),
            $this->account(8, 'أصول أخرى', ['opening' => 100, 'closing' => 100]),
            $this->account(9, 'موردون', ['opening' => -60, 'closing' => -40]),
        ])));
        $line = collect($state['statements']['financial_position'])->firstWhere('key', 'sofp.other_assets');
        $this->assertTrue($this->note($state, 'other_assets')['visible']);
        $this->assertSame($this->numbers($state)['other_assets'], $line['note']);
        $this->assertSame([' | 2025م |  | 2024م', 'أصول أخرى | 100 |  | 100', 'مجمع إطفاء أصول أخرى | -60 |  | -40', ' | 40 |  | 60'],
            $this->tables($state, 'other_assets'));
        $this->assertSame([], $this->checks($state, 'note_total_mismatch'));
    }

    public function test_typed_prior_notes_are_checked_against_the_comparative_lines(): void
    {
        $id = $this->report($this->rows(), ['closing']);
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual',
            'figures' => ['sofp.cash' => 100, 'sofp.share_capital' => 100, 'is.revenue' => 50, 'is.general_admin' => -50],
            'notes' => ['cash' => ['نقدية لدى البنوك' => 70, 'نقدية بالصندوق' => 20], 'general_admin' => ['إيجارات' => 50]]])->assertOk()->json();

        $this->assertSame([' | 2025م |  | 2024م', 'نقدية لدى البنوك | 150 |  | 70', 'نقدية بالصندوق | - |  | 20', ' | 150 |  | 90'], $this->tables($state, 'cash'));
        $this->assertSame([' | 2025م |  | 2024م', 'إيجارات | 180 |  | 50', 'الإجمالي | 180 |  | 50'], $this->tables($state, 'general_admin'));
        $mismatch = $this->checks($state, 'note_total_mismatch');
        $this->assertSame([['comparative', 'sofp.cash', 100, 90, -10]], array_map(fn ($c) => [$c['scope'], $c['line_key'], $c['expected'], $c['actual'], $c['difference']], $mismatch));
        // Revenue: no typed table, so its prior year is missing (a draft only).
        $this->assertContains('[ناقص]', explode(' | ', $this->tables($state, 'revenue')[1]));
        $this->assertSame([], $state['comparative']['defaulted']['notes']);   // every class of this year typed
        // A class of this year left out of a typed table counts as 0, and the review lists it.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 100],
            'notes' => ['cash' => ['نقدية بالصندوق' => 20]]])->assertOk()->json();
        $this->assertSame(['cash' => ['نقدية لدى البنوك']], $state['comparative']['defaulted']['notes']);

        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 100], 'notes' => ['capital' => ['x' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('notes.capital');
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'none', 'notes' => ['cash' => ['x' => 1]]])->assertStatus(422);

        // A riyal's fraction is no mismatch: the note and the line print the same whole riyals.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual',
            'figures' => ['sofp.cash' => 100, 'sofp.share_capital' => 100, 'is.revenue' => 50, 'is.general_admin' => -50],
            'notes' => ['cash' => ['نقدية لدى البنوك' => 79.6, 'نقدية بالصندوق' => 20]]])->assertOk()->json();
        $this->assertSame([], $this->checks($state, 'note_total_mismatch'));
    }

    public function test_the_prior_year_of_the_movement_tables(): void
    {
        // End-of-service: 10 at the start, 5 charged, 2 paid.
        $rows = $this->rows([
            $this->account(7, 'مخصص نهاية الخدمة', ['opening' => -10, 'period' => [2, 5]]),
            $this->account(8, 'مصروف مكافأة نهاية الخدمة', ['period' => [5, 0]]),
            $this->account(9, 'نقدية بالصندوق', ['opening' => 10, 'period' => [0, 2]]),
        ]);
        $id    = $this->report($rows, ['opening', 'period', 'closing']);
        $state = $this->state($id);
        // No source: the closing of last year is this year's opening; its movements are missing (a missing row isn't 0, so it stays).
        $this->assertSame([' | 2025م |  | 2024م', 'الرصيد في أول المدة | 10 |  | [ناقص]', 'المحمل على المصاريف خلال الفترة | 5 |  | [ناقص]',
            'المدفوع خلال الفترة | 2 |  | [ناقص]', 'رد مخصص انتفى الغرض منه | - |  | [ناقص]', 'الرصيد في آخر المدة | 13 |  | 10'], $this->tables($state, 'employee_benefits'));

        // Typed from last year's notes (manual): opening 4, charge 6, nothing paid; closing 10 = this year's opening.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 100, 'sofp.share_capital' => 100],
            'schedules' => ['employee_benefits.opening' => 4, 'employee_benefits.charge' => 6]])->assertOk()->json();
        $this->assertSame(['الرصيد في أول المدة | 10 |  | 4', 'المحمل على المصاريف خلال الفترة | 5 |  | 6', 'المدفوع خلال الفترة | 2 |  | -', 'الرصيد في آخر المدة | 13 |  | 10'],
            array_slice($this->tables($state, 'employee_benefits'), 1));
        $this->assertSame([], array_filter($this->checks($state, 'provision_rollforward'), fn ($c) => $c['scope'] === 'comparative'));
        // The rows not typed count as 0, and the review lists them.
        $this->assertSame(['employee_benefits.paid', 'employee_benefits.reversal'], $state['comparative']['defaulted']['schedules']);

        // A roll that doesn't reach the closing: checked.
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 100, 'sofp.share_capital' => 100],
            'schedules' => ['employee_benefits.opening' => 4, 'employee_benefits.charge' => 5]])->assertOk()->json();
        $roll = array_values(array_filter($this->checks($state, 'provision_rollforward'), fn ($c) => $c['scope'] === 'comparative'));
        $this->assertSame([[10, 9, -1]], array_map(fn ($c) => [$c['expected'], $c['actual'], $c['difference']], $roll));
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 100], 'schedules' => ['employee_benefits.closing' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('schedules.employee_benefits.closing');
    }

    public function test_periodic_stock_builds_up_the_cost_of_goods_sold(): void
    {
        // Periodic system: opening stock 40 and closing stock 60 in cost of revenue; purchases 300, freight 20.
        $state = $this->state($this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 60, 'closing' => 0]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'مخزون بضاعة', ['opening' => 40, 'closing' => 60]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -500]),
            $this->account(6, 'مخزون أول المدة', ['opening' => 0, 'closing' => 40]),
            $this->account(7, 'تكلفة المشتريات', ['opening' => 0, 'closing' => 300]),
            $this->account(8, 'مصاريف نقل المشتريات', ['opening' => 0, 'closing' => 20]),
            $this->account(9, 'مخزون آخر المدة', ['opening' => 0, 'closing' => -60]),
            $this->account(10, 'أجور ورواتب', ['opening' => 0, 'closing' => 300], ['existing_classification' => 'تكلفة المبيعات']),
            $this->account(11, 'موردون', ['opening' => 0, 'closing' => -60]),
        ]));
        $this->assertTrue($state['narrative']['blocks']['cost_periodic_inventory']);
        $this->assertSame([' | 2025م |  | 2024م', 'مخزون أول المدة | 40 |  | [ناقص]', 'المشتريات | 300 |  | [ناقص]', 'مصاريف الشراء والاستيراد | 20 |  | [ناقص]',
            'يخصم: مخزون آخر المدة | -60 |  | [ناقص]', 'تكلفة البضاعة المباعة | 300 |  | [ناقص]',
            ' | 2025م |  | 2024م', 'تكلفة البضاعة المباعة | 300 |  | [ناقص]', 'اجور و رواتب و مافي حكمها | 300 |  | [ناقص]', 'الإجمالي | 600 |  | [ناقص]'],
            $this->tables($state, 'cost_of_revenue'));
        $this->assertSame([], $this->checks($state, 'note_total_mismatch'));
    }

    /* ---------- capital (check 20), related parties (26e) ---------- */

    public function test_the_partners_must_add_up_to_the_capital(): void
    {
        $id = $this->report($this->rows());
        $state = $this->inputs($id, ['partners' => [['name' => 'أحمد', 'shares' => 6, 'share_value' => 10], ['name' => 'سارة', 'shares' => 3, 'share_value' => 10]]])->assertOk()->json();
        $check = $this->checks($state, 'capital_note_mismatch');
        $this->assertSame([[100, 90, -10]], array_map(fn ($c) => [$c['expected'], $c['actual'], $c['difference']], $check));
        $this->assertStringContainsString('يبلغ رأس مال الشركة 100 ريال سعودي مقسم إلى تسع (9) حصص، قيمة كل حصة 10 ريال سعودي', $this->text($state, 'capital'));
        $this->assertSame(['الإجمالي ريال سعودي |  | نسبة الحصة |  | قيمة الحصة الواحدة |  | عدد الحصص | الاسم', '60 |  | 66.67% |  | 10 |  | 6 | أحمد',
            '30 |  | 33.33% |  | 10 |  | 3 | سارة', '90 |  | 100% |  |  |  | 9 | الإجمالي'], array_map(fn ($r) => implode(' | ', array_reverse(explode(' | ', $r))), $this->tables($state, 'capital')));

        // The questions no longer asked are accepted and ignored (deprecated), never stored.
        $state = $this->inputs($id, ['partners' => [['name' => 'أحمد', 'shares' => 7, 'share_value' => 10], ['name' => 'سارة', 'shares' => 3, 'share_value' => 10]],
            'capital.shares_in_words' => 'عشر', 'dividends.proposed' => 50])->assertOk()->json();
        $this->assertArrayNotHasKey('capital.shares_in_words', (array) $state['inputs']);
        $this->assertArrayNotHasKey('dividends.proposed', (array) $state['inputs']);
        $this->assertStringContainsString('مقسم إلى عشر (10) حصص', $this->text($state, 'capital'));
        $this->assertSame([], $this->checks($state, 'capital_note_mismatch'));
        $this->inputs($id, ['partners' => [['name' => 'أحمد', 'shares' => 0, 'share_value' => 10]]])->assertStatus(422)->assertJsonValidationErrors('inputs.partners');
        $this->inputs($id, ['partners' => [['name' => 'أحمد', 'shares' => 7]]])->assertStatus(422);
        $this->inputs($id, ['partners' => 'أحمد'])->assertStatus(422);
    }

    public function test_related_parties_are_proposed_from_their_accounts_and_confirmed(): void
    {
        $id = $this->report($this->rows([
            $this->account(7, 'اطراف ذات علاقة مدينة', ['opening' => 0, 'closing' => 25], ['account_name' => 'شركة النخبة']),
            $this->account(8, 'اطراف ذات علاقة دائنة', ['opening' => -10, 'closing' => -5], ['account_name' => 'مؤسسة الريان']),
            $this->account(9, 'عملاء', ['opening' => 10, 'closing' => -20], ['account_name' => 'شركة النخبة - مبيعات']),
        ]));
        $state = $this->state($id);
        $field = collect($this->getJson(self::URL."/{$id}/intake")->json('fields'))->firstWhere('key', 'related_parties');
        $this->assertSame([[7], [8]], array_column($field['default'], 'rows'));
        $this->assertSame(['شركة النخبة', 'مؤسسة الريان'], array_column($field['default'], 'name'));
        $this->assertContains('related_parties', $state['required_inputs']);
        // The proposals show until confirmed; 26e: the same name in another account.
        $same = $this->checks($state, 'same_party_multiple_accounts');
        $this->assertSame([['info', [7, 9]]], array_map(fn ($c) => [$c['severity'], $c['rows']], $same));

        $state = $this->inputs($id, ['related_parties' => [
            ['name' => 'شركة النخبة', 'relationship' => 'شركة شقيقة', 'nature' => 'مبيعات', 'rows' => [7], 'volume_cy' => 120, 'volume_py' => 80],
            ['name' => 'مؤسسة الريان', 'relationship' => 'مملوكة لشريك', 'nature' => 'تمويل', 'rows' => [8], 'volume_cy' => 0, 'volume_py' => 15],
        ]])->assertOk()->json();
        $this->assertSame(['البيان | طبيعة العلاقة | طبيعة المعاملات | حجم المعاملات 2025م | حجم المعاملات 2024م | 2025م |  | 2024م',
            'شركة النخبة | شركة شقيقة | مبيعات | 120 | 80 | 25 |  | -', 'الإجمالي |  |  |  |  | 25 |  | -',
            'البيان | طبيعة العلاقة | طبيعة المعاملات | حجم المعاملات 2025م | حجم المعاملات 2024م | 2025م |  | 2024م',
            'مؤسسة الريان | مملوكة لشريك | تمويل | - | 15 | 5 |  | 10', 'الإجمالي |  |  |  |  | 5 |  | 10'], $this->tables($state, 'related_parties'));
        $this->assertStringContainsString(($this->numbers($state)['related_parties']).'/أ مستحق من أطراف ذات علاقة', $this->text($state, 'related_parties'));
        $this->assertSame([], $this->checks($state, 'note_total_mismatch'));
        $this->assertNotContains('related_parties', $state['required_inputs']);

        // An account left out: the table doesn't reach its line (check 14).
        $state = $this->inputs($id, ['related_parties' => [['name' => 'شركة النخبة', 'relationship' => 'شركة شقيقة', 'nature' => 'مبيعات', 'rows' => [7], 'volume_cy' => 120, 'volume_py' => 80]]])->assertOk()->json();
        $this->assertSame([['current', 'sofp.due_to_related_parties', 5, 0]], array_map(fn ($c) => [$c['scope'], $c['line_key'], $c['expected'], $c['actual']],
            array_values(array_filter($this->checks($state, 'note_total_mismatch'), fn ($c) => $c['scope'] === 'current'))));
        $this->inputs($id, ['related_parties' => [['name' => 'شركة النخبة', 'relationship' => 'شقيقة', 'nature' => 'مبيعات', 'rows' => ['سبعة'], 'volume_cy' => 1, 'volume_py' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('inputs.related_parties');
    }

    /* ---------- policies and the conditional wording ---------- */

    public function test_policies_follow_their_lines_and_the_answers(): void
    {
        $id = $this->report($this->rows([
            $this->account(7, 'قروض طويلة الأجل', ['opening' => 0, 'closing' => -40]),
            $this->account(8, 'نقدية بالصندوق', ['opening' => 0, 'closing' => 40]),
        ]));
        $state = $this->state($id);
        $blocks = $state['narrative']['blocks'];
        // Murabaha loans: the financing classification sentence, and no "no interest-bearing debt" sentence in the risk note.
        $this->assertSame([true, false, false, true, false], [$blocks['cf_murabaha_finance_cost'], $blocks['risk_no_interest_bearing'], $blocks['cf_overdraft_in_cash'],
            $blocks['risk_no_foreign_currency'], $blocks['policy_ppe']]);
        $this->assertStringContainsString('تُصنَّف تكاليف التمويل المدفوعة على قروض عقود المرابحة ضمن الأنشطة التمويلية', $this->text($state, 'policies'));
        $this->assertStringNotContainsString('خاضعة للفائدة', $this->text($state, 'financial_risk'));
        $this->assertStringContainsString('والمطبقة بصورة متسقة على جميع الفترات المعروضة', $this->text($state, 'policies'));
        // Labour-law end-of-service by default; revenue policies from the answer.
        $this->assertTrue($blocks['eosb_labour_law']);
        $state = $this->inputs($id, ['policies.revenue' => ['goods'], 'policies.employee_benefits' => 'actuarial', 'policies.subsidiaries' => true])->assertOk()->json();
        $this->assertSame([true, false, true, false, true], [$state['narrative']['blocks']['revenue_goods'], $state['narrative']['blocks']['revenue_services'],
            $state['narrative']['blocks']['eosb_actuarial'], $state['narrative']['blocks']['eosb_labour_law'], $state['narrative']['blocks']['policy_subsidiaries']]);
        $this->inputs($id, ['policies.revenue' => ['goods', 'goods']])->assertStatus(422);
        $this->inputs($id, ['policies.revenue' => ['shipping']])->assertStatus(422);

        // Forced: the subsidiaries policy off despite the answer; the PPE policy on with no PPE.
        $state = $this->notes($id, ['visibility' => ['policy_subsidiaries' => 'off', 'policy_ppe' => 'on']])->assertOk()->json();
        $this->assertSame([false, true], [$state['narrative']['blocks']['policy_subsidiaries'], $state['narrative']['blocks']['policy_ppe']]);
    }

    public function test_the_headings_of_the_basis_and_the_policies_are_numbered_under_their_note(): void
    {
        $state = $this->state($this->report($this->rows()));
        foreach (['basis', 'policies'] as $key) {
            $note = $this->note($state, $key);
            $numbers = array_values(array_filter(array_column($note['blocks'], 'number')));
            // «N-1», «N-2» … in order, counting only the headings shown (no PPE here: its policy goes, and no gap is left).
            $this->assertSame(array_map(fn ($k) => "{$note['number']}-{$k}", range(1, count($numbers))), $numbers, $key);
        }
        $headings = array_column(array_filter($this->note($state, 'policies')['blocks'], fn ($b) => ($b['number'] ?? null) !== null), 'text_ar');
        $this->assertSame('تصنيف ما هو متداول مقابل ما هو غير متداول', $headings[0]);
        $this->assertNotContains('الممتلكات والآلات والمعدات', $headings);
        $this->assertNotContains('الموجودات', $headings); // a heading under a policy stays unnumbered
        $this->assertCount(3, array_filter(array_column($this->note($state, 'basis')['blocks'], 'number')));
    }

    public function test_first_year_and_restated_comparatives(): void
    {
        $id = $this->report($this->rows(), ['closing']);   // with opening balances a first year isn't applied
        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year'])->assertOk()->json();
        $this->assertStringContainsString('وهي أول قوائم مالية للشركة', $this->text($state, 'policies'));
        $this->assertStringNotContainsString('بصورة متسقة على جميع الفترات', $this->text($state, 'policies'));
        $this->assertSame([' | 2025م', 'نقدية لدى البنوك | 150', ' | 150'], $this->tables($state, 'cash'));   // one year

        $state = $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 100, 'sofp.share_capital' => 100],
            'restated' => true, 'restatement_note' => 'أعيد تصنيف أرصدة النقد لتصحيح خطأ.'])->assertOk()->json();
        $this->assertTrue($this->note($state, 'restatement')['visible']);
        $this->assertSame(3, $this->numbers($state)['restatement']);
        $this->assertSame('أعيد تصنيف أرصدة النقد لتصحيح خطأ.', $this->text($state, 'restatement'));
    }

    /* ---------- the user's wording (Q11) ---------- */

    public function test_a_reworded_paragraph_is_kept_and_flagged_when_its_template_text_moves(): void
    {
        $id = $this->report($this->rows());
        $this->inputs($id, ['approval.body' => 'الشركاء', 'approval.date_hijri' => '1 رمضان 1447هـ', 'approval.date' => '2026-02-18'])->assertOk();
        $state = $this->notes($id, ['texts' => ['approval.1' => 'اعتمد الشركاء هذه القوائم المالية في 18 فبراير 2026م.']])->assertOk()->json();
        $p = collect($this->note($state, 'approval')['blocks'])->firstWhere('key', 'approval.1');
        $this->assertSame([true, false, 'اعتمد الشركاء هذه القوائم المالية في 18 فبراير 2026م.'], [$p['edited'], $p['outdated'], $p['text_ar']]);
        $this->assertStringContainsString('الموافق 18 فبراير 2026م', $p['template_ar']);
        $this->assertSame([], $this->checks($state, 'edited_text_outdated'));

        // The approval date changes: the template's wording moved, the user's stays and is flagged.
        $state = $this->inputs($id, ['approval.date' => '2026-02-20'])->assertOk()->json();
        $p = collect($this->note($state, 'approval')['blocks'])->firstWhere('key', 'approval.1');
        $this->assertSame([true, 'اعتمد الشركاء هذه القوائم المالية في 18 فبراير 2026م.'], [$p['outdated'], $p['text_ar']]);
        $this->assertSame(['approval.1'], array_column($this->checks($state, 'edited_text_outdated'), 'paragraph'));

        // Back to the template's text.
        $state = $this->notes($id, ['texts' => ['approval.1' => null]])->assertOk()->json();
        $this->assertSame('اعتمدت هذه القوائم المالية من قبل الشركاء بتاريخ 1 رمضان 1447هـ الموافق 20 فبراير 2026م.', $this->text($state, 'approval'));
        $this->assertSame([], $this->checks($state, 'edited_text_outdated'));

        $this->notes($id, ['texts' => ['approval.9' => 'x y z']])->assertStatus(422)->assertJsonValidationErrors('texts.approval.9');
        $this->notes($id, ['texts' => ['going_concern.1' => 'نص']])->assertStatus(422);   // not shown now
        $this->notes($id, ['texts' => ['approval.1' => '  ']])->assertStatus(422);
    }

    /* ---------- the template, whole ---------- */

    public function test_every_note_block_and_placeholder_of_the_template_is_filled(): void
    {
        $template = NotesTemplate::load('sa-ifrs-smes-3');
        $config   = config('financial_statements.templates.sa-ifrs-smes-3.notes');
        // The config's notes are the template's, in order, with the same blocks.
        $this->assertCount(count($config), $template['notes']);
        foreach (array_values($config) as $i => $cfg) {
            $this->assertSame($cfg['block'] ?? null, $template['notes'][$i]['block'], array_keys($config)[$i]);
        }
        foreach ($config as $key => $cfg) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __("statements.notes.{$key}", [], 'ar'), $key);
        }

        $state = $this->state($this->report($this->rows()));
        $this->assertEqualsCanonicalizing($template['blocks'], array_keys($state['narrative']['blocks']));
        $placeholders = [];
        foreach ($template['notes'] as $n) {
            foreach ($n['items'] as $item) {
                preg_match_all('/\$\{([a-z0-9_]+)\}/', $item['type'] === 'paragraph' ? $item['text'] : implode(' ', array_merge(...$item['rows'])), $m);
                $placeholders = [...$placeholders, ...$m[1]];
            }
        }
        $itemKeys = array_merge(...array_map(fn ($list) => array_keys($list[0] ?? []), array_values($state['narrative']['items'])));
        $known = [...array_keys($state['narrative']['values']), ...array_keys($state['narrative']['items']), ...$itemKeys];
        // Item placeholders of empty lists have no set: the list's own key names them by prefix.
        $prefixes = array_map(fn ($k) => preg_replace('/_(label|name)$/', '_', $k), array_keys($state['narrative']['items']));
        // A repeated column's placeholders (the PPE categories) are numbered per set (`x__1`, …); with no set, its own key
        // names them: `ppe_category` and the category's cells, `ppe_cy_…_c` / `ppe_py_…_c`.
        $column = fn (string $p) => array_key_exists($p, $state['narrative']['columns']) || preg_match('/^ppe_(cy|py)_[a-z_]+_c$/', $p) === 1;
        $unknown = array_values(array_filter(array_unique($placeholders), fn ($p) => ! in_array($p, $known, true) && ! $column($p)
            && ! array_filter($prefixes, fn ($x) => str_starts_with($p, $x))));
        $this->assertSame([], $unknown);
    }

    public function test_profit_or_loss_in_a_label_follows_the_figures_sign(): void
    {
        // A loss of 300 this year, a first year: the result lines speak of a loss, and say so.
        $id = $this->report([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'closing' => 50]),
            $this->account(3, 'رأس المال', ['opening' => -100, 'closing' => -100]),
            $this->account(4, 'موردون', ['opening' => 0, 'closing' => -250]),
            $this->account(5, 'إيرادات المبيعات', ['opening' => 0, 'closing' => -100]),
            $this->account(6, 'إيجارات', ['opening' => 0, 'closing' => 400]),
        ]);
        $this->putJson(self::URL."/{$id}/prior-year", ['source' => 'first_year', 'skip_opening' => true])->assertOk();
        $state = $this->state($id);
        $label = fn (string $st, string $key) => collect($state['statements'][$st])->firstWhere('key', $key)['label_ar'];

        $this->assertSame('صافي الخسارة للسنة', $label('comprehensive_income', 'is.net_profit'));
        $this->assertSame('مجمل الربح', $label('comprehensive_income', 'is.gross_profit'));   // revenue 100, no cost: a profit
        // OCI is derived (no reserve), so the total is known and named by its sign.
        $this->assertSame('إجمالي الخسارة الشاملة للسنة', $label('comprehensive_income', 'is.total_comprehensive_income'));
        $this->assertSame('خسائر متراكمة', $label('financial_position', 'sofp.retained_earnings'));
        $this->assertSame('صافي الخسارة للسنة', collect($state['statements']['changes_in_equity']['current'])->firstWhere('key', 'net_profit')['label_ar']);
        $said = collect($state['checks'])->where('code', 'label_by_sign')->pluck('message_ar')->all();
        $this->assertContains('كُتب «صافي الدخل (الخسارة) للسنة» بصيغة «صافي الخسارة للسنة» بحسب إشارة الرقم.', $said);
    }

    public function test_profit_or_loss_wording_by_the_years_signs(): void
    {
        $label = fn (string $l, array $a) => \App\Services\Statements\ProfitLossWording::label($l, $a)['label'];
        // Last year's line kept, its sign this year's: a profit; a profit and a loss in a two-year table: both words.
        $this->assertSame('صافي الربح قبل الزكاة', $label('صافي الخسارة قبل الزكاة', [100, 50]));
        $this->assertSame('صافي الربح (الخسارة) قبل الزكاة', $label('صافي الخسارة قبل الزكاة', [100, -50]));
        $this->assertSame('صافي خسارة السنة', $label('صافي ربح السنة', [-10]));
        $this->assertSame('صافي خسارة العام قبل الزكاة', $label('صافي ربح ( خسارة ) العام قبل الزكاة', [-1, -2]));
        $this->assertSame('صافي الدخل (الخسارة) للسنة', $label('صافي الدخل (الخسارة) للسنة', [5, -3]));
        $this->assertSame('الخسائر المتراكمة', $label('الأرباح المبقاة', [-3]));
        // Never a tax, never a line of nothing.
        $this->assertSame('ضريبة الدخل', $label('ضريبة الدخل', [-3]));
        $this->assertSame('صافي الخسارة للسنة', $label('صافي الخسارة للسنة', [0, null]));
        // This year's figure still missing, last year a loss: the sign isn't known, both words stay (not «الخسارة» alone).
        $this->assertSame('إجمالي الدخل (الخسارة) الشامل للسنة', $label('إجمالي الدخل (الخسارة) الشامل للسنة', [NAN, -774]));
        $this->assertSame('صافي الربح (الخسارة) قبل الزكاة', $label('صافي الخسارة قبل الزكاة', [NAN, -5]));
    }
}
