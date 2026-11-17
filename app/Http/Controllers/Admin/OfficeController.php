<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The platform office's page for the offices that use the site: add one with its first admin, switch one off or on. */
class OfficeController extends Controller
{
    public function index(): View
    {
        return view('admin.offices', [
            'offices' => Office::withCount(['users as staff_count' => fn ($q) => $q->whereNull('company_id')->where('status', User::ACTIVE),
                'users as clients_count' => fn ($q) => $q->whereNotNull('company_id')])->orderByDesc('is_platform')->orderBy('name_ar')->get(),
        ]);
    }

    /** The office and its first admin, who then approves its staff and adds its clients. */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => mb_strtolower(trim((string) $request->input('code'))), 'admin_username' => mb_strtolower(trim((string) $request->input('admin_username')))]);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['required', Office::CODE_RULE, 'unique:offices,code'],
            'admin_name' => ['required', 'string', 'max:255'], 'admin_username' => ['required', User::USERNAME_RULE],
            'admin_password' => ['required', Password::min(8)->letters()->numbers()],
        ]);
        DB::transaction(function () use ($data) {
            $office = Office::create(['name_ar' => $data['name_ar'], 'name_en' => $data['name_en'] ?? null, 'code' => $data['code'], 'status' => Office::ACTIVE]);
            $role = Role::where('is_admin', true)->where('is_client', false)->orderBy('id')->first()
                ?? Role::create(['name_ar' => 'مدير', 'name_en' => 'Admin', 'is_admin' => true, 'open_for_signup' => false]);
            User::create(['office_id' => $office->id, 'name' => $data['admin_name'], 'username' => $data['admin_username'], 'password' => $data['admin_password'],
                'role_id' => $role->id, 'status' => User::ACTIVE, 'locale' => app()->getLocale()]);
        });

        return back()->with('status', __('portal.offices.created', ['code' => $data['code']]));
    }

    public function update(Request $request, Office $office): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([Office::ACTIVE, Office::DISABLED])]]);
        if ($office->is_platform && $data['status'] !== Office::ACTIVE) {
            return back()->withErrors(['status' => __('portal.offices.platform_stays')]);
        }
        $office->update($data);

        return back()->with('status', __('portal.offices.saved'));
    }
}
