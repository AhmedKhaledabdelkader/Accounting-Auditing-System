<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The user's choices on the notes of a report (PR 11): {visibility: {note or policy: on|off}, texts: {paragraph: {text, base}}}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->longText('notes')->nullable()->after('movement_decisions');
        });
    }

    public function down(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
