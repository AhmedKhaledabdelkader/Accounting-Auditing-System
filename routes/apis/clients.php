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
