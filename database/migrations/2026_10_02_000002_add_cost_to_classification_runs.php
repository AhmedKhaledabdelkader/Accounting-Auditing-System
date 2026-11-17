<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            // From the provider's usage fields, summed over every billed response (retries and the matching call included).
            $table->unsignedBigInteger('input_tokens')->nullable();        // uncached input
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cache_read_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('llm_requests')->nullable();           // every request sent, failed attempts included
            $table->decimal('cost_usd', 12, 6)->nullable();                // actual, from config('classification.prices'); null = unknown price
            $table->decimal('cost_per_row_usd', 14, 8)->nullable();        // row-classification cost / rows sent: the savings basis
            $table->decimal('estimated_savings_usd', 12, 6)->nullable();   // ESTIMATE: cost_per_row_usd × rows_from_cache
            $table->string('savings_basis')->nullable();                   // this_run | recent_runs | none | unknown
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->dropColumn(['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'llm_requests',
                'cost_usd', 'cost_per_row_usd', 'estimated_savings_usd', 'savings_basis']);
        });
    }
};
