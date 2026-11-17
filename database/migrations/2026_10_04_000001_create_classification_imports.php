<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Importing accountant-labelled trial balances into the classification cache (CacheImport), and the name-only layer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('status');                     // pending_review | applied
            $table->json('report')->nullable();           // the last import report (rebuilt after every review change)
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // One row per distinct value, mapped once: kind budget = a الميزانية value → main class (or route / skip);
        // kind note = a (main class or route, الإيضاحات) pair → sub-class.
        Schema::create('classification_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('classification_imports')->cascadeOnDelete();
            $table->string('kind', 20);                   // budget | note
            $table->string('value');                      // budget: the value as first seen; note: the note (or the account name when the note is empty)
            $table->string('normalized');                 // ImportNormalizer::text of value
            $table->string('parent', 100)->nullable();    // note only: the budget mapping's main class, or «route»
            $table->string('decision');                   // map | route (budget only) | skip
            $table->string('main_class')->nullable();
            $table->string('sub_class')->nullable();      // note: the target by nature (any sub-class); budget: set when the main class has one sub-class
            $table->string('function_sub_class')->nullable();   // note under a policy (e.g. cost_of_revenue): the target by function
            $table->string('policy')->nullable();         // an open accounting decision (config classification.import.policies) this value falls under
            $table->string('method');                     // taxonomy | override | single_sub | llm | manual
            $table->unsignedTinyInteger('confidence');
            $table->text('reason')->nullable();           // the LLM's or the override's reason, or why it is skipped
            $table->text('flag')->nullable();             // why the accountant must look at it before apply (e.g. outside الميزانية's main class)
            $table->boolean('reviewed')->default(false);
            $table->unsignedInteger('rows')->default(0);
            $table->timestamps();

            // Short name and columns: MySQL caps identifiers at 64 chars and index keys at 3072 bytes (utf8mb4).
            $table->unique(['import_id', 'kind', 'parent', 'normalized'], 'cim_import_kind_parent_normalized_unique');
        });

        Schema::create('classification_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('classification_imports')->cascadeOnDelete();
            $table->unsignedInteger('row');               // Excel row
            $table->string('company')->nullable();
            $table->string('account_code')->nullable();
            $table->string('account_name')->nullable();
            $table->string('budget_value')->nullable();
            $table->string('note_value')->nullable();
            $table->string('side')->nullable();           // debit | credit (closing balance); null when zero
            $table->foreignId('budget_mapping_id')->nullable();
            $table->foreignId('note_mapping_id')->nullable();
            $table->string('sub_class')->nullable();      // resolved; null when skipped
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('skip_reason')->nullable();

            $table->index(['import_id', 'skip_reason']);
        });

        // Name-only lookup, rebuilt from every applied import: a name (+ balance side) labelled the same way by most companies.
        Schema::create('classification_name_layer', function (Blueprint $table) {
            $table->id();
            $table->string('name_hash', 40);
            $table->string('side');                       // debit | credit
            $table->string('normalized_name');
            $table->string('original_name');
            $table->string('sub_class');
            $table->unsignedInteger('companies');         // companies with this name and side
            $table->unsignedTinyInteger('share');         // % of them that agree on sub_class
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();

            $table->unique(['name_hash', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_name_layer');
        Schema::dropIfExists('classification_import_rows');
        Schema::dropIfExists('classification_import_mappings');
        Schema::dropIfExists('classification_imports');
    }
};
