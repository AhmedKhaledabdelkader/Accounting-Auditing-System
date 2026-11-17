<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions of a report (PR 12, Q19): immutable snapshots of its state, draft or final. Plus the report's warning
 * acknowledgements: [{key, note, acknowledged_at}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('report_id')->constrained('financial_reports')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('kind');                         // draft | final
            $table->longText('payload');                    // the report state as it was (plan §4.4)
            $table->string('inputs_hash', 40);              // the report's own data
            $table->string('run_result_hash', 40);
            $table->string('content_hash', 40);             // what the statements and notes show
            $table->timestamp('created_at')->nullable();
            $table->unique(['report_id', 'version'], 'frv_report_version_unique');
        });

        Schema::table('financial_reports', function (Blueprint $table) {
            $table->text('acknowledgements')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->dropColumn('acknowledgements');
        });
        Schema::dropIfExists('financial_report_versions');
    }
};
