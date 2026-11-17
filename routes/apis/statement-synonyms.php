<?php

use App\Http\Controllers\Api\StatementSynonymController;
use Illuminate\Support\Facades\Route;

// Other wordings of the statements' lines, for reading files of last year's issued statements (PR 17).
Route::get('/', [StatementSynonymController::class, 'index'])->middleware('can:synonyms.view')->name('statement-synonyms.index');
Route::post('/', [StatementSynonymController::class, 'store'])->middleware('can:synonyms.manage')->name('statement-synonyms.store');
Route::put('/{id}', [StatementSynonymController::class, 'update'])->whereNumber('id')->middleware('can:synonyms.manage')->name('statement-synonyms.update');
Route::delete('/{id}', [StatementSynonymController::class, 'destroy'])->whereNumber('id')->middleware('can:synonyms.manage')->name('statement-synonyms.destroy');
