<?php

namespace Tests\Support;

use App\Models\ClassificationRun;
use Illuminate\Support\Str;

/** Finished runs for the pages' tests, among them one that raises most checks. Needs ClassifiedRows. */
trait BusyRun
{
    protected function doneRun(array $rows, array $groups = ['opening', 'period', 'closing']): ClassificationRun
    {
        return ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'ميزان المراجعة.xlsx',
            'result' => $this->runResult($rows, $groups)]);
    }

    /** A run that raises most checks: unbalanced, unclassified, unmapped, review, reconciliation, wrong side, periodic stock, empty group, rounding. */
    protected function busyRun(): ClassificationRun
    {
        return $this->doneRun([
            $this->account(2, 'نقدية لدى البنوك', ['opening' => 100, 'period' => [550.4, 300]], [
                'data_flags' => [['type' => 'reconciliation', 'difference' => 5.0, 'message' => 'فرق']], 'needs_review' => true]),
            $this->account(3, 'رأس المال', ['opening' => -100]),
            $this->account(4, 'جاري الشريك', ['opening' => 0, 'period' => [0, 40]]),
            $this->account(5, 'إيرادات المبيعات', ['period' => [0, 500]]),
            $this->account(6, 'إيجارات', ['period' => [300, 0]]),
            $this->account(7, 'عملاء', ['opening' => 0, 'period' => [0, 80]]),
            $this->account(8, 'إيرادات مستحقة', ['opening' => 0, 'period' => [10, 0]]),
            $this->account(9, 'استثمارات طويلة الأجل', ['opening' => 0, 'period' => [40, 0]]),
            $this->account(10, null, ['opening' => 0, 'period' => [9, 0]]),
            $this->account(11, 'مخزون بضاعة', ['opening' => 500, 'period' => [800, 500]]),
            $this->account(12, 'مخزون بضاعة', ['opening' => 0, 'period' => [500, 0]]),
            $this->account(13, 'مخزون بضاعة', ['opening' => 0, 'period' => [0, 800]]),
            // Movement schedules: PPE with additions and disposals, accumulated depreciation of no category, end-of-service
            // benefits with a reversal, a long-term loan and a deferred finance cost.
            $this->account(14, 'سيارات ووسائل نقل', ['opening' => 200, 'period' => [50, 30]]),
            $this->account(15, 'مجمع إهلاك الأصول الثابتة', ['opening' => -40, 'period' => [0, 20]]),
            $this->account(16, 'مخصص نهاية الخدمة', ['opening' => -10, 'period' => [2, 5]]),
            $this->account(17, 'مصروف مكافأة نهاية الخدمة', ['period' => [5, 2]]),
            $this->account(18, 'مصروفات مدفوعة مقدماً', ['opening' => 0, 'period' => [6, 0]]),
            $this->account(19, 'قروض طويلة الأجل', ['opening' => 0, 'period' => [0, 60]]),
        ], ['opening', 'period', 'adjustment', 'closing']);
    }

}
