<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users, roles and permissions: a user has one role and a status (pending until the admin approves the sign-up); a role
 * has a list of permission keys (config/permissions.php), or all of them when it is an admin role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('open_for_signup')->default(true);   // offered on the sign-up page
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            $table->primary(['role_id', 'permission']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('requested_role_id')->nullable()->after('role_id')->constrained('roles')->nullOnDelete();
            $table->string('status', 16)->default('pending')->after('requested_role_id');
            $table->string('locale', 2)->default('ar')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_role_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['status', 'locale']);
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
