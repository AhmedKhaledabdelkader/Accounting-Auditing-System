<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request can be a working paper the client fills: `paper` is its key in config/working_papers.php (or a recalculation's in
 * config/recalculations.php), `instructions` what to fill (read from the paper), `analysis` what the returned copy showed
 * (how far it is filled, its differences), kept as read when the client uploaded it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('paper', 40)->nullable()->after('format');
            $table->text('instructions')->nullable()->after('paper');
            $table->json('analysis')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn(['paper', 'instructions', 'analysis']);
        });
    }
};
