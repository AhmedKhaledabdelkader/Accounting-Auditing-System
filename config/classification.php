<?php

// A value not set yet: empty, or the .env placeholder <need-to-change>. Treated as missing, never sent anywhere.
$value = fn (string $key) => in_array($v = env($key), [null, '', '<need-to-change>'], true) ? null : $v;

return [
    // AI provider: anthropic | gemini | openai. Switching = CLASSIFIER_PROVIDER only; each provider keeps its own key and model,
    // and a key is never sent to another provider. After changing .env: php artisan queue:restart (workers keep the old config).
    // Add one: a class implementing App\Support\Llm\ClassifierDriver, one line in AppServiceProvider, its block here, its prices.
    'provider'  => env('CLASSIFIER_PROVIDER', 'anthropic'),
    'providers' => [
        // Models must accept a forced tool call: claude-sonnet-5 (default), claude-haiku-4-5, claude-opus-5 (not the 5.5 / Fable 5.1 models).
        'anthropic' => [
            'api_key'  => $value('ANTHROPIC_API_KEY'),
            'model'    => $value('CLASSIFIER_ANTHROPIC_MODEL') ?? 'claude-sonnet-5',
            'base_url' => $value('CLASSIFIER_ANTHROPIC_BASE_URL'),   // empty = the official API
        ],
        'openai' => [
            'api_key'          => $value('OPENAI_API_KEY'),
            'model'            => $value('CLASSIFIER_OPENAI_MODEL') ?? 'gpt-6-astra',
            'base_url'         => $value('CLASSIFIER_OPENAI_BASE_URL'),
            // low | medium | high, or empty = the model's default. Reasoning tokens count against the output limit.
            'reasoning_effort' => $value('CLASSIFIER_OPENAI_REASONING_EFFORT'),
            'max_output_tokens' => (int) ($value('CLASSIFIER_OPENAI_MAX_OUTPUT') ?? 8000),   // per request; counts against the per-minute token limit
        ],
        'gemini' => [
            'api_key'        => $value('GEMINI_API_KEY'),
            'model'          => $value('CLASSIFIER_GEMINI_MODEL') ?? 'gemini-3.8-flash',
            'base_url'       => $value('CLASSIFIER_GEMINI_BASE_URL'),
            // low | medium | high. Thinking tokens count against the output limit and are billed as output: low keeps a
            // batch's answer from being cut off by its own reasoning. Empty = the model's default (medium).
            'thinking_level' => $value('CLASSIFIER_GEMINI_THINKING_LEVEL') ?? 'low',
        ],
    ],
    // A few classes still have a sub class of their own name (main = sub): zakat, income tax, amortisation, other income. On the classifier page and
    // in its CSV such a row shows the GROUP its main class belongs to instead, so the two columns are not the same word twice. Display only: what is
    // stored, the rules and the statements keep the taxonomy's own main class. main class (config name) => group.
    'display_groups' => [
        'مصروف الزكاة الشرعية' => 'الزكاة وضريبة الدخل',
        'مصروف ضريبة الدخل' => 'الزكاة وضريبة الدخل',
        'ضريبة الدخل المؤجلة' => 'الزكاة وضريبة الدخل',
        'إطفاء الأصول غير الملموسة' => 'الإهلاك والإطفاء',
        'إيرادات أخرى' => 'إيرادات ومكاسب أخرى',
    ],
    'batch_size'       => (int) env('CLASSIFIER_BATCH_SIZE', 100),   // data rows per classification call
    // A trial balance (full or partial) whose opening, movement or closing debits and credits differ by this or more is
    // refused (ClassificationService::fileChecks): only rounding below it passes.
    'reject_unbalanced_from' => (float) env('CLASSIFIER_REJECT_UNBALANCED_FROM', 1.00),
    // Classification requests in flight at once (batches + the matching call); a finished one frees its slot for the next.
    // Invalid or below 1 → 4.
    'concurrency'      => filter_var(env('CLASSIFIER_CONCURRENCY', 4), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 4]]),
    'review_threshold' => 70,     // classification_confidence below this → needs_review

    // USD per million tokens, per model (LlmCost), each price with the day it takes effect (UTC; null = always). A run is
    // costed at the price in effect when it started. A model missing here gets no cost (null), never a guess.
    // cache_write = 5-minute cache writes (Anthropic, what classifyPrompt uses); Gemini caches implicitly: no write price.
    // Anthropic: https://platform.claude.com/docs/en/about-claude/pricing (checked 2026-10-02).
    // OpenAI (gpt-6-astra): no price known yet, so its runs show no cost (null, never a guess): add it here once known.
    // Gemini: https://ai.google.dev/gemini-api/docs/pricing (paid tier, page updated 2026-10-07, checked 2026-10-08).
    'prices' => [
        'claude-sonnet-5'   => [['from' => null, 'input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.20, 'output' => 10.00]],
        'claude-sonnet-5-5' => [['from' => null, 'input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.20, 'output' => 10.00]],
        'claude-haiku-4-5'  => [['from' => null, 'input' => 1.00, 'cache_write' => 1.25, 'cache_read' => 0.10, 'output' => 5.00]],
        'claude-opus-5-5'   => [['from' => null, 'input' => 4.00, 'cache_write' => 5.00, 'cache_read' => 0.20, 'output' => 20.00]],
        'gemini-3.8-flash'  => [
            ['from' => null,         'input' => 0.75, 'cache_write' => 0, 'cache_read' => 0.075, 'output' => 3.75],
            ['from' => '2027-01-01', 'input' => 1.50, 'cache_write' => 0, 'cache_read' => 0.15,  'output' => 7.50],
        ],
    ],
    'savings_recent_runs' => 20,   // runs averaged for the cost per row when a run sent no rows to the AI

    // Classification cache (ClassificationCache): rows seen before are labelled from the DB instead of the AI.
    'cache' => [
        // Off until the classification_cache migration has run (with the table missing, every run would fail).
        'enabled'          => (bool) env('CLASSIFIER_CACHE', false),
        'min_confidence'   => (int) env('CLASSIFIER_CACHE_MIN_CONFIDENCE', 90),   // AI answers below this are never stored or reused
        // Bump when the taxonomy or the classification prompt changes: older AI answers are then ignored (manual corrections are kept).
        // 2: periodic opening/closing stock in cost of sales, «مخصصات أخرى», note_reference column.
        'taxonomy_version' => (string) env('CLASSIFIER_TAXONOMY_VERSION', '3'),
    ],

    // Category names in the rules below (side_pairs, import) are the taxonomy's config names: after a rename on the taxonomy
    // page, TaxonomyRules rewrites them to the current name when the app starts (docs/16-taxonomy.md).
    // Sub-classes that depend on the balance side. Whatever the AI or the cache answers (any source, the name layer too), the
    // row's side re-decides within the pair: debit → the first debit member, credit → the first credit member. The side is the
    // closing balance's, then the opening's, then the net movement's; all zero: the cached sub-class stays. flag = a member on the
    // side opposite the pair's base (a supplier in debit, a customer in credit) needs a review; a flip to the base side doesn't. A manual correction that contradicts the row's side when made is locked (never flipped).
    // by_name: side => [member => words]: the member when the account name has one of the words, else the first member.
    // only_names: the pair flips only accounts whose name has one of these; others keep the cached sub-class with a review
    // flag (the side contradicts the class). Words match whole words after normalization (ImportNormalizer: «ق.م» = «ق م»).
    // base: the side whose member the cache stores (ClassificationCache::baseClass), so an entry holds the account's kind and
    // not the side it had once; the side with more members, so nothing is lost (عُهد vs سلف, VAT vs accrued VAT).
    'side_pairs' => [
        'employees' => ['label' => 'عهد وسلف العاملين ↔ أمانات العاملين', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['عُهد العاملين', 'سلف العاملين'], 'credit' => ['أمانات العاملين'],
            'by_name' => ['debit' => ['سلف العاملين' => ['سلف', 'سلفه', 'سلفيات']]]],
        'vat'       => ['label' => 'ضريبة القيمة المضافة (مدينة ↔ دائنة)', 'enabled' => true, 'flag' => false, 'base' => 'credit',
            'debit' => ['حسابات مدينة لدى الجهات الحكومية'], 'credit' => ['ضريبة القيمة المضافة', 'ضريبة القيمة المضافة المستحقة'],
            'only_names' => ['ضريبة القيمة المضافة', 'القيمة المضافة', 'ق.م', 'ض.ق.م', 'ضريبة ق.م', 'vat']],
        'customers' => ['label' => 'عملاء ↔ دفعات مقدمة من العملاء', 'enabled' => true, 'flag' => true, 'base' => 'debit',
            'debit' => ['عملاء'], 'credit' => ['دفعات مقدمة من العملاء']],
        'suppliers' => ['label' => 'دفعات مقدمة للموردين ↔ موردون', 'enabled' => true, 'flag' => true, 'base' => 'credit',
            'debit' => ['دفعات مقدمة للموردين'], 'credit' => ['موردون']],
        'employee_accounts' => ['label' => 'عُهد وسُلف العاملين ↔ عُهد وسُلف دائنه', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['عُهد وسُلف العاملين'], 'credit' => ['عُهد وسُلف دائنه']],
        'expenses'  => ['label' => 'مصروفات مدفوعة مقدماً ↔ مصروفات مستحقة', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['مصروفات مدفوعة مقدماً'], 'credit' => ['مصروفات مستحقة']],
        // The accountant's answers of 2026-10-20: a class on the wrong side goes to its counterpart. A class in two pairs
        // flips by the first one (pairOf), so each pair below is reached only from the class listed in it alone.
        'sundry'    => ['label' => 'مدينون متنوعون ↔ دائنون متنوعون', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['مدينون متنوعون'], 'credit' => ['دائنون متنوعون']],
        'other_balances' => ['label' => 'أرصدة مدينة أخرى ↔ أرصدة دائنة أخرى', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['أرصدة مدينة أخرى'], 'credit' => ['أرصدة دائنة أخرى']],
        'revenue'   => ['label' => 'إيرادات مستحقة ↔ إيرادات مؤجلة', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['إيرادات مستحقة'], 'credit' => ['إيرادات مؤجلة']],
        'supplier_advances' => ['label' => 'سلف الموردين ↔ موردون', 'enabled' => true, 'flag' => true, 'base' => 'debit',
            'debit' => ['سلف الموردين'], 'credit' => ['موردون']],
        'taxes'     => ['label' => 'ضرائب مستحقة ↔ حسابات مدينة لدى الجهات الحكومية', 'enabled' => true, 'flag' => false, 'base' => 'credit',
            'debit' => ['حسابات مدينة لدى الجهات الحكومية'], 'credit' => ['ضرائب مستحقة']],
        'deposits'  => ['label' => 'تأمينات لدى الغير ↔ أرصدة دائنة أخرى', 'enabled' => true, 'flag' => false, 'base' => 'debit',
            'debit' => ['تأمينات لدى الغير'], 'credit' => ['أرصدة دائنة أخرى']],
        'salaries'  => ['label' => 'رواتب وأجور مستحقة ↔ سلف العاملين', 'enabled' => true, 'flag' => false, 'base' => 'credit',
            'debit' => ['سلف العاملين'], 'credit' => ['رواتب وأجور مستحقة']],
    ],

    // Importing trial balances already classified by accountants (CacheImport): الميزانية → main class, then
    // (main class, الإيضاحات) → sub-class, each distinct value mapped once. Nothing reaches the cache before apply.
    'import' => [
        'llm_batch_size' => 150,   // distinct (main class, الإيضاحات) pairs per mapping call
        // Decisions on الميزانية values, matched after normalization (ImportNormalizer), before the taxonomy and the LLM.
        // main = a main class; route = the sub-class comes from الإيضاحات across the whole taxonomy; skip = never imported.
        'budget_overrides' => [
            'أصول حق الاستخدام'              => ['main' => 'الممتلكات و الالات و المعدات', 'flag' => 'أصول حق استخدام (IFRS 16): وُضعت تحت الممتلكات، وتحتاج إلى مراجعة المحاسب.'],
            'اصول حق استخدام'                => ['main' => 'الممتلكات و الالات و المعدات', 'flag' => 'أصول حق استخدام (IFRS 16): وُضعت تحت الممتلكات، وتحتاج إلى مراجعة المحاسب.'],
            'العقارات الاستثمارية'           => ['main' => 'استثمارات', 'sub' => 'استثمارات عقارية', 'reason' => 'IAS 40: تُعرض العقارات الاستثمارية بمفردها وليس مع الممتلكات.'],
            'أصول الأوقاف'                   => ['skip' => 'أصول أوقاف: لا يوجد لها تصنيف في الشجرة.'],
            'أصول عقود'                      => ['skip' => 'أصول عقود (IFRS 15)، وليست عقود إيجار.'],
            'التزامات العقود'                => ['skip' => 'التزامات عقود (IFRS 15)، وليست عقود إيجار.'],
            'التزامات عقود طويلة الاجل'      => ['skip' => 'التزامات عقود (IFRS 15)، وليست عقود إيجار.'],
            'التزام ايجار قصير الاجل'        => ['main' => 'التزامات عقود الإيجار'],
            'التزام ايجار طويل الاجل'        => ['main' => 'التزامات عقود الإيجار'],
            'اطفاء الأصول غير الملموسة'      => ['main' => 'إطفاء الأصول غير الملموسة'],
            'ارباح و خسائر رأسمالية'         => ['main' => 'ارباح و خسائر رأسمالية'],
            'توزيعات ارباح'                  => ['skip' => 'غامض (صف واحد).'],
            'ضريبة الدخل'                    => ['skip' => 'غامض: مخصص ولا مصروف (صف واحد).'],
            'الاربارح والخسائر'              => ['main' => 'الارباح المبقاه'],
            'أرباح وخسائر'                   => ['main' => 'الارباح المبقاه'],
            'مخصص الزكاة والدخل'             => ['main' => 'مخصص الزكاة الشرعية'],
            'ايراد مستحق'                    => ['main' => 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى'],
            'ضريبة القيمة المضافة'           => ['main' => 'مصروفات مستحقةو ارصدة دائنة اخرى'],
            'ضرائب مستحقة'                   => ['main' => 'مصروفات مستحقةو ارصدة دائنة اخرى'],
            // Cost of revenue: an open accounting decision (policies.cost_of_revenue).
            'تكاليف المبيعات'                => ['main' => 'تكلفة الإيرادات', 'policy' => 'cost_of_revenue'],
            'تكلفة المبيعات'                 => ['main' => 'تكلفة الإيرادات', 'policy' => 'cost_of_revenue'],
            'تكلفة الإيرادات'                => ['main' => 'تكلفة الإيرادات', 'policy' => 'cost_of_revenue'],
            'تكاليف الإيرادات'               => ['main' => 'تكلفة الإيرادات', 'policy' => 'cost_of_revenue'],
            'صافي الاصول المقيدة'            => ['skip' => 'حقوق ملكية جهة غير هادفة للربح: لا يوجد لها تصنيف في الشجرة.'],
            'صافي الاصول غير المقيدة'        => ['skip' => 'حقوق ملكية جهة غير هادفة للربح: لا يوجد لها تصنيف في الشجرة.'],
            'صافي أصول الأوقاف'              => ['skip' => 'حقوق ملكية جهة غير هادفة للربح: لا يوجد لها تصنيف في الشجرة.'],
            'المحافظ الإقراضية - اسر منتجة'  => ['skip' => 'محافظ إقراضية لجهة غير هادفة للربح: لا يوجد لها تصنيف في الشجرة.'],
        ],
        // Notes decided by the closing balance side, whatever the line item: matched after normalization (ImportNormalizer).
        // Employee advances are usually debit assets; a credit balance is money held for employees. The side is the closing
        // balance's; when it is zero, the opening balance's, then the net movement's; all zero: the row is not imported.
        'side_rules' => [
            ['notes' => ['عهد وسلف موظفين', 'عهد وسلف الموظفين', 'عهد وسلف', 'ذمم وعهد موظفين', 'عهد موظفين'],
                'debit' => 'عُهد العاملين', 'credit' => 'أمانات العاملين'],
            ['notes' => ['ذمم موظفين', 'ذمم الموظفين', 'سلف موظفين', 'سلف الموظفين'],
                'debit' => 'سلف العاملين', 'credit' => 'أمانات العاملين'],
        ],
        // Open accounting decisions. The LLM answers both ways once (by function, within function_main; and by nature,
        // any sub-class); mode picks which one rows use. Switch: change mode, then `php artisan classification:import --reapply=<id>`.
        'policies' => [
            'cost_of_revenue' => [
                'mode'          => env('CLASSIFIER_IMPORT_COST_OF_REVENUE', 'function'),   // function | nature
                'function_main' => 'تكلفة الإيرادات',
                'label'         => 'تكاليف المبيعات / تكلفة الإيرادات',
                'decision'      => 'مصروفات تكلفة الإيراد (إيجار، محروقات، صيانة، تأمينات…) تبقى تكلفة إيراد (بضاعة → تكلفة البضاعة المباعة، '
                    .'خدمات → تكلفة الخدمات المقدمة) أم تُصنَّف بحسب طبيعتها (إيجار → إيجارات)؟ فالتصنيف بحسب الطبيعة يدمجها مع المصروفات الإدارية ويغيّر مجمل الربح. '
                    .'قرار مفتوح للمحاسب.',
            ],
        ],
        // Name-only layer: used after the normal key misses, for names (+ closing balance side) most companies agree on.
        'name_layer' => [
            'min_share'     => (int) env('CLASSIFIER_NAME_LAYER_MIN_SHARE', 80),     // % of companies on the same sub-class
            'min_companies' => (int) env('CLASSIFIER_NAME_LAYER_MIN_COMPANIES', 2),
            // Never used for these names (after normalization): they mean different things in different companies.
            'generic_names' => [
                'أخرى', 'اخرى', 'متنوعة', 'متنوعه', 'عام', 'عامة', 'نثريات', 'مصروفات أخرى', 'مصروفات متنوعة', 'مصاريف أخرى',
                'مصاريف متنوعة', 'مصروفات عامة', 'إيرادات أخرى', 'ايرادات متنوعة', 'أرصدة مدينة أخرى', 'أرصدة دائنة أخرى',
                'مدينون متنوعون', 'دائنون متنوعون', 'حسابات أخرى', 'جاري', 'تسوية', 'تسويات', 'إجمالي', 'الإجمالي',
            ],
        ],
    ],

    // Numeric checks (PHP only, independent of the AI).
    // A row with one of these flags gets at most this confidence (AI and cache answers; manual corrections and imported
    // accountant labels keep theirs), and its answer is never stored in the classification cache.
    'confidence_caps' => [
        'abnormal_balance' => 60,   // abnormal_opening / abnormal_closing: the balance is on the wrong side for the sub_class
        'prefix_mismatch'  => 50,   // the account number's first digit belongs to another family in this file
    ],
    // Account-number check: the file's own numbering scheme, inferred from the file (no hard-coded first-digit rule).
    // Per first digit: a digit counts when at least min_accounts OTHER classified accounts start with it and min_share of
    // them share one family (the tested account is never its own evidence).
    // Per statement: the first digits split into a balance-sheet range and an income-statement range (e.g. 1–3 / 4–9) when
    // at least statement_min_share of the accounts agree and each range has min_accounts; an account on the wrong side is
    // flagged high (it moves profit). Codes only need to start with a digit here (1204MBA009 counts as 1).
    'prefix_check' => ['min_accounts' => 5, 'min_share' => 0.8, 'min_length' => 2, 'statement_min_share' => 0.95],
    'materiality_threshold'    => 1.00, // abnormal balances at or below this are rounding, not flagged
    // A credit closing balance on cash is a booking mistake (box) or an overdraft (bank): flagged plainly, with a warning.
    // The accountant's rules (ClassificationRules, docs/17). Off in the test suite (phpunit.xml): its tests turn them on,
    // so a suite on a shared database (MySQL keeps the seeded rules for every test) behaves as on SQLite.
    'rules_enabled' => (bool) env('CLASSIFIER_RULES', true),
    // Classes whose balance is normal on either side, never flagged abnormal: retained earnings in debit are accumulated losses.
    'either_side_classes' => ['أرباح محتجزة', 'أرباح أو خسائر العام الحالي', 'أرباح أو خسائر سنوات سابقة'],
    'cash_classes' => ['box' => ['نقدية بالصندوق'], 'bank' => ['نقدية لدى البنوك']],
    // An account whose name has one of these (whole words) is marked not to be used: movement on it is flagged.
    'unused_words' => ['لا تستخدم', 'لا تستخدمه', 'لاتستخدم', 'لاتستخدمه', 'لا يستخدم', 'غير مستخدم'],
    'reconciliation_tolerance' => 0.01, // allowed |closing - (opening + movement)|

    // File structure detection (AI reads a preview, Laravel validates and extracts exact values).
    'max_sheets'             => 10,   // sheets included in the structure preview
    'structure_preview_rows' => 30,   // first non-empty rows per sheet sent for structure detection
    'context_rows'           => 3,    // neighbouring rows sent with each batch (context only)
    'balance_tolerance'      => 0.01, // allowed difference for debit/credit and total-row checks

    // Structure checks (StructureChecks): the AI's column mapping against the file itself.
    // "error" rejects the mapping (sent back to the AI for the retry); "doubt" only adds a warning for the user.
    // Shares are 0–1. Uncertain checks are doubts only; tighten from storage/logs/classification-*.log.
    'structure_checks' => [
        'min_values'         => 5,                                  // ratio checks (except amount_numeric's error) need this many values
        'amount_numeric'     => ['error' => 0.5, 'doubt' => 0.8],   // share of numbers among a mapped amount column's filled cells
        'name_fill'          => ['error' => 0.05, 'doubt' => 0.5],  // share of rows with amounts that have a name
        'name_text'          => ['error' => 0.1, 'doubt' => 0.6],   // share of names that are text, not numbers
        'number_code'        => ['doubt' => 0.6],                   // share of account numbers that look like codes
        'number_distinct'    => ['doubt' => 0.1],                   // share of distinct account numbers (repeats are allowed: 3 stacked branches = 0.33)
        'side_values'        => ['error' => 0.2, 'doubt' => 0.8],   // share of debit/credit words in a side column
        'text_numeric'       => ['doubt' => 0.5],                   // notes / requests / file classification: share of numbers above this
        'group_fill'         => ['doubt' => 0.02],                  // share of data rows with any value in a balance group (adjustments are exempt: sparse by nature)
        'row_reconciliation' => ['doubt' => 0.5, 'min_rows' => 10], // share of rows where closing = opening + movement (+ adjustments)
        'groups_swapped'     => ['share' => 0.9, 'min_rows' => 5],   // error: of the rows an opening↔closing or movement↔closing exchange
                                                                    // decides (reconcile one way only), at least this share favour the exchange
                                                                    // (calibrated: correct mappings 0%, swapped 100%, on every file)
        'header_numeric'     => ['doubt' => 0.5, 'min_values' => 3],// share of numbers among the header cells of mapped columns
        'first_rows'         => 5,                                  // one of the first N data rows must have a name and an amount
        'debit_credit_swap'  => ['doubt' => 0.6, 'min_rows' => 20], // share of classified accounts on the abnormal side

        // Queued runs wait for the user to confirm the mapping on any error, or on these doubts (the ones that make
        // the results wrong). Other doubts stay warnings. The sync endpoint never waits.
        'pause_on' => ['group_contradiction', 'row_reconciliation', 'rows_skipped', 'amount_numeric', 'side_values', 'group_fill', 'name_fill', 'header_rows'],
    ],
    // A running run whose heartbeat is older than this has no live worker. Heartbeats come at every stage, before every
    // AI attempt and after every response: the longest silence is one 120 s call plus a capped 60 s Retry-After wait.
    'stale_minutes'      => 10,
    'confirmation_hours' => 24,   // an unconfirmed run's file and column preview are deleted after this (classification:prune)
    'log_channel' => env('CLASSIFIER_LOG_CHANNEL', 'classification'),   // where every structure issue is logged (config/logging.php)

    // The families of the main classes below (TaxonomyRepository::FAMILIES): the statement side each one sits on. An
    // income-statement main class not listed is an expense. Seed and no-database fallback, like the taxonomy.
    'taxonomy_families' => [
        'assets'      => ['النقد ومافي حكمه', 'الذمم المدينة التجارية', 'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى',
            'المخزون', 'الممتلكات و الالات و المعدات', 'اصول غير ملموسة', 'أصول أخرى', 'استثمارات', 'اطراف ذات علاقة مدينة'],
        'liabilities' => ['الذمم الدائنة التجارية', 'مصروفات مستحقةو ارصدة دائنة اخرى', 'قروض قصيرة الأجل',
            'مخصص نهاية الخدمة', 'مخصص الزكاة الشرعية', 'مخصص ضريبة الدخل', 'قروض طويلة الأجل',
            'التزامات عقود الإيجار', 'اطراف ذات علاقة دائنة', 'مخصصات أخرى'],
        'equity'      => ['رأس المال', 'احتياطي نظامي', 'الارباح المبقاه'],
        'revenue'     => ['إيرادات النشاط', 'إيرادات أخرى', 'ارباح و خسائر رأسمالية'],
        'cost'        => ['تكلفة الإيرادات'],
    ],

    // The initial taxonomy: financial_statement => main_class => [sub_class => normal_side]. Since the taxonomy page
    // (docs/16-taxonomy.md) it lives in the taxonomy_* tables, seeded from this list by their migration; this list is only
    // read when those tables don't exist (tests without a database). Edit the categories in the app, not here.
    // normal_side ('debit' | 'credit') is the side a normal balance sits on; the abnormal-balance
    // check (ClassificationService) relies on it, so contra accounts carry the opposite side of their group.
    'taxonomy' => [

        'قائمة المركز المالي' => [

            'النقد ومافي حكمه' => [
                'نقدية بالصندوق'  => 'debit',
                'نقدية لدى البنوك' => 'debit',
            ],

            'الذمم المدينة التجارية' => [
                'عملاء'                      => 'debit',
                'أوراق قبض'                  => 'debit',   // moved from other debit balances
                'مخصص ديون مشكوك في تحصيلها' => 'credit',  // contra
            ],

            'مصروفات مدفوعة مقدما و ارصدة مدينة اخرى' => [
                'مدينون متنوعون'                  => 'debit',
                'حسابات مدينة لدى الجهات الحكومية' => 'debit',
                'تأمينات لدى الغير'               => 'debit',
                'عُهد العاملين'                   => 'debit',   // custody/imprest, separate from advances
                'سلف العاملين'                    => 'debit',
                'عُهد وسُلف العاملين'             => 'debit',   // the accountant's (2026-10-19): an employee's own account or a custody, debit
                'سلف الموردين'                    => 'debit',
                'دفعات مقدمة للموردين'            => 'debit',
                'مصروفات مدفوعة مقدماً'           => 'debit',
                'إيرادات مستحقة'                  => 'debit',
                'أرصدة مدينة أخرى'                => 'debit',
            ],

            'المخزون' => [
                'مخزون بضاعة'              => 'debit',
                'مخزون مواد خام'           => 'debit',
                'مخزون إنتاج تحت التشغيل'  => 'debit',
                'مخزون إنتاج تام'          => 'debit',
                'مخزون قطع غيار ومهمات'    => 'debit',
                'مخزون مواد تعبئة وتغليف'  => 'debit',
                'مخزون تالف وراكد'         => 'debit',
                'مخصص هبوط أسعار المخزون'  => 'credit',  // contra
            ],

            'الممتلكات و الالات و المعدات' => [
                'أراضي'                                     => 'debit',
                'مباني وإنشاءات'                            => 'debit',
                'آلات ومعدات'                               => 'debit',
                'سيارات ووسائل نقل'                         => 'debit',
                'أثاث وتجهيزات'                             => 'debit',
                'اجهزة كهربائية'                            => 'debit',
                'مكيفات'                                    => 'debit',
                'عدد و ادوات'                               => 'debit',
                'أجهزة كمبيوتر ومعدات تكنولوجيا المعلومات' => 'debit',
                'أصول ثابتة تحت التنفيذ'                    => 'debit',
                'مشروعات تحت التنفيذ'                       => 'debit',
                'مجمع إهلاك الأصول الثابتة'                 => 'credit',  // contra
            ],

            'اصول غير ملموسة' => [
                'برمجيات وتراخيص'                 => 'debit',
                'علامات تجارية'                   => 'debit',
                'شهرة'                            => 'debit',
                'مجمع إهلاك الأصول غير الملموسة'  => 'credit',  // contra
            ],

            // The accountant's (2026-10-19): pre-operating expenses, under PPE in the non-current assets (sofp.other_assets).
            'أصول أخرى' => [
                'أصول أخرى'               => 'debit',
                'مجمع إطفاء أصول أخرى'    => 'credit',  // contra
            ],

            'استثمارات' => [
                'استثمارات قصيرة الأجل' => 'debit',   // moved from other debit balances
                'استثمارات طويلة الأجل' => 'debit',
                'استثمارات عقارية'          => 'debit',   // investment property (land / buildings held for rent or appreciation)
                'استثمارات في شركات زميلة' => 'debit',   // associates and joint ventures
            ],

            'اطراف ذات علاقة مدينة' => [
                'اطراف ذات علاقة مدينة' => 'debit',
            ],

            'الذمم الدائنة التجارية' => [
                'موردون'   => 'credit',
                'أوراق دفع' => 'credit',  // moved from other credit balances
            ],

            'مصروفات مستحقةو ارصدة دائنة اخرى' => [
                'دائنون متنوعون'                 => 'credit',
                'مصروفات مستحقة'                 => 'credit',
                'رواتب وأجور مستحقة'             => 'credit',
                'ضرائب مستحقة'                   => 'credit',
                'ضريبة القيمة المضافة المستحقة' => 'credit',
                'ضريبة القيمة المضافة'          => 'credit',
                'تأمينات اجتماعية مستحقة'        => 'credit',
                'أمانات العاملين'                => 'credit',
                'عُهد وسُلف دائنه'               => 'credit',  // the accountant's (2026-10-19): an employee's own account or a custody, credit
                'دفعات مقدمة من العملاء'         => 'credit',
                'بطاقات ائتمان دائنة'            => 'credit',  // credit cards (current liability)
                'توزيعات أرباح مستحقة'           => 'credit',  // dividends declared, not yet paid
                'إيرادات مؤجلة'                  => 'credit',  // billed or received, not yet earned (contract liability)
                'أرصدة دائنة أخرى'               => 'credit',
            ],

            'قروض قصيرة الأجل' => [
                'قروض قصيرة الأجل' => 'credit',
            ],

            'مخصص نهاية الخدمة' => [
                'مخصص نهاية الخدمة' => 'credit',
            ],

            'مخصص الزكاة الشرعية' => [
                'مخصص الزكاة الشرعية' => 'credit',
            ],

            'مخصص ضريبة الدخل' => [
                'مخصص ضريبة الدخل' => 'credit',
            ],

            // Provisions for liabilities with no dedicated main class (warranty on goods sold, legal claims…).
            'مخصصات أخرى' => [
                'مخصص ضمان'   => 'credit',
                'مخصصات أخرى' => 'credit',
            ],

            'قروض طويلة الأجل' => [
                'قروض طويلة الأجل' => 'credit',
            ],

            'التزامات عقود الإيجار' => [
                'التزامات عقود الإيجار' => 'credit',
            ],

            'رأس المال' => [
                'رأس المال'         => 'credit',
                'رأس المال المدفوع' => 'credit',
                'رأس المال الإضافي' => 'credit',
            ],

            'احتياطي نظامي' => [
                'احتياطي نظامي'  => 'credit',
                'احتياطي عام'    => 'credit',
                'احتياطيات أخرى' => 'credit',
            ],

            'الارباح المبقاه' => [
                'أرباح محتجزة'                => 'credit',
                'أرباح أو خسائر العام الحالي' => 'credit',
                'أرباح أو خسائر سنوات سابقة'  => 'credit',
            ],

            'اطراف ذات علاقة دائنة' => [
                'اطراف ذات علاقة دائنة' => 'credit',
                'جاري الشريك'           => 'credit',
            ],
        ],

        'قائمة الدخل' => [

            'إيرادات النشاط' => [
                'إيرادات المبيعات'           => 'credit',
                'مبيعات البضائع'             => 'credit',
                'إيرادات الخدمات'            => 'credit',
                'إيرادات عقود ومشروعات'      => 'credit',
                'مردودات ومسموحات المبيعات'  => 'debit',   // contra revenue
                'خصم مسموح به للعملاء'       => 'debit',   // contra revenue (was treated as credit)
            ],

            'إيرادات أخرى' => [
                'إيرادات استثمارات' => 'credit',
                'إيرادات فوائد'     => 'credit',
                'إيرادات إيجارات'   => 'credit',
                'فروق عملة دائنة'   => 'credit',
                'رد خسائر الانخفاض' => 'credit',   // reversal of impairment of receivables
                'إيرادات أخرى'      => 'credit',
            ],

            'ارباح و خسائر رأسمالية' => [
                'أرباح بيع أصول ثابتة' => 'credit',
            ],

            'تكلفة الإيرادات' => [
                'تكلفة البضاعة المباعة'        => 'debit',
                'تكلفة المواد الخام المستخدمة' => 'debit',
                'تكلفة الإنتاج التام المباع'   => 'debit',
                'تكلفة الخدمات المقدمة'        => 'debit',
                'خصم مكتسب'                    => 'credit',  // reduces cost (was treated as debit)
                'تكلفة المشتريات'              => 'debit',
                'مردودات المشتريات'            => 'credit',  // contra cost
                'مصاريف نقل المشتريات'         => 'debit',
                'جمارك ورسوم استيراد'          => 'debit',
                // Periodic inventory: the opening and closing stock accounts posted in the income statement.
                'مخزون أول المدة'              => 'debit',
                'مخزون آخر المدة'              => 'credit',  // reduces cost (contra)
            ],

            'المصروفات الإدارية والعمومية' => [   // by function: the nature of each expense is its sub class
                'أجور ورواتب'                      => 'debit',
                'رواتب الإدارة'                    => 'debit',
                'مصروف مكافأة نهاية الخدمة'        => 'debit',   // the expense; the liability is مخصص نهاية الخدمة
                'سكن العاملين'                     => 'debit',
                'مصروفات تعبئة وتغليف'             => 'debit',   // the expense; stock is مخزون مواد تعبئة وتغليف
                'أدوات سلامة ودفاع مدني'           => 'debit',
                'تأمينات اجتماعية'                 => 'debit',
                'مكافآت وحوافز العاملين'           => 'debit',
                'إيجارات'                          => 'debit',
                'إيجار المقر الإداري'              => 'debit',
                'كهرباء ومياه وغاز'                => 'debit',
                'اتصالات وإنترنت'                  => 'debit',
                'صيانة وإصلاحات'                   => 'debit',
                'وقود ومحروقات'                    => 'debit',
                'نقل وانتقالات'                    => 'debit',
                'مهمات وأدوات مكتبية'              => 'debit',
                'سفر وإقامة'                       => 'debit',
                'تدريب وتأهيل'                     => 'debit',
                'مصروفات بريد وشحن'                => 'debit',
                'مصروفات تأمين'                    => 'debit',
                'كهرباء ومياه المقر الإداري'       => 'debit',
                'أتعاب مهنية واستشارات'            => 'debit',
                'أتعاب مراجعة ومحاسبة'             => 'debit',
                'مصروفات قانونية'                  => 'debit',
                'قرطاسية ومطبوعات'                 => 'debit',
                'ضيافة واستقبال'                   => 'debit',
                'اشتراكات وبرامج'                  => 'debit',
                'مصروفات بنكية'                    => 'debit',
                'رسوم وتراخيص'                     => 'debit',
            ],

            'المصروفات البيعية والتوزيعية' => [
                'دعاية وإعلان'                     => 'debit',
                'تسويق ومبيعات'                    => 'debit',
                'عمولات بيع'                       => 'debit',
            ],

            'مصروفات أخرى' => [
                'غرامات ومخالفات'                  => 'debit',
                'مصروفات متنوعة'                   => 'debit',
            ],

            'تكاليف التمويل' => [
                'فوائد قروض'                       => 'debit',
                'فوائد تسهيلات ائتمانية'           => 'debit',
                'عمولات تمويلية'                   => 'debit',
                'خسائر فروق عملة'                  => 'debit',
            ],

            'استهلاك ممتلكات وآلات ومعدات' => [
                'إهلاك الأصول الثابتة'             => 'debit',   // when the file doesn't split by asset
                'إهلاك المباني'                    => 'debit',
                'إهلاك الآلات والمعدات'            => 'debit',
                'إهلاك السيارات'                   => 'debit',
                'إهلاك الأثاث والتجهيزات'          => 'debit',
                'إهلاك أجهزة الكمبيوتر'            => 'debit',
            ],

            'مصروف الزكاة الشرعية'             => ['مصروف الزكاة الشرعية' => 'debit'],
            'مصروف ضريبة الدخل'                => ['مصروف ضريبة الدخل' => 'debit'],
            'ضريبة الدخل المؤجلة'              => ['ضريبة الدخل المؤجلة' => 'debit'],
            'إطفاء الأصول غير الملموسة'        => ['إطفاء الأصول غير الملموسة' => 'debit'],

            'خسائر وانخفاض قيمة' => [
                'خسائر انخفاض قيمة الأصول'   => 'debit',
                'مصروف ديون مشكوك في تحصيلها' => 'debit',
                'خسائر بيع أصول ثابتة'        => 'debit',
                'خسائر مخزون'                 => 'debit',
            ],
        ],
    ],
];
