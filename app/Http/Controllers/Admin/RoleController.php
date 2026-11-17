<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Access\KeepAnAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The admin's roles page: a role is a name and a set of permissions (config/permissions.php), or every permission. */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.roles', ['roles' => Role::withCount('users')->orderByDesc('is_admin')->orderBy('name_ar')->get()]);
    }

    public function create(): View
    {
        return view('admin.role-form', ['role' => new Role(['open_for_signup' => true]), 'granted' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create($data['role']);
        $role->syncPermissions($data['permissions']);

        return redirect()->route('admin.roles')->with('status', __('auth_pages.roles.saved'));
    }

    public function edit(Role $role): View
    {
        return view('admin.role-form', ['role' => $role, 'granted' => $role->is_admin ? [] : $role->permissions()]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request);
        KeepAnAdmin::run(function () use ($role, $data) {
            $role->update($data['role']);
            $role->syncPermissions($data['permissions']);
        }, __('auth_pages.roles.last_admin'));

        return redirect()->route('admin.roles')->with('status', __('auth_pages.roles.saved'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => __('auth_pages.roles.in_use')]);
        }
        $role->delete();

        return redirect()->route('admin.roles')->with('status', __('auth_pages.roles.deleted'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'is_admin' => ['boolean'],
            'open_for_signup' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Role::catalogue())],
        ]);

        return [
            'role' => ['name_ar' => $data['name_ar'], 'name_en' => $data['name_en'] ?? null, 'is_admin' => $request->boolean('is_admin'),
                'open_for_signup' => $request->boolean('open_for_signup') && ! $request->boolean('is_admin')],
            'permissions' => $data['permissions'] ?? [],
        ];
    }
}
