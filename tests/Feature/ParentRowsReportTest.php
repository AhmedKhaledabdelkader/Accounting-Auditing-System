<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * Parent rows in a report: one the file's levels mark is left out; a probable parent is counted, pending and proposed until
 * the user excludes it (with a reason) or keeps it; any account can be left out by hand, with a reason. Made-up rows.
 */
class ParentRowsReportTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';

    /** A parent (row 3, code 4100) over its only child (row 4, code 7700, same balance): counted twice, assets exceed by 500. */
    private function report(string $flag): string
    {
        $parent = ['type' => $flag, 'difference' => null, 'rows' => [4], 'message' => 'حساب تجميعي'] + ($flag === 'parent_level' ? ['severity' => 'info'] : []);
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['closing' => 100]),
            $this->account(3, 'مباني وإنشاءات', ['closing' => 500], ['account_number' => '4100', 'data_flags' => [$parent]]),
            $this->account(4, 'مباني وإنشاءات', ['closing' => 500], ['account_number' => '7700']),
            $this->account(5, 'رأس المال', ['closing' => -500]),
            $this->account(8, 'موردون', ['closing' => -100]),
            $this->account(6, 'إيرادات المبيعات', ['closing' => -100]),
            $this->account(7, 'إيجارات', ['closing' => 100]),
        ], ['closing'])]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    private function line(array $state, string $key): ?float
    {
        return collect($state['statements']['financial_position'])->firstWhere('key', $key)['current']['amount'] ?? null;
    }

    private function codes(array $state): array
    {
        return array_column($state['checks'], 'code');
    }

    public function test_a_parent_by_the_files_levels_is_left_out(): void
    {
        $state = $this->getJson(self::URL.'/'.$this->report('parent_level'))->assertOk()->json();

        $this->assertSame('parent_level', collect($state['mapping']['excluded'])->firstWhere('row', 3)['reason']);
        $this->assertEquals(500, $this->line($state, 'sofp.ppe'));
        $this->assertNotContains('tb_unbalanced', $this->codes($state));
        $this->assertNotContains('sofp_unbalanced', $this->codes($state));
        $this->assertSame([], $state['mapping']['parent_proposals']);
    }

    public function test_a_probable_parent_is_proposed_until_excluded_or_kept(): void
    {
        $id = $this->report('probable_parent');
        $state = $this->getJson(self::URL."/{$id}")->json();

        // Counted (the trial balance doesn't balance), pending, proposed, and a final is blocked.
        $this->assertEquals(1000, $this->line($state, 'sofp.ppe'));
        $this->assertContains('tb_unbalanced', $this->codes($state));
        $this->assertSame([['row' => 3, 'rows' => [4], 'current_line' => 'sofp.ppe']],
            array_map(fn ($p) => array_intersect_key($p, array_flip(['row', 'rows', 'current_line'])), $state['mapping']['parent_proposals']));
        $this->assertSame(['4100', 'حساب 3'], [$state['mapping']['parent_proposals'][0]['account_number'], $state['mapping']['parent_proposals'][0]['account_name']]);
        $this->assertContains('pending_mappings', array_column($state['blocking'], 'reason'));

        // Excluded, with a reason: out of the statements and of the trial balance's check.
        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 3, 'exclude' => true, 'reason' => 'رصيده بيساوي الحساب اللي تحته']]])->assertOk()->json();
        $this->assertSame([['row' => 3, 'exclude' => true, 'reason' => 'رصيده بيساوي الحساب اللي تحته']], $state['account_overrides']);
        $excluded = collect($state['mapping']['excluded'])->firstWhere('row', 3);
        $this->assertSame(['user', 'رصيده بيساوي الحساب اللي تحته', ['sofp.ppe' => 1]], [$excluded['reason'], $excluded['note'], $excluded['original']['lines']]);
        $this->assertEquals(500, $this->line($state, 'sofp.ppe'));
        $this->assertNotContains('tb_unbalanced', $this->codes($state));
        $this->assertSame([], $state['mapping']['parent_proposals']);
        $this->assertNotContains('pending_mappings', array_column($state['blocking'], 'reason'));

        // Kept instead (a line override): counted, decided.
        $state = $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 3, 'line_key' => 'sofp.ppe']]])->assertOk()->json();
        $this->assertEquals(1000, $this->line($state, 'sofp.ppe'));
        $this->assertSame([], $state['mapping']['parent_proposals']);
        $this->assertNotContains('pending_mappings', array_column($state['blocking'], 'reason'));
    }

    public function test_an_exclusion_needs_a_reason_and_nothing_else(): void
    {
        $id = $this->report('probable_parent');
        $put = fn (array $o) => $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 3] + $o]]);

        $put(['exclude' => true])->assertStatus(422)->assertJsonValidationErrors('overrides.0.reason');
        $put(['exclude' => true, 'reason' => 'لا'])->assertStatus(422)->assertJsonValidationErrors('overrides.0.reason');
        $put(['exclude' => true, 'reason' => 'حساب تجميعي', 'line_key' => 'sofp.ppe'])->assertStatus(422);
        $put(['exclude' => false, 'reason' => 'حساب تجميعي'])->assertStatus(422);
        // Any account can be left out by hand.
        $this->putJson(self::URL."/{$id}/account-overrides", ['overrides' => [['row' => 7, 'exclude' => true, 'reason' => 'اتسجل في حساب تاني']]])->assertOk()
            ->assertJsonPath('mapping.excluded.0.reason', 'user');
    }
}
