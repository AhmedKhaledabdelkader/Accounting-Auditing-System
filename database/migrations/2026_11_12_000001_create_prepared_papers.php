<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The working papers and recalculations the AI prepares from what the client uploaded and the trial balance (PaperMaker).
        Schema::create('prepared_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 10);          // paper | recalc
            $table->string('key', 60);
            $table->string('status', 12)->default('queued');   // queued | working | done | blocked | failed
            $table->unsignedSmallInteger('progress_done')->default(0);
            $table->unsignedSmallInteger('progress_total')->default(0);
            $table->string('path')->nullable();
            $table->json('analysis')->nullable();   // what the paper shows (WorkingPaperReader / Recalculations)
            $table->json('sources')->nullable();    // the files it was prepared from
            $table->json('notes')->nullable();      // what the AI could not find
            $table->string('error')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'year', 'kind', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepared_papers');
    }
};
