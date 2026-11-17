<?php

use App\Repositories\Eloquents\TaxonomyRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The classification taxonomy, edited on the taxonomy page (docs/16-taxonomy.md): main classes, sub classes and every
// former name. Seeded from config('classification.taxonomy'), so nothing changes on deploy; `origin_name` keeps the
// config name the rules (config files, the AI's hints) are written with.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_main_classes', function (Blueprint $table) {
            $table->id();
            $table->string('statement', 60);
            $table->string('name', 100)->unique();
            $table->string('family', 20);                          // TaxonomyRepository::FAMILIES
            $table->unsignedInteger('sort')->default(0);
            $table->string('origin_name', 100)->nullable()->unique();   // the config name; null when added in the app
            $table->timestamps();
        });
        Schema::create('taxonomy_sub_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_class_id')->constrained('taxonomy_main_classes')->restrictOnDelete();
            $table->string('name', 100)->unique();
            $table->string('normal_side', 6);                      // debit | credit
            $table->string('line_key', 80)->nullable();            // the statement line; required when added in the app
            $table->unsignedInteger('sort')->default(0);
            $table->string('origin_name', 100)->nullable()->unique();
            $table->timestamps();
        });
        // Every former name → the category it now is, so classification runs stored with an old name still build statements.
        Schema::create('taxonomy_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 4);                             // main | sub
            $table->string('name', 100);
            $table->foreignId('main_class_id')->nullable()->constrained('taxonomy_main_classes')->cascadeOnDelete();
            $table->foreignId('sub_class_id')->nullable()->constrained('taxonomy_sub_classes')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        $now  = now();
        $sort = 0;
        foreach (config('classification.taxonomy') as $statement => $mains) {
            foreach ($mains as $main => $subs) {
                $id = DB::table('taxonomy_main_classes')->insertGetId(['statement' => $statement, 'name' => $main,
                    'family' => TaxonomyRepository::configFamily($statement, $main), 'sort' => ++$sort, 'origin_name' => $main,
                    'created_at' => $now, 'updated_at' => $now]);
                $rows = [];
                foreach ($subs as $sub => $side) {
                    $rows[] = ['main_class_id' => $id, 'name' => $sub, 'normal_side' => $side, 'line_key' => null, 'sort' => ++$sort,
                        'origin_name' => $sub, 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('taxonomy_sub_classes')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_aliases');
        Schema::dropIfExists('taxonomy_sub_classes');
        Schema::dropIfExists('taxonomy_main_classes');
    }
};
