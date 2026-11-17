<?php

use App\Services\Statements\StatementSynonyms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Other wordings of the statement lines (PR 17), edited in the app; started with common wordings of issued statements.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('statement', 32);
            $table->string('line_key', 64);
            $table->string('label', 255);
            $table->string('wording', 255);
            $table->timestamps();
            $table->unique(['statement', 'wording']);
        });
        $now = now();
        DB::table('statement_synonyms')->insert(array_map(fn ($s) => $s + ['created_at' => $now, 'updated_at' => $now], StatementSynonyms::initial()));
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_synonyms');
    }
};
