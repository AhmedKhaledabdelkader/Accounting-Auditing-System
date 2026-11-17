<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Words the accountant marked as right in a PDF of last year's statements (a name, a rare word), never flagged again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 255)->unique();   // normalised (Wording::normalised)
            $table->string('label', 255);            // as printed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_words');
    }
};
