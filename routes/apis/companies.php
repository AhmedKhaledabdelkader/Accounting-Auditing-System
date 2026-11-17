<?php

use App\Http\Controllers\Api\CompanyController;
use Illuminate\Support\Facades\Route;

// Company profiles for the financial statements, reused across years.
Route::get('/', [CompanyController::class, 'index'])->middleware('can:companies.view')->name('companies.index');
Route::post('/', [CompanyController::class, 'store'])->middleware('can:companies.manage')->name('companies.store');
Route::get('/{id}', [CompanyController::class, 'show'])->middleware('can:companies.view')->name('companies.show');
Route::patch('/{id}', [CompanyController::class, 'update'])->middleware('can:companies.manage')->name('companies.update');
Route::post('/{id}/registration', [CompanyController::class, 'uploadRegistration'])->middleware('can:companies.manage')->name('companies.registration.upload');
Route::post('/{id}/articles', [CompanyController::class, 'uploadArticles'])->middleware('can:companies.manage')->name('companies.articles.upload');
Route::delete('/{id}/articles', [CompanyController::class, 'deleteArticles'])->middleware('can:companies.manage')->name('companies.articles.delete');
