<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The worker processing a run holds its lease and keeps its heartbeat fresh; every write it makes checks the lease. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->string('lease')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->dropColumn(['lease', 'heartbeat_at']);
        });
    }
};
