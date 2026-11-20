<?php

/*
 * Matching what the client's documents and the recalculations show against the year's trial balance (App\Services\Audit).
 * Accounts are found by their classification (main class / sub class, a word of the name is enough) and, within those, by the
 * reference a document prints (account number, IBAN) or the counterparty's name.
 * `side`: the balance's natural side (debit: an asset or an expense, shown positive when debit; credit: a liability or a provision).
 */
return [
    // Differences below this are rounding (the accountant's rule: under 2 riyals).
    'tolerance' => (float) env('TB_MATCH_TOLERANCE', 2),

    // What a document's balance is, and the accounts of the trial balance it is compared with.
    'documents' => [
        'bank' => ['label' => 'رصيد البنك', 'classes' => ['نقدية لدى البنوك', 'البنوك', 'النقد ومافي حكمه'], 'side' => 'debit'],
        'cash' => ['label' => 'النقدية بالصندوق', 'classes' => ['نقدية بالصندوق', 'الصندوق'], 'side' => 'debit'],
        'customer' => ['label' => 'رصيد العميل', 'classes' => ['عملاء', 'أوراق قبض', 'الذمم المدينة التجارية'], 'side' => 'debit'],
        'supplier' => ['label' => 'رصيد المورد', 'classes' => ['موردون', 'الذمم الدائنة التجارية'], 'side' => 'credit'],
        'loan' => ['label' => 'رصيد القرض', 'classes' => ['قروض', 'تمويل', 'مرابحة'], 'side' => 'credit'],
        'related_party' => ['label' => 'رصيد الطرف ذو العلاقة', 'classes' => ['اطراف ذات علاقة', 'جاري الشريك', 'جاري الشركاء'], 'side' => null],
        'zakat' => ['label' => 'الزكاة المستحقة', 'classes' => ['مخصص الزكاة'], 'side' => 'credit'],
        'vat' => ['label' => 'ضريبة القيمة المضافة', 'classes' => ['ضريبة القيمة المضافة'], 'side' => null],
        'gosi' => ['label' => 'التأمينات الاجتماعية', 'classes' => ['تأمينات اجتماعية'], 'side' => null],
        'inventory' => ['label' => 'المخزون', 'classes' => ['المخزون', 'مخزون'], 'side' => 'debit'],
        'other' => ['label' => 'رصيد', 'classes' => [], 'side' => null],
    ],

    // The trial balance's figure each recalculation is compared with (its natural side), and the adjusting entry a difference calls for:
    // `more` when the recalculation is above the books (debit, credit), `less` when below.
    'papers' => [
        'recalc.eosb' => ['classes' => ['مخصص نهاية الخدمة', 'مخصص مكافأة نهاية الخدمة'], 'side' => 'credit',
            'more' => ['مصروف مكافأة نهاية الخدمة', 'مخصص نهاية الخدمة'], 'less' => ['مخصص نهاية الخدمة', 'مصروف مكافأة نهاية الخدمة']],
        'recalc.depreciation' => ['classes' => ['الممتلكات و الالات و المعدات', 'الممتلكات والآلات والمعدات', 'الأصول الثابتة'], 'side' => 'debit',
            // the net book value: above the books means too much depreciation was charged
            'more' => ['مجمع إهلاك الأصول الثابتة', 'مصروف الإهلاك'], 'less' => ['مصروف الإهلاك', 'مجمع إهلاك الأصول الثابتة']],
        'recalc.prepaid' => ['classes' => ['مصروفات مدفوعة مقدماً', 'مصروفات مدفوعة مقدما', 'إيجار مدفوع مقدماً', 'تأمين مدفوع مقدماً'], 'side' => 'debit', 'sub_only' => true,
            'more' => ['مصروفات مدفوعة مقدماً', 'المصروف المعني'], 'less' => ['المصروف المعني', 'مصروفات مدفوعة مقدماً']],
        'recalc.ecl' => ['classes' => ['مخصص ديون مشكوك في تحصيلها', 'مخصص خسائر ائتمانية'], 'side' => 'credit', 'sub_only' => true,
            'more' => ['مصروف ديون مشكوك في تحصيلها', 'مخصص ديون مشكوك في تحصيلها'], 'less' => ['مخصص ديون مشكوك في تحصيلها', 'مصروف ديون مشكوك في تحصيلها']],
        'recalc.payroll' => null,   // compared with the payments and the bank, not with a balance
    ],
];
