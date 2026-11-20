<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The accountant's word on a finding (App\Services\Audit\Findings): the findings themselves are computed from the documents,
        // the papers and the trial balance; only what the accountant decides about one is kept, by the finding's key.
        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('key', 80);
            $table->string('status', 12)->default('open');   // open | adjusted | accepted | dismissed
            $table->text('comment')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'year', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
    }
};
