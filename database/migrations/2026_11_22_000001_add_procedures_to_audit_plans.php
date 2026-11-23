<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The audit program's answers (step 14, App\Services\Audit\AuditProcedures): by area, each procedure's status,
        // reference and note, the accountant's own procedures, the conclusion and the sign-offs.
        Schema::table('audit_plans', function (Blueprint $table) {
            $table->json('procedures')->nullable()->after('sampling');
        });
    }

    public function down(): void
    {
        Schema::table('audit_plans', function (Blueprint $table) {
            $table->dropColumn('procedures');
        });
    }
};
