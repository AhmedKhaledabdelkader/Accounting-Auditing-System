<?php

/*
 * The working papers the client fills and sends back (resources/working-papers: the firm's blank forms). Applying the
 * checklist to a client adds one request per paper, with the form attached and what to fill said in plain words (read from
 * the form itself by WorkingPaperReader). The form's own title and purpose are the request's title and instructions.
 *
 * key => file. The three recalculation papers (service benefits, depreciation, prepaid expenses, receivables allowance,
 * payroll) are in config/recalculations.php.
 */
return [
    'section' => 'أوراق العمل (يملؤها العميل ويرجعها)',
    'papers' => [
        'sales-cutoff' => 'sales-cutoff.xlsx',
        'bank-confirmations' => 'bank-confirmations.xlsx',
        'customer-confirmations' => 'customer-confirmations.xlsx',
        'supplier-confirmations' => 'supplier-confirmations.xlsx',
        'inventory' => 'inventory.xlsx',
        'ppe-additions' => 'ppe-additions.xlsx',
        'ppe-movement' => 'ppe-movement.xlsx',
        'depreciation-analytics' => 'depreciation-analytics.xlsx',
        'payroll-analytics' => 'payroll-analytics.xlsx',
        'investment-property' => 'investment-property.xlsx',
        'equity-method' => 'equity-method.xlsx',
        'prepaid-expenses' => 'prepaid-expenses.xlsx',
        'equity' => 'equity.xlsx',
        'loans' => 'loans.xlsx',
        'unrecorded-liabilities-a' => 'unrecorded-liabilities-a.xlsx',
        'unrecorded-liabilities-b' => 'unrecorded-liabilities-b.xlsx',
    ],
];
