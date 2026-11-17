<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The financial statements generator (plan §4.1): company profiles and reports. No owner columns yet (local-only, Q21). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('legal_form')->default('llc');
            $table->char('country', 2)->default('SA');
            $table->string('framework')->default('ifrs_for_smes');
            $table->string('cr_number')->nullable();
            $table->string('cr_date_hijri')->nullable();   // stored exactly as entered (Q26)
            $table->date('cr_date')->nullable();
            $table->string('unified_number')->nullable();
            $table->string('city')->nullable();
            $table->string('head_office')->nullable();
            $table->text('activity')->nullable();
            $table->text('branches')->nullable();          // list of strings
            $table->unsignedTinyInteger('fiscal_year_end_month')->default(12);
            $table->unsignedTinyInteger('fiscal_year_end_day')->default(31);
            $table->char('currency', 3)->default('SAR');
            $table->text('profile')->nullable();           // policy choices and useful lives carried between years
            $table->timestamps();
        });

        Schema::create('financial_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('classification_run_id')->constrained('classification_runs');
            $table->foreignUuid('prior_report_id')->nullable()->constrained('financial_reports')->nullOnDelete();
            $table->uuid('prior_run_id')->nullable();
            $table->string('status')->default('draft');   // draft | final
            $table->date('period_start');
            $table->date('period_end');
            $table->date('comparative_start')->nullable(); // null in the first year
            $table->date('comparative_end')->nullable();
            $table->string('template_version');
            $table->longText('inputs')->nullable();        // every user-entered figure, text, toggle and confirmation
            $table->text('account_overrides')->nullable(); // row → line_key, plus tags
            $table->text('adjustments')->nullable();       // report-level adjusting entries (Q39)
            $table->longText('prior_figures')->nullable(); // line_key → amount, with origin
            $table->string('run_result_hash', 40)->nullable();   // sha1 of the run's result at the last compute
            $table->timestamp('last_computed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reports');
        Schema::dropIfExists('companies');
    }
};
