<?php

// The names of the permission groups and keys (config/permissions.php) on the roles page.
return [
    'groups' => [
        'reports' => 'Financial statements', 'companies' => 'Companies', 'clients' => 'Clients (main portal)', 'portal' => 'Client portal', 'classifier' => 'Trial balance classifier',
        'cache' => 'Classification memory', 'synonyms' => 'Line synonyms', 'taxonomy' => 'Classification taxonomy', 'system' => 'System',
    ],
    // Nested by the key's first part: `reports.view` is keys.reports.view.
    'keys' => [
        'reports' => ['view' => 'View reports', 'create' => 'Create a report', 'edit' => 'Edit a report and save drafts', 'finalize' => 'Issue a final version', 'export' => 'Download statements (Excel, Word, PDF)'],
        'companies' => ['view' => 'View companies', 'manage' => 'Add and edit companies'],
        'clients' => ['view' => 'View clients, follow their requests and chat with them', 'manage' => 'Add clients and their accounts, request documents and review them', 'delete' => 'Delete a client for good, with their files, company and financial statements'],
        'portal' => ['use' => 'Sign in to the client portal and upload the requested documents'],
        'classify' => ['view' => 'View classification results', 'run' => 'Upload a trial balance and classify it', 'correct' => 'Correct account classifications'],
        'cache' => ['view' => 'View the classification memory', 'import' => 'Import classified files', 'review' => 'Review and apply mappings', 'delete' => 'Delete from the memory', 'transfer' => 'Export and transfer the memory'],
        'synonyms' => ['view' => 'View synonyms', 'manage' => 'Add, edit and delete synonyms'],
        'taxonomy' => ['view' => 'View the classification taxonomy', 'manage' => 'Add, edit and delete categories'],
        'api_docs' => ['view' => 'View the API documentation'],
        'api_tokens' => ['manage' => 'Create API keys'],
        'users' => ['manage' => 'Manage users'],
        'roles' => ['manage' => 'Manage roles and permissions (platform office only)'],
        'offices' => ['manage' => 'Manage offices (platform office only)'],
    ],
];
