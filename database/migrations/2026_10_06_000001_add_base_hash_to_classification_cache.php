<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The code + name fallback of imported entries (ClassificationKey::base): their key has a budget item a client's file lacks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_cache', function (Blueprint $table) {
            $table->string('base_hash', 40)->nullable()->index();   // sha1 of «code:…|name:…»; null without a code
        });
    }

    public function down(): void
    {
        Schema::table('classification_cache', function (Blueprint $table) {
            $table->dropIndex(['base_hash']);
            $table->dropColumn('base_hash');
        });
    }
};
