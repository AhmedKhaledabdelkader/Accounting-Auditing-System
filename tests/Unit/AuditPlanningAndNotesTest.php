<?php

namespace Tests\Unit;

use App\Services\Audit\Materiality;
use App\Services\Audit\RevenueSampling;
use App\Services\Audit\SalesListReader;
use App\Services\Statements\NotesBuilder;
use App\Services\Statements\PriorNoteItems;
use App\Services\Statements\PriorPdf\RelatedParties;
use App\Services\Statements\PriorPdf\Sections;
use Tests\TestCase;

/** The materiality and revenue sample (steps 12–13), and the notes' fixes of 2026-11-21 (order, lettered parts, totals, merging, parties). */
class AuditPlanningAndNotesTest extends TestCase
{
    private static function sale(float $amount, string $kind = 'sale'): array
    {
        return ['date' => null, 'number' => null, 'party' => null, 'amount' => $amount, 'kind' => $kind];
    }

    public function test_the_sample_takes_every_key_item_and_draws_the_rest_by_monetary_unit(): void
    {
        $rows = [self::sale(50000), self::sale(60000)];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = self::sale(1000 + $i * 10);
        }
        $rows[] = self::sale(5000, 'return');
        $draw = RevenueSampling::draw($rows, 40000, 'high', 99);

        $reasons = array_column($draw['selected'], 'reason', 'index');
        $this->assertSame('key', $reasons[0]);
        $this->assertSame('key', $reasons[1]);
        $this->assertArrayNotHasKey(202, $reasons, 'a return is never sampled');
        // Rest: 200 sales adding up to 399,000; 399,000 × 3.0 ÷ 40,000 = 29.9 → 30 items.
        $this->assertSame(30, $draw['size']);
        $this->assertEqualsWithDelta(399000 / 30, $draw['interval'], 0.01);
        $this->assertSame(30, count(array_filter($reasons, fn ($r) => $r === 'mus')));
        // The same random start gives the same sample.
        $this->assertSame($draw['selected'], RevenueSampling::draw($rows, 40000, 'high', 99)['selected']);
    }

    public function test_a_population_smaller_than_the_sample_is_taken_whole(): void
    {
        $draw = RevenueSampling::draw([self::sale(100), self::sale(200), self::sale(300)], 40000, 'low', 1);

        $this->assertSame(['all', 'all', 'all'], array_column($draw['selected'], 'reason'));
    }

    public function test_every_sample_keeps_to_the_offices_limits(): void
    {
        // 12% to 25% of the invoices, never more than 50 nor fewer than 5 (nor more than there are).
        $this->assertSame([[3, 3], [5, 5], [5, 10], [12, 25], [36, 50], [50, 50]],
            array_map(fn ($n) => [RevenueSampling::limits($n)['min'], RevenueSampling::limits($n)['max']], [3, 20, 40, 100, 300, 1088]));

        // 400 invoices and 60 above performance materiality: the key items are all tested, outside the cap (the brief of
        // 2026-11-23); the cap and the floor hold n only: 340 left (340,000), n = 340,000 × 3 ÷ 40,000 = 26, raised to the floor 41.
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = self::sale($i < 60 ? 50000 + $i : 1000);
        }
        $draw = RevenueSampling::draw($rows, 40000, 'high', 3, 'strata_date');
        $this->assertSame(60, count(array_filter($draw['selected'], fn ($x) => $x['reason'] === 'key')));
        $this->assertSame([26, 41, 41, 50], [$draw['calculated_size'], $draw['executed_size'], $draw['limits']['min'], $draw['cap']]);
        $this->assertSame([4.82, 'high'], [$draw['achieved_cf'], $draw['achieved_level']]);

        // A small method's size raised to the minimum: 100 invoices, 12 at least.
        $few = array_map(fn () => self::sale(100), range(1, 100));
        $this->assertCount(12, RevenueSampling::draw($few, 40000, 'low', 3, 'mus')['selected']);
    }

    public function test_the_brief_example_asks_a_decision_above_the_cap_and_shows_the_confidence_achieved(): void
    {
        // The brief's revenue sample: key items 2,944,898.50 (50 of them), the rest 5,103,995.12, performance materiality 28,665.33.
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = self::sale(round(2944898.50 / 50, 2));
        }
        for ($i = 0; $i < 999; $i++) {
            $rows[] = self::sale(5109.10);
        }
        $rows[] = self::sale(round(5103995.12 - 999 * 5109.10, 2));
        $ask = RevenueSampling::draw($rows, 28665.33, 'high', 7);
        // n = 5,103,995.12 × 3 ÷ 28,665.33 = 534.2 → 535, above the cap of 50: a decision is asked, nothing drawn.
        $this->assertTrue($ask['needs_decision']);
        $this->assertSame([535, 50, 0.28], [$ask['calculated_size'], $ask['cap'], $ask['achieved_cf_at_cap']]);

        $capped = RevenueSampling::draw($rows, 28665.33, 'high', 7, 'mus', 3, 0.0, 'lower_assurance');
        $this->assertSame([50, 50, 0.28, 'below'], [$capped['keys']['count'], $capped['executed_size'], $capped['achieved_cf'], $capped['achieved_level']]);
        $full = RevenueSampling::draw($rows, 28665.33, 'high', 7, 'mus', 3, 0.0, 'full');
        $this->assertGreaterThanOrEqual(3.0, $full['achieved_cf'] + 0.1);   // n tested in full: the assurance asked for
        $this->assertSame('full', $full['cap_decision']);

        // An expected misstatement widens n: 5,103,995.12 × 3 ÷ (28,665.33 − 5,000 × 1.6).
        $this->assertSame((int) ceil(5103995.12 * 3 / (28665.33 - 8000)), RevenueSampling::draw($rows, 28665.33, 'high', 7, 'mus', 3, 5000.0)['calculated_size']);
    }

    public function test_materiality_is_rounded_down(): void
    {
        $this->assertSame(57000.0, Materiality::roundDown(57330.66));
        $this->assertSame(5700.0, Materiality::roundDown(5733.06));
        $this->assertSame(570.0, Materiality::roundDown(573.3));
    }

    public function test_a_sales_list_is_read_from_its_header_row_without_its_totals(): void
    {
        $read = SalesListReader::grid([
            ['مؤسسة لؤلؤة الطب'],
            ['رقم الفاتورة', 'تاريخ الفاتورة', 'اسم العميل', 'المبلغ قبل الضريبة', 'الإجمالي شامل الضريبة'],
            ['INV-1', '05/02/2023', 'عميل 1', '1,000.00', 1150],
            ['INV-2', 44960, 'عميل 2', 2000, 2300],
            ['RET-1', '01/03/2023', 'عميل 1', -300, -345],
            ['', '', 'الإجمالي', 2700, 3105],
        ]);

        $this->assertSame('invoices', $read['kind']);
        $this->assertSame('المبلغ قبل الضريبة', $read['columns']['amount']);
        $this->assertCount(3, $read['rows']);
        $this->assertSame(['2023-02-05', 'INV-1', 'عميل 1', 1000.0, 'sale', null, null, null], array_values($read['rows'][0]));
        $this->assertSame('2023-02-03', $read['rows'][1]['date']);   // an Excel date serial
        $this->assertSame(['amount' => 300.0, 'kind' => 'return'], array_intersect_key($read['rows'][2], ['amount' => 1, 'kind' => 1]));
    }

    public function test_a_sales_account_statement_takes_its_credits_as_sales_and_its_debits_as_returns(): void
    {
        $read = SalesListReader::grid([
            ['التاريخ', 'رقم القيد', 'البيان', 'مدين', 'دائن', 'الرصيد'],
            ['01/01/2023', '', 'رصيد افتتاحي', 0, 0, 0],
            ['02/01/2023', 'J-1', 'مبيعات نقدية', 0, 5000, 5000],
            ['03/01/2023', 'J-2', 'مردود مبيعات', 800, 0, 4200],
        ]);

        $this->assertSame('statement', $read['kind']);
        $this->assertSame([[5000.0, 'sale'], [800.0, 'return']], array_map(fn ($r) => [$r['amount'], $r['kind']], $read['rows']));
    }

    public function test_a_file_of_every_accounts_statement_keeps_every_account_and_names_the_revenue(): void
    {
        // As the client's system prints «كشوفات حساب الحسابات»: a block per account, the description away from its heading.
        $head = ['رصيد', '', 'دائن', '', '', 'مدين', '', 'الوصف', '', '', '', '', 'التاريخ', '', 'الرقم', '', '', 'الفرع'];
        $account = fn (string $name, string $number) => ['', '', '', $name, '', '', '', '', '', '', '', '', '', $number, '', '', '', ': الحساب'];
        $line = fn (float $credit, float $debit, string $text, $date = 45302, string $no = '1') => [1, 0, $credit, '', '', $debit, '', '', '', '', $text, '', $date, '', $no, '', '', '0001'];
        $read = SalesListReader::grid([
            $account('النقد في الصندوق', '110101010'), $head, $line(7600, 0, 'ايداع نقدي'),
            $account('ضريبة القيمة المضافة - لمخرجات إيرادات المبيعات', '210252010'), $head, $line(540, 0, 'ضريبة فاتورة'),
            $account('إيرادات بنود المبيعات - 1', '410101010'), $head,
            $line(67070.66, 0, 'رصيد افتتاحي', null, ''), $line(0, 67070.66, 'رصيد افتتاحي', null, ''),
            $line(3600, 0, 'فاتورة مبيعات', 45302, '9'), $line(17950, 0, 'فاتورة مبيعات', 45302, '10'),
            [21550, 0, 0, '', '', 67070.66, '', 'فقط واحد وعشرون ألف لا غير', '', '', '', '', '', '', '', 'اجمالي الحساب'],
            $account('مرتجع المبيعات', '410101015'), $head, $line(0, 480, 'مرتجع من الفاتورة رقم 8', 45305, '33'),
            $account('تكلفة البضاعة المباعة', '510101010'), $head, $line(0, 2000, 'تكلفة'),
        ]);

        $this->assertSame(['410101010' => 'sales', '410101015' => 'returns'], array_column(array_filter($read['accounts'], fn ($a) => $a['role'] !== null), 'role', 'number'));
        // The revenue: its sales and returns, for the revenue reconciliation.
        $revenue = array_values(array_filter($read['rows'], fn ($r) => in_array($r['kind'], ['sale', 'return'], true)));
        $this->assertSame([[3600.0, 'sale', 'فاتورة مبيعات', '2024-01-11'], [17950.0, 'sale', 'فاتورة مبيعات', '2024-01-11'], [480.0, 'return', 'مرتجع من الفاتورة رقم 8', '2024-01-14']],
            array_map(fn ($r) => [$r['amount'], $r['kind'], $r['party'], $r['date']], $revenue));
        // Every other account kept with its entries and their sides, for a sample on any account (the cost's debit).
        $cost = array_values(array_filter($read['rows'], fn ($r) => $r['account'] === '510101010'));
        $this->assertSame([['entry', 2000.0, 0.0]], array_map(fn ($r) => [$r['kind'], $r['debit'], $r['credit']], $cost));
    }

    public function test_the_notes_order_keeps_a_note_left_out_after_the_one_before_it(): void
    {
        $keys = ['a', 'b', 'c', 'd', 'e'];

        $this->assertSame($keys, NotesBuilder::order($keys, []));
        $this->assertSame(['a', 'd', 'b', 'c', 'e'], NotesBuilder::order($keys, ['a', 'd', 'b', 'c', 'e']));
        $this->assertSame(['e', 'a', 'b', 'c', 'd'], NotesBuilder::order($keys, ['e', 'a', 'x']));
    }

    public function test_lettered_parts_and_numbers_printed_with_a_comma_sit_inside_their_note(): void
    {
        // As a reading made before these rules stored them: «٣,٢» numbered «3,2», the letters as notes of their own.
        $section = fn (int $id, string $number, string $printed) => ['id' => $id, 'number' => $number, 'printed' => $printed, 'title' => '', 'page' => 1,
            'parent' => null, 'paragraphs' => [], 'tables' => [], 'slip' => false];
        $sections = [$section(0, '3', '٣'), $section(1, '3,2', '٣,٢'), $section(2, '11', '١١'), $section(3, 'أ', 'أ'), $section(4, 'ب', 'ب'), $section(5, '12', '١٢')];
        $nested = Sections::nestLetters($sections);

        $this->assertSame('3.2', Sections::number('٣,٢'));
        $this->assertSame(0, $nested[1]['parent']);
        $this->assertSame(2, $nested[3]['parent']);
        $this->assertSame(2, $nested[4]['parent']);
        $this->assertNull($nested[5]['parent']);
        $this->assertSame($nested, Sections::nestLetters($nested), 'idempotent');
    }

    public function test_a_total_is_never_a_note_line_and_accounts_merge_by_meaning(): void
    {
        $this->assertTrue(PriorNoteItems::isTotal('الإجمالي'));
        $this->assertTrue(PriorNoteItems::isTotal('المجموع ٢٠٢٢ م'));
        $this->assertFalse(PriorNoteItems::isTotal('أجور ورواتب ومافي حكمها'));

        $this->assertSame('government', PriorNoteItems::family('رسوم وتراخيص'));
        $this->assertSame('government', PriorNoteItems::family('مصروفات حكوميه'));
        $this->assertSame('utilities', PriorNoteItems::family('كهرباء ومياه وغاز'));
        $this->assertSame('travel', PriorNoteItems::family('سفر وإقامة'));
        $this->assertSame('eosb', PriorNoteItems::family('مصروف مكافأة نهاية الخدمة'));
        $this->assertSame('misc', PriorNoteItems::family('متنوعه'));
    }

    public function test_a_party_column_header_gives_the_name_and_the_role(): void
    {
        $this->assertSame(['name' => 'السيدة / فاطمة سامح رمضان الشوا', 'role' => 'شريك تشغيلي'], RelatedParties::fromHeader('السيدة / فاطمة سامح رمضان الشوا شريك تشغيلي'));
        $this->assertSame(['name' => 'شركة الواحة', 'role' => null], RelatedParties::fromHeader('شركة الواحة'));
    }
}
