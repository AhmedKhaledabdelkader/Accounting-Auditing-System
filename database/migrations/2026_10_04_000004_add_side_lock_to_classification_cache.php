<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Side pairs (config side_pairs): a manual correction against the row's balance side is a deliberate override, never flipped. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_cache', function (Blueprint $table) {
            $table->string('side')->nullable();                  // manual corrections: the row's balance side when corrected
            $table->boolean('side_locked')->default(false);      // true: never re-decided by balance side
        });
    }

    public function down(): void
    {
        Schema::table('classification_cache', fn (Blueprint $table) => $table->dropColumn(['side', 'side_locked']));
    }
};
