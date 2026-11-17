<?php

/*
 * Every permission the app checks, by group. Roles are made by the admin (admin → roles) from these; a role marked
 * «admin» has all of them. Each key has its name in lang/{ar,en}/permissions.php. A new feature adds its key here and
 * checks it on its routes (`can:<key>`).
 */
return [
    'reports' => ['reports.view', 'reports.create', 'reports.edit', 'reports.finalize', 'reports.export'],
    'companies' => ['companies.view', 'companies.manage'],
    'clients' => ['clients.view', 'clients.manage', 'clients.delete'],
    'portal' => ['portal.use'],
    'classifier' => ['classify.view', 'classify.run', 'classify.correct'],
    'cache' => ['cache.view', 'cache.import', 'cache.review', 'cache.delete', 'cache.transfer'],
    'synonyms' => ['synonyms.view', 'synonyms.manage'],
    'taxonomy' => ['taxonomy.view', 'taxonomy.manage'],
    'system' => ['api_docs.view', 'api_tokens.manage', 'users.manage', 'roles.manage', 'offices.manage'],
];
