<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The company a trial balance belongs to, chosen at upload: its code-group marks (classification rules) apply to the
// run, and a report made from the run starts with it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->after('original_name')->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
