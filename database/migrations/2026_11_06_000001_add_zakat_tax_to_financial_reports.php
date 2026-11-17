<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The zakat and income tax dialog's own data: the accountant's answers to the calculation (ZakatCalculator::INPUTS) and
 * the charges they took into the statements (`applied`), kept apart from the report's `inputs`, which only keep the
 * fields the statements ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->json('zakat_tax')->nullable()->after('movement_decisions');
        });
    }

    public function down(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->dropColumn('zakat_tax');
        });
    }
};
