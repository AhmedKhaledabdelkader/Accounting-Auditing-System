<?php

/*
 * Laravel's validation messages in Arabic (PR 13a), for API requests that ask for Arabic (`Accept-Language: ar`, as the
 * pages do; SetLocaleFromHeader). Each field's message names the field in Arabic (`attributes`) and says what is wrong.
 * Codes never show: allowed values are named in `values`, and rules whose English message would list codes (`in`,
 * `prohibited_unless`, a date format) say what is wrong without them.
 */
return [
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute في :date أو بعده.',
    'alpha' => 'يجب أن يتكون :attribute من حروف فقط.',
    'alpha_dash' => 'يجب أن يتكون :attribute من حروف وأرقام وشرطات فقط.',
    'alpha_num' => 'يجب أن يتكون :attribute من حروف وأرقام فقط.',
    'any_of' => ':attribute غير صحيح.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'ascii' => 'يجب أن يتكون :attribute من حروف وأرقام إنجليزية فقط.',
    'before' => 'يجب أن يكون :attribute قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute في :date أو قبله.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد من العناصر بين :min و:max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute بين :min و:max.',
        'string' => 'يجب أن يتراوح طول :attribute بين :min و:max حرفًا.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute نعم أو لا.',
    'can' => 'يحتوي :attribute على قيمة غير مسموح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'يجب أن يكون :attribute تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون :attribute هو :date.',
    'date_format' => 'يجب أن يكون :attribute تاريخًا صحيحًا بالصيغة 2025-12-31.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منازل عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يكون :attribute و:other مختلفين.',
    'digits' => 'يجب أن يتكون :attribute من :digits أرقام.',
    'digits_between' => 'يجب أن يتكون :attribute من :min إلى :max أرقام.',
    'dimensions' => 'أبعاد صورة :attribute غير مناسبة.',
    'distinct' => ':attribute مكرر.',
    'doesnt_contain' => 'يحتوي :attribute على قيمة غير مسموح بها.',
    'doesnt_end_with' => 'ينتهي :attribute بقيمة غير مسموح بها.',
    'doesnt_start_with' => 'يبدأ :attribute بقيمة غير مسموح بها.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'encoding' => 'ترميز :attribute غير صحيح.',
    'ends_with' => 'يجب أن ينتهي :attribute بإحدى القيم المسموح بها.',
    'enum' => 'القيمة المختارة في :attribute غير صحيحة.',
    'exists' => ':attribute المختار غير موجود.',
    'extensions' => 'نوع ملف :attribute غير مسموح به.',
    'file' => 'يجب أن يكون :attribute ملفًا.',
    'filled' => 'يجب أن يحتوي :attribute على قيمة.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصر.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفًا.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصر أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكثر.',
        'numeric' => 'يجب أن يكون :attribute :value أو أكثر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أكثر.',
    ],
    'hex_color' => 'يجب أن يكون :attribute لونًا صحيحًا.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'in_array' => 'يجب أن يكون :attribute من :other.',
    'in_array_keys' => 'ينقص :attribute مفتاح مطلوب.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا بلا كسور.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون :attribute بصيغة JSON صحيحة.',
    'list' => 'يجب أن يكون :attribute قائمة مرتبة.',
    'lowercase' => 'يجب أن يتكون :attribute من حروف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصر.',
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أقل من :value.',
        'string' => 'يجب أن يكون طول :attribute أقل من :value حرفًا.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أقل.',
        'numeric' => 'يجب أن يكون :attribute :value أو أقل.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أقل.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحًا.',
    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصر.',
        'file' => 'يجب ألا يزيد حجم :attribute على :max كيلوبايت.',
        'numeric' => 'يجب ألا يزيد :attribute على :max.',
        'string' => 'يجب ألا يزيد طول :attribute على :max حرفًا.',
    ],
    'max_digits' => 'يجب ألا يزيد :attribute على :max أرقام.',
    'mimes' => 'نوع ملف :attribute غير مسموح به.',
    'mimetypes' => 'نوع ملف :attribute غير مسموح به.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصر على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفًا.',
    ],
    'min_digits' => 'يجب ألا يقل :attribute عن :min أرقام.',
    'missing' => 'يجب عدم إرسال :attribute.',
    'missing_if' => 'يجب عدم إرسال :attribute عندما يكون :other هو :value.',
    'missing_unless' => 'يجب عدم إرسال :attribute في هذه الحالة.',
    'missing_with' => 'يجب عدم إرسال :attribute مع :values.',
    'missing_with_all' => 'يجب عدم إرسال :attribute مع :values.',
    'multiple_of' => 'يجب أن يكون :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'not_regex' => 'صيغة :attribute غير صحيحة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'password' => [
        'letters' => 'يجب أن يحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهر :attribute في تسريب بيانات؛ يرجى اختيار قيمة أخرى.',
    ],
    'present' => 'يجب إرسال :attribute.',
    'present_if' => 'يجب إرسال :attribute عندما يكون :other هو :value.',
    'present_unless' => 'يجب إرسال :attribute.',
    'present_with' => 'يجب إرسال :attribute مع :values.',
    'present_with_all' => 'يجب إرسال :attribute مع :values.',
    'prohibited' => ':attribute غير مسموح به.',
    'prohibited_if' => ':attribute غير مسموح به عندما يكون :other هو :value.',
    'prohibited_if_accepted' => ':attribute غير مسموح به مع :other.',
    'prohibited_if_declined' => ':attribute غير مسموح به دون :other.',
    'prohibited_unless' => ':attribute غير مسموح به مع هذا الاختيار.',
    'prohibits' => 'لا يجوز الجمع بين :attribute و:other.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => ':attribute مطلوب.',
    'required_array_keys' => 'ينقص :attribute بيانات مطلوبة.',
    'required_if' => ':attribute مطلوب عندما يكون :other هو «:value».',
    'required_if_accepted' => ':attribute مطلوب مع :other.',
    'required_if_declined' => ':attribute مطلوب دون :other.',
    'required_unless' => ':attribute مطلوب.',
    'required_with' => ':attribute مطلوب مع :values.',
    'required_with_all' => ':attribute مطلوب مع :values.',
    'required_without' => ':attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => ':attribute مطلوب عند عدم وجود أي من :values.',
    'same' => 'يجب أن يطابق :attribute :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصر.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute :size.',
        'string' => 'يجب أن يتكون :attribute من :size حرفًا.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بإحدى القيم المسموح بها.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => ':attribute مستخدم من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'يجب أن يتكون :attribute من حروف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'ulid' => 'يجب أن يكون :attribute معرّفًا صحيحًا.',
    'uuid' => 'يجب أن يكون :attribute معرّفًا صحيحًا.',

    'custom' => [
        'username' => ['regex' => 'يتكون اسم المستخدم من 3 إلى 30 حرفًا إنجليزيًا أو رقمًا أو . أو - أو _.', 'unique' => 'اسم المستخدم هذا مستخدم من قبل.'],
    ],

    // Arabic names of the fields the API validates. `*` stands for one key segment or row number (a line key like
    // `sofp.cash` is two segments, a movement cell up to three).
    'attributes' => [
        // Accounts and roles
        'name' => 'الاسم', 'username' => 'اسم المستخدم', 'password' => 'كلمة المرور', 'current_password' => 'كلمة المرور الحالية', 'role_id' => 'الدور', 'permissions' => 'الصلاحيات', 'locale' => 'اللغة',

        // Taxonomy page
        'statement' => 'القائمة', 'family' => 'المجموعة', 'normal_side' => 'طبيعة الرصيد', 'line_key' => 'السطر في القائمة',

        // Classification memory
        'account_code' => 'كود الحساب', 'account_name' => 'اسم الحساب', 'budget_item' => 'بند الميزانية', 'parent' => 'الحساب الأب', 'sub_class' => 'التصنيف', 'ids' => 'السجلات',

        // Lists
        'search' => 'البحث', 'per_page' => 'عدد العناصر في الصفحة', 'page' => 'رقم الصفحة', 'status' => 'الحالة',

        // Companies
        'name_ar' => 'اسم الشركة بالعربية', 'name_en' => 'الاسم بالإنجليزية', 'legal_form' => 'الشكل القانوني', 'country' => 'الدولة',
        'framework' => 'الإطار المحاسبي', 'currency' => 'العملة', 'cr_number' => 'رقم السجل التجاري', 'cr_date_hijri' => 'تاريخ السجل (هجري)',
        'cr_date' => 'تاريخ السجل (ميلادي)', 'unified_number' => 'الرقم الموحد', 'city' => 'المدينة', 'head_office' => 'المركز الرئيسي',
        'activity' => 'النشاط', 'branches' => 'الفروع', 'branches.*' => 'الفرع', 'fiscal_year_end_month' => 'شهر نهاية السنة المالية',
        'fiscal_year_end_day' => 'يوم نهاية السنة المالية',

        // Reports
        'classification_run_id' => 'ميزان المراجعة', 'company_id' => 'الشركة', 'period_start' => 'بداية الفترة', 'period_end' => 'نهاية الفترة',
        'inputs' => 'المدخلات',

        // Prior year
        'source' => 'مصدر أرقام السنة السابقة', 'prior_report_id' => 'القوائم المالية للسنة السابقة', 'prior_run_id' => 'ميزان السنة السابقة',
        'figures' => 'أرقام السنة السابقة', 'figures.*' => 'المبلغ', 'figures.*.*' => 'المبلغ', 'comparative_start' => 'بداية فترة المقارنة', 'comparative_end' => 'نهاية فترة المقارنة',
        'restated' => 'تعديل أرقام المقارنة', 'restatement_note' => 'سبب التعديل',
        'changes_in_equity' => 'قائمة التغيرات في حقوق الملكية', 'changes_in_equity.*' => 'صف قائمة التغيرات في حقوق الملكية', 'changes_in_equity.*.*' => 'المبلغ',
        'cash_flows' => 'قائمة التدفقات النقدية', 'cash_flows.*' => 'المبلغ', 'cash_flows.*.*' => 'المبلغ', 'notes' => 'جداول الإيضاحات', 'notes.*' => 'جدول الإيضاح',
        'notes.*.*' => 'المبلغ', 'schedules' => 'جداول الحركة', 'schedules.*' => 'المبلغ', 'schedules.*.*' => 'المبلغ', 'schedules.*.*.*' => 'المبلغ',

        // Account overrides
        'overrides' => 'تعديلات الحسابات', 'overrides.*' => 'التعديل', 'overrides.*.row' => 'الحساب', 'overrides.*.line_key' => 'السطر',
        'overrides.*.shares' => 'التوزيع على السطور', 'overrides.*.shares.*' => 'نسبة السطر', 'overrides.*.shares.*.*' => 'نسبة السطر', 'overrides.*.category' => 'الفئة',
        'overrides.*.tags' => 'العلامات', 'overrides.*.tags.*' => 'العلامة', 'overrides.*.exclude' => 'الاستبعاد من القوائم',
        'overrides.*.reason' => 'سبب الاستبعاد',

        // Adjusting entries
        'entries' => 'قيود التسوية', 'entries.*.reason' => 'سبب القيد', 'entries.*.lines' => 'سطور القيد', 'entries.*.lines.*.row' => 'الحساب',
        'entries.*.lines.*.line_key' => 'السطر', 'entries.*.lines.*.debit' => 'المدين', 'entries.*.lines.*.credit' => 'الدائن',

        // Movement decisions, notes, acknowledgements, versions
        'decisions' => 'القرارات', 'decisions.*.key' => 'المبلغ', 'decisions.*.action' => 'القرار', 'decisions.*.amount' => 'المبلغ',
        'visibility' => 'إظهار الإيضاحات', 'visibility.*' => 'اختيار الإظهار', 'texts' => 'نصوص الإيضاحات', 'texts.*' => 'النص', 'texts.*.*' => 'النص',
        'acknowledgements' => 'التأكيدات', 'acknowledgements.*.key' => 'التنبيه', 'acknowledgements.*.note' => 'ملاحظة التأكيد',
        'kind' => 'نوع النسخة',
    ],

    // Arabic names of the allowed values that a message may quote (`:value`).
    'values' => [
        'source' => ['none' => 'بلا مصدر', 'first_year' => 'أول سنة', 'report' => 'القوائم المالية السابقة', 'run' => 'ميزان سابق', 'manual' => 'إدخال يدوي'],
        'kind' => ['draft' => 'مسودة', 'final' => 'نهائي'],
        'restated' => ['true' => 'نعم', '1' => 'نعم'],
    ],
];
