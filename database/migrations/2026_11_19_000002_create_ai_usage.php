<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every AI call, whoever and whatever it was for (MeteredDriver): the tokens and the dollars, so each user can have a monthly limit.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // who it was for (none: a system task)
            $table->unsignedBigInteger('office_id')->nullable()->index();
            $table->string('kind', 60);                                   // the tool the AI was asked to fill (submit_rows, submit_page…)
            $table->string('provider', 20);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();               // null: the model has no price (config classification.prices)
            $table->timestamp('created_at')->useCurrent()->index();
        });
        // A user's monthly AI limit in dollars; null = no limit.
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('ai_limit_usd', 10, 4)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ai_limit_usd'));
    }
};
