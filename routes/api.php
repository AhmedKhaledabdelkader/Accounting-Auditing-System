<?php

use Illuminate\Support\Facades\Route;

// Every endpoint needs an active account (the pages' session, or an API key: Sanctum) and its permission (config/permissions.php).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('classify')->group(base_path('routes/apis/classify.php'));
    Route::prefix('classification-cache')->group(base_path('routes/apis/classification-cache.php'));
    Route::prefix('companies')->group(base_path('routes/apis/companies.php'));
    Route::prefix('financial-reports')->group(base_path('routes/apis/financial-reports.php'));
    Route::prefix('statement-synonyms')->group(base_path('routes/apis/statement-synonyms.php'));
});
