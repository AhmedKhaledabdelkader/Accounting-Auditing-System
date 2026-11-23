<?php

return [
    /*
     * Materiality (ISA 320; App\Services\Audit\Materiality): each benchmark with its usual range of percentages and the one
     * proposed; the performance materiality as a share of the overall (lower for a higher risk: a first audit, misstatements
     * last year, weak controls) and the clearly trivial threshold as a share of it.
     */
    'materiality' => [
        // The bases of the working paper (2003), in its order: the percentage on each, whether it is taken by default (counted in
        // the highest and the average), and its usual range (said on the page).
        'bases' => [
            'total_assets'        => ['percent' => 1, 'taken' => true, 'low' => 0.5, 'high' => 2],
            'gross_profit'        => ['percent' => 2, 'taken' => false, 'low' => 1, 'high' => 5],
            'profit_before_zakat' => ['percent' => 10, 'taken' => true, 'low' => 5, 'high' => 10],
            'net_profit'          => ['percent' => 10, 'taken' => false, 'low' => 5, 'high' => 10],
            'revenue'             => ['percent' => 1, 'taken' => true, 'low' => 0.5, 'high' => 2],
            'total_liabilities'   => ['percent' => 1, 'taken' => false, 'low' => 0.5, 'high' => 2],
            'total_equity'        => ['percent' => 2, 'taken' => false, 'low' => 1, 'high' => 5],
        ],
        // Performance materiality as a share of the overall: 50% by default (the working paper's); suggested by the risk.
        'performance' => ['low' => 75, 'medium' => 60, 'high' => 50],
        'performance_default' => 50,
        'trivial_default' => 5,
        // The profit before zakat is proposed as the benchmark when it is at least this share of the revenue (%): a steady profit.
        'steady_profit_share' => 3,
    ],

    /*
     * The revenue sample (App\Services\Audit\RevenueSampling): every item at or above the performance materiality (key items),
     * then a monetary-unit sample of the rest: size = the rest's total × the assurance factor ÷ the performance materiality
     * (no misstatement expected), at least `min_sample` items (or all of them when fewer).
     */
    'sampling' => [
        'factors' => ['high' => 3.0, 'moderate' => 2.3, 'low' => 1.6],   // 95%, 90%, 80% confidence
        // The expansion factor of an expected misstatement (AICPA sampling guide, by the risk of incorrect acceptance 5%, 10%, 20%):
        // n = remainder × factor ÷ (performance materiality − expected × expansion).
        'expansion' => ['high' => 1.6, 'moderate' => 1.5, 'low' => 1.3],
        // When n is above the cap: test it all, accept a lower assurance (the achieved factor said), or keep the cap with a justification.
        'cap_decisions' => ['full', 'lower_assurance', 'capped_with_approval'],
        'default_assurance' => 'high',
        'min_sample' => 10,
        // The office's limits (the user's rule, 2026-11-22; the brief of 2026-11-23): on n only — the representative part, over
        // the invoices left after the key items (which are all tested, outside the cap): between 12% and 25% of them, never more
        // than 50 nor fewer than 5 (all of them when there are fewer). Above the cap, a decision (cap_decisions) with a justification.
        'limits' => ['min_share' => 12, 'max_share' => 25, 'min_items' => 5, 'max_items' => 50],
        'max_rows' => 100000,   // a whole ledger (every account's statement) fits
        // Words of a column's header in a sales list, Excel or PDF (checked in order).
        'columns' => [
            'date'   => '/تاريخ|التاريخ|date/iu',
            'number' => '/رقم\s*(ال)?(فاتور|مستند|قيد|سند)|الفاتورة|فاتورة|invoice|doc|no\.?$|المرجع|رقم/iu',
            'party'  => '/عميل|العميل|الاسم|اسم|البيان|الوصف|customer|description|party/iu',
            'net'    => '/قبل\s*(ال)?ضريب|بدون\s*(ال)?ضريب|غير\s*شامل|الصافي|صافي|net|excl/iu',
            'credit' => '/دائن|credit/iu',
            'debit'  => '/مدين|debit/iu',
            'amount' => '/المبلغ|مبلغ|القيمة|قيمة|الإجمالي|الاجمالي|اجمالي|إجمالي|المجموع|total|amount/iu',
        ],
    ],
];
