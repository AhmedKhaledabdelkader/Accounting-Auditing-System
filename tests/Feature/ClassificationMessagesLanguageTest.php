<?php

namespace Tests\Feature;

use App\Jobs\ProcessClassificationImport;
use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping;
use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;
use App\Services\CacheImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeTaxonomyMapper;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * Messages the classification side stores stay Arabic where they always were, and come back in the request's language
 * beside them (`*_text`); a record stored before the phrases existed falls back to its Arabic.
 */
class ClassificationMessagesLanguageTest extends TestCase
{
    use RefreshDatabase, TrialBalanceFiles;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];
    private const AR = ['Accept' => 'application/json', 'Accept-Language' => 'ar'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->useModel('test-model');
    }

    public function test_a_runs_warnings_flags_and_note_come_in_the_request_language_and_stay_arabic_when_stored(): void
    {
        // Row 2: closing ≠ opening + movement (a flag); row 3: a non-account row with balances (a file warning).
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق'), $this->rowOfType(3, 'other')]);
        $file = $this->trialBalance([
            ['110101010', 'النقد في الصندوق', 10340, 0, 411756.57, 423270.57, 0, 0],
            ['', 'بيان', 0, 0, 0, 0, 250, 0],
        ]);
        $id = $this->post('/api/v1/classify/jobs', ['file' => $file], self::EN)->assertStatus(202)->json('id');

        $en = $this->getJson("/api/v1/classify/jobs/{$id}", self::EN)->assertOk()->assertJsonPath('status', 'done')->json('result');
        $ar = $this->getJson("/api/v1/classify/jobs/{$id}", self::AR)->assertOk()->json('result');

        $flag = $en['data'][0]['data_flags'][0];
        $this->assertSame('reconciliation', $flag['type']);
        $this->assertSame('الرصيد الختامي أعلى من المحسوب بمقدار 1,174.00', $flag['message']);
        $this->assertSame('The closing balance is higher than calculated by 1,174.00', $flag['message_text']);
        $this->assertSame($flag['message'], $ar['data'][0]['data_flags'][0]['message_text']);
        $this->assertSame('The closing balance is higher than calculated by 1,174.00', $en['data'][0]['note_text']);
        $this->assertSame($en['data'][0]['note'], $ar['data'][0]['note_text']);

        $warning = collect($en['meta']['warnings'])->search(fn ($w) => str_contains($w, 'غير مصنفة كحسابات'));
        $this->assertNotFalse($warning);
        $this->assertStringStartsWith('1 row(s) carry balances but are not in the totals', $en['meta']['warnings_text'][$warning]);
        $this->assertStringContainsString('row 3 in the file', $en['meta']['warnings_text'][$warning]);
        $this->assertSame($en['meta']['warnings'], $ar['meta']['warnings_text']);
        $this->assertSame($en['meta']['info'], $ar['meta']['info_text']);
        $this->assertCount(count($en['meta']['info']), $en['meta']['info_text']);
        foreach (array_merge($en['meta']['warnings_text'], $en['meta']['info_text']) as $text) {
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text);
        }

        // Stored: the Arabic as always (phrases beside it), never the request's language.
        $stored = ClassificationRun::find($id)->result;
        $this->assertSame($en['meta']['warnings'], $stored['meta']['warnings']);
        $this->assertSame($flag['message'], $stored['data'][0]['data_flags'][0]['message']);
        $this->assertArrayNotHasKey('message_phrase', $flag);
        $this->assertArrayNotHasKey('warnings_phrases', $en['meta']);
    }

    public function test_run_errors_come_in_the_request_language_and_old_runs_fall_back_to_arabic(): void
    {
        $waiting = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'needs_confirmation', 'original_name' => 'tb.xlsx',
            'expires_at' => now()->subMinute()]);
        Artisan::call('classification:prune');
        $this->assertSame('انتهت مهلة تأكيد أعمدة الملف فتم حذفه. يرجى رفعه مرة أخرى.', $waiting->fresh()->error);
        $this->getJson("/api/v1/classify/jobs/{$waiting->id}", self::EN)->assertOk()
            ->assertJsonPath('error', 'انتهت مهلة تأكيد أعمدة الملف فتم حذفه. يرجى رفعه مرة أخرى.')
            ->assertJsonPath('error_text', 'The time to confirm the file’s columns ran out, so it was deleted. Upload it again.');

        // A run failed before phrases: its Arabic, whatever the language.
        $old = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'failed', 'original_name' => 'tb.xlsx', 'error' => 'رسالة قديمة.']);
        $this->getJson("/api/v1/classify/jobs/{$old->id}", self::EN)->assertOk()->assertJsonPath('error_text', 'رسالة قديمة.');

        // A result stored before phrases: every *_text is its Arabic.
        $result = ['data' => [['row' => 2, 'note' => 'ملاحظة قديمة', 'classification_reason' => 'سبب', 'data_flags' => [['type' => 'possible_parent', 'difference' => null, 'message' => 'رسالة']]]],
            'meta' => ['warnings' => ['تحذير قديم'], 'info' => []]];
        $done = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $result]);
        $json = $this->getJson("/api/v1/classify/jobs/{$done->id}", self::EN)->assertOk()->json('result');
        $this->assertSame(['تحذير قديم'], $json['meta']['warnings_text']);
        $this->assertSame('ملاحظة قديمة', $json['data'][0]['note_text']);
        $this->assertSame('رسالة', $json['data'][0]['data_flags'][0]['message_text']);
    }

    public function test_an_imports_reasons_flags_and_errors_come_in_the_request_language(): void
    {
        $mapper = new FakeTaxonomyMapper;
        $mapper->notes = ['حساب بنكي' => 'نقدية لدى البنوك'];   // outside the budget item's main class: a review flag
        $this->app->instance(TaxonomyMapperRepositoryInterface::class, $mapper);

        $file = $this->xlsxUpload([
            ['رقم الحساب', 'الميزانية', 'الإيضاحات', 'اسم الحساب', 'أخر مدين', 'أخر دائن', 'اسم الشركة'],
            ['1501', 'الذمم المدينة التجارية', 'سلف موظفين', 'سلفة أحمد', 500, 0, 'أ'],   // a side rule
            ['1502', 'الذمم المدينة التجارية', 'حساب بنكي', 'بنك', 100, 0, 'أ'],
            ['1503', null, null, 'من غير ميزانية', 10, 0, 'أ'],                         // skipped: empty budget
        ]);
        $id = $this->post('/api/v1/classification-cache/import', ['file' => $file], self::EN)->assertStatus(202)->json('id');

        $notes = collect($this->getJson("/api/v1/classification-cache/imports/{$id}/mappings?kind=note", self::EN)->assertOk()->json('data'))->keyBy('value');
        $side = $notes['سلف موظفين'];
        $this->assertStringStartsWith('حسب اتجاه الرصيد: مدين → «سلف العاملين»', $side['reason']);
        $this->assertStringStartsWith('By the balance side: debit → «سلف العاملين», credit → «أمانات العاملين»', $side['reason_text']);
        $bank = $notes['حساب بنكي'];
        $this->assertSame('خارج التصنيف الرئيسي لبند الميزانية: الميزانية = «الذمم المدينة التجارية»، والتصنيف = «النقد ومافي حكمه».', $bank['flag']);
        $this->assertSame('Outside the budget item’s main class: budget item = «الذمم المدينة التجارية», class = «النقد ومافي حكمه».', $bank['flag_text']);
        $this->assertSame('اختبار', $bank['reason_text']);   // the AI's reason is data
        $this->assertArrayNotHasKey('reason_phrase', $bank);

        $report = $this->getJson("/api/v1/classification-cache/imports/{$id}", self::EN)->assertOk()->json('report');
        $this->assertSame('الميزانية فارغة', $report['skipped']['empty_budget']['label']);
        $this->assertSame('Empty budget item', $report['skipped']['empty_budget']['label_text']);
        $this->assertSame($bank['flag_text'], collect($report['review']['flagged_top'])->firstWhere('value', 'حساب بنكي')['flag_text']);
        $this->assertSame($side['reason'], ClassificationImportMapping::find($side['id'])->reason);   // stored Arabic

        // A mapping stored before phrases: its Arabic.
        $old = ClassificationImportMapping::create(['import_id' => $id, 'kind' => 'note', 'value' => 'قديم', 'normalized' => 'قديم', 'parent' => 'x',
            'decision' => 'skip', 'method' => 'llm', 'confidence' => 0, 'reason' => 'سبب قديم', 'flag' => 'تنبيه قديم', 'reviewed' => false, 'rows' => 1]);
        $old = collect($this->getJson("/api/v1/classification-cache/imports/{$id}/mappings?kind=note", self::EN)->json('data'))->firstWhere('id', $old->id);
        $this->assertSame(['سبب قديم', 'تنبيه قديم'], [$old['reason_text'], $old['flag_text']]);

        // A failed import: the Arabic stored, the request's language beside it.
        $broken = ClassificationImport::create(['file_name' => 'x.xlsx', 'status' => ClassificationImport::QUEUED,
            'file_path' => $this->xlsxUpload([['أ', 'ب'], [1, 2]])->storeAs('classification-imports', 'x.xlsx', 'local')]);
        (new ProcessClassificationImport($broken->id))->handle(app(CacheImport::class));
        $this->getJson("/api/v1/classification-cache/imports/{$broken->id}", self::EN)->assertOk()
            ->assertJsonPath('error', 'أول صف فيه بيانات جاء قبل صف العناوين («اسم الحساب» و«الميزانية»).')
            ->assertJsonPath('error_text', 'The first row with data comes before the header row («اسم الحساب» and «الميزانية»).');
    }

    public function test_arabic_and_english_messages_have_the_same_keys_and_placeholders(): void
    {
        $ar = Arr::dot(require lang_path('ar/classification_messages.php'));
        $en = Arr::dot(require lang_path('en/classification_messages.php'));
        $this->assertSame(array_keys($ar), array_keys($en));
        foreach ($ar as $key => $text) {
            preg_match_all('/:[a-z_]+/', $text, $a);
            preg_match_all('/:[a-z_]+/', $en[$key], $e);
            sort($a[0]);
            sort($e[0]);
            $this->assertSame(array_values(array_unique($a[0])), array_values(array_unique($e[0])), $key);
        }
    }
}
