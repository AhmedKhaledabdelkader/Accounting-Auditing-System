<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Imports run in the queue from the site, applied in stages; the cache can be exported and imported elsewhere. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_imports', function (Blueprint $table) {
            $table->string('kind')->default('file');      // file (an uploaded Excel) | export (mappings from a JSON export: no rows)
            $table->string('stage')->nullable();          // reading | budget | notes (while running)
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->text('error')->nullable();            // Arabic, for the user
            $table->string('file_path')->nullable();      // the upload on the local disk, until the job is done
            $table->string('file_hash', 64)->nullable()->index();   // sha256: warns about the same file uploaded twice
        });

        Schema::table('classification_import_rows', function (Blueprint $table) {
            $table->boolean('approved')->default(false);   // every mapping of the row is reviewed (or decided by config): apply writes it
            $table->boolean('applied')->default(false);    // written (or considered) by the last apply: the name layer counts it
        });

        // The import that wrote an imported entry: a re-apply removes only its own entries that no row produces any more.
        Schema::table('classification_cache', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->index();
        });

        // rows = rebuilt from the applied imports' rows; export = brought by a JSON export (kept by the rebuild).
        Schema::table('classification_name_layer', function (Blueprint $table) {
            $table->string('origin')->default('rows');
        });
    }

    public function down(): void
    {
        Schema::table('classification_name_layer', fn (Blueprint $table) => $table->dropColumn('origin'));
        Schema::table('classification_import_rows', fn (Blueprint $table) => $table->dropColumn(['approved', 'applied']));
        Schema::table('classification_cache', function (Blueprint $table) {
            $table->dropIndex(['import_id']);
            $table->dropColumn('import_id');
        });
        Schema::table('classification_imports', function (Blueprint $table) {
            $table->dropIndex(['file_hash']);
            $table->dropColumn(['kind', 'stage', 'progress_done', 'progress_total', 'error', 'file_path', 'file_hash']);
        });
    }
};
