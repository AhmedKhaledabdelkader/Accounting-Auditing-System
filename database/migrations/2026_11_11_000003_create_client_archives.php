<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A compressed file (zip, rar, 7z...) a client or the office uploads with everything in it: the system unpacks it, the AI reads the
 * names and puts each file in the request it answers (ArchiveSorter). `summary` keeps what happened: how many files, how many placed,
 * how many left unsorted, what was skipped and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');                       // the fiscal year (the year its period ends in) the files are for
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_client')->default(true);
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 12)->default('queued');            // queued | sorting | done | failed
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_archives');
    }
};