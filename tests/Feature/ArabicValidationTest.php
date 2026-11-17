<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/**
 * PR 13a: validation messages in Arabic for clients that ask for them (`Accept-Language: ar`, as the pages do), each
 * naming its field in Arabic, with no code or English left; other clients keep the default messages.
 */
class ArabicValidationTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    /** Every message of a 422: Arabic, no Latin word (a field key, a rule, an English sentence). */
    private function assertArabicErrors(\Illuminate\Testing\TestResponse $response, string $where): array
    {
        $response->assertStatus(422);
        $messages = [$response->json('message'), ...Arr::flatten($response->json('errors') ?? [])];
        $this->assertNotEmpty($messages);
        foreach ($messages as $m) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $m, "{$where}: {$m}");
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{2,}/', $m, "{$where}: {$m}");
        }

        return $response->json('errors');
    }

    public function test_every_rule_has_an_arabic_message(): void
    {
        $en = Arr::dot(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
        $ar = Arr::dot(require lang_path('ar/validation.php'));
        foreach ($en as $key => $message) {
            if (str_starts_with($key, 'custom.') || str_starts_with($key, 'attributes')) {
                continue;
            }
            $this->assertArrayHasKey($key, $ar, "lang/ar/validation.php {$key}");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar[$key], $key);
        }
        foreach ((require lang_path('ar/validation.php'))['attributes'] as $key => $name) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $name, "attributes.{$key}");
        }
    }

    public function test_the_api_answers_in_arabic_when_asked_and_in_the_default_otherwise(): void
    {
        $errors = $this->assertArabicErrors($this->postJson('/api/v1/companies', ['fiscal_year_end_month' => 13], self::AR), 'companies');
        $this->assertSame(['اسم الشركة بالعربية مطلوب.'], $errors['name_ar']);
        $this->assertSame(['يجب أن يكون شهر نهاية السنة المالية بين 1 و12.'], $errors['fiscal_year_end_month']);

        $this->postJson('/api/v1/companies', [])->assertStatus(422)->assertJsonPath('errors.name_ar.0', 'The Arabic company name field is required.');
        $this->postJson('/api/v1/companies', [], ['Accept-Language' => 'en-US,en;q=0.9'])->assertJsonPath('errors.name_ar.0', 'The Arabic company name field is required.');
    }

    public function test_the_fields_the_pages_send_are_named_in_arabic(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx',
            'result' => $this->runResult([$this->account(2, 'نقدية لدى البنوك', ['closing' => 100]), $this->account(3, 'رأس المال', ['closing' => -100])], ['closing'])]);
        $this->assertArabicErrors($this->postJson('/api/v1/financial-reports', ['period_start' => '2025', 'period_end' => 'x'], self::AR), 'create');
        $id = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->json('id');
        $base = "/api/v1/financial-reports/{$id}";

        $cases = [
            ['PATCH', $base, ['period_start' => '1/1/2025', 'company_id' => 'x']],
            ['PUT', "{$base}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 'abc'], 'cash_flows' => ['cf.depreciation_ppe' => 'x'],
                'schedules' => ['ppe.additions.vehicles' => 'x'], 'restated' => true, 'comparative_end' => 'x']],
            ['PUT', "{$base}/prior-year", ['source' => 'nope']],
            ['PUT', "{$base}/prior-year", ['source' => 'report']],
            ['PUT', "{$base}/account-overrides", ['overrides' => [['row' => 'x', 'shares' => ['sofp.cash' => 2]]]]],
            ['PUT', "{$base}/adjustments", ['entries' => [['reason' => 'x', 'lines' => [['debit' => 'a']]]]]],
            ['POST', "{$base}/suggestions", ['decisions' => [['key' => 'x', 'action' => 'nope']]]],
            ['PUT', "{$base}/acknowledgements", ['acknowledgements' => [['note' => str_repeat('ا', 600)]]]],
            ['POST', "{$base}/versions", ['kind' => 'other']],
            ['GET', '/api/v1/classify/jobs?status=nope&per_page=0', null],
            ['GET', '/api/v1/financial-reports?company_id=x', null],
        ];
        foreach ($cases as [$method, $uri, $body]) {
            $this->assertArabicErrors($this->json($method, $uri, $body ?? [], self::AR), "{$method} {$uri}");
        }
        $errors = $this->json('PUT', "{$base}/prior-year", ['source' => 'report'], self::AR)->json('errors');
        $this->assertSame(['القوائم المالية للسنة السابقة مطلوب عندما يكون مصدر أرقام السنة السابقة هو «القوائم المالية السابقة».'], $errors['prior_report_id']);
    }
}
