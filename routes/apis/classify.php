<?php

use App\Http\Controllers\Api\ClassificationCacheController;
use App\Http\Controllers\Api\ClassificationController;
use App\Http\Controllers\Api\ClassificationRowController;
use Illuminate\Support\Facades\Route;

Route::post('/trial-balance', [ClassificationController::class, 'classify'])->middleware('can:classify.run')->name('classify.trial-balance');

// Background classification: submit, then poll.
Route::post('/jobs', [ClassificationController::class, 'submit'])->middleware('can:classify.run')->name('classify.jobs.submit');
// Runs, newest first, without their results (?status=done to pick a trial balance).
Route::get('/jobs', [ClassificationController::class, 'index'])->middleware('can:classify.view')->name('classify.jobs.index');
Route::get('/jobs/{id}', [ClassificationController::class, 'show'])->middleware('can:classify.view')->name('classify.jobs.show');

// A run waiting for the user (status needs_confirmation): confirm / correct the column mapping, or cancel.
Route::post('/jobs/{id}/structure', [ClassificationController::class, 'confirm'])->middleware('can:classify.run')->name('classify.jobs.structure');
Route::post('/jobs/{id}/cancel', [ClassificationController::class, 'cancel'])->middleware('can:classify.run')->name('classify.jobs.cancel');

// A manual correction of one row of a finished run (also remembered by the classification cache).
Route::patch('/jobs/{id}/rows/{row}', [ClassificationCacheController::class, 'correct'])->whereNumber('row')->middleware('can:classify.correct')->name('classify.jobs.rows.correct');
// Rows changed by hand after the classification: an account added, its number / name / amounts edited, or removed.
Route::post('/jobs/{id}/rows', [ClassificationRowController::class, 'store'])->middleware('can:classify.correct')->name('classify.jobs.rows.store');
Route::put('/jobs/{id}/rows/{row}/values', [ClassificationRowController::class, 'update'])->whereNumber('row')->middleware('can:classify.correct')->name('classify.jobs.rows.update');
Route::delete('/jobs/{id}/rows/{row}', [ClassificationRowController::class, 'destroy'])->whereNumber('row')->middleware('can:classify.correct')->name('classify.jobs.rows.destroy');
// Several rows at once, all or nothing (the classifier page's «حفظ التعديلات»).
Route::post('/jobs/{id}/corrections', [ClassificationCacheController::class, 'correctMany'])->middleware('can:classify.correct')->name('classify.jobs.corrections');
