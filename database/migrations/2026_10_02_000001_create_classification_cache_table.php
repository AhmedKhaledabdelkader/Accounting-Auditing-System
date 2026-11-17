<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_cache', function (Blueprint $table) {
            $table->id();
            $table->string('key_hash', 40)->unique();     // sha1 of normalized_key: the lookup of rows with a code or budget item
            $table->string('name_hash', 40)->index();     // sha1 of the normalized name: one lookup for every generic row (key needs the parent)
            $table->text('normalized_key');               // readable key (ClassificationKey), for the entries API and debugging
            $table->string('original_name');
            $table->string('account_code')->nullable();
            $table->string('budget_item')->nullable();
            $table->string('row_type');                   // account | parent | total | section | other
            $table->string('main_class')->nullable();     // names: the taxonomy has no ids; null for non-account rows
            $table->string('sub_class')->nullable();
            $table->string('source');                     // ai | manual_correction
            $table->unsignedTinyInteger('confidence');    // manual_correction = 100
            $table->text('reason')->nullable();           // the AI's own reason (null for manual corrections)
            $table->string('taxonomy_version');
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();

            $table->index(['source', 'updated_at']);      // entries API: ?source= filter, newest first
            $table->index('updated_at');                  // entries API without a filter
        });

        Schema::table('classification_runs', function (Blueprint $table) {
            $table->unsignedInteger('rows_from_cache')->nullable();
            $table->unsignedInteger('rows_sent_to_llm')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_runs', function (Blueprint $table) {
            $table->dropColumn(['rows_from_cache', 'rows_sent_to_llm']);
        });
        Schema::dropIfExists('classification_cache');
    }
};
