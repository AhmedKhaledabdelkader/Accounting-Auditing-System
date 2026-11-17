<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** "-" means zero in amount columns; a user-confirmed mapping is never blocked by a measurement error. */
class ZeroMarkersAndOverridesTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->useModel('test-model');
    }

    /** Most amount cells are "-", as in real accounting exports. */
    private function dashFile()
    {
        return $this->trialBalance(array_map(fn ($i) => ['110'.$i, "صندوق {$i}", '-', '—', 100 * $i, '-', 100 * $i, ' - '], range(1, 8)));
    }

    private function labels(): array
    {
        return array_map(fn ($r) => $this->account($r, 'نقدية بالصندوق'), range(2, 13));
    }

    public function test_dash_zero_markers_pass_the_checks_and_extract_as_zero_without_flags(): void
    {
        $this->fakeLlm($this->standardStructure(), $this->labels());

        $response = $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->dashFile()])->assertOk();

        $this->assertSame([], $response->json('meta.warnings'));   // no amount_numeric doubt either
        foreach ($response->json('data') as $i => $row) {
            $this->assertSame([0, 0, 100 * ($i + 1), 0, 100 * ($i + 1), 0], [$row['opening_debit'], $row['opening_credit'], $row['period_debit'], $row['period_credit'], $row['closing_debit'], $row['closing_credit']]);
            $this->assertSame([], $row['data_flags']);
        }
    }

    /** Pauses on a duplicate mapping (blocking), so the tests can confirm a mapping of their choice. Column I holds remarks. */
    private function pausedRun(): string
    {
        $this->fakeLlm($this->standardStructure(['columns' => ['closing_debit' => 'B']]), $this->labels());

        return $this->postJson('/api/v1/classify/jobs', ['file' => $this->xlsxUpload([[...self::FULL_HEADER, 'ملاحظات'],
            ...array_map(fn ($i) => ["110{$i}", "صندوق {$i}", 5 * $i, 0, 10 * $i, 0, 15 * $i, 0, "تمت المطابقة"], range(1, 12)),   // ≥ 10 rows: enough for the swap checks
        ])])->json('id');
    }

    private function mapping(array $columns = []): array
    {
        $s = $this->standardStructure();

        return ['header_rows' => $s['header_rows'], 'first_data_row' => $s['first_data_row'], 'columns' => $columns + $s['columns']];
    }

    public function test_confirmed_swap_needs_confirm_anyway_then_runs_with_a_severe_warning_first(): void
    {
        config(['classification.log_channel' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $id      = $this->pausedRun();
        $swapped = $this->mapping(['closing_debit' => 'H', 'closing_credit' => 'G']);   // debit under the credit header

        $doubts = $this->postJson("/api/v1/classify/jobs/{$id}/structure", $swapped)
            ->assertStatus(422)->assertJsonPath('needs_acknowledgement', true)->json('doubts');
        $this->assertNotEmpty($doubts);
        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'needs_confirmation');

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $swapped + ['acknowledge_doubts' => true])->assertStatus(202);

        $warnings = $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')->json('result.meta.warnings');
        $checks   = collect($handler->getRecords())->firstWhere('message', 'structure_confirmed')->context['errors_overridden'];
        $this->assertContains('header_contradiction', $checks);
        $this->assertContains('sides_swapped', $checks);
        $this->assertStringStartsWith('⛔ أكّدت قراءة الأعمدة رغم أن الأرقام تشير إلى:', $warnings[0]);   // before everything else
        $this->assertStringEndsWith('إن كان هذا التنبيه صحيحًا، فجميع الأرصدة في النتيجة خاطئة.', $warnings[0]);
        $this->assertNotEmpty(array_filter($warnings, fn ($w) => str_starts_with($w, 'أكّدت قراءة الأعمدة رغم هذا التنبيه:')));   // header_contradiction: normal
        $this->assertSame(count($doubts), count(array_filter($warnings, fn ($w) => str_contains($w, 'أكّدت قراءة الأعمدة'))));   // one per overridden error
    }

    public function test_an_amount_column_of_text_needs_confirm_anyway_instead_of_blocking(): void
    {
        $id      = $this->pausedRun();
        $remarks = $this->mapping(['closing_credit' => 'I']);   // the remarks column read as closing credit

        $response = $this->postJson("/api/v1/classify/jobs/{$id}/structure", $remarks)->assertStatus(422)->assertJsonPath('needs_acknowledgement', true);
        $this->assertContains('قُرئ العمود I على أنه «دائن آخر المدة» لكن 0% فقط من قيمه أرقام.', $response->json('doubts'));

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $remarks + ['acknowledge_doubts' => true])->assertStatus(202);
        $warnings = $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')->json('result.meta.warnings');
        $this->assertContains('أكّدت قراءة الأعمدة رغم هذا التنبيه: قُرئ العمود I على أنه «دائن آخر المدة» لكن 0% فقط من قيمه أرقام.', $warnings);
        $this->assertEmpty(array_filter($warnings, fn ($w) => str_starts_with($w, '⛔ أكدت')));   // not a swap: no ⛔
    }

    public function test_blocking_errors_never_become_confirm_anyway(): void
    {
        $id = $this->pausedRun();

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $this->mapping(['closing_debit' => 'Z']) + ['acknowledge_doubts' => true])
            ->assertStatus(422)->assertJsonStructure(['problems'])->assertJsonMissingPath('needs_acknowledgement');
        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'needs_confirmation');
    }

    public function test_an_acknowledged_other_sheet_error_drops_that_sheet(): void
    {
        $tb = [self::FULL_HEADER, ...array_map(fn ($i) => ['110'.$i, "صندوق {$i}", 0, 0, 10 * $i, 0, 10 * $i, 0], range(1, 8))];
        $file = $this->workbook(['ميزان' => $tb, 'قيود' => [['الحساب', 'مدين', 'دائن'], ['صندوق 1', 5, 0]]]);
        // The AI reads sheet 2 as adjustments with an empty credit column (Z): an other_sheets error, and a duplicate mapping to pause.
        $this->fakeLlm($this->standardStructure(['columns' => ['closing_debit' => 'B'], 'other_sheets' => [
            ['sheet' => 2, 'type' => 'adjustments', 'description' => 'قيود', 'first_data_row' => 2, 'columns' => ['account_name' => 'A', 'debit' => 'B', 'credit' => 'Z']],
        ]]), $this->labels());
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $file])->json('id');
        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'needs_confirmation');

        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $this->mapping())->assertStatus(422)->assertJsonPath('needs_acknowledgement', true);
        $this->postJson("/api/v1/classify/jobs/{$id}/structure", $this->mapping() + ['acknowledge_doubts' => true])->assertStatus(202);

        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'done')->assertJsonPath('result.meta.other_sheets', []);
        $this->assertSame([], ClassificationRun::find($id)->structure['other_sheets']);
    }
}
