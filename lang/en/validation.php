<?php

// English field and value names for validation messages; Laravel's built-in English messages apply.
return [
    'custom' => [
        'username' => ['regex' => 'The username must be 3 to 30 Latin letters, digits, dots, dashes or underscores.', 'unique' => 'This username is already taken.'],
    ],

    'attributes' => [
        'name' => 'name', 'username' => 'username', 'password' => 'password', 'current_password' => 'current password', 'role_id' => 'role', 'permissions' => 'permissions', 'locale' => 'language',

        // Classification memory
        'account_code' => 'account code', 'account_name' => 'account name', 'budget_item' => 'budget item', 'parent' => 'parent account', 'sub_class' => 'class', 'ids' => 'entries',

        // Taxonomy page
        'statement' => 'statement', 'family' => 'group', 'normal_side' => 'normal balance', 'line_key' => 'statement line',
        // Lists
        'search' => 'search', 'per_page' => 'items per page', 'page' => 'page number', 'status' => 'status',

        // Companies
        'name_ar' => 'Arabic company name', 'name_en' => 'English name', 'legal_form' => 'legal form', 'country' => 'country',
        'framework' => 'reporting framework', 'currency' => 'currency', 'cr_number' => 'commercial registration number', 'cr_date_hijri' => 'registration date (Hijri)',
        'cr_date' => 'registration date (Gregorian)', 'unified_number' => 'unified number', 'city' => 'city', 'head_office' => 'head office',
        'activity' => 'activity', 'branches' => 'branches', 'branches.*' => 'branch', 'fiscal_year_end_month' => 'financial year-end month',
        'fiscal_year_end_day' => 'financial year-end day',

        // Reports
        'classification_run_id' => 'trial balance', 'company_id' => 'company', 'period_start' => 'period start', 'period_end' => 'period end',
        'inputs' => 'inputs',

        // Prior year
        'source' => 'comparative figures source', 'prior_report_id' => 'prior-year report', 'prior_run_id' => 'prior-year trial balance',
        'figures' => 'prior-year figures', 'figures.*' => 'amount', 'figures.*.*' => 'amount', 'comparative_start' => 'comparative period start', 'comparative_end' => 'comparative period end',
        'restated' => 'restatement of comparative figures', 'restatement_note' => 'restatement reason',
        'changes_in_equity' => 'statement of changes in equity', 'changes_in_equity.*' => 'statement of changes in equity row', 'changes_in_equity.*.*' => 'amount',
        'cash_flows' => 'statement of cash flows', 'cash_flows.*' => 'amount', 'cash_flows.*.*' => 'amount', 'notes' => 'note tables', 'notes.*' => 'note table',
        'notes.*.*' => 'amount', 'schedules' => 'movement schedules', 'schedules.*' => 'amount', 'schedules.*.*' => 'amount', 'schedules.*.*.*' => 'amount',

        // Account overrides
        'overrides' => 'account overrides', 'overrides.*' => 'override', 'overrides.*.row' => 'account', 'overrides.*.line_key' => 'line',
        'overrides.*.shares' => 'allocation to lines', 'overrides.*.shares.*' => 'line share', 'overrides.*.shares.*.*' => 'line share', 'overrides.*.category' => 'category',
        'overrides.*.tags' => 'tags', 'overrides.*.tags.*' => 'tag', 'overrides.*.exclude' => 'exclusion from the statements',
        'overrides.*.reason' => 'exclusion reason',

        // Adjusting entries
        'entries' => 'adjusting entries', 'entries.*.reason' => 'entry reason', 'entries.*.lines' => 'entry lines', 'entries.*.lines.*.row' => 'account',
        'entries.*.lines.*.line_key' => 'line', 'entries.*.lines.*.debit' => 'debit', 'entries.*.lines.*.credit' => 'credit',

        // Movement decisions, notes, acknowledgements, versions
        'decisions' => 'decisions', 'decisions.*.key' => 'amount', 'decisions.*.action' => 'decision', 'decisions.*.amount' => 'amount',
        'visibility' => 'note visibility', 'visibility.*' => 'visibility choice', 'texts' => 'note texts', 'texts.*' => 'text', 'texts.*.*' => 'text',
        'acknowledgements' => 'acknowledgements', 'acknowledgements.*.key' => 'warning', 'acknowledgements.*.note' => 'acknowledgement note',
        'kind' => 'version type',
    ],

    'values' => [
        'source' => ['none' => 'no source', 'first_year' => 'first year', 'report' => 'prior report', 'run' => 'prior trial balance', 'manual' => 'manual entry'],
        'kind' => ['draft' => 'draft', 'final' => 'final'],
        'restated' => ['true' => 'yes', '1' => 'yes'],
    ],
];
