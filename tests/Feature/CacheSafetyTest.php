<?php

namespace Tests\Feature;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationRun;
use App\Services\ClassificationCache;
use App\Support\ClassificationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Answers that look wrong are capped and never cached; side pairs are stored as their base class; the one-time cleanup. */
class CacheSafetyTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    /** @var array<int, array> row => the AI's label */
    private array $ai = [];

    /** @var list<int> rows the AI was asked about */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['classification.cache.enabled' => true]);
        $this->fakeLlm($this->standardStructure(), function ($call, array $requested) {
            array_push($this->asked, ...$requested);

            return array_values($this->ai);
        });
    }

    /** One queued run; each account: [code, name, closing debit, closing credit] (movement = closing, no opening). */
    private function classify(array $accounts): TestResponse
    {
        $rows = array_map(fn ($a) => [$a[0], $a[1], 0, 0, $a[2], $a[3], $a[2], $a[3]], $accounts);
        $id   = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance($rows)])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/classify/jobs/{$id}")->assertOk()->assertJsonPath('status', 'done');
    }

    private function row(TestResponse $run, int $excelRow): array
    {
        return collect($run->json('result.data'))->firstWhere('row', $excelRow);
    }

    private function cached(string $code): ?ClassificationCacheEntry
    {
        return ClassificationCacheEntry::where('account_code', $code)->first();
    }

    public function test_a_wrong_side_balance_caps_confidence_at_60_and_is_not_cached(): void
    {
        $this->ai = [2 => $this->account(2, 'نقدية بالصندوق', 95), 3 => $this->account(3, 'نقدية لدى البنوك', 95)];
        $run = $this->classify([['1101', 'الصندوق', 0, 500], ['1102', 'البنك', 300, 0]]);

        $wrong = $this->row($run, 2);
        $this->assertSame([60, true], [$wrong['classification_confidence'], $wrong['needs_review']]);
        $this->assertContains('cash_credit', array_column($wrong['data_flags'], 'type'));
        $this->assertSame(95, $this->row($run, 3)['classification_confidence']);

        $this->assertNull($this->cached('1101'));
        $this->assertSame('نقدية لدى البنوك', $this->cached('1102')->sub_class);
    }

    public function test_a_possible_parent_is_not_cached(): void
    {
        $this->ai = [2 => $this->account(2, 'نقدية لدى البنوك'), 3 => $this->account(3, 'نقدية لدى البنوك'), 4 => $this->account(4, 'نقدية لدى البنوك')];
        $run = $this->classify([['1102', 'البنوك', 300, 0], ['110201', 'بنك الأهلي', 100, 0], ['110202', 'بنك مصر', 200, 0]]);

        $this->assertContains('possible_parent', array_column($this->row($run, 2)['data_flags'], 'type'));
        $this->assertNull($this->cached('1102'));
        $this->assertNotNull($this->cached('110201'));
    }

    public function test_an_account_number_from_another_family_is_flagged_capped_at_50_and_not_cached(): void
    {
        // Five other accounts under 1 are assets in this file, so 1 means assets here; R7 is an expense numbered 1….
        $names = ['1101' => 'الصندوق', '1102' => 'بنك الأهلي', '1103' => 'بنك مصر', '1104' => 'بنك القاهرة', '1105' => 'بنك قطر', '1106' => 'إيجار المعرض'];
        foreach (array_keys($names) as $i => $code) {
            $this->ai[$i + 2] = $this->account($i + 2, (string) $code === '1106' ? 'إيجارات' : 'نقدية لدى البنوك', 95);
        }
        $run = $this->classify(array_map(fn ($c, $n) => [(string) $c, $n, 100, 0], array_keys($names), $names));

        $odd = $this->row($run, 7);
        $flag = collect($odd['data_flags'])->firstWhere('type', 'prefix_mismatch');
        $this->assertSame('رقم الحساب يبدأ بـ 1، وفي هذا الملف 5 من 5 حساب آخر تبدأ به «أصول»، لكن التصنيف المقترح «مصروفات»', $flag['message']);
        $this->assertSame([50, true], [$odd['classification_confidence'], $odd['needs_review']]);
        $this->assertNull($this->cached('1106'));

        $ok = $this->row($run, 2);
        $this->assertSame([95, []], [$ok['classification_confidence'], $ok['data_flags']]);
        $this->assertNotNull($this->cached('1101'));

        // Under the share (80%) or the count (5): no scheme, no flag.
        config(['classification.prefix_check.min_accounts' => 7]);
        $this->assertSame([], $this->row($this->classify(array_map(fn ($c, $n) => [(string) $c, $n, 100, 0], array_keys($names), $names)), 7)['data_flags']);
    }

    public function test_a_manual_correction_keeps_its_confidence_but_the_flag_stays(): void
    {
        $this->ai = [2 => $this->account(2, 'نقدية بالصندوق', 95)];
        $first = $this->classify([['1101', 'الصندوق', 500, 0]]);
        $this->patchJson("/api/v1/classify/jobs/{$first->json('id')}/rows/2", ['sub_class' => 'نقدية لدى البنوك'])->assertOk();

        $row = $this->row($this->classify([['1101', 'الصندوق', 0, 500]]), 2);
        $this->assertSame(['نقدية لدى البنوك', 100, true], [$row['sub_class'], $row['classification_confidence'], $row['needs_review']]);
        $this->assertContains('cash_credit', array_column($row['data_flags'], 'type'));
    }

    public function test_the_ai_answer_is_stored_as_the_base_class_of_its_side_pair(): void
    {
        // A customer with a credit balance: the AI rightly says advance from customer; the cache keeps the kind (customer).
        $this->ai = [2 => $this->account(2, 'دفعات مقدمة من العملاء', 95)];
        $this->classify([['1201', 'العميل أحمد', 0, 100]]);
        $this->assertSame(['عملاء', 'الذمم المدينة التجارية'], [$this->cached('1201')->sub_class, $this->cached('1201')->main_class]);

        // Each later row's own side picks the member.
        $this->asked = [];
        $this->assertSame('دفعات مقدمة من العملاء', $this->row($this->classify([['1201', 'العميل أحمد', 0, 100]]), 2)['sub_class']);
        $this->assertSame('عملاء', $this->row($this->classify([['1201', 'العميل أحمد', 100, 0]]), 2)['sub_class']);
        $this->assertSame([], $this->asked);
    }

    public function test_the_cleanup_removes_flagged_ai_answers_converts_to_base_and_fills_base_hash(): void
    {
        $entry = fn (string $code, string $name, string $sub, string $source, array $extra = []) => ClassificationCacheEntry::create($extra + [
            'key_hash' => ClassificationKey::hash($k = ClassificationKey::make($code, $name, null, '')), 'name_hash' => ClassificationKey::nameHash($name),
            'normalized_key' => $k, 'original_name' => $name, 'account_code' => $code, 'row_type' => 'account', 'main_class' => 'x',
            'sub_class' => $sub, 'source' => $source, 'confidence' => 95, 'taxonomy_version' => ClassificationCache::version(),
        ]);
        $bad      = $entry('1101', 'الصندوق', 'نقدية بالصندوق', ClassificationCache::AI);
        $manual   = $entry('1102', 'البنك', 'نقدية لدى البنوك', ClassificationCache::MANUAL);
        $advance  = $entry('1201', 'العميل أحمد', 'دفعات مقدمة من العملاء', ClassificationCache::AI);
        $locked   = $entry('2101', 'مورد', 'دفعات مقدمة للموردين', ClassificationCache::MANUAL, ['side' => 'credit', 'side_locked' => true]);
        $updated  = $advance->updated_at;
        // A finished run in which R2 (1101) and R3 (1102) had a wrong-side balance.
        $flagged = fn (string $code, string $name) => ['cache_key' => ClassificationKey::make($code, $name, null, ''), 'data_flags' => [['type' => 'abnormal_closing']]];
        ClassificationRun::create(['status' => 'done', 'original_name' => 'tb.xlsx', 'result' => ['data' => [$flagged('1101', 'الصندوق'), $flagged('1102', 'البنك')]]]);

        $this->artisan('classification:cache-cleanup', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(4, ClassificationCacheEntry::count());
        $this->assertNull($advance->fresh()->base_hash);

        $this->artisan('classification:cache-cleanup')->assertSuccessful();
        $this->assertNull($bad->fresh());                                   // the AI answer goes back to the AI
        $this->assertSame('نقدية لدى البنوك', $manual->fresh()->sub_class); // a person's label stays
        $this->assertSame(['عملاء', 'الذمم المدينة التجارية', sha1('code:1201|name:العميل احمد')],
            [$advance->fresh()->sub_class, $advance->fresh()->main_class, $advance->fresh()->base_hash]);
        $this->assertEquals($updated, $advance->fresh()->updated_at);
        $this->assertSame('دفعات مقدمة للموردين', $locked->fresh()->sub_class);   // a deliberate override is never converted

        // Running it again changes nothing.
        $this->artisan('classification:cache-cleanup')->expectsTable(['deleted_ai', 'converted_to_base', 'base_hash_filled'], [[0, 0, 0]])->assertSuccessful();
    }
}
