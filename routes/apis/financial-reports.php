<?php

use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\ReportExportController;
use Illuminate\Support\Facades\Route;

// Financial statements built from a finished classification run.
Route::get('/', [FinancialReportController::class, 'index'])->middleware('can:reports.view')->name('financial-reports.index');
Route::post('/', [FinancialReportController::class, 'store'])->middleware('can:reports.create')->name('financial-reports.store');
Route::get('/{id}', [FinancialReportController::class, 'show'])->middleware('can:reports.view')->name('financial-reports.show');
Route::patch('/{id}', [FinancialReportController::class, 'update'])->middleware('can:reports.edit')->name('financial-reports.update');
Route::get('/{id}/intake', [FinancialReportController::class, 'intake'])->middleware('can:reports.view')->name('financial-reports.intake');
Route::patch('/{id}/inputs', [FinancialReportController::class, 'updateInputs'])->middleware('can:reports.edit')->name('financial-reports.inputs');
Route::put('/{id}/zakat-tax', [FinancialReportController::class, 'updateZakatTax'])->middleware('can:reports.edit')->name('financial-reports.zakat-tax');
Route::put('/{id}/account-overrides', [FinancialReportController::class, 'updateOverrides'])->middleware('can:reports.edit')->name('financial-reports.account-overrides');
Route::put('/{id}/adjustments', [FinancialReportController::class, 'updateAdjustments'])->middleware('can:reports.edit')->name('financial-reports.adjustments');
Route::put('/{id}/prior-year', [FinancialReportController::class, 'updatePriorYear'])->middleware('can:reports.edit')->name('financial-reports.prior-year');
Route::post('/{id}/prior-year/statements-file', [FinancialReportController::class, 'uploadPriorStatements'])->middleware('can:reports.edit')->middleware('ai.budget')->name('financial-reports.prior-year.statements-file');
Route::put('/{id}/prior-year/statements-file/pages', [FinancialReportController::class, 'priorStatementPages'])->middleware('can:reports.edit')->name('financial-reports.prior-year.statements-file.pages');
Route::put('/{id}/prior-year/statements-file/wording', [FinancialReportController::class, 'priorStatementsWording'])->middleware('can:reports.edit')->name('financial-reports.prior-year.statements-file.wording');
Route::put('/{id}/prior-year/approved-opening', [FinancialReportController::class, 'approvedOpening'])->middleware('can:reports.edit')->name('financial-reports.prior-year.approved-opening');
Route::put('/{id}/prior-year/statements-file/totals', [FinancialReportController::class, 'priorStatementsTotals'])->middleware('can:reports.edit')->name('financial-reports.prior-year.statements-file.totals');
Route::get('/{id}/prior-year/statements-file', [FinancialReportController::class, 'priorStatementsPdf'])->middleware('can:reports.view')->name('financial-reports.prior-year.statements-file.show');
Route::post('/{id}/suggestions', [FinancialReportController::class, 'decideSuggestions'])->middleware('can:reports.edit')->name('financial-reports.suggestions');
Route::patch('/{id}/notes', [FinancialReportController::class, 'updateNotes'])->middleware('can:reports.edit')->name('financial-reports.notes');
Route::put('/{id}/acknowledgements', [FinancialReportController::class, 'updateAcknowledgements'])->middleware('can:reports.edit')->name('financial-reports.acknowledgements');
Route::post('/{id}/versions', [FinancialReportController::class, 'storeVersion'])->middleware('can:reports.edit')->name('financial-reports.versions.store');
Route::get('/{id}/versions', [FinancialReportController::class, 'versions'])->middleware('can:reports.view')->name('financial-reports.versions');
Route::get('/{id}/versions/{version}', [FinancialReportController::class, 'showVersion'])->whereNumber('version')->middleware('can:reports.view')->name('financial-reports.versions.show');
Route::get('/{id}/versions/{version}/export.xlsx', [ReportExportController::class, 'xlsx'])->whereNumber('version')->middleware('can:reports.export')->name('financial-reports.versions.xlsx');
Route::get('/{id}/versions/{version}/export.docx', [ReportExportController::class, 'docx'])->whereNumber('version')->middleware('can:reports.export')->name('financial-reports.versions.docx');
Route::get('/{id}/versions/{version}/export.pdf', [ReportExportController::class, 'pdf'])->whereNumber('version')->middleware('can:reports.export')->name('financial-reports.versions.pdf');
