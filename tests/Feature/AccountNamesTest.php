<?php

namespace Tests\Feature;

use App\Models\ClassificationRun;
use App\Services\Statements\AccountNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClassifiedRows;
use Tests\TestCase;

/** PR 13b: wherever the user reads an account it is «number — name», never an internal row number. */
class AccountNamesTest extends TestCase
{
    use ClassifiedRows, RefreshDatabase;

    public function test_one_account_and_a_list(): void
    {
        $this->assertSame('120901 — بضاعة موجودة بالمستودع', AccountNames::one(['account_number' => '120901', 'account_name' => 'بضاعة موجودة بالمستودع']));
        $this->assertSame('120901', AccountNames::one(['account_number' => '120901', 'account_name' => '']));
        $this->assertSame('حساب بلا رقم ولا اسم', AccountNames::one(null));

        $rows = [];
        foreach (range(1, 8) as $n) {
            $rows[$n] = ['account_number' => (string) (100 + $n), 'account_name' => "حساب {$n}"];
        }
        $this->assertSame('«101 — حساب 1»، «102 — حساب 2»', AccountNames::list([1, 2, 2], $rows));
        $this->assertSame('«101 — حساب 1»، «102 — حساب 2»، «103 — حساب 3»، «104 — حساب 4»، «105 — حساب 5» و3 حسابات أخرى', AccountNames::list(range(1, 8), $rows));
        $this->assertStringEndsWith('وحساب آخر', AccountNames::list(range(1, 6), $rows));
        // A difference from last year's approved statements is said to be one, once, after the accounts; never «no name».
        $this->assertSame('فرق من القوائم المعتمدة للسنة السابقة', AccountNames::one(['row' => 1000003]));
        $this->assertSame('«101 — حساب 1»، «فرق من القوائم المعتمدة للسنة السابقة»', AccountNames::list([1000001, 1, 1000002], $rows));
    }

    public function test_the_reports_messages_name_accounts(): void
    {
        $run = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [600, 287]]),
            $this->account(3, 'رأس المال', ['opening' => -760]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [0, 1000]]),
            $this->account(6, 'تكلفة المشتريات', ['period' => [700, 0]]),
            $this->account(10, 'مخزون بضاعة', ['opening' => 500]),
            $this->account(11, 'مخزون بضاعة', ['period' => [500, 0]]),                 // opening stock, periodic
            $this->account(14, 'سيارات ووسائل نقل', ['opening' => 200, 'period' => [50, 30]]),
            $this->account(15, 'مجمع إهلاك الأصول الثابتة', ['opening' => -40, 'period' => [0, 20]]),
            $this->account(16, 'مخصص نهاية الخدمة', ['opening' => 0, 'period' => [2, 7]]),
            $this->account(17, 'مصروف مكافأة نهاية الخدمة', ['period' => [7, 0]]),
            $this->account(18, 'إهلاك السيارات', ['period' => [20, 0]]),
        ])]);
        $state = $this->postJson('/api/v1/financial-reports', ['classification_run_id' => $run->id, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'])->assertCreated()->json();

        $texts = [...array_column($state['checks'], 'message_ar'), ...array_column($state['suggestions'], 'basis_ar'), ...array_column($state['mapping']['proposals'], 'message')];
        $this->assertNotEmpty($state['suggestions']);
        $this->assertNotEmpty($state['mapping']['proposals']);
        foreach ($texts as $t) {
            $this->assertDoesNotMatchRegularExpression('/\bR\d+\b|الصفوف|\bصف \d/u', $t);
        }
        $this->assertStringContainsString('«1014 — حساب 14»', collect($state['suggestions'])->firstWhere('key', 'ppe.additions.vehicles')['basis_ar']);
        $this->assertStringContainsString('«1010 — حساب 10»', $state['mapping']['proposals'][0]['message']);
    }
}
