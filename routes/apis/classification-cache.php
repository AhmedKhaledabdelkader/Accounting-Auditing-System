<?php

use App\Http\Controllers\Api\ClassificationCacheController;
use App\Http\Controllers\Api\ClassificationImportController;
use App\Http\Controllers\Api\ClassificationTransferController;
use Illuminate\Support\Facades\Route;

// Read-only view of the classification cache for the accountant, and removal of a wrong entry.
Route::get('/explain', [ClassificationCacheController::class, 'explain'])->middleware('can:cache.view')->name('classification-cache.explain');
Route::get('/entries', [ClassificationCacheController::class, 'entries'])->middleware('can:cache.view')->name('classification-cache.entries');
Route::post('/entries', [ClassificationCacheController::class, 'store'])->middleware('can:cache.review')->name('classification-cache.entries.store');
Route::post('/entries/reclassify', [ClassificationCacheController::class, 'reclassify'])->middleware('can:cache.review')->name('classification-cache.entries.reclassify');
Route::post('/entries/delete', [ClassificationCacheController::class, 'destroyMany'])->middleware('can:cache.delete')->name('classification-cache.entries.destroy-many');
Route::delete('/entries/{id}', [ClassificationCacheController::class, 'destroy'])->whereNumber('id')->middleware('can:cache.delete')->name('classification-cache.entries.destroy');

// Importing accountant-labelled trial balances: upload (queued: read + map), review the mappings, then apply the approved ones.
Route::get('/imports', [ClassificationImportController::class, 'index'])->middleware('can:cache.view')->name('classification-cache.imports');
Route::post('/import', [ClassificationImportController::class, 'store'])->middleware('can:cache.import')->name('classification-cache.import');
Route::get('/imports/{id}', [ClassificationImportController::class, 'show'])->whereNumber('id')->middleware('can:cache.view')->name('classification-cache.imports.show');
Route::get('/imports/{id}/mappings', [ClassificationImportController::class, 'mappings'])->whereNumber('id')->middleware('can:cache.view')->name('classification-cache.imports.mappings');
Route::patch('/imports/mappings/{mappingId}', [ClassificationImportController::class, 'review'])->whereNumber('mappingId')->middleware('can:cache.review')->name('classification-cache.imports.review');
Route::post('/imports/{id}/mappings/approve', [ClassificationImportController::class, 'approve'])->whereNumber('id')->middleware('can:cache.review')->name('classification-cache.imports.approve');
Route::post('/imports/{id}/apply', [ClassificationImportController::class, 'apply'])->whereNumber('id')->middleware('can:cache.review')->name('classification-cache.imports.apply');

// Moving the cache between instances. Needs its permission, like every endpoint.
Route::get('/export.xlsx', [ClassificationTransferController::class, 'exportXlsx'])->middleware('can:cache.transfer')->name('classification-cache.export.xlsx');
Route::get('/export.json', [ClassificationTransferController::class, 'exportJson'])->middleware('can:cache.transfer')->name('classification-cache.export.json');
Route::post('/import-export', [ClassificationTransferController::class, 'importJson'])->middleware('can:cache.transfer')->name('classification-cache.import-export');
