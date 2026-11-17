<?php

use App\Services\CacheCleanup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/** One-time: entries stored before the current rules (see CacheCleanup). Data only, nothing to roll back. */
return new class extends Migration
{
    public function up(): void
    {
        Log::info('classification_cache_cleanup', app(CacheCleanup::class)->run());
    }

    public function down(): void {}
};
