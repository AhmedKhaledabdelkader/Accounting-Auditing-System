<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A side rule needs a side when the closing balance is zero: the opening balance's, then the net movement's. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_import_rows', function (Blueprint $table) {
            $table->string('opening_side')->nullable();    // debit | credit of the opening balance; null when zero
            $table->string('movement_side')->nullable();   // debit | credit of the period's net movement; null when zero
        });
    }

    public function down(): void
    {
        Schema::table('classification_import_rows', fn (Blueprint $table) => $table->dropColumn(['opening_side', 'movement_side']));
    }
};
