<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/companies';

    public function test_create_with_defaults_and_read_back(): void
    {
        $created = $this->postJson(self::URL, ['name_ar' => 'شركة المثال للتجارة', 'cr_number' => '1010000000',
            'cr_date_hijri' => '1440/05/12هـ', 'branches' => ['جدة', 'الدمام']])->assertCreated()->json();

        $this->assertSame(['llc', 'SA', 'ifrs_for_smes', 'SAR', 12, 31], [$created['legal_form'], $created['country'], $created['framework'],
            $created['currency'], $created['fiscal_year_end_month'], $created['fiscal_year_end_day']]);
        $this->assertSame('1440/05/12هـ', $created['cr_date_hijri']);   // Hijri kept exactly as entered (Q26)
        $this->assertSame(['جدة', 'الدمام'], $created['branches']);
        $this->assertSame('{}', json_encode($created['profile'] ?: new \stdClass));
        $this->assertStringContainsString('"profile":{}', $this->getJson(self::URL.'/'.$created['id'])->assertOk()->getContent());
    }

    public function test_partial_update_changes_only_the_fields_sent(): void
    {
        $id = $this->postJson(self::URL, ['name_ar' => 'أ', 'city' => 'الرياض'])->json('id');

        $updated = $this->patchJson(self::URL."/{$id}", ['name_en' => 'A Co', 'fiscal_year_end_month' => 6, 'fiscal_year_end_day' => 30])->assertOk()->json();
        $this->assertSame(['أ', 'A Co', 'الرياض', 6, 30], [$updated['name_ar'], $updated['name_en'], $updated['city'], $updated['fiscal_year_end_month'], $updated['fiscal_year_end_day']]);

        // A month that lacks the stored day is refused; 29 February is a year end.
        $this->patchJson(self::URL."/{$id}", ['fiscal_year_end_month' => 2])->assertStatus(422)->assertJsonValidationErrors('fiscal_year_end_day');
        $this->patchJson(self::URL."/{$id}", ['fiscal_year_end_month' => 2, 'fiscal_year_end_day' => 29])->assertOk();
        $this->patchJson(self::URL."/{$id}", ['name_ar' => null])->assertStatus(422)->assertJsonValidationErrors('name_ar');
    }

    public function test_validation_and_scope(): void
    {
        $this->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors('name_ar');
        // Saudi LLC under IFRS for SMEs in SAR only, for now (Q5).
        $this->postJson(self::URL, ['name_ar' => 'أ', 'legal_form' => 'jsc', 'currency' => 'USD', 'framework' => 'ifrs', 'country' => 'EG'])
            ->assertStatus(422)->assertJsonValidationErrors(['legal_form', 'currency', 'framework', 'country']);
        $this->postJson(self::URL, ['name_ar' => 'أ', 'fiscal_year_end_month' => 4])->assertStatus(422)->assertJsonValidationErrors('fiscal_year_end_day');
        $this->postJson(self::URL, ['name_ar' => 'أ', 'cr_date' => '1440-05-12x', 'branches' => 'جدة'])->assertStatus(422)->assertJsonValidationErrors(['cr_date', 'branches']);
        // Validation runs before the lookup.
        $this->patchJson(self::URL.'/'.Str::uuid(), ['legal_form' => 'x'])->assertStatus(422);
        $this->patchJson(self::URL.'/'.Str::uuid(), [])->assertNotFound();
        $this->getJson(self::URL.'/'.Str::uuid())->assertNotFound()->assertJson(['message' => 'الشركة غير موجودة.']);
    }

    public function test_search_and_pagination(): void
    {
        Company::create(['name_ar' => 'شركة النخيل', 'cr_number' => '4030000001']);
        Company::create(['name_ar' => 'مؤسسة البحر', 'name_en' => 'Sea Est', 'unified_number' => '7000000002']);
        Company::create(['name_ar' => 'مصنع الأمل']);

        $this->assertSame(3, $this->getJson(self::URL)->assertOk()->json('total'));
        $this->assertSame(['شركة النخيل'], array_column($this->getJson(self::URL.'?search='.urlencode('النخيل'))->json('data'), 'name_ar'));
        $this->assertSame(['مؤسسة البحر'], array_column($this->getJson(self::URL.'?search=sea')->json('data'), 'name_ar'));
        $this->assertSame(['شركة النخيل'], array_column($this->getJson(self::URL.'?search=4030')->json('data'), 'name_ar'));
        $this->assertSame(['مؤسسة البحر'], array_column($this->getJson(self::URL.'?search=7000000002')->json('data'), 'name_ar'));
        $this->assertSame(1, $this->getJson(self::URL.'?per_page=1')->json('per_page'));
        $this->getJson(self::URL.'?per_page=101')->assertStatus(422);
    }

    public function test_a_registration_number_belongs_to_one_company(): void
    {
        $first = $this->postJson(self::URL, ['name_ar' => 'الأولى', 'cr_number' => '1010000001'])->assertCreated()->json();
        $this->postJson(self::URL, ['name_ar' => 'الثانية', 'cr_number' => '1010000001'])->assertStatus(422)->assertJsonValidationErrors('cr_number');
        $second = $this->postJson(self::URL, ['name_ar' => 'الثانية', 'cr_number' => '1010000002'])->assertCreated()->json();
        $this->patchJson(self::URL.'/'.$second['id'], ['cr_number' => '1010000001'])->assertStatus(422)->assertJsonValidationErrors('cr_number');
        // Its own number, or none, is fine.
        $this->patchJson(self::URL.'/'.$first['id'], ['cr_number' => '1010000001', 'city' => 'جدة'])->assertOk();
        $this->postJson(self::URL, ['name_ar' => 'من غير رقم'])->assertCreated();
        $this->postJson(self::URL, ['name_ar' => 'برضه من غير رقم'])->assertCreated();
    }
}
