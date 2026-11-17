<?php

/* The main side menu: its sections and links, with a line under each saying in plain words what is there. */
return [
    'current_client' => 'Current client',
    'sections' => ['work' => 'Work', 'tuning' => 'Classification tuning', 'developers' => 'For developers', 'admin' => 'Administration'],
    'items' => [
        'clients' => ['Clients', 'Clients\' files, requests and chats'],
        'portal' => ['My portal', 'The documents asked of you and the chat with the office'],
        'classifier' => ['Trial balance', 'Upload the trial balance and classify its accounts'],
        'reports' => ['Financial statements', 'Prepare the statements, the notes and zakat'],
        'trial_balance' => ['Trial balance', 'The client\'s current and previous trial balance, and its classification'],
        'prior' => ['Previous financial statements', 'The opening balances comparison'],
        'statements' => ['Financial statements', 'Prepare the statements and the notes'],
        'zakat' => ['Zakat and income tax', 'The zakat and income tax calculation'],
        'requirements' => ['Requirements', 'The documents asked of the client, and their follow-up'],
        'papers' => ['Working papers', 'Prepared by the AI reviewer from the documents of the client'],
        'recalc' => ['Recalculation', 'The AI reviewer recalculates provisions and depreciation'],
        'cache' => ['Classification memory', 'How classifications are kept and used again'],
        'memory' => ['Saved accounts', 'Review and correct the saved classifications'],
        'rules' => ['Classification rules', 'The accountant\'s decisions for groups of accounts'],
        'taxonomy' => ['Classification guide', 'The approved main and sub classes'],
        'synonyms' => ['Statement line synonyms', 'Line names as they appear in previous statements'],
        'api_docs' => ['API', 'Usage documentation for developers'],
        'users' => ['Users', 'The office team\'s accounts and join requests'],
        'offices' => ['Offices', 'The offices on the platform'],
        'roles' => ['Roles and permissions', 'What each role may do'],
    ],
];
