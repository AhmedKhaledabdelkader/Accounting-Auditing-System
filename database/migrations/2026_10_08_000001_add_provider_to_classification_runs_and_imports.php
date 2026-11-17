<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->string('provider')->nullable();        // CLASSIFIER_PROVIDER the run (last) started with; null = before this column
            $table->string('model')->nullable();
            $table->timestamp('started_at')->nullable();   // first start: the day its cost is priced at
        });
        Schema::table('classification_imports', function (Blueprint $table) {
            $table->string('provider')->nullable();        // set when the import's mapping first asks the AI; null = no AI needed
            $table->string('model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', fn (Blueprint $table) => $table->dropColumn(['provider', 'model', 'started_at']));
        Schema::table('classification_imports', fn (Blueprint $table) => $table->dropColumn(['provider', 'model']));
    }
};
