<?php

// The taxonomy page (/taxonomy): its own words. Statement and category names are Arabic data, never translated.
return [
    'nav'      => 'Taxonomy',
    'title'    => 'Classification taxonomy',
    'sub'      => 'The main and sub classes the system chooses from, the classification memory stores and the financial statements are built on.',
    'counts'   => ['mains' => 'Main classes', 'subs' => 'Sub classes', 'added' => 'Added here'],
    'search'   => 'Search a category…',
    'no_match' => 'No category by that name.',
    'view_only' => 'You can view only.',

    'families' => ['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'cost' => 'Cost', 'expenses' => 'Expenses'],
    'sides'    => ['debit' => 'Debit', 'credit' => 'Credit'],

    'cols'     => ['sub' => 'Sub class', 'side' => 'Normal balance', 'line' => 'Statement line', 'actions' => ''],
    'subs_count'    => ':count sub classes',
    'built_in_rule' => 'By the built-in rules',
    'added_badge'   => 'Added',
    'former_names'  => 'Former names: :names',
    'no_subs'       => 'No sub classes here yet.',

    'fields'   => ['name' => 'Name', 'family' => 'Group', 'normal_side' => 'Normal balance', 'line_key' => 'Statement line', 'choose' => 'Choose…'],
    'add_main' => 'New main class',
    'add_sub'  => 'New sub class',
    'edit'     => 'Edit',
    'save'     => 'Save',
    'add'      => 'Add',
    'delete'   => 'Delete',
    'rename_note' => 'The new name applies everywhere: the classification memory, accountant-import mappings and the rules. Past classification results keep the old name and still read correctly.',
    'line_note'   => 'The statement line this category\'s accounts go to.',
    'confirm_delete_main' => 'Delete the main class «:name»?',
    'confirm_delete_sub'  => 'Delete the sub class «:name»?',

    'main_added' => 'Main class added.',
    'sub_added'  => 'Sub class added.',
    'saved'      => 'Saved.',
    'deleted'    => 'Category deleted.',
    'name_taken' => 'This name belongs to another category (current or former). Choose another one.',

    'in_use_main'    => '«:name» can\'t be deleted: it is used in :uses.',
    'in_use_sub'     => '«:name» can\'t be deleted: it is used in :uses.',
    'list_separator' => ', ',
    'uses' => [
        'sub_classes' => 'its sub classes (:count)',
        'memory'      => 'the classification memory (:count entries)',
        'name_layer'  => 'the memory\'s name layer (:count names)',
        'imports'     => 'imported accountant-file mappings (:count)',
        'runs'        => 'past classification results (:count files)',
        'classification_rules' => 'classification rules (:count rules)',
        'rules' => [
            'statement_rules' => 'the statement line rules',
            'schedules'       => 'the notes\' movement schedules',
            'periodic_cost'   => 'the periodic-inventory cost of goods sold',
            'notes'           => 'the notes\' rules',
            'side_pairs'      => 'the categories that flip with the balance side',
            'import_rules'    => 'the accountant-file import rules',
            'ai_hints'        => 'the system\'s instructions',
        ],
    ],
];
