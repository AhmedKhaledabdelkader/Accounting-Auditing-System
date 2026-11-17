<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored messages keep their Arabic text where it always was; these columns hold the same message as a phrase (lang key
 * and parameters, App\Support\Phrase), so the API can give it in the reader's language. Null: a record from before them,
 * or text that is data (an AI's reason, a config text), shown as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_runs', fn (Blueprint $table) => $table->json('error_phrase')->nullable()->after('error'));
        Schema::table('classification_imports', fn (Blueprint $table) => $table->json('error_phrase')->nullable()->after('error'));
        Schema::table('classification_import_mappings', function (Blueprint $table) {
            $table->json('reason_phrase')->nullable()->after('reason');
            $table->json('flag_phrase')->nullable()->after('flag');
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', fn (Blueprint $table) => $table->dropColumn('error_phrase'));
        Schema::table('classification_imports', fn (Blueprint $table) => $table->dropColumn('error_phrase'));
        Schema::table('classification_import_mappings', fn (Blueprint $table) => $table->dropColumn(['reason_phrase', 'flag_phrase']));
    }
};
