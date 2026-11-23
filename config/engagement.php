<?php

/*
 * The engagement file (ملف الارتباط): a client's year as six phases, each of steps in the office's order (2026-11-23):
 *  1 acceptance, the client's details and permanent file; 2 the trial balances, this year and last; 3 the opening balances and
 *  last year's statements; 4 the classification; 5 understanding the entity and the risks; 6 the materiality; 7 the audit plan
 *  (each area with its risks, assertions, procedures and the client's requests); 8 the evidence (the requirements, the working
 *  papers, the recalculations); 9 the samples and tests; 10 the findings, the adjusting entries and the misstatements (ISA 450);
 *  11 zakat and tax (after the entries: they change them); 12 the final statements and notes; 13 the completion memo
 *  (subsequent events, going concern, representations); 14 the partner's review and the release.
 *
 * Each step: its number (several pages may share one), its phase, the client's page, the work page of the side menu, the
 * steps it `requires` (the page is locked until they are done; `materiality` must be approved) and the ones it comes `after`
 * (said as a blocker, the page stays open). Read by App\Services\Engagement\Engagement; the pages only show what it says.
 */
return [
    'phases' => ['acceptance', 'data_intake', 'planning', 'fieldwork', 'completion', 'reporting'],

    // Whether a step whose requirements aren't done refuses its page (the brief's rule). Off only in the older tests.
    'enforce_locks' => env('ENGAGEMENT_LOCKS', true),

    'steps' => [
        'acceptance'     => ['n' => 1,  'phase' => 'acceptance',  'route' => 'clients.edit',                  'work' => null,                 'requires' => [],                               'after' => []],
        'trial_balance'  => ['n' => 2,  'phase' => 'data_intake', 'route' => 'clients.file.trial-balance',    'work' => 'work.trial-balance', 'requires' => [],                               'after' => ['acceptance']],
        'prior'          => ['n' => 3,  'phase' => 'data_intake', 'route' => 'clients.file.prior',            'work' => 'work.prior',         'requires' => [],                               'after' => ['trial_balance']],
        'classification' => ['n' => 4,  'phase' => 'data_intake', 'route' => 'clients.file.classification',   'work' => 'work.classification', 'requires' => [],                              'after' => ['trial_balance']],
        'risks'          => ['n' => 5,  'phase' => 'planning',    'route' => 'clients.file.risks',            'work' => 'work.risks',         'requires' => ['classification'],               'after' => ['prior']],
        'materiality'    => ['n' => 6,  'phase' => 'planning',    'route' => 'clients.file.materiality',      'work' => 'work.materiality',   'requires' => ['classification'],               'after' => ['risks']],
        'procedures'     => ['n' => 7,  'phase' => 'planning',    'route' => 'clients.file.procedures',       'work' => 'work.procedures',    'requires' => ['risks', 'materiality'],         'after' => []],
        'requirements'   => ['n' => 8,  'phase' => 'fieldwork',   'route' => 'clients.show',                  'work' => 'work.requirements',  'requires' => [],                               'after' => []],
        'papers'         => ['n' => 8,  'phase' => 'fieldwork',   'route' => 'clients.file.papers',           'work' => 'work.papers',        'requires' => [],                               'after' => ['trial_balance']],
        'recalc'         => ['n' => 8,  'phase' => 'fieldwork',   'route' => 'clients.file.recalculation',    'work' => 'work.recalc',        'requires' => [],                               'after' => ['trial_balance']],
        'sampling'       => ['n' => 9,  'phase' => 'fieldwork',   'route' => 'clients.file.sampling',         'work' => 'work.sampling',      'requires' => ['materiality'],                  'after' => ['risks']],
        'findings'       => ['n' => 10, 'phase' => 'fieldwork',   'route' => 'clients.file.findings',         'work' => 'work.findings',      'requires' => [],                               'after' => ['papers', 'recalc']],
        'adjustments'    => ['n' => 10, 'phase' => 'fieldwork',   'route' => 'clients.file.adjustments',      'work' => 'work.adjustments',   'requires' => ['classification'],               'after' => ['findings', 'sampling']],
        'zakat'          => ['n' => 11, 'phase' => 'completion',  'route' => 'clients.file.zakat',            'work' => 'work.zakat',         'requires' => [],                               'after' => ['adjustments']],
        'statements'     => ['n' => 12, 'phase' => 'completion',  'route' => 'clients.file.statements',       'work' => 'work.statements',    'requires' => [],                               'after' => ['zakat']],
        'completion'     => ['n' => 13, 'phase' => 'completion',  'route' => 'clients.file.completion',       'work' => 'work.completion',    'requires' => ['procedures', 'adjustments'],    'after' => ['statements']],
        'release'        => ['n' => 14, 'phase' => 'reporting',   'route' => 'clients.file.release',          'work' => 'work.release',       'requires' => ['completion'],                   'after' => []],
    ],

    // The steps the preparer signs to call them done (the others are done by their work), and the reviewer after.
    'signed' => ['risks', 'adjustments', 'completion'],

    // The opinions (ISA 700/705/706) and what each allows.
    'opinions' => ['unmodified', 'qualified', 'adverse', 'disclaimer'],
];
