<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the AI made of a file the client uploaded: its verdict, the issues, and what it read (DocumentReviewer). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_files', function (Blueprint $table) {
            $table->json('review')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('client_files', function (Blueprint $table) {
            $table->dropColumn('review');
        });
    }
};