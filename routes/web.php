<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AccountController;
use App\Http\Controllers\MailAccountController;
use App\Http\Controllers\Portal\FollowUpController;
use App\Http\Controllers\Admin\OfficeController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ClassificationRuleController;
use App\Http\Controllers\Portal\ClientController;
use App\Http\Controllers\Portal\ClientFileController;
use App\Http\Controllers\Portal\WorkController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\WorkspaceController;
use App\Http\Controllers\TaxonomyController;
use App\Support\Access\Navigation;

// Sign-in and sign-up; the language can be chosen before signing in.
// The mail service posts a client's reply here (no session; the secret in X-Inbound-Secret is the way in): FollowUp::receive.
Route::post('/mail/inbound', [FollowUpController::class, 'inbound'])->middleware('throttle:60,1');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
});
Route::post('/locale', [AccountController::class, 'locale'])->name('locale');

// Every other page needs an active account and the page's permission (config/permissions.php).
Route::middleware(['auth', 'active', 'ai.payer'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', fn () => redirect(Navigation::home(request()->user())))->name('home');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    // Where a user whose monthly AI limit is used up is sent (EnsureAiBudget): every signed-in user may see their own.
    Route::get('/ai-limit', [\App\Http\Controllers\AiLimitController::class, 'show'])->name('ai-limit');
    // The accountant's own mailbox (SMTP + app password) for emailing clients.
    Route::get('/account/mail', [MailAccountController::class, 'show'])->middleware('can:clients.manage')->name('account.mail');
    Route::post('/account/mail', [MailAccountController::class, 'save'])->middleware('can:clients.manage')->name('account.mail.save');
    Route::post('/account/mail/test', [MailAccountController::class, 'test'])->middleware('can:clients.manage')->name('account.mail.test');
    Route::post('/account/mail/check', [MailAccountController::class, 'check'])->middleware('can:clients.manage')->name('account.mail.check');
    Route::delete('/account/mail', [MailAccountController::class, 'destroy'])->middleware('can:clients.manage')->name('account.mail.destroy');
    Route::put('/account/password', [AccountController::class, 'password'])->name('account.password');
    Route::put('/account/preferences', [AccountController::class, 'preference'])->name('account.preferences');
    Route::delete('/account/preferences/columns', [AccountController::class, 'resetColumns'])->name('account.columns.reset');
    Route::post('/account/tokens', [AccountController::class, 'createToken'])->name('account.tokens.store');
    Route::delete('/account/tokens/{token}', [AccountController::class, 'revokeToken'])->whereNumber('token')->name('account.tokens.destroy');

    Route::middleware('can:users.manage')->group(function () {
        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users');
        Route::put('/admin/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::put('/admin/users/{user}/password', [UserController::class, 'password'])->name('admin.users.password');
        Route::put('/admin/users/{user}/ai-limit', [UserController::class, 'aiLimit'])->name('admin.users.ai-limit');
    });
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/admin/roles', [RoleController::class, 'index'])->name('admin.roles');
        Route::get('/admin/roles/new', [RoleController::class, 'create'])->name('admin.roles.create');
        Route::post('/admin/roles', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::get('/admin/roles/{role}', [RoleController::class, 'edit'])->name('admin.roles.edit');
        Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
    });

    // The classification taxonomy: its categories, and adding, renaming and deleting them (docs/16-taxonomy.md).
    Route::get('/taxonomy', [TaxonomyController::class, 'index'])->middleware('can:taxonomy.view')->name('taxonomy');
    Route::middleware('can:taxonomy.manage')->group(function () {
        Route::post('/taxonomy/main-classes', [TaxonomyController::class, 'storeMain'])->name('taxonomy.mains.store');
        Route::put('/taxonomy/main-classes/{main}', [TaxonomyController::class, 'updateMain'])->whereNumber('main')->name('taxonomy.mains.update');
        Route::delete('/taxonomy/main-classes/{main}', [TaxonomyController::class, 'destroyMain'])->whereNumber('main')->name('taxonomy.mains.destroy');
        Route::post('/taxonomy/main-classes/{main}/sub-classes', [TaxonomyController::class, 'storeSub'])->whereNumber('main')->name('taxonomy.subs.store');
        Route::put('/taxonomy/sub-classes/{sub}', [TaxonomyController::class, 'updateSub'])->whereNumber('sub')->name('taxonomy.subs.update');
        Route::delete('/taxonomy/sub-classes/{sub}', [TaxonomyController::class, 'destroySub'])->whereNumber('sub')->name('taxonomy.subs.destroy');
    });

    // The offices' clients (البوابة الرئيسية): the accountant's side. Another office's company is a 404 (Company is scoped to the office).
    Route::get('/clients', [ClientController::class, 'index'])->middleware('can:clients.view')->name('clients.index');
    Route::get('/clients/new', [ClientController::class, 'create'])->middleware('can:clients.manage')->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.store');
    Route::post('/clients/{company}/archive', [ClientController::class, 'archive'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.archive');
    // The work on a client (4 to 7 of the main side menu): about the client opened last, or the one chosen.
    Route::get('/work/trial-balance', [WorkController::class, 'trialBalance'])->middleware('can:clients.view')->name('work.trial-balance');
    Route::get('/work/prior-statements', [WorkController::class, 'prior'])->middleware('can:clients.view')->name('work.prior');
    Route::get('/work/statements', [WorkController::class, 'statements'])->middleware('can:clients.view')->name('work.statements');
    Route::get('/work/zakat', [WorkController::class, 'zakat'])->middleware('can:clients.view')->name('work.zakat');
    Route::get('/work/requirements', [WorkController::class, 'requirements'])->middleware('can:clients.view')->name('work.requirements');
    Route::get('/work/working-papers', [WorkController::class, 'papers'])->middleware('can:clients.view')->name('work.papers');
    Route::get('/work/recalculation', [WorkController::class, 'recalculation'])->middleware('can:clients.view')->name('work.recalc');
    Route::get('/work/findings', [WorkController::class, 'findings'])->middleware('can:clients.view')->name('work.findings');
    Route::get('/work/materiality', [WorkController::class, 'materiality'])->middleware('can:clients.view')->name('work.materiality');
    Route::get('/work/sampling', [WorkController::class, 'sampling'])->middleware('can:clients.view')->name('work.sampling');
    Route::get('/work/procedures', [WorkController::class, 'procedures'])->middleware('can:clients.view')->name('work.procedures');
    // The engagement file (config/engagement.php): the board, and the steps the order of 2026-11-23 added.
    Route::get('/work/engagement', [WorkController::class, 'engagement'])->middleware('can:clients.view')->name('work.engagement');
    Route::get('/work/classification', [WorkController::class, 'classification'])->middleware('can:clients.view')->name('work.classification');
    Route::get('/work/risks', [WorkController::class, 'risks'])->middleware('can:clients.view')->name('work.risks');
    Route::get('/work/adjustments', [WorkController::class, 'adjustments'])->middleware('can:clients.view')->name('work.adjustments');
    Route::get('/work/completion', [WorkController::class, 'completion'])->middleware('can:clients.view')->name('work.completion');
    Route::get('/work/release', [WorkController::class, 'release'])->middleware('can:clients.view')->name('work.release');
    // A client's file, step by step (the side menu of ClientSteps): the registration, the articles, the trial balance, last year's statements, the statements, zakat.
    Route::get('/clients/{company}/registration', [ClientFileController::class, 'registration'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.registration');
    Route::post('/clients/{company}/registration', [ClientFileController::class, 'updateRegistration'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.file.registration.update');
    Route::get('/clients/{company}/articles', [ClientFileController::class, 'articles'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.articles');
    Route::post('/clients/{company}/articles', [ClientFileController::class, 'updateArticles'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.file.articles.update');
    Route::get('/clients/{company}/trial-balance', [ClientFileController::class, 'trialBalance'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.trial-balance');
    Route::get('/clients/{company}/prior-statements', [ClientFileController::class, 'prior'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.prior');
    Route::get('/clients/{company}/statements', [ClientFileController::class, 'statements'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.statements');
    Route::get('/clients/{company}/zakat', [ClientFileController::class, 'zakat'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.zakat');
    Route::get('/clients/{company}/working-papers', [ClientFileController::class, 'papers'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.papers');
    Route::get('/clients/{company}/recalculation', [ClientFileController::class, 'recalculation'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.recalculation');
    Route::get('/clients/{company}/findings', [\App\Http\Controllers\Portal\FindingsController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.findings');
    Route::post('/clients/{company}/findings', [\App\Http\Controllers\Portal\FindingsController::class, 'decide'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.findings.decide');
    Route::get('/clients/{company}/findings/export', [\App\Http\Controllers\Portal\FindingsController::class, 'export'])->whereUuid('company')->middleware('can:clients.view')->name('clients.findings.export');
    Route::get('/clients/{company}/materiality', [\App\Http\Controllers\Portal\AuditPlanController::class, 'materiality'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.materiality');
    Route::post('/clients/{company}/materiality', [\App\Http\Controllers\Portal\AuditPlanController::class, 'saveMateriality'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.materiality.save');
    Route::get('/clients/{company}/sampling', [\App\Http\Controllers\Portal\AuditPlanController::class, 'sampling'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.sampling');
    Route::post('/clients/{company}/sampling/sales', [\App\Http\Controllers\Portal\AuditPlanController::class, 'uploadSales'])->whereUuid('company')->middleware(['can:clients.manage', 'ai.budget'])->name('clients.sampling.sales');
    Route::post('/clients/{company}/sampling/draw', [\App\Http\Controllers\Portal\AuditPlanController::class, 'draw'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.sampling.draw');
    Route::get('/clients/{company}/sampling/export', [\App\Http\Controllers\Portal\AuditPlanController::class, 'export'])->whereUuid('company')->middleware('can:clients.view')->name('clients.sampling.export');
    Route::post('/clients/{company}/sampling/{sample}/results', [\App\Http\Controllers\Portal\AuditPlanController::class, 'results'])->whereUuid('company')->where('sample', 's[0-9]+')->middleware('can:clients.manage')->name('clients.sampling.results');
    Route::post('/clients/{company}/sampling/{sample}/delete', [\App\Http\Controllers\Portal\AuditPlanController::class, 'deleteSample'])->whereUuid('company')->where('sample', 's[0-9]+')->middleware('can:clients.manage')->name('clients.sampling.delete');
    Route::get('/clients/{company}/engagement', [\App\Http\Controllers\Portal\EngagementController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.engagement');
    Route::get('/clients/{company}/engagement/status', [\App\Http\Controllers\Portal\EngagementController::class, 'status'])->whereUuid('company')->middleware('can:clients.view')->name('clients.engagement.status');
    Route::post('/clients/{company}/engagement/{step}/sign', [\App\Http\Controllers\Portal\EngagementController::class, 'sign'])->whereUuid('company')->where('step', '[a-z_]+')->middleware('can:clients.manage')->name('clients.engagement.sign');
    Route::post('/clients/{company}/engagement/{step}/notes', [\App\Http\Controllers\Portal\EngagementController::class, 'addNote'])->whereUuid('company')->where('step', '[a-z_]+')->middleware('can:clients.manage')->name('clients.notes.store');
    Route::post('/clients/{company}/notes/{note}/{action}', [\App\Http\Controllers\Portal\EngagementController::class, 'note'])->whereUuid('company')->whereNumber('note')->where('action', 'answer|close|reopen')->middleware('can:clients.manage')->name('clients.notes.act');
    Route::get('/clients/{company}/classification', [\App\Http\Controllers\Portal\EngagementController::class, 'classification'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.classification');
    Route::get('/clients/{company}/risks', [\App\Http\Controllers\Portal\RisksController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.risks');
    Route::post('/clients/{company}/risks', [\App\Http\Controllers\Portal\RisksController::class, 'store'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.risks.store');
    Route::post('/clients/{company}/risks/suggest', [\App\Http\Controllers\Portal\RisksController::class, 'suggest'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.risks.suggest');
    Route::post('/clients/{company}/risks/{risk}', [\App\Http\Controllers\Portal\RisksController::class, 'update'])->whereUuid('company')->whereNumber('risk')->middleware('can:clients.manage')->name('clients.risks.update');
    Route::post('/clients/{company}/risks/{risk}/delete', [\App\Http\Controllers\Portal\RisksController::class, 'destroy'])->whereUuid('company')->whereNumber('risk')->middleware('can:clients.manage')->name('clients.risks.delete');
    Route::post('/clients/{company}/materiality/approve', [\App\Http\Controllers\Portal\AuditPlanController::class, 'approveMateriality'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.materiality.approve');
    Route::get('/clients/{company}/adjustments', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.adjustments');
    Route::post('/clients/{company}/adjustments', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'store'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.entries.store');
    Route::post('/clients/{company}/adjustments/import', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'import'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.entries.import');
    Route::post('/clients/{company}/adjustments/{entry}/move', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'move'])->whereUuid('company')->whereNumber('entry')->middleware('can:clients.manage')->name('clients.entries.move');
    Route::post('/clients/{company}/misstatements', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'storeMisstatement'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.misstatements.store');
    Route::post('/clients/{company}/misstatements/{misstatement}', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'updateMisstatement'])->whereUuid('company')->whereNumber('misstatement')->middleware('can:clients.manage')->name('clients.misstatements.update');
    Route::post('/clients/{company}/misstatements/{misstatement}/delete', [\App\Http\Controllers\Portal\AdjustmentsController::class, 'destroyMisstatement'])->whereUuid('company')->whereNumber('misstatement')->middleware('can:clients.manage')->name('clients.misstatements.delete');
    Route::get('/clients/{company}/completion', [\App\Http\Controllers\Portal\CompletionController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.completion');
    Route::post('/clients/{company}/completion', [\App\Http\Controllers\Portal\CompletionController::class, 'save'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.completion.save');
    Route::get('/clients/{company}/release', [\App\Http\Controllers\Portal\CompletionController::class, 'release'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.release');
    Route::post('/clients/{company}/release', [\App\Http\Controllers\Portal\CompletionController::class, 'saveRelease'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.release.save');
    Route::post('/clients/{company}/release/issue', [\App\Http\Controllers\Portal\CompletionController::class, 'issue'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.release.issue');
    Route::get('/clients/{company}/procedures', [\App\Http\Controllers\Portal\ProceduresController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.file.procedures');
    Route::get('/clients/{company}/procedures/export', [\App\Http\Controllers\Portal\ProceduresController::class, 'export'])->whereUuid('company')->middleware('can:clients.view')->name('clients.procedures.export');
    Route::post('/clients/{company}/procedures/{area}', [\App\Http\Controllers\Portal\ProceduresController::class, 'save'])->whereUuid('company')->where('area', '[a-z_]+')->middleware('can:clients.manage')->name('clients.procedures.save');
    Route::post('/clients/{company}/procedures/{area}/add', [\App\Http\Controllers\Portal\ProceduresController::class, 'add'])->whereUuid('company')->where('area', '[a-z_]+')->middleware('can:clients.manage')->name('clients.procedures.add');
    Route::post('/clients/{company}/procedures/{area}/sign', [\App\Http\Controllers\Portal\ProceduresController::class, 'sign'])->whereUuid('company')->where('area', '[a-z_]+')->middleware('can:clients.manage')->name('clients.procedures.sign');
    Route::post('/clients/{company}/procedures/{area}/{procedure}/delete', [\App\Http\Controllers\Portal\ProceduresController::class, 'delete'])->whereUuid('company')->where('area', '[a-z_]+')->where('procedure', 'c[0-9]+')->middleware('can:clients.manage')->name('clients.procedures.delete');
    Route::post('/clients/{company}/prepared-papers', [ClientFileController::class, 'prepare'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.papers.prepare');
    Route::get('/clients/{company}/prepared-papers/status', [ClientFileController::class, 'preparedStatus'])->whereUuid('company')->middleware('can:clients.view')->name('clients.papers.status');
    Route::get('/clients/{company}/prepared-papers/{paper}', [ClientFileController::class, 'downloadPaper'])->whereUuid('company')->whereNumber('paper')->middleware('can:clients.view')->name('clients.papers.download');
    Route::get('/clients/{company}/edit', [ClientController::class, 'edit'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.edit');
    Route::delete('/clients/{company}', [ClientController::class, 'destroy'])->whereUuid('company')->middleware('can:clients.delete')->name('clients.destroy');
    Route::put('/clients/{company}', [ClientController::class, 'update'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.update');
    Route::get('/clients/{company}/documents/{kind}', [ClientController::class, 'document'])->whereUuid('company')->middleware('can:clients.view')->name('clients.documents');
    Route::get('/clients/{company}', [ClientController::class, 'show'])->whereUuid('company')->middleware('can:clients.view')->name('clients.show');
    Route::put('/clients/{company}/access', [ClientController::class, 'access'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.access');
    Route::post('/clients/{company}/follow-up/draft', [FollowUpController::class, 'draft'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.follow-up.draft');
    Route::post('/clients/{company}/follow-up/send', [FollowUpController::class, 'send'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.follow-up.send');
    Route::post('/clients/{company}/follow-up/reply', [FollowUpController::class, 'reply'])->whereUuid('company')->middleware('can:clients.manage')->middleware('ai.budget')->name('clients.follow-up.reply');
    Route::post('/clients/{company}/checklist', [ClientController::class, 'checklist'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.checklist');
    Route::post('/clients/{company}/requests', [ClientController::class, 'storeRequest'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.requests.store');
    Route::put('/clients/{company}/requests/{documentRequest}', [ClientController::class, 'review'])->whereUuid('company')->whereNumber('documentRequest')->middleware('can:clients.manage')->name('clients.requests.review');
    Route::delete('/clients/{company}/requests/{documentRequest}', [ClientController::class, 'destroyRequest'])->whereUuid('company')->whereNumber('documentRequest')->middleware('can:clients.manage')->name('clients.requests.destroy');
    Route::post('/clients/{company}/requests/{documentRequest}/files', [WorkspaceController::class, 'upload'])->whereUuid('company')->whereNumber('documentRequest')->middleware('can:clients.manage')->name('clients.requests.upload');
    // What a client uploaded, for the accountant to pick instead of uploading it again (the classifier, last year's statements, the articles).
    Route::get('/clients/{company}/files', [ClientController::class, 'pickFiles'])->whereUuid('company')->middleware('can:clients.view')->name('clients.files.pick');
    Route::get('/clients/{company}/files/{file}', [WorkspaceController::class, 'download'])->whereUuid('company')->whereNumber('file')->middleware('can:clients.view')->name('clients.files.download');
    Route::delete('/clients/{company}/files/{file}', [WorkspaceController::class, 'destroy'])->whereUuid('company')->whereNumber('file')->middleware('can:clients.manage')->name('clients.files.destroy');
    Route::get('/clients/{company}/messages', [WorkspaceController::class, 'messages'])->whereUuid('company')->middleware('can:clients.view')->name('clients.messages');
    Route::post('/clients/{company}/messages', [WorkspaceController::class, 'send'])->whereUuid('company')->middleware('can:clients.manage')->name('clients.messages.send');

    // The client's own portal: their company's requests, uploads and chat (a client's user holds only portal.use).
    Route::get('/portal', [PortalController::class, 'home'])->middleware('can:portal.use')->name('portal.home');
    Route::post('/portal/requests/{documentRequest}/declare', [PortalController::class, 'declare'])->whereNumber('documentRequest')->middleware('can:portal.use')->name('portal.requests.declare');
    Route::post('/portal/requests/{documentRequest}/files', [WorkspaceController::class, 'portalUpload'])->whereNumber('documentRequest')->middleware('can:portal.use')->name('portal.requests.upload');
    Route::post('/portal/archive', [PortalController::class, 'archive'])->middleware('can:portal.use')->name('portal.archive');
    Route::get('/portal/files/{file}', [WorkspaceController::class, 'portalDownload'])->whereNumber('file')->middleware('can:portal.use')->name('portal.files.download');
    Route::delete('/portal/files/{file}', [WorkspaceController::class, 'portalDestroy'])->whereNumber('file')->middleware('can:portal.use')->name('portal.files.destroy');
    Route::get('/portal/messages', [WorkspaceController::class, 'portalMessages'])->middleware('can:portal.use')->name('portal.messages');
    Route::post('/portal/messages', [WorkspaceController::class, 'portalSend'])->middleware('can:portal.use')->name('portal.messages.send');

    // The platform office's page for the offices that use the site.
    Route::middleware('can:offices.manage')->group(function () {
        Route::get('/admin/offices', [OfficeController::class, 'index'])->name('admin.offices');
        Route::post('/admin/offices', [OfficeController::class, 'store'])->name('admin.offices.store');
        Route::put('/admin/offices/{office}', [OfficeController::class, 'update'])->whereNumber('office')->name('admin.offices.update');
    });

    // The accountant's classification rules and the companies' code groups (docs/17-classification-rules.md).
    Route::get('/classification-rules', [ClassificationRuleController::class, 'index'])->middleware('can:taxonomy.view')->name('classification-rules');
    Route::middleware('can:taxonomy.manage')->group(function () {
        Route::post('/classification-rules', [ClassificationRuleController::class, 'store'])->name('classification-rules.store');
        Route::put('/classification-rules/{rule}', [ClassificationRuleController::class, 'update'])->whereNumber('rule')->name('classification-rules.update');
        Route::delete('/classification-rules/{rule}', [ClassificationRuleController::class, 'destroy'])->whereNumber('rule')->name('classification-rules.destroy');
        Route::post('/classification-rules/code-groups', [ClassificationRuleController::class, 'storeMark'])->name('classification-rules.marks.store');
        Route::delete('/classification-rules/code-groups/{mark}', [ClassificationRuleController::class, 'destroyMark'])->whereNumber('mark')->name('classification-rules.marks.destroy');
    });

    Route::view('/classifier', 'classifier')->middleware('can:classify.view')->name('classifier');
    // The classification cache: upload accountant-classified files, previous imports, export / import of the data.
    Route::view('/classification-cache', 'classification-cache')->middleware('can:cache.view')->name('classification-cache');
    // The classification memory's entries: looked up, given another class or removed (docs/11-classification-cache.md).
    Route::view('/classification-memory', 'classification-memory')->middleware('can:cache.view')->name('classification-memory');
    // Review of an imported, accountant-classified file before it reaches the classification cache.
    Route::get('/classification-cache/imports/{id}/review', function (int $id, \App\Repositories\Contracts\TaxonomyRepositoryInterface $taxonomy) {
        $import = \App\Models\ClassificationImport::findOrFail($id);

        return view('classification-import-review', ['import' => $import, 'taxonomy' => $taxonomy->map(), 'policies' => config('classification.import.policies')]);
    })->whereNumber('id')->middleware('can:cache.view')->name('classification-cache.imports.review-page');
    // Swagger UI for the API; the spec is public/docs/openapi.yaml.
    Route::view('/api/docs', 'swagger')->middleware('can:api_docs.view')->name('api.docs');
    // The financial-report pages (PR 13): list, new report, wizard. Their scripts use the API.
    Route::get('/financial-reports', [\App\Http\Controllers\FinancialReportPagesController::class, 'index'])->middleware('can:reports.view')->name('financial-reports.list');
    Route::get('/financial-reports/new', [\App\Http\Controllers\FinancialReportPagesController::class, 'create'])->middleware('can:reports.create')->name('financial-reports.new');
    Route::get('/financial-reports/{report}', [\App\Http\Controllers\FinancialReportPagesController::class, 'show'])->whereUuid('report')->middleware('can:reports.view')->name('financial-reports.wizard');
    // The other wordings of the statements' lines, used to read files of last year's issued statements (PR 17).
    Route::get('/statement-synonyms', [\App\Http\Controllers\FinancialReportPagesController::class, 'synonyms'])->middleware('can:synonyms.view')->name('statement-synonyms');
    // The old read-only previews, replaced by the wizard's review step (PR 13c): their links and bookmarks lead there.
    Route::get('/financial-reports/{report}/preview', fn (string $report) => redirect('/financial-reports/'.rawurlencode($report).'#review', 301))->middleware('can:reports.view')->name('financial-reports.preview');
    Route::get('/financial-statements/preview/{run}', [\App\Http\Controllers\FinancialReportPagesController::class, 'runPreview'])->middleware('can:reports.view')->name('financial-statements.preview');
});

// An address that doesn't exist: through the web middleware (session, sign-in, language), so the 404 page shows the
// menu in the reader's language. Not under api/: the API keeps its own 404 and 405 (bootstrap/app.php).
Route::any('{path}', fn () => abort(404))->where('path', '(?!api/).*')->fallback();
