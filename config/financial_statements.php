<?php

// Financial statements generator: the line catalogue and the rules that map a classified account to a statement line.
// Keyed by template_version (resources/templates/<version>); line keys match the hidden key column of that version's
// statements.xlsx. Reviewed by an accountant through PRs (plan Q3). No AI anywhere in the generator (plan Q11).
//
// Category names here are the taxonomy's initial names: after a rename on the taxonomy page they are rewritten to the
// current name when the app starts (App\Support\Taxonomy\TaxonomyRules lists every place), and a sub-class added there
// gets its chosen line in `sub_classes`.
//
// Rule values (sub_classes, main_classes):
//   'sofp.x'                              the line
//   ['line' => 'sofp.x', 'confirm' => …]  the line, pending until the user confirms (the reason is shown)
//   ['function' => 'general_admin']        an expense by nature: the line comes from its function (plan Q4, Q34)
//   ['choose' => [lines…], 'reason' => …, 'reason_key' => …]  unmapped until the user picks one: the class covers lines of
//   different nature; reason_key: the reason in every language (report_messages.choose_reasons)
//   ['partners_current' => true]           the partners' current account: liability or equity, per report (plan Q23)

return [
    'template_version' => env('FS_TEMPLATE_VERSION', 'sa-ifrs-smes-3'),

    // Whole riyals (Q27): the most a total's rounding difference may be to go on a line under it (WholeRiyals); above it
    // the check is an error.
    'rounding_limit' => (float) env('FS_ROUNDING_LIMIT', 5),
    // The calculated zakat and income tax are charged into the statements by themselves (the zakat step can take one out). Tests of the
    // older flows switch it off (phpunit.xml); the auto-charge tests switch it on.
    'auto_charge' => (bool) env('FS_AUTO_CHARGE', true),

    // How a negative amount is written on every screen and file (App\Support\Amounts, R1): 'parentheses' (1,234) or
    // 'minus' -1,234. Zero is always «-».
    'negatives' => env('FS_NEGATIVES', 'parentheses'),

    // Last year's issued statements as a PDF (PriorStatementsPdf): the AI finds the statements' pages and copies each
    // statement's table as printed; PHP reads the amounts and matches the lines, as for an Excel file. qpdf cuts the pages
    // out (it must be installed: docs/08). A larger file is refused before anything is sent.
    'prior_pdf' => [
        'qpdf'      => env('QPDF_BINARY', 'qpdf'),
        'max_kb'    => (int) env('PRIOR_PDF_MAX_KB', 20480),
        'max_pages' => (int) env('PRIOR_PDF_MAX_PAGES', 150),
        // The AI's answers, kept by the file's content (PriorPdf\Llm): reading the same file again pays only for what is new.
        'cache'     => env('PRIOR_PDF_CACHE', storage_path('app/private/prior-statements/cache')),
    ],

    'templates' => [
        'sa-ifrs-smes-3' => [

            // Statement lines that take account balances. side = where a normal balance sits; amounts are signed by it.
            'lines' => [
                'sofp.ppe'                                => ['label_ar' => 'الممتلكات والآلات والمعدات', 'side' => 'debit'],
                'sofp.investment_property'                => ['label_ar' => 'استثمارات عقارية', 'side' => 'debit'],
                'sofp.other_assets'                       => ['label_ar' => 'أصول أخرى', 'side' => 'debit'],   // pre-operating expenses, net of their amortisation
                'sofp.intangible_assets'                  => ['label_ar' => 'الأصول غير الملموسة', 'side' => 'debit'],
                'sofp.associates'                         => ['label_ar' => 'استثمارات في شركات زميلة ومشروعات مشتركة', 'side' => 'debit'],
                'sofp.fvoci_investments'                  => ['label_ar' => 'استثمارات في أدوات حقوق الملكية بالقيمة العادلة من خلال الدخل الشامل الآخر', 'side' => 'debit'],
                'sofp.amortised_cost_non_current'         => ['label_ar' => 'استثمارات بالتكلفة المطفأة (غير متداولة)', 'side' => 'debit'],
                'sofp.inventory'                          => ['label_ar' => 'المخزون', 'side' => 'debit'],
                'sofp.contract_assets'                    => ['label_ar' => 'أصول العقود (إيرادات غير مفوترة)', 'side' => 'debit'],
                'sofp.trade_receivables'                  => ['label_ar' => 'الذمم التجارية المدينة', 'side' => 'debit'],
                'sofp.prepayments_other_receivables'      => ['label_ar' => 'مصاريف مقدمة وأرصدة مدينة أخرى', 'side' => 'debit'],
                'sofp.amortised_cost_current'             => ['label_ar' => 'استثمارات بالتكلفة المطفأة (متداولة)', 'side' => 'debit'],
                'sofp.due_from_related_parties'           => ['label_ar' => 'مستحق من أطراف ذات علاقة', 'side' => 'debit'],
                'sofp.murabaha_deposits'                  => ['label_ar' => 'ودائع مرابحة لأجل لدى البنوك', 'side' => 'debit'],
                'sofp.cash'                               => ['label_ar' => 'النقد ومعادلات النقد', 'side' => 'debit'],
                'sofp.share_capital'                      => ['label_ar' => 'رأس المال', 'side' => 'credit'],
                'sofp.statutory_reserve'                  => ['label_ar' => 'الاحتياطي النظامي', 'side' => 'credit'],
                'sofp.other_reserves'                     => ['label_ar' => 'احتياطيات أخرى', 'side' => 'credit'],
                'sofp.retained_earnings'                  => ['label_ar' => 'أرباح (خسائر) مبقاة', 'side' => 'credit'],
                'sofp.partners_current_equity'            => ['label_ar' => 'الحسابات الجارية للشركاء', 'side' => 'credit'],
                'sofp.murabaha_loans_non_current'         => ['label_ar' => 'قروض عقود مرابحة', 'side' => 'credit'],
                'sofp.lease_liabilities_non_current'      => ['label_ar' => 'التزامات عقود الإيجار (غير متداولة)', 'side' => 'credit'],
                'sofp.employee_benefits'                  => ['label_ar' => 'مخصص التزامات منافع الموظفين', 'side' => 'credit'],
                'sofp.due_to_related_parties_non_current' => ['label_ar' => 'مستحق لأطراف ذات علاقة طويلة الأجل', 'side' => 'credit'],
                'sofp.trade_payables'                     => ['label_ar' => 'ذمم تجارية دائنة', 'side' => 'credit'],
                'sofp.murabaha_loans_current'             => ['label_ar' => 'قروض عقود مرابحة وأقساط قروض قصيرة الأجل', 'side' => 'credit'],
                'sofp.lease_liabilities_current'          => ['label_ar' => 'التزامات عقود الإيجار (الجزء المتداول)', 'side' => 'credit'],
                'sofp.bank_overdraft'                     => ['label_ar' => 'سحب على المكشوف', 'side' => 'credit'],
                'sofp.contract_liabilities'               => ['label_ar' => 'التزامات العقود (إيرادات مؤجلة) - الجزء المتداول', 'side' => 'credit'],
                'sofp.dividends_payable'                  => ['label_ar' => 'دائنو توزيعات أرباح (توزيعات أرباح مستحقة)', 'side' => 'credit'],
                'sofp.accruals_other_payables'            => ['label_ar' => 'مصاريف مستحقة وأرصدة دائنة أخرى', 'side' => 'credit'],
                'sofp.due_to_related_parties'             => ['label_ar' => 'مستحق لأطراف ذات علاقة', 'side' => 'credit'],
                'sofp.partners_current_liability'         => ['label_ar' => 'الحسابات الجارية للشركاء', 'side' => 'credit'],
                'sofp.zakat_provision'                    => ['label_ar' => 'مخصص الزكاة', 'side' => 'credit'],
                'sofp.income_tax_provision'               => ['label_ar' => 'مخصص ضريبة الدخل', 'side' => 'credit'],
                'sofp.other_provisions'                   => ['label_ar' => 'مخصصات أخرى', 'side' => 'credit'],

                'is.revenue'                   => ['label_ar' => 'إيرادات النشاط', 'side' => 'credit'],
                'is.cost_of_revenue'           => ['label_ar' => 'تكلفة النشاط', 'side' => 'debit'],
                'is.selling_marketing'         => ['label_ar' => 'مصاريف البيع والتسويق', 'side' => 'debit'],
                'is.general_admin'             => ['label_ar' => 'المصروفات العمومية والإدارية', 'side' => 'debit'],
                'is.depreciation'              => ['label_ar' => 'مصروف الإهلاك', 'side' => 'debit'],
                'is.amortisation'              => ['label_ar' => 'مصروف الإطفاء', 'side' => 'debit'],   // separate lines (R5)
                'is.other_expenses'            => ['label_ar' => 'مصروفات أخرى', 'side' => 'debit'],
                'is.other_income'              => ['label_ar' => 'إيرادات أخرى', 'side' => 'credit'],
                'is.impairment_losses'         => ['label_ar' => 'خسائر الانخفاض في قيمة الذمم المدينة التجارية', 'side' => 'debit'],
                'is.impairment_reversals'      => ['label_ar' => 'رد خسائر الانخفاض في قيمة الذمم المدينة التجارية', 'side' => 'credit'],
                'is.finance_cost'              => ['label_ar' => 'تكاليف التمويل', 'side' => 'debit'],
                'is.dividend_income'           => ['label_ar' => 'توزيعات أرباح من أدوات حقوق الملكية', 'side' => 'credit'],
                'is.share_of_associates'       => ['label_ar' => 'الحصة في نتائج شركات زميلة ومشروعات مشتركة', 'side' => 'credit'],
                'is.zakat'                     => ['label_ar' => 'الزكاة الشرعية', 'side' => 'debit'],
                'is.income_tax'                => ['label_ar' => 'ضريبة الدخل', 'side' => 'debit'],
            ],

            // Main class → line. A sub-class rule below wins over its main class.
            'main_classes' => [
                'النقد ومافي حكمه'                          => 'sofp.cash',
                'الذمم المدينة التجارية'                     => 'sofp.trade_receivables',
                'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى'   => 'sofp.prepayments_other_receivables',   // advances to suppliers too (Q35)
                'المخزون'                                   => 'sofp.inventory',
                'الممتلكات و الالات و المعدات'              => 'sofp.ppe',                              // CWIP too: its own note column
                'اصول غير ملموسة'                           => 'sofp.intangible_assets',
                'أصول أخرى'                                 => 'sofp.other_assets',
                'اطراف ذات علاقة مدينة'                     => 'sofp.due_from_related_parties',
                'الذمم الدائنة التجارية'                     => 'sofp.trade_payables',
                'مصروفات مستحقةو ارصدة دائنة اخرى'           => 'sofp.accruals_other_payables',         // advances from customers too (Q35)
                'قروض قصيرة الأجل'                          => 'sofp.murabaha_loans_current',
                'قروض طويلة الأجل'                          => 'sofp.murabaha_loans_non_current',       // current portion: user split
                'التزامات عقود الإيجار'                     => 'sofp.lease_liabilities_non_current',    // current portion: user split
                'مخصص نهاية الخدمة'                         => 'sofp.employee_benefits',
                'مخصص الزكاة الشرعية'                       => 'sofp.zakat_provision',
                'مخصص ضريبة الدخل'                          => 'sofp.income_tax_provision',
                'مخصصات أخرى'                               => 'sofp.other_provisions',
                'رأس المال'                                 => 'sofp.share_capital',
                'احتياطي نظامي'                             => 'sofp.statutory_reserve',
                'الارباح المبقاه'                           => 'sofp.retained_earnings',
                'اطراف ذات علاقة دائنة'                     => 'sofp.due_to_related_parties',

                'إيرادات النشاط'          => 'is.revenue',
                'إيرادات أخرى'            => 'is.other_income',
                'ارباح و خسائر رأسمالية'  => 'is.other_income',
                'تكلفة البضاعة المباعة'   => 'is.cost_of_revenue',   // periodic opening / closing stock too (Q32)

                'اجور و رواتب و مافي حكمها'      => ['function' => 'general_admin'],
                'سكن العاملين'                   => ['function' => 'general_admin'],
                'مصروفات تعبئة وتغليف'           => ['function' => 'general_admin'],
                'مصروفات السلامة'                => ['function' => 'general_admin'],
                'غرامات ومخالفات'                => ['function' => 'other'],
                'تأمينات اجتماعية'               => ['function' => 'general_admin'],
                'مكافآت وحوافز العاملين'         => ['function' => 'general_admin'],
                'إيجارات'                        => ['function' => 'general_admin'],
                'كهرباء ومياه وغاز'              => ['function' => 'general_admin'],
                'اتصالات وإنترنت'                => ['function' => 'general_admin'],
                'صيانة وإصلاحات'                 => ['function' => 'general_admin'],
                'وقود ومحروقات'                  => ['function' => 'general_admin'],
                'نقل وانتقالات'                  => ['function' => 'general_admin'],
                'مهمات وأدوات مكتبية'            => ['function' => 'general_admin'],
                'دعاية وإعلان'                   => ['function' => 'selling'],
                'تسويق ومبيعات'                  => ['function' => 'selling'],
                'عمولات بيع'                     => ['function' => 'selling'],
                'سفر وإقامة'                     => ['function' => 'general_admin'],
                'تدريب وتأهيل'                   => ['function' => 'general_admin'],
                'مصروفات بريد وشحن'              => ['function' => 'general_admin'],
                'مصروفات تأمين'                  => ['function' => 'general_admin'],
                'كهرباء ومياه المقر الإداري'     => ['function' => 'general_admin'],
                'أتعاب مهنية واستشارات'          => ['function' => 'general_admin'],
                'أتعاب مراجعة ومحاسبة'           => ['function' => 'general_admin'],
                'مصروفات قانونية'                => ['function' => 'general_admin'],
                'قرطاسية ومطبوعات'               => ['function' => 'general_admin'],
                'ضيافة واستقبال'                 => ['function' => 'general_admin'],
                'اشتراكات وبرامج'                => ['function' => 'general_admin'],
                'مصروفات بنكية'                  => ['function' => 'general_admin'],
                'رسوم وتراخيص'                   => ['function' => 'general_admin'],
                'مصروفات متنوعة'                 => ['function' => 'other'],
                'فوائد قروض'                     => ['function' => 'finance'],
                'فوائد تسهيلات ائتمانية'         => ['function' => 'finance'],
                'عمولات تمويلية'                 => ['function' => 'finance'],
                'مصروفات تمويلية'                => ['function' => 'finance'],
                'إهلاك الأصول الثابتة'           => ['function' => 'depreciation'],
                'إهلاك المباني'                  => ['function' => 'depreciation'],
                'إهلاك الآلات والمعدات'          => ['function' => 'depreciation'],
                'إهلاك السيارات'                 => ['function' => 'depreciation'],
                'إهلاك الأثاث والتجهيزات'        => ['function' => 'depreciation'],
                'إهلاك أجهزة الكمبيوتر'          => ['function' => 'depreciation'],
                'إطفاء الأصول غير الملموسة'      => ['function' => 'amortisation'],
                'خسائر وانخفاض قيمة'             => ['function' => 'other'],
                'مصروف الزكاة الشرعية'           => 'is.zakat',
                'مصروف ضريبة الدخل'              => 'is.income_tax',
                'ضريبة الدخل المؤجلة'            => 'is.income_tax',
            ],

            'sub_classes' => [
                'إيرادات مستحقة'           => ['line' => 'sofp.contract_assets', 'confirm' => 'وُضعت إيرادات مستحقة في أصول العقود (إيرادات غير مفوترة)؛ وإن كانت مستحقات أخرى (فوائد مثلًا) فانقلها إلى أرصدة مدينة أخرى.'],
                'استثمارات قصيرة الأجل'    => ['choose' => ['sofp.amortised_cost_current', 'sofp.murabaha_deposits'], 'reason' => 'قد تكون الاستثمارات قصيرة الأجل بالتكلفة المطفأة أو ودائع مرابحة: اختر السطر.', 'reason_key' => 'short_term_investments'],
                'استثمارات طويلة الأجل'    => ['choose' => ['sofp.associates', 'sofp.investment_property', 'sofp.fvoci_investments', 'sofp.amortised_cost_non_current'], 'reason' => 'قد تكون الاستثمارات طويلة الأجل شركات زميلة أو عقارات استثمارية أو أدوات ملكية أو بالتكلفة المطفأة: اختر السطر.', 'reason_key' => 'long_term_investments'],
                'استثمارات عقارية'         => 'sofp.investment_property',
                'استثمارات في شركات زميلة' => 'sofp.associates',
                'توزيعات أرباح مستحقة'     => 'sofp.dividends_payable',
                'إيرادات مؤجلة'            => 'sofp.contract_liabilities',
                'رد خسائر الانخفاض'        => 'is.impairment_reversals',
                'احتياطي عام'              => 'sofp.other_reserves',
                'احتياطيات أخرى'           => 'sofp.other_reserves',
                'جاري الشريك'              => ['partners_current' => true],
                'إيرادات استثمارات'        => ['line' => 'is.other_income', 'confirm' => 'وُضعت إيرادات الاستثمارات في الإيرادات الأخرى؛ وإن كانت توزيعات أرباح أو حصة في شركة زميلة فانقلها إلى السطر الخاص بها.'],
                'خسائر فروق عملة'          => ['function' => 'other'],
                'مصروف ديون مشكوك في تحصيلها' => 'is.impairment_losses',
                'خسائر مخزون'              => ['function' => 'cost'],
            ],

            // Applied on the balance of each column group: a balance on the given side moves the account to the line.
            'side_rules' => [
                ['main_class' => 'النقد ومافي حكمه', 'side' => 'credit', 'line' => 'sofp.bank_overdraft'],              // Q25
                ['main_class' => 'اطراف ذات علاقة مدينة', 'side' => 'credit', 'line' => 'sofp.due_to_related_parties'],
                ['main_class' => 'اطراف ذات علاقة دائنة', 'side' => 'debit', 'line' => 'sofp.due_from_related_parties'],
            ],

            // Function → line (expenses by function, Q4).
            'functions' => [
                'cost'          => 'is.cost_of_revenue',
                'selling'       => 'is.selling_marketing',
                'general_admin' => 'is.general_admin',
                'depreciation'  => 'is.depreciation',
                'amortisation'  => 'is.amortisation',
                'finance'       => 'is.finance_cost',
                'other'         => 'is.other_expenses',
            ],

            // A function named in the file's own classification column (Q34): word stems after ImportNormalizer, checked in
            // this order («تكاليف التمويل» is finance, not cost). The client's label is a default, never trusted over a check.
            'function_labels' => [
                'finance'       => ['تمويل', 'فوائد', 'فايد'],
                'amortisation'  => ['اطفاء'],
                'depreciation'  => ['اهلاك', 'استهلاك'],
                'selling'       => ['بيع', 'تسويق', 'توزيع'],
                'general_admin' => ['عموميه', 'اداري'],
                'cost'          => ['تكلف', 'تكالي'],
            ],

            // Partners' current account (Q23): a per-report choice, liability by default.
            'partners_current' => ['default' => 'liability', 'liability' => 'sofp.partners_current_liability', 'equity' => 'sofp.partners_current_equity'],

            // Flags on a row that make its mapping pending: the classification itself is in doubt (Q34).
            'pending_flags' => ['statement_mismatch', 'prefix_mismatch:high'],

            // Movement schedules (plan §3.6, PR 9): the notes' roll-forwards. Every amount is the trial balance's, a
            // movement suggestion the user confirms, or the user's; nothing is assumed.
            // - asset: the rows on `line`. A row whose sub-class is a contra (credit) account is accumulated depreciation;
            //   the others are cost. A row's category is the user's (account override `category`), else its sub-class's
            //   below; a side with any row of no category has no per-category figures (Q8), only the total.
            //   `charge`: the expense main classes of the year's depreciation (check 16). Columns = notes.docx placeholders.
            //   `revaluation`: the OCI revaluation surplus is a non-cash row of the note.
            // - provision: the rows on `line` (or of `sub_classes`); `increase` and `decrease` name the template rows.
            //   `charge`: the P&L counterpart of the increase (sub_classes, main_classes, or the rows the user tags
            //   `provision_charge`); `reversal`: the P&L classes a reversal is credited to besides the charge classes.
            //   `counterpart: false`: none is expected (dividends are declared from retained earnings).
            'schedules' => [
                'ppe' => ['kind' => 'asset', 'line' => 'sofp.ppe',
                    'categories' => [
                        'land'      => ['أراضي'],
                        'buildings' => ['مباني وإنشاءات'],
                        'machinery' => ['آلات ومعدات', 'عدد و ادوات'],
                        'furniture' => ['أثاث وتجهيزات', 'اجهزة كهربائية', 'مكيفات'],
                        'computers' => ['أجهزة كمبيوتر ومعدات تكنولوجيا المعلومات'],
                        'vehicles'  => ['سيارات ووسائل نقل'],
                        'cwip'      => ['أصول ثابتة تحت التنفيذ', 'مشروعات تحت التنفيذ'],
                    ],
                    'transfers_from' => 'cwip',
                    'revaluation' => true,   // the revaluation model: the year's surplus (OCI) is a row of the note
                    'charge' => ['main_classes' => ['إهلاك الأصول الثابتة', 'إهلاك المباني', 'إهلاك الآلات والمعدات', 'إهلاك السيارات', 'إهلاك الأثاث والتجهيزات', 'إهلاك أجهزة الكمبيوتر']]],
                'intangible_assets' => ['kind' => 'asset', 'line' => 'sofp.intangible_assets',
                    'categories' => ['software' => ['برمجيات وتراخيص'], 'licences' => [], 'other' => ['علامات تجارية', 'شهرة']],
                    'charge' => ['main_classes' => ['إطفاء الأصول غير الملموسة']]],
                'investment_property' => ['kind' => 'asset', 'line' => 'sofp.investment_property',
                    'categories' => ['land' => [], 'buildings' => []]],

                'employee_benefits' => ['kind' => 'provision', 'line' => 'sofp.employee_benefits', 'increase' => 'charge', 'decrease' => 'paid',
                    'charge' => ['sub_classes' => ['مصروف مكافأة نهاية الخدمة']]],
                'zakat' => ['kind' => 'provision', 'line' => 'sofp.zakat_provision', 'increase' => 'charge', 'decrease' => 'paid', 'prior_year_adjustments' => true,
                    'charge' => ['main_classes' => ['مصروف الزكاة الشرعية']]],
                'other_provisions' => ['kind' => 'provision', 'line' => 'sofp.other_provisions', 'increase' => 'charge', 'decrease' => 'used',
                    'charge' => ['tag' => 'provision_charge']],
                'receivables_impairment' => ['kind' => 'provision', 'sub_classes' => ['مخصص ديون مشكوك في تحصيلها'], 'increase' => 'charge', 'decrease' => 'written_off',
                    'charge' => ['sub_classes' => ['مصروف ديون مشكوك في تحصيلها']], 'reversal' => ['sub_classes' => ['رد خسائر الانخفاض']]],
                'dividends_payable' => ['kind' => 'provision', 'line' => 'sofp.dividends_payable', 'increase' => 'declared', 'decrease' => 'paid', 'counterpart' => false],

                // Q36: gross loans, less the deferred finance cost the user tags `deferred_finance_cost` (netted on the loan line).
                'loans' => ['kind' => 'loans', 'lines' => ['sofp.murabaha_loans_non_current', 'sofp.murabaha_loans_current']],
            ],

            // Account tags (account overrides `tags`).
            'tags' => [
                'deferred_finance_cost' => 'sofp.murabaha_loans_non_current',   // the row is netted against the loans, on this line unless a line is given
                'provision_charge'      => null,                                 // the row is the P&L counterpart of the other provisions
                'not_disposal'          => null,                                 // a disposal result kept as other income or expenses (classification pending)
            ],

            // Gains and losses on disposal of assets (check 18: proceeds − net book value disposed).
            'disposal_results' => ['أرباح بيع أصول ثابتة', 'خسائر بيع أصول ثابتة'],

            // Other assets (sofp.other_assets): the accumulated amortisation, whose increase is the year's amortisation in
            // the cash flow (CashFlowBuilder); the rest of the line's change is paid (investing).
            'other_assets_amortisation' => ['مجمع إطفاء أصول أخرى'],
            // Supplier finance arrangements (the trade payables note's question): an account named with one of these (whole
            // words), with a balance, makes «نعم» the suggested answer; the accountant confirms it.
            'supplier_finance_words' => ['تسهيلات موردين', 'تسهيلات الموردين', 'تمويل موردين', 'تمويل الموردين'],

            // The statements' rows in template order (statements.xlsx, hidden column J): headings, lines and totals. A total is
            // the sum of the keys in `sum` as displayed: SOFP lines positive on their side; IS income +, expenses −.
            // An `input` row is a user-entered figure (OCI). The comparatives come with a later step.
            'statements' => [
                'financial_position' => [
                    ['heading' => 'الأصول'],
                    ['heading' => 'الأصول غير المتداولة'],
                    'sofp.ppe', 'sofp.other_assets', 'sofp.investment_property', 'sofp.intangible_assets', 'sofp.associates', 'sofp.fvoci_investments', 'sofp.amortised_cost_non_current',
                    ['total' => 'sofp.total_non_current_assets', 'label_ar' => 'إجمالي الأصول غير المتداولة',
                        'sum' => ['sofp.ppe', 'sofp.other_assets', 'sofp.investment_property', 'sofp.intangible_assets', 'sofp.associates', 'sofp.fvoci_investments', 'sofp.amortised_cost_non_current']],
                    ['heading' => 'الأصول المتداولة'],
                    'sofp.inventory', 'sofp.contract_assets', 'sofp.trade_receivables', 'sofp.prepayments_other_receivables', 'sofp.amortised_cost_current',
                    'sofp.due_from_related_parties', 'sofp.murabaha_deposits', 'sofp.cash',
                    ['total' => 'sofp.total_current_assets', 'label_ar' => 'إجمالي الأصول المتداولة',
                        'sum' => ['sofp.inventory', 'sofp.contract_assets', 'sofp.trade_receivables', 'sofp.prepayments_other_receivables', 'sofp.amortised_cost_current',
                            'sofp.due_from_related_parties', 'sofp.murabaha_deposits', 'sofp.cash']],
                    ['total' => 'sofp.total_assets', 'label_ar' => 'إجمالي الأصول', 'sum' => ['sofp.total_non_current_assets', 'sofp.total_current_assets']],
                    ['heading' => 'حقوق الملكية والالتزامات'],
                    ['heading' => 'حقوق الملكية'],
                    'sofp.share_capital', 'sofp.statutory_reserve', 'sofp.other_reserves', 'sofp.retained_earnings', 'sofp.partners_current_equity',
                    ['total' => 'sofp.total_equity', 'label_ar' => 'إجمالي حقوق الملكية',
                        'sum' => ['sofp.share_capital', 'sofp.statutory_reserve', 'sofp.other_reserves', 'sofp.retained_earnings', 'sofp.partners_current_equity']],
                    ['heading' => 'الالتزامات غير المتداولة'],
                    'sofp.murabaha_loans_non_current', 'sofp.lease_liabilities_non_current', 'sofp.employee_benefits', 'sofp.due_to_related_parties_non_current',
                    ['total' => 'sofp.total_non_current_liabilities', 'label_ar' => 'إجمالي الالتزامات غير المتداولة',
                        'sum' => ['sofp.murabaha_loans_non_current', 'sofp.lease_liabilities_non_current', 'sofp.employee_benefits', 'sofp.due_to_related_parties_non_current']],
                    ['heading' => 'الالتزامات المتداولة'],
                    'sofp.trade_payables', 'sofp.murabaha_loans_current', 'sofp.lease_liabilities_current', 'sofp.bank_overdraft', 'sofp.contract_liabilities',
                    'sofp.dividends_payable', 'sofp.accruals_other_payables', 'sofp.due_to_related_parties', 'sofp.partners_current_liability',
                    'sofp.zakat_provision', 'sofp.income_tax_provision', 'sofp.other_provisions',
                    ['total' => 'sofp.total_current_liabilities', 'label_ar' => 'إجمالي الالتزامات المتداولة',
                        'sum' => ['sofp.trade_payables', 'sofp.murabaha_loans_current', 'sofp.lease_liabilities_current', 'sofp.bank_overdraft', 'sofp.contract_liabilities',
                            'sofp.dividends_payable', 'sofp.accruals_other_payables', 'sofp.due_to_related_parties', 'sofp.partners_current_liability',
                            'sofp.zakat_provision', 'sofp.income_tax_provision', 'sofp.other_provisions']],
                    ['total' => 'sofp.total_liabilities', 'label_ar' => 'إجمالي الالتزامات', 'sum' => ['sofp.total_non_current_liabilities', 'sofp.total_current_liabilities']],
                    ['total' => 'sofp.total_equity_and_liabilities', 'label_ar' => 'إجمالي حقوق الملكية والالتزامات', 'sum' => ['sofp.total_equity', 'sofp.total_liabilities']],
                ],
                'comprehensive_income' => [
                    'is.revenue', 'is.cost_of_revenue',
                    ['total' => 'is.gross_profit', 'label_ar' => 'مجمل الربح (الخسارة)', 'sum' => ['is.revenue', 'is.cost_of_revenue']],
                    ['heading' => 'يضاف (يخصم)'],
                    'is.selling_marketing', 'is.general_admin', 'is.depreciation', 'is.amortisation', 'is.other_expenses', 'is.other_income', 'is.impairment_losses', 'is.impairment_reversals',
                    ['total' => 'is.operating_profit', 'label_ar' => 'صافي الدخل من التشغيل',
                        'sum' => ['is.gross_profit', 'is.selling_marketing', 'is.general_admin', 'is.depreciation', 'is.amortisation', 'is.other_expenses', 'is.other_income',
                            'is.impairment_losses', 'is.impairment_reversals']],
                    'is.finance_cost', 'is.dividend_income', 'is.share_of_associates',
                    ['total' => 'is.profit_before_zakat', 'label_ar' => 'صافي الدخل (الخسارة) قبل الزكاة والضريبة',
                        'sum' => ['is.operating_profit', 'is.finance_cost', 'is.dividend_income', 'is.share_of_associates']],
                    'is.zakat', 'is.income_tax',
                    ['total' => 'is.net_profit', 'label_ar' => 'صافي الدخل (الخسارة) للسنة', 'sum' => ['is.profit_before_zakat', 'is.zakat', 'is.income_tax']],
                    // OCI: user input (intake `oci.*`), never from the trial balance.
                    ['heading' => 'الدخل الشامل الآخر'],
                    ['heading' => 'بنود لا يمكن إعادة تصنيفها لاحقاً إلى الأرباح أو الخسائر:'],
                    ['input' => 'is.oci_fvoci', 'label_ar' => 'صافي أرباح (خسائر) أدوات حقوق الملكية بالقيمة العادلة من خلال الدخل الشامل الآخر'],
                    ['input' => 'is.oci_actuarial', 'label_ar' => 'الأرباح (الخسائر) الإكتوارية لمكافأة نهاية الخدمة'],
                    ['input' => 'is.oci_revaluation', 'label_ar' => 'التغيرات في فائض إعادة تقويم الممتلكات والآلات والمعدات'],
                    ['input' => 'is.oci_translation', 'label_ar' => 'فروقات ترجمة العمليات الأجنبية'],
                    ['heading' => 'بنود يمكن إعادة تصنيفها لاحقاً إلى الأرباح أو الخسائر:'],
                    ['input' => 'is.oci_cash_flow_hedge', 'label_ar' => 'الحركة في القيمة العادلة لتحوط التدفقات النقدية'],
                    ['input' => 'is.oci_hedge_reclassification', 'label_ar' => 'تعديل إعادة التصنيف لتحوط التدفقات النقدية المحول إلى الأرباح أو الخسائر'],
                    ['input' => 'is.oci_associates', 'label_ar' => 'الحصة في الدخل الشامل الآخر للشركات الزميلة'],
                    ['total' => 'is.oci_total', 'label_ar' => 'الدخل (الخسارة) الشامل الآخر للسنة',
                        'sum' => ['is.oci_fvoci', 'is.oci_actuarial', 'is.oci_revaluation', 'is.oci_translation', 'is.oci_cash_flow_hedge', 'is.oci_hedge_reclassification', 'is.oci_associates']],
                    ['total' => 'is.total_comprehensive_income', 'label_ar' => 'إجمالي الدخل (الخسارة) الشامل للسنة', 'sum' => ['is.net_profit', 'is.oci_total']],
                ],
                // Indirect method (PR 10), as displayed: inflows +, outflows −. Every `cf` line is computed (CashFlowBuilder).
                'cash_flows' => [
                    ['heading' => 'التدفقات النقدية من الأنشطة التشغيلية'],
                    ['cf' => 'cf.profit_before_zakat', 'label_ar' => 'صافي دخل السنة قبل الزكاة والضريبة'],
                    ['heading' => 'تعديلات لمطابقة صافي ربح (خسارة) السنة مع صافي التدفقات النقدية من الأنشطة التشغيلية:'],
                    ['cf' => 'cf.depreciation_ppe', 'label_ar' => 'استهلاك ممتلكات وآلات ومعدات'],
                    ['cf' => 'cf.depreciation_investment_property', 'label_ar' => 'استهلاك استثمارات عقارية'],
                    ['cf' => 'cf.amortisation_intangibles', 'label_ar' => 'إطفاء أصول غير ملموسة'],
                    ['cf' => 'cf.amortisation_other_assets', 'label_ar' => 'إطفاء أصول أخرى'],
                    ['cf' => 'cf.eosb_charge', 'label_ar' => 'مخصص نهاية الخدمة'],
                    ['cf' => 'cf.receivables_impairment', 'label_ar' => 'صافي الحركة في مخصص الانخفاض في قيمة المدينين التجاريين'],
                    ['cf' => 'cf.inventory_writedown', 'label_ar' => 'صافي الحركة في مخصص الانخفاض في قيمة المخزون'],
                    ['cf' => 'cf.finance_cost', 'label_ar' => 'صافي تكلفة التمويل'],
                    ['cf' => 'cf.disposal_gain_loss', 'label_ar' => '(أرباح) خسائر بيع ممتلكات وآلات ومعدات'],
                    ['cf' => 'cf.fx_gain_loss', 'label_ar' => 'مكاسب التغير في أسعار الصرف، بالصافي'],
                    ['cf' => 'cf.provisions_released', 'label_ar' => 'مخصصات انتفى الغرض منها'],
                    ['cf' => 'cf.share_of_associates', 'label_ar' => 'الحصة في نتائج استثمارات في شركات زميلة ومشروعات مشتركة'],
                    ['cf' => 'cf.prior_year_adjustments', 'label_ar' => 'تسويات سنوات سابقة على الأرباح المبقاة'],
                    ['cf' => 'cf.other_retained_movements', 'label_ar' => 'حركات أخرى غير نقدية في الأرباح المبقاة'],
                    ['heading' => 'التغيرات في:'],
                    ['cf' => 'cf.wc_inventory', 'label_ar' => 'المخزون'],
                    ['cf' => 'cf.wc_contract_assets', 'label_ar' => 'أصول العقود (إيرادات غير مفوترة)'],
                    ['cf' => 'cf.wc_trade_receivables', 'label_ar' => 'الذمم التجارية المدينة'],
                    ['cf' => 'cf.wc_prepayments_other_receivables', 'label_ar' => 'مصاريف مقدمة وأرصدة مدينة أخرى'],
                    ['cf' => 'cf.wc_due_from_related_parties', 'label_ar' => 'مستحق من أطراف ذات علاقة'],
                    ['cf' => 'cf.wc_trade_payables', 'label_ar' => 'الذمم التجارية الدائنة'],
                    ['cf' => 'cf.wc_accruals_other_payables', 'label_ar' => 'مصروفات مستحقة وأرصدة دائنة أخرى'],
                    ['cf' => 'cf.wc_contract_liabilities', 'label_ar' => 'التزامات العقود (إيرادات مؤجلة) - الجزء المتداول'],
                    ['cf' => 'cf.wc_due_to_related_parties', 'label_ar' => 'مستحق إلى أطراف ذات علاقة'],
                    ['cf' => 'cf.wc_other_provisions', 'label_ar' => 'مخصصات أخرى'],
                    ['cf' => 'cf.eosb_paid', 'label_ar' => 'مدفوعات نهاية الخدمة للموظفين'],
                    ['cf' => 'cf.zakat_paid', 'label_ar' => 'الزكاة المدفوعة'],
                    ['cf' => 'cf.income_tax_paid', 'label_ar' => 'ضريبة الدخل المدفوعة'],
                    ['cf' => 'cf.finance_cost_paid', 'label_ar' => 'تكاليف تمويل مدفوعة'],
                    ['total' => 'cf.net_operating', 'label_ar' => 'صافي التدفقات النقدية من الأنشطة التشغيلية',
                        'sum' => ['cf.profit_before_zakat', 'cf.depreciation_ppe', 'cf.depreciation_investment_property', 'cf.amortisation_intangibles', 'cf.amortisation_other_assets', 'cf.eosb_charge',
                            'cf.receivables_impairment', 'cf.inventory_writedown', 'cf.finance_cost', 'cf.disposal_gain_loss', 'cf.fx_gain_loss', 'cf.provisions_released',
                            'cf.share_of_associates', 'cf.prior_year_adjustments', 'cf.other_retained_movements', 'cf.wc_inventory', 'cf.wc_contract_assets', 'cf.wc_trade_receivables', 'cf.wc_prepayments_other_receivables',
                            'cf.wc_due_from_related_parties', 'cf.wc_trade_payables', 'cf.wc_accruals_other_payables', 'cf.wc_contract_liabilities', 'cf.wc_due_to_related_parties',
                            'cf.wc_other_provisions', 'cf.eosb_paid', 'cf.zakat_paid', 'cf.income_tax_paid', 'cf.finance_cost_paid']],
                    ['heading' => 'التدفقات النقدية من الأنشطة الاستثمارية'],
                    ['cf' => 'cf.ppe_purchases', 'label_ar' => 'مدفوعات لشراء ممتلكات وآلات ومعدات'],
                    ['cf' => 'cf.ppe_disposal_proceeds', 'label_ar' => 'متحصلات من بيع ممتلكات وآلات ومعدات'],
                    ['cf' => 'cf.cwip_payments', 'label_ar' => 'مدفوعات لمشروعات تحت التنفيذ'],
                    ['cf' => 'cf.investment_property_purchases', 'label_ar' => 'مدفوعات لشراء استثمارات عقارية'],
                    ['cf' => 'cf.investment_property_disposal_proceeds', 'label_ar' => 'متحصلات من بيع استثمارات عقارية'],
                    ['cf' => 'cf.intangible_purchases', 'label_ar' => 'مدفوعات لشراء أصول غير ملموسة'],
                    ['cf' => 'cf.intangible_disposal_proceeds', 'label_ar' => 'متحصلات من بيع أصول غير ملموسة'],
                    ['cf' => 'cf.other_assets_payments', 'label_ar' => 'مدفوعات أصول أخرى'],
                    ['cf' => 'cf.equity_investment_purchases', 'label_ar' => 'مدفوعات لشراء استثمارات في أدوات حقوق الملكية'],
                    ['cf' => 'cf.equity_investment_proceeds', 'label_ar' => 'متحصلات من بيع استثمارات في أدوات حقوق الملكية'],
                    ['cf' => 'cf.associates_investment', 'label_ar' => 'استثمارات في شركات زميلة ومشروعات مشتركة'],
                    ['cf' => 'cf.amortised_cost_investments', 'label_ar' => 'صافي الحركة في الاستثمارات بالتكلفة المطفأة'],
                    ['cf' => 'cf.murabaha_deposits', 'label_ar' => 'صافي الحركة في ودائع المرابحة لأجل'],
                    ['total' => 'cf.net_investing', 'label_ar' => 'صافي التدفقات النقدية من الأنشطة الاستثمارية',
                        'sum' => ['cf.ppe_purchases', 'cf.ppe_disposal_proceeds', 'cf.cwip_payments', 'cf.investment_property_purchases', 'cf.investment_property_disposal_proceeds',
                            'cf.intangible_purchases', 'cf.intangible_disposal_proceeds', 'cf.other_assets_payments', 'cf.equity_investment_purchases', 'cf.equity_investment_proceeds', 'cf.associates_investment',
                            'cf.amortised_cost_investments', 'cf.murabaha_deposits']],
                    ['heading' => 'التدفقات النقدية من الأنشطة التمويلية'],
                    ['cf' => 'cf.loans_drawn', 'label_ar' => 'متحصلات قروض بالمرابحة'],
                    ['cf' => 'cf.loans_repaid', 'label_ar' => 'مدفوعات قروض بالمرابحة'],
                    ['cf' => 'cf.lease_payments', 'label_ar' => 'سداد التزامات عقود الإيجار'],
                    ['cf' => 'cf.capital_increase', 'label_ar' => 'متحصلات من زيادة رأس المال'],
                    ['cf' => 'cf.partners_current', 'label_ar' => 'صافي الحركة في الحسابات الجارية للشركاء'],
                    ['cf' => 'cf.due_to_related_parties_non_current', 'label_ar' => 'صافي الحركة في المستحق لأطراف ذات علاقة طويلة الأجل'],
                    ['cf' => 'cf.dividends_paid', 'label_ar' => 'توزيعات أرباح مدفوعة'],
                    ['cf' => 'cf.bank_overdraft', 'label_ar' => 'صافي الحركة في السحب على المكشوف'],
                    ['total' => 'cf.net_financing', 'label_ar' => 'صافي التدفقات النقدية من الأنشطة التمويلية',
                        'sum' => ['cf.loans_drawn', 'cf.loans_repaid', 'cf.lease_payments', 'cf.capital_increase', 'cf.partners_current', 'cf.due_to_related_parties_non_current',
                            'cf.dividends_paid', 'cf.bank_overdraft']],
                    ['total' => 'cf.net_change', 'label_ar' => 'صافي التدفقات النقدية', 'sum' => ['cf.net_operating', 'cf.net_investing', 'cf.net_financing']],
                    ['cf' => 'cf.opening_cash', 'label_ar' => 'رصيد النقدية في أول السنة'],
                    ['total' => 'cf.closing_cash', 'label_ar' => 'رصيد النقدية في آخر السنة', 'sum' => ['cf.net_change', 'cf.opening_cash']],
                    ['heading' => 'معاملات غير نقدية'],
                    ['cf' => 'cf.noncash_distributions_to_partners', 'label_ar' => 'توزيعات أرباح مقيدة على الحسابات الجارية للشركاء'],
                ],
            ],

            // Statement of changes in equity (PR 10, Q23): columns and the balance-sheet line each one rolls to; rows in
            // template order. Unpaid capital has no line: the share capital line is the net amount.
            'changes_in_equity' => [
                'columns' => [
                    'share_capital'     => ['line' => 'sofp.share_capital', 'label_ar' => 'رأس المال'],
                    'unpaid_capital'    => ['line' => null, 'label_ar' => 'رأس المال غير المدفوع'],
                    'statutory_reserve' => ['line' => 'sofp.statutory_reserve', 'label_ar' => 'الاحتياطي النظامي'],
                    'other_reserves'    => ['line' => 'sofp.other_reserves', 'label_ar' => 'احتياطيات أخرى'],
                    'retained_earnings' => ['line' => 'sofp.retained_earnings', 'label_ar' => 'أرباح مبقاة'],
                    'partners_current'  => ['line' => 'sofp.partners_current_equity', 'label_ar' => 'الحسابات الجارية للشركاء'],
                ],
                'rows' => [
                    'opening'                      => 'الرصيد في أول السنة',
                    'net_profit'                   => 'صافي الدخل (الخسارة) للسنة',
                    'oci'                          => 'الدخل (الخسارة) الشامل الآخر للسنة',
                    'total_comprehensive_income'   => 'إجمالي الدخل (الخسارة) الشامل للسنة',
                    'capital_increase'             => 'زيادة رأس المال',
                    'transfer_to_reserve'          => 'المحول إلى الاحتياطي النظامي',
                    'dividends'                    => 'توزيعات أرباح',
                    'prior_year_adjustments'       => 'تسويات سنوات سابقة',
                    'other_retained_movements'     => 'حركات أخرى في الأرباح المبقاة',
                    'transfer_to_partners_current' => 'المحول إلى الحسابات الجارية للشركاء',
                    'partners_current_movements'   => 'صافي الحركة في الحسابات الجارية للشركاء',
                    'closing'                      => 'الرصيد في آخر السنة',
                ],
            ],

            // Notes (PR 11, Q13): notes.docx's numbered notes, in order (a test keeps them in step with the template).
            // - `block`: the docx block holding the note; without one the note is always shown.
            // - `lines`: the statement lines that refer to the note («إيضاح» column); a figure ≠ 0 in either year shows it.
            // - `show`: another reason to show it (NotesBuilder): restated, first_application, going_concern, related_parties,
            //   significant_events. (No proposed-dividends note: the accountant's rule R4.)
            // - `breakdown`: the note's table lists its lines' accounts by class (placeholders <breakdown>_label, _cy, _py,
            //   _total_cy, _total_py); `group`: main_class for expenses by function (one row per nature), else the sub-class.
            // Visibility can be forced on or off by the user, except for notes shown always.
            'notes' => [
                'incorporation'       => [],
                'basis'               => [],
                'restatement'         => ['block' => 'note_restatement', 'show' => 'restated'],
                'first_application'   => ['block' => 'note_first_application', 'show' => 'first_application'],
                'going_concern'       => ['block' => 'note_going_concern', 'show' => 'going_concern'],
                'policies'            => [],
                'ppe'                 => ['block' => 'note_ppe', 'lines' => ['sofp.ppe']],
                'other_assets'        => ['block' => 'note_other_assets', 'lines' => ['sofp.other_assets'], 'breakdown' => 'other_assets'],   // cost less the accumulated amortisation
                'intangibles'         => ['block' => 'note_intangibles', 'lines' => ['sofp.intangible_assets']],
                'investment_property' => ['block' => 'note_investment_property', 'lines' => ['sofp.investment_property']],
                'inventory'           => ['block' => 'note_inventory', 'lines' => ['sofp.inventory'], 'breakdown' => 'inventory'],
                'trade_receivables'   => ['block' => 'note_trade_receivables', 'lines' => ['sofp.trade_receivables'], 'breakdown' => 'receivables'],   // gross less the impairment
                'other_receivables'   => ['block' => 'note_other_receivables', 'lines' => ['sofp.prepayments_other_receivables'], 'breakdown' => 'other_receivables'],
                'cash'                => ['block' => 'note_cash', 'lines' => ['sofp.cash'], 'breakdown' => 'cash'],
                'capital'             => ['lines' => ['sofp.share_capital']],
                'loans'               => ['block' => 'note_loans', 'lines' => ['sofp.murabaha_loans_non_current', 'sofp.murabaha_loans_current']],
                'employee_benefits'   => ['block' => 'note_employee_benefits', 'lines' => ['sofp.employee_benefits']],
                'trade_payables'      => ['block' => 'note_trade_payables', 'lines' => ['sofp.trade_payables'], 'breakdown' => 'trade_payables'],
                'other_payables'      => ['block' => 'note_other_payables', 'lines' => ['sofp.accruals_other_payables'], 'breakdown' => 'other_payables'],
                'related_parties'     => ['block' => 'note_related_parties', 'lines' => ['sofp.due_from_related_parties', 'sofp.due_to_related_parties', 'sofp.due_to_related_parties_non_current'], 'show' => 'related_parties'],
                'zakat'               => ['block' => 'note_zakat', 'lines' => ['sofp.zakat_provision', 'is.zakat']],
                'other_provisions'    => ['block' => 'note_other_provisions', 'lines' => ['sofp.other_provisions']],
                'revenue'             => ['block' => 'note_revenue', 'lines' => ['is.revenue', 'sofp.contract_assets', 'sofp.contract_liabilities'], 'breakdown' => 'revenue', 'breakdown_lines' => ['is.revenue']],
                'cost_of_revenue'     => ['block' => 'note_cost_of_revenue', 'lines' => ['is.cost_of_revenue'], 'breakdown' => 'cost_of_revenue', 'group' => 'main_class'],
                'selling'             => ['block' => 'note_selling', 'lines' => ['is.selling_marketing'], 'breakdown' => 'selling', 'group' => 'main_class'],
                'general_admin'       => ['block' => 'note_general_admin', 'lines' => ['is.general_admin'], 'breakdown' => 'general_admin', 'group' => 'main_class'],
                'other_income'        => ['block' => 'note_other_income', 'lines' => ['is.other_income'], 'breakdown' => 'other_income'],
                'finance_cost'        => ['block' => 'note_finance_cost', 'lines' => ['is.finance_cost'], 'breakdown' => 'finance_cost', 'group' => 'main_class'],
                'financial_risk'      => [],
                'subsequent_events'   => [],
                'significant_events'  => ['block' => 'note_significant_events', 'show' => 'significant_events'],
                'approval'            => [],
            ],

            // Policies shown when their line is ≠ 0 in either year (Q13), or forced by the user. `lines` may name a schedule
            // category instead (`ppe:cwip`: the capital work in progress column of the PPE note).
            'policies' => [
                'policy_ppe'                 => ['sofp.ppe'],
                'policy_intangibles'         => ['sofp.intangible_assets'],
                'policy_investment_property' => ['sofp.investment_property'],
                'policy_murabaha_deposits'   => ['sofp.murabaha_deposits'],
                'policy_inventory'           => ['sofp.inventory'],
                'policy_associates'          => ['sofp.associates'],
                'policy_cwip'                => ['ppe:cwip'],
                'policy_leases_lessee'       => ['sofp.lease_liabilities_non_current', 'sofp.lease_liabilities_current'],
            ],

            // The cost-of-revenue build-up of a periodic inventory system (Q32, block cost_periodic_inventory): the
            // sub-classes of each row of the build-up. An account of the inventory family moved to cost of revenue by the
            // user (a periodic stock account) is opening stock with a debit balance, closing stock with a credit balance.
            'periodic_cost' => [
                'opening'        => ['مخزون أول المدة'],
                'purchases'      => ['تكلفة المشتريات', 'مردودات المشتريات', 'خصم مكتسب'],
                'purchase_costs' => ['مصاريف نقل المشتريات', 'جمارك ورسوم استيراد'],
                'closing'        => ['مخزون آخر المدة'],
                'inventory_family' => 'المخزون',
                // The sub-class whose name the combined cost-of-goods-sold row of the note takes.
                'cost_of_goods_sold' => 'تكلفة البضاعة المباعة',
            ],

            // Check 26e: the classes where a related party's or partner's accounts may sit (related parties, receivables,
            // payables, prepayments, accruals, salaries; the partners' current account).
            'related_parties' => [
                'main_classes' => ['اطراف ذات علاقة مدينة', 'اطراف ذات علاقة دائنة', 'الذمم المدينة التجارية', 'الذمم الدائنة التجارية',
                    'اجور و رواتب و مافي حكمها', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى', 'مصروفات مستحقةو ارصدة دائنة اخرى'],
                'sub_classes'  => ['جاري الشريك'],
            ],

            // Accounts that show foreign-currency transactions (the foreign-currency policy, and the risk note's sentence).
            'foreign_currency' => ['sub_classes' => ['خسائر فروق عملة']],

            // Q6, E1: the 3rd edition applies to periods starting on or after this date; a period starting earlier applies
            // it early and says so.
            'edition_effective' => '2027-01-01',

            // The xlsx export (PR 14): where statements.xlsx keeps each thing. Rows and columns are found by the keys in the
            // template's hidden key columns and rows; only the columns are named here.
            'xlsx' => [
                'file' => 'statements.xlsx',
                'data' => ['sheet' => 'data', 'key' => 'C', 'value' => 'B'],
                'cover' => ['sheet' => 'الغلاف', 'draft_row' => 15, 'columns' => 'A:J'],
                'statements' => [
                    'financial_position'   => ['sheet' => 'المركز المالى', 'key' => 'J', 'label' => 'A', 'note' => 'B', 'current' => 'E', 'comparative' => 'H',
                        'comparative_columns' => ['G', 'H'], 'comparative_header' => 'G4'],
                    'comprehensive_income' => ['sheet' => 'قائمة الدخل الشامل', 'key' => 'J', 'label' => 'A', 'note' => 'B', 'current' => 'E', 'comparative' => 'H',
                        'comparative_columns' => ['G', 'H'], 'comparative_header' => 'G6'],
                    'cash_flows'           => ['sheet' => 'قائمة التدفقات النقدية', 'key' => 'J', 'label' => 'B', 'note' => 'C', 'current' => 'E', 'comparative' => 'H',
                        'comparative_columns' => ['G', 'H'], 'comparative_header' => 'G5'],
                ],
                // Keys `soce.<scope>.<row>` in column A, column keys in row 5; each amount column has a spacer after it.
                'changes_in_equity' => ['sheet' => 'قائمة التغيرات', 'key' => 'A', 'label' => 'C', 'columns_row' => 5,
                    'spacers' => ['E' => 'F', 'G' => 'H', 'I' => 'J', 'K' => 'L', 'M' => 'N', 'O' => 'P'], 'always' => ['opening', 'net_profit', 'total_comprehensive_income', 'closing']],
                // Keys `ppe.<row>` in column A (the template's names → the schedule's rows), column keys in row 5, the categories'
                // names in row 7 (written by the exporter: the note's columns, NotesBuilder).
                'ppe' => ['sheet' => 'ايضاح الاصول والممتلكات', 'key' => 'A', 'columns_row' => 5, 'header_row' => 7, 'schedule' => 'ppe', 'note' => 'ppe',
                    'rows' => ['acc_dep_opening' => 'acc_opening', 'acc_dep_disposals' => 'acc_disposals', 'acc_dep_closing' => 'acc_closing', 'nbv_comparative' => 'nbv_opening'],
                    'comparative_rows' => ['nbv_comparative'], 'always' => ['cost_opening', 'cost_closing', 'acc_dep_opening', 'acc_dep_closing', 'nbv_closing', 'nbv_comparative']],
            ],
        ],
    ],
];
