<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «عضو» (Member), offered on the sign-up page: classifies a trial balance and builds the financial statements from it.
 * No final version, memory, companies or admin pages; the admin changes it from admin → roles.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['classify.view', 'classify.run', 'classify.correct',
        'reports.view', 'reports.create', 'reports.edit', 'reports.export'];

    public function up(): void
    {
        if (DB::table('roles')->where('name_en', 'Member')->exists()) {
            return;
        }
        $id = DB::table('roles')->insertGetId(['name_ar' => 'عضو', 'name_en' => 'Member', 'is_admin' => false,
            'open_for_signup' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $id, 'permission' => $p], self::PERMISSIONS));
    }

    public function down(): void
    {
        $id = DB::table('roles')->where('name_en', 'Member')->value('id');
        if ($id && ! DB::table('users')->where('role_id', $id)->orWhere('requested_role_id', $id)->exists()) {
            DB::table('role_permissions')->where('role_id', $id)->delete();
            DB::table('roles')->where('id', $id)->delete();
        }
    }
};
