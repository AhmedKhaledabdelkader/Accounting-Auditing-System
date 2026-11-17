<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The last uploaded file of last year's issued statements (PR 17), read and proposed, waiting for the user's review: it is
// used only once confirmed through PUT …/prior-year (source `statements`), which copies it into prior_figures.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->longText('prior_upload')->nullable()->after('prior_figures');
        });
    }

    public function down(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->dropColumn('prior_upload');
        });
    }
};
