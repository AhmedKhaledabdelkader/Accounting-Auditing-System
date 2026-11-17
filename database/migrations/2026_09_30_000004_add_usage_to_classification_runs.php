<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tokens a run's structure detection used before it paused for confirmation, added back when it resumes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->json('usage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->dropColumn('usage');
        });
    }
};
