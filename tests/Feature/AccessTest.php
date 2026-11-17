<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Users, roles and permissions: sign-in only for active accounts, sign-up waits for the admin, every page and endpoint
 * needs its permission, roles made by the admin, API keys with the account's permissions, and always an admin left.
 */
class AccessTest extends TestCase
{
    use RefreshDatabase;

    /** The main office, made by the migration: the users of these tests belong to it and sign in with its code. */
    private function main(): Office
    {
        return Office::byCode('main');
    }

    private function role(array $permissions, array $attrs = []): Role
    {
        $role = Role::create($attrs + ['name_ar' => 'محاسب', 'name_en' => 'Accountant']);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function user(?Role $role, string $status = User::ACTIVE, string $username = 'sara'): User
    {
        return User::create(['office_id' => $this->main()->id, 'name' => 'سارة علي', 'username' => $username, 'password' => 'secret123', 'role_id' => $role?->id, 'status' => $status]);
    }

    private function adminUser(): User
    {
        return $this->user($this->role([], ['name_ar' => 'مدير النظام', 'is_admin' => true, 'open_for_signup' => false]), User::ACTIVE, 'boss');
    }

    public function test_a_visitor_is_sent_to_sign_in_and_the_api_answers_401(): void
    {
        $this->signOut();
        $this->get('/financial-reports')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/v1/financial-reports')->assertUnauthorized();
        $this->get('/login')->assertOk()->assertSee('تسجيل الدخول');
    }

    public function test_only_an_active_account_signs_in(): void
    {
        $this->signOut();
        $this->user($this->role(['reports.view']));
        $this->user(null, User::PENDING, 'pending.one');
        $this->user(null, User::DISABLED, 'disabled-one');

        $this->post('/login', ['office_code' => 'main', 'username' => 'sara', 'password' => 'wrong'])->assertSessionHasErrors(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة.']);
        $this->post('/login', ['office_code' => 'main', 'username' => 'pending.one', 'password' => 'secret123'])->assertSessionHasErrors(['username' => 'حسابك بانتظار موافقة مدير النظام.']);
        $this->assertGuest();
        $this->post('/login', ['office_code' => 'main', 'username' => 'disabled-one', 'password' => 'secret123'])->assertSessionHasErrors(['username' => 'حسابك متوقف. يُرجى التواصل مع مدير النظام.']);
        $this->post('/login', ['office_code' => 'main', 'username' => 'Sara', 'password' => 'secret123'])->assertRedirect('/');   // any case
        $this->assertAuthenticated();
        // The start page is the first page the role allows: the classifier for whoever may use it, else the reports.
        $this->get('/')->assertRedirect('/financial-reports');
        $this->actingAs($this->user($this->role(['reports.view', 'classify.view'], ['name_ar' => 'محاسب مبوّب']), User::ACTIVE, 'omar'));
        $this->get('/')->assertRedirect('/classifier');
    }

    public function test_sign_up_waits_for_the_admin_with_the_asked_role(): void
    {
        $this->signOut();
        $open = $this->role(['reports.view']);
        $closed = $this->role([], ['name_ar' => 'مدقق داخلي', 'open_for_signup' => false]);
        $admin = $this->role([], ['name_ar' => 'مشرف عام', 'is_admin' => true]);

        $this->get('/register')->assertOk()->assertSee('محاسب')->assertDontSee('مدقق داخلي')->assertDontSee('مشرف عام');
        $form = ['office_code' => 'main', 'name' => 'منى', 'username' => 'Mona.Ali', 'password' => 'secret123', 'password_confirmation' => 'secret123'];
        $this->post('/register', $form + ['role_id' => $closed->id])->assertSessionHasErrors('role_id');
        $this->post('/register', $form + ['role_id' => $admin->id])->assertSessionHasErrors('role_id');
        $this->post('/register', ['password' => 'short', 'password_confirmation' => 'short'] + $form + ['role_id' => $open->id])->assertSessionHasErrors('password');
        $this->post('/register', $form + ['role_id' => $open->id])->assertRedirect('/login')->assertSessionHas('status');

        $this->post('/register', ['username' => 'mona.ali'] + $form + ['role_id' => $open->id])->assertSessionHasErrors(['username' => 'اسم المستخدم هذا مستخدم من قبل.']);
        $this->post('/register', ['username' => 'منى'] + $form + ['role_id' => $open->id])->assertSessionHasErrors('username');
        $user = User::where('username', 'mona.ali')->first();
        $this->assertSame([User::PENDING, $open->id, null], [$user->status, $user->requested_role_id, $user->role_id]);
        $this->assertGuest();
    }

    public function test_the_member_role_is_offered_and_only_classifies_and_builds_statements(): void
    {
        $this->signOut();
        $member = Role::where('name_en', 'Member')->first();
        $this->get('/register')->assertOk()->assertSee('عضو')->assertDontSee('التسجيل مقفول');
        $this->assertEqualsCanonicalizing(['classify.view', 'classify.run', 'classify.correct',
            'reports.view', 'reports.create', 'reports.edit', 'reports.export'], $member->permissions());

        $this->actingAs($this->user($member));
        $this->get('/')->assertRedirect('/classifier');
        $this->get('/classification-cache')->assertForbidden();
        $this->postJson('/api/v1/financial-reports/00000000-0000-0000-0000-000000000000/versions', ['kind' => 'final'])->assertForbidden();
    }

    public function test_a_missing_page_shows_the_apps_page_and_a_missing_endpoint_json(): void
    {
        $this->actingAs($this->user($this->role(['reports.view', 'users.manage'])));
        User::create(['office_id' => $this->main()->id, 'name' => 'منى', 'username' => 'mona', 'password' => 'secret123', 'status' => User::PENDING]);

        // The menu, with the accounts waiting for approval counted beside «المستخدمين».
        $this->get('/nope')->assertNotFound()->assertSee('الصفحة غير موجودة')->assertSee('المستخدمون')->assertSee('1 بانتظار الموافقة');
        $this->post('/api/v1/nope')->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Route not found']);
    }

    public function test_each_user_keeps_their_own_column_order(): void
    {
        $sara = $this->user($this->role(['classify.view']));
        $this->actingAs($sara);

        $this->putJson('/account/preferences', ['key' => 'columns.classifier', 'value' => ['account_name', 'account_number', 'account_name']])
            ->assertOk()->assertJsonPath('preferences.columns.classifier', ['account_name', 'account_number']);
        $this->putJson('/account/preferences', ['key' => 'columns.memory', 'value' => ['c2', 'c1']])->assertOk();
        $this->putJson('/account/preferences', ['key' => 'theme', 'value' => ['x']])->assertJsonValidationErrors('key');
        $this->putJson('/account/preferences', ['key' => 'columns.memory', 'value' => [str_repeat('x', 41)]])->assertJsonValidationErrors('value.0');
        // Every page carries them for its tables; another user has their own.
        $this->get('/classifier')->assertOk()->assertSee('"classifier":["account_name","account_number"]', false);
        $this->actingAs($this->user($this->role(['classify.view'], ['name_ar' => 'آخر']), User::ACTIVE, 'omar'));
        $this->get('/classifier')->assertOk()->assertSee('"prefs":{}', false);

        $this->actingAs($sara->fresh());
        $this->putJson('/account/preferences', ['key' => 'columns.memory', 'value' => null])->assertOk();
        $this->assertSame(['classifier' => ['account_name', 'account_number']], $sara->fresh()->preferences['columns']);
        $this->get('/account')->assertSee('عدد الجداول المرتّبة حسب تفضيلك: 1');
        $this->delete('/account/preferences/columns')->assertRedirect()->assertSessionHas('status');
        $this->assertNull($sara->fresh()->preferences);
    }

    public function test_every_page_and_endpoint_needs_its_permission(): void
    {
        $catalogue = Role::catalogue();
        $open = ['login', 'register', 'locale', 'logout', 'home', 'account', 'account.password', 'account.preferences', 'account.columns.reset', 'account.tokens.store', 'account.tokens.destroy',
            'sanctum.csrf-cookie', 'storage.local', 'storage.local.upload'];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || in_array($name, $open, true) || str_starts_with($route->uri(), 'up') || str_starts_with($route->uri(), '_dusk')) {
                continue;
            }
            $can = array_values(array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'can:')));
            $this->assertNotEmpty($can, "{$name} has no permission");
            $this->assertContains(substr($can[0], 4), $catalogue, $name);
            $this->assertTrue(in_array('auth', $route->gatherMiddleware(), true) || in_array('auth:sanctum', $route->gatherMiddleware(), true), "{$name} needs a signed-in user");
        }
    }

    public function test_a_role_opens_only_what_it_allows(): void
    {
        $this->actingAs($this->user($this->role(['reports.view', 'synonyms.view'])));

        $this->get('/financial-reports')->assertOk()->assertSee('مرادفات البنود')->assertDontSee('ذاكرة التصنيف')->assertDontSee('المستخدمون');
        $this->get('/classification-cache')->assertForbidden()->assertSee('غير مسموح');
        $this->getJson('/api/v1/financial-reports')->assertOk();
        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->postJson('/api/v1/statement-synonyms', [])->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_a_final_version_needs_its_own_permission(): void
    {
        $this->actingAs($this->user($this->role(['reports.view', 'reports.edit'])));

        $this->postJson('/api/v1/financial-reports/00000000-0000-0000-0000-000000000000/versions', ['kind' => 'final'])->assertForbidden();
        $this->postJson('/api/v1/financial-reports/00000000-0000-0000-0000-000000000000/versions', ['kind' => 'draft'])->assertNotFound();
    }

    public function test_a_disabled_user_is_signed_out(): void
    {
        $user = $this->user($this->role(['reports.view']));
        $this->actingAs($user);
        $user->update(['status' => User::DISABLED]);

        $this->get('/financial-reports')->assertRedirect('/login');
        $this->actingAs($user->fresh())->getJson('/api/v1/financial-reports')->assertForbidden();
    }

    public function test_the_admin_approves_and_changes_roles_but_one_admin_always_stays(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $role = $this->role(['reports.view']);
        $other = $this->role(['cache.view'], ['name_ar' => 'مراجع']);
        $pending = User::create(['office_id' => $this->main()->id, 'name' => 'منى', 'username' => 'mona', 'password' => 'secret123', 'requested_role_id' => $role->id, 'status' => User::PENDING]);

        $this->get('/admin/users')->assertOk()->assertSee('منى')->assertSee('بانتظار الموافقة');
        $this->put("/admin/users/{$pending->id}", ['status' => User::ACTIVE, 'role_id' => ''])->assertSessionHasErrors('role_id');
        $this->put("/admin/users/{$pending->id}", ['status' => User::ACTIVE, 'role_id' => $other->id])->assertSessionHas('status', 'تمت الموافقة على الحساب.');
        $this->assertSame([User::ACTIVE, $other->id], [$pending->fresh()->status, $pending->fresh()->role_id]);

        $this->put("/admin/users/{$admin->id}", ['status' => User::DISABLED, 'role_id' => $admin->role_id])->assertSessionHasErrors('admin');
        $this->put("/admin/roles/{$admin->role_id}", ['name_ar' => 'مدير النظام', 'is_admin' => '0'])->assertSessionHasErrors('admin');
        $this->assertTrue($admin->fresh()->isAdmin());

        $this->put("/admin/users/{$pending->id}/password", ['password' => 'newpass123'])->assertSessionHas('status');
        $this->signOut();
        $this->post('/login', ['office_code' => 'main', 'username' => 'mona', 'password' => 'newpass123'])->assertRedirect('/');
    }

    public function test_the_admin_makes_roles_from_the_permission_list(): void
    {
        $this->actingAs($this->adminUser());

        $this->get('/admin/roles/new')->assertOk()->assertSee('إصدار نسخة نهائية')->assertSee('إدارة المستخدمين');
        $this->post('/admin/roles', ['name_ar' => 'مراجع', 'name_en' => 'Reviewer', 'permissions' => ['reports.view', 'reports.finalize']])->assertRedirect('/admin/roles');
        $role = Role::where('name_ar', 'مراجع')->first();
        $this->assertEqualsCanonicalizing(['reports.view', 'reports.finalize'], $role->permissions());
        $this->post('/admin/roles', ['name_ar' => 'x', 'permissions' => ['made.up']])->assertSessionHasErrors('permissions.0');

        $this->put("/admin/roles/{$role->id}", ['name_ar' => 'مراجع', 'permissions' => ['cache.view']])->assertRedirect('/admin/roles');
        $this->assertSame(['cache.view'], $role->fresh()->permissions());

        $this->user($role);
        $this->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');
        $empty = $this->role([], ['name_ar' => 'فاضي']);
        $this->delete("/admin/roles/{$empty->id}")->assertRedirect('/admin/roles');
        $this->assertNull(Role::find($empty->id));
    }

    public function test_an_api_key_carries_the_accounts_permissions(): void
    {
        $user = $this->user($this->role(['reports.view', 'api_tokens.manage']));
        $this->actingAs($user)->post('/account/tokens', ['name' => 'برنامج'])->assertSessionHas('token');
        $token = session('token');

        $this->signOut();
        $this->withToken($token)->getJson('/api/v1/financial-reports')->assertOk();
        $this->withToken($token)->getJson('/api/v1/companies')->assertForbidden();
        $this->signOut();   // the guard keeps the last request's user between test requests
        $this->withToken('1|wrong')->getJson('/api/v1/financial-reports')->assertUnauthorized();
    }

    public function test_every_permission_has_an_arabic_and_an_english_name(): void
    {
        foreach (config('permissions') as $group => $keys) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', Lang::get("permissions.groups.{$group}", [], 'ar'));
            $this->assertNotSame("permissions.groups.{$group}", Lang::get("permissions.groups.{$group}", [], 'en'));
            foreach ($keys as $key) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', Lang::get("permissions.keys.{$key}", [], 'ar'), $key);
                $this->assertNotSame("permissions.keys.{$key}", Lang::get("permissions.keys.{$key}", [], 'en'), $key);
            }
        }
    }

    public function test_the_first_admin_is_made_from_the_command_line(): void
    {
        $this->artisan('users:create-admin', ['username' => 'Boss', '--name' => 'المدير', '--password' => 'secret123'])->assertSuccessful();
        $this->assertTrue(User::where('username', 'boss')->first()->isAdmin());
        $this->artisan('users:create-admin', ['username' => 'boss', '--password' => 'secret123'])->assertFailed();
    }
}
