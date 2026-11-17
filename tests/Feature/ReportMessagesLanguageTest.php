<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Services\Statements\ReportChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * Interface messages the report API builds come in the request's language (`title`, `message`, `label`, `help`, `basis`),
 * while every `*_ar` field stays Arabic and a check's key is the same in every language.
 */
class ReportMessagesLanguageTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const URL = '/api/v1/financial-reports';
    private const AR  = ['Accept-Language' => 'ar'];
    private const EN  = ['Accept-Language' => 'en'];

    private function report(): string
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [500, 300]]),
            $this->account(3, 'رأس المال', ['opening' => -100]),
            $this->account(4, 'إيرادات المبيعات', ['period' => [0, 500]]),
            $this->account(5, 'إيجارات', ['period' => [300, 0]]),
            $this->account(7, null, ['closing' => 0]),
        ], ['opening', 'period', 'closing'])]);

        return $this->postJson(self::URL, ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json('id');
    }

    public function test_checks_and_questions_follow_the_request_language_and_ar_fields_stay_arabic(): void
    {
        $id = $this->report();
        $en = $this->getJson(self::URL."/{$id}", self::EN)->assertOk()->json();
        $ar = $this->getJson(self::URL."/{$id}", self::AR)->assertOk()->json();

        $check = collect($en['checks'])->firstWhere('code', 'unclassified_rows');
        $this->assertSame('Unclassified accounts', __('statements.checks.unclassified_rows', [], 'en'));
        $this->assertSame('Unclassified accounts', $check['title']);
        $this->assertSame('1 account(s) not classified; classify them on the classification page.', $check['message']);
        $this->assertSame('1 حساب بلا تصنيف؛ صنّفها في صفحة التصنيف.', $check['message_ar']);
        $this->assertSame(__('statements.checks.unclassified_rows', [], 'ar'), $check['title_ar']);

        // Arabic request: both fields Arabic; same keys and the same Arabic in both languages.
        $arCheck = collect($ar['checks'])->firstWhere('code', 'unclassified_rows');
        $this->assertSame($check['message_ar'], $arCheck['message']);
        $this->assertSame(array_column($en['checks'], 'key'), array_column($ar['checks'], 'key'));
        $this->assertSame(array_column($en['checks'], 'message_ar'), array_column($ar['checks'], 'message_ar'));
        // A check as saved before the new fields keys the same: acknowledgements of existing reports still match.
        foreach ($en['checks'] as $c) {
            $this->assertSame($c['key'], ReportChecks::key(array_diff_key($c, ['title' => 1, 'message' => 1])));
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $c['title'], $c['code']);
        }
        $this->assertSame('Errors to be fixed', collect($en['blocking'])->firstWhere('reason', 'errors')['label']);
        $this->assertSame(__('statements.blocking.errors', [], 'ar'), collect($en['blocking'])->firstWhere('reason', 'errors')['label_ar']);

        // Intake questions.
        $field = collect($this->getJson(self::URL."/{$id}/intake", self::EN)->assertOk()->json('fields'))->firstWhere('key', 'oci.has_items');
        $this->assertSame('Are there items of other comprehensive income?', $field['label']);
        $this->assertSame('Such as revaluation or actuarial gains. Most small and medium-sized entities: no.', $field['help']);
        $this->assertSame('هل توجد بنود دخل شامل آخر؟', $field['label_ar']);
        $this->assertSame('مثل إعادة التقويم أو الأرباح الإكتوارية. في أغلب المنشآت الصغيرة والمتوسطة: لا.', $field['help_ar']);
        $arField = collect($this->getJson(self::URL."/{$id}/intake", self::AR)->json('fields'))->firstWhere('key', 'oci.has_items');
        $this->assertSame($field['label_ar'], $arField['label']);

        // Validation messages: the request's language.
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['oci.has_items' => 'x']], self::EN)
            ->assertStatus(422)->assertJsonPath('errors', ['inputs.oci.has_items' => ['The answer must be yes or no.']]);
        $this->patchJson(self::URL."/{$id}/inputs", ['inputs' => ['oci.has_items' => 'x']], self::AR)
            ->assertStatus(422)->assertJsonPath('errors', ['inputs.oci.has_items' => ['يجب أن تكون الإجابة نعم أو لا.']]);
    }

    public function test_arabic_and_english_messages_have_the_same_keys_and_placeholders(): void
    {
        $ar = Arr::dot(require lang_path('ar/report_messages.php'));
        $en = Arr::dot(require lang_path('en/report_messages.php'));
        $this->assertSame(array_keys($ar), array_keys($en));
        foreach ($ar as $key => $text) {
            preg_match_all('/:[a-z_]+/', $text, $a);
            preg_match_all('/:[a-z_]+/', $en[$key], $e);
            // English may leave out a code the Arabic quotes (a line key, a run status); it never adds one.
            $this->assertSame([], array_diff($e[0], $a[0]), $key);
        }
    }
}
