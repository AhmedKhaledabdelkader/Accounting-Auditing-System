<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/v1/me: the signed-in user's name, role, permissions and office, for the client screen. */
class MeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_signed_in_user_with_only_the_permissions_of_their_role(): void
    {
        $office = Office::create(['name_ar' => 'مكتب النور', 'code' => 'noor']);
        $role = Role::create(['name_ar' => 'مراجع', 'name_en' => 'Auditor', 'is_admin' => false]);
        $role->syncPermissions(['clients.view']);
        $user = User::create(['office_id' => $office->id, 'name' => 'سارة', 'username' => 'sara', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $this->actingAs($user);

        // The screen asks in its language (Accept-Language), like every other API call.
        $this->getJson('/api/v1/me', ['Accept-Language' => 'ar'])->assertOk()->assertJson([
            'name' => 'سارة',
            'username' => 'sara',
            'role' => ['name' => 'مراجع', 'is_admin' => false],
            'is_admin' => false,
            'permissions' => ['clients.view'],
            'office' => ['name' => 'مكتب النور'],
            'is_client' => false,
        ]);
        $this->getJson('/api/v1/me', ['Accept-Language' => 'en'])->assertOk()->assertJsonPath('role.name', 'Auditor');
    }

    public function test_an_office_admin_gets_every_permission_except_the_platform_ones(): void
    {
        $office = Office::create(['name_ar' => 'مكتب', 'code' => 'alpha']);
        $role = Role::create(['name_ar' => 'مدير', 'is_admin' => true]);
        $user = User::create(['office_id' => $office->id, 'name' => 'منى', 'username' => 'mona', 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $this->actingAs($user);

        $me = $this->getJson('/api/v1/me')->assertOk()->json();
        $this->assertTrue($me['is_admin']);
        $this->assertContains('clients.manage', $me['permissions']);
        foreach (User::PLATFORM_ONLY as $key) {
            $this->assertNotContains($key, $me['permissions']);
        }
    }

    public function test_needs_sign_in(): void
    {
        $this->signOut();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
