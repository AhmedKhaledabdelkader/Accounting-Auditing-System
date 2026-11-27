<?php

use App\Http\Controllers\Api\ClientFollowUpController;
use App\Http\Controllers\Api\FindingsController;
use App\Http\Controllers\Api\PreparedPaperController;
use Illuminate\Support\Facades\Route;

// A client's follow-up by email (what is missing, the AI's draft, sending, replies) and the working papers the AI prepares.
Route::get('/{id}/follow-up', [ClientFollowUpController::class, 'show'])->middleware('can:clients.view')->name('api.clients.follow-up');
Route::post('/{id}/follow-up/draft', [ClientFollowUpController::class, 'draft'])->middleware('can:clients.manage')->middleware('ai.budget')->name('api.clients.follow-up.draft');
Route::post('/{id}/follow-up/send', [ClientFollowUpController::class, 'send'])->middleware('can:clients.manage')->name('api.clients.follow-up.send');
Route::post('/{id}/follow-up/replies', [ClientFollowUpController::class, 'reply'])->middleware('can:clients.manage')->middleware('ai.budget')->name('api.clients.follow-up.reply');
Route::get('/{id}/prepared-papers', [PreparedPaperController::class, 'index'])->middleware('can:clients.view')->name('api.clients.papers');
Route::post('/{id}/prepared-papers', [PreparedPaperController::class, 'prepare'])->middleware('can:clients.manage')->middleware('ai.budget')->name('api.clients.papers.prepare');
// The audit findings: the documents and the recalculations matched with the trial balance, and the accountant's word on each.
Route::get('/{id}/findings', [FindingsController::class, 'index'])->middleware('can:clients.view')->name('api.clients.findings');
Route::get('/{id}/findings/export', [FindingsController::class, 'export'])->middleware('can:clients.view')->name('api.clients.findings.export');
Route::put('/{id}/findings/{key}', [FindingsController::class, 'decide'])->middleware('can:clients.manage')->where('key', '[A-Za-z0-9_-]+')->name('api.clients.findings.decide');
Route::get('/{id}/prepared-papers/{paper}/download', [PreparedPaperController::class, 'download'])->middleware('can:clients.view')->name('api.clients.papers.download');
// The engagement file: the steps' statuses, sign-offs and locks, the blockers, the current phase and the next step.
Route::get('/{id}/engagement', [\App\Http\Controllers\Api\EngagementController::class, 'status'])->middleware('can:clients.view')->name('api.clients.engagement');
// The materiality of the year (ISA 320): the working paper, saving the choice, approving a version (as the page).
Route::get('/{id}/materiality', [\App\Http\Controllers\Api\MaterialityController::class, 'show'])->middleware('can:clients.view')->name('api.clients.materiality');
Route::put('/{id}/materiality', [\App\Http\Controllers\Api\MaterialityController::class, 'save'])->middleware('can:clients.manage')->name('api.clients.materiality.save');
Route::post('/{id}/materiality/approve', [\App\Http\Controllers\Api\MaterialityController::class, 'approve'])->middleware('can:clients.manage')->name('api.clients.materiality.approve');
// The risk register of the year (ISA 315 / 330): the risks, the suggestions from the figures, the procedures answering each (as the page).
Route::get('/{id}/risks', [\App\Http\Controllers\Api\RisksController::class, 'show'])->middleware('can:clients.view')->name('api.clients.risks');
Route::post('/{id}/risks/suggest', [\App\Http\Controllers\Api\RisksController::class, 'suggest'])->middleware('can:clients.manage')->name('api.clients.risks.suggest');
Route::post('/{id}/risks', [\App\Http\Controllers\Api\RisksController::class, 'store'])->middleware('can:clients.manage')->name('api.clients.risks.store');
Route::put('/{id}/risks/{risk}', [\App\Http\Controllers\Api\RisksController::class, 'update'])->middleware('can:clients.manage')->name('api.clients.risks.update');
Route::patch('/{id}/risks/{risk}/status', [\App\Http\Controllers\Api\RisksController::class, 'status'])->middleware('can:clients.manage')->name('api.clients.risks.status');
Route::delete('/{id}/risks/{risk}', [\App\Http\Controllers\Api\RisksController::class, 'destroy'])->middleware('can:clients.manage')->name('api.clients.risks.delete');
// The audit program of the year (ISA 330): each area by its letter code, its procedures and answers, sign-offs (as the page).
Route::get('/{id}/program', [\App\Http\Controllers\Api\ProgramController::class, 'show'])->middleware('can:clients.view')->name('api.clients.program');
Route::put('/{id}/program/{area}', [\App\Http\Controllers\Api\ProgramController::class, 'save'])->where('area', '[a-z_]+')->middleware('can:clients.manage')->name('api.clients.program.save');
Route::post('/{id}/program/{area}/procedures', [\App\Http\Controllers\Api\ProgramController::class, 'add'])->where('area', '[a-z_]+')->middleware('can:clients.manage')->name('api.clients.program.add');
Route::delete('/{id}/program/{area}/procedures/{procedure}', [\App\Http\Controllers\Api\ProgramController::class, 'delete'])->where('area', '[a-z_]+')->where('procedure', 'c[0-9]+')->middleware('can:clients.manage')->name('api.clients.program.delete');
Route::post('/{id}/program/{area}/sign', [\App\Http\Controllers\Api\ProgramController::class, 'sign'])->where('area', '[a-z_]+')->middleware('can:clients.manage')->name('api.clients.program.sign');
