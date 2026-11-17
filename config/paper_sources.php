<?php

/*
 * Which of the client's documents each working paper and recalculation is prepared from (PaperMaker): the sections of the
 * requirements (config/audit_requirements.php) whose uploads the AI reads for it. A word of the section's name is enough.
 * kind.key => words.
 */
return [
    'max_files' => 12,            // documents read for one paper
    'sheet_rows' => 400,          // rows of a spreadsheet the AI is given, per sheet
    'tb_rows' => 600,             // accounts of the trial balance the AI is given
    'sources' => [
        'recalc.eosb' => ['مخصصات', 'المصاريف العمومية', 'عامة'],
        'recalc.depreciation' => ['الموجودات الثابتة'],
        'recalc.prepaid' => ['مدفوعة مقدماً', 'عامة'],
        'recalc.ecl' => ['الذمم المدينة'],
        'recalc.payroll' => ['المصاريف العمومية', 'النقدية لدى البنوك', 'عامة'],
        'paper.sales-cutoff' => ['الإيرادات', 'الذمم المدينة'],
        'paper.bank-confirmations' => ['النقدية لدى البنوك'],
        'paper.customer-confirmations' => ['الذمم المدينة'],
        'paper.supplier-confirmations' => ['الذمم الدائنة'],
        'paper.inventory' => ['المخزون'],
        'paper.ppe-additions' => ['الموجودات الثابتة'],
        'paper.ppe-movement' => ['الموجودات الثابتة'],
        'paper.depreciation-analytics' => ['الموجودات الثابتة'],
        'paper.payroll-analytics' => ['المصاريف العمومية'],
        'paper.investment-property' => ['الاستثمارات'],
        'paper.equity-method' => ['الاستثمارات'],
        'paper.prepaid-expenses' => ['مدفوعة مقدماً'],
        'paper.equity' => ['عامة', 'القوائم المالية'],
        'paper.loans' => ['القروض'],
        'paper.unrecorded-liabilities-a' => ['مصاريف مستحقة', 'الذمم الدائنة', 'الالتزامات'],
        'paper.unrecorded-liabilities-b' => ['مصاريف مستحقة', 'الذمم الدائنة', 'الالتزامات'],
    ],
];
