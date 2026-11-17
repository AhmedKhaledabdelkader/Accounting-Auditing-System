<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status')->default('queued');   // queued | running | needs_confirmation | done | failed | cancelled | expired
            $table->string('stage')->nullable();           // reading | structure | classifying | checks
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->string('original_name');
            $table->string('file_path')->nullable();       // deleted once the run finishes
            $table->longText('result')->nullable();        // {data, meta} — same shape as the sync endpoint
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_runs');
    }
};
