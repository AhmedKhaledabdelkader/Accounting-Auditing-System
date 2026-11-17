<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Runs can wait for the user to confirm the column mapping (status needs_confirmation). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->text('structure')->nullable();          // the mapping: the AI's, then the user's once confirmed
            $table->text('structure_issues')->nullable();   // errors / doubts shown to the user (cleared with the preview)
            $table->longText('confirmation')->nullable();   // column preview (headers, sample values): client data, cleared when the run ends
            $table->timestamp('expires_at')->nullable();    // an unconfirmed run is deleted after this (classification:prune)
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->dropColumn(['structure', 'structure_issues', 'confirmation', 'expires_at', 'confirmed_at']);
        });
    }
};
