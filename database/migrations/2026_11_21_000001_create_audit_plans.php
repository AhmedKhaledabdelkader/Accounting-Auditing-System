<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client's year of audit planning: the materiality the accountant set (App\Services\Audit\Materiality) and the revenue
        // sample drawn from the client's sales list (App\Services\Audit\RevenueSampling). The figures are recomputed from the
        // statements; only the accountant's choices, the sales list read and the sample drawn are kept.
        Schema::create('audit_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->json('materiality')->nullable();   // {benchmark, percent, performance_percent, trivial_percent, reason}
            $table->longText('sales')->nullable();     // the sales list read: {file, name, kind, columns, rows: [[date, number, party, amount]]}
            $table->json('sampling')->nullable();      // {assurance, seed, drawn_at, selected: [row indexes], reasons}
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_plans');
    }
};
