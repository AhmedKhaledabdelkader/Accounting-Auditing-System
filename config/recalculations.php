<?php

/*
 * The recalculation papers (إعادة احتساب): the client fills what only the client has (dates, wages, costs, balances per the
 * books) and the system recalculates and compares with the books. Methods: App\Services\WorkingPapers\Recalculations.
 * Each has its own request when the checklist is applied (the blank form is made from the definition).
 */
return [
    'section' => 'إعادة الاحتساب (يملؤها العميل ويرجعها)',
    // key => the request's title.
    'papers' => [
        'eosb' => 'إعادة احتساب مخصص نهاية الخدمة للموظفين',
        'depreciation' => 'إعادة احتساب الإهلاك وصافي قيمة الأصول الثابتة',
        'prepaid' => 'إعادة احتساب المصروفات المدفوعة مقدماً (عقود الإيجار والتأمين وغيرها)',
        'ecl' => 'إعادة احتساب مخصص الديون المشكوك في تحصيلها (بأعمار الديون)',
        'payroll' => 'مطابقة الرواتب مع السداد وكشف حساب البنك',
    ],

    // End-of-service benefit (Saudi Labour Law arts. 84–85): half a month's wage a year for the first five years, a month's
    // after; the wage is the basic plus allowances. «five_years» is the five years in days over 365 (1826 / 365), as the firm's
    // own recalculation sheets count the boundary. A resignation is paid a share by length of service (art. 85).
    'eosb' => [
        'five_years' => 1826 / 365,
        'resignation' => [[2, 0.0], [5, 1 / 3], [10, 2 / 3], [PHP_INT_MAX, 1.0]],   // [up to (years), share]
    ],

    // Receivables allowance by age: the rate of each age band, to the accountant's judgement (editable in the paper's header).
    'ecl' => [
        'bands' => ['current' => ['غير مستحقة', 0.005], 'd90' => ['حتى 90 يوماً', 0.02], 'd180' => ['91 – 180 يوماً', 0.10], 'd365' => ['181 – 365 يوماً', 0.25], 'over' => ['أكثر من 365 يوماً', 0.50]],
    ],

    // Differences below this are rounding.
    'tolerance' => 1.0,
];
