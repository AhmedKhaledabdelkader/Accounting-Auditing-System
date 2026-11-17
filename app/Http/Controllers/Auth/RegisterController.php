<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign-up of an office's staff: the new user gives the office's code, picks a role among those open for sign-up, and the
 * account waits for that office's admin to approve it. (A client has no sign-up: the accountant makes their account.)
 */
class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register', ['roles' => $this->openRoles()->orderBy('name_ar')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['username' => mb_strtolower(trim((string) $request->input('username'))), 'office_code' => mb_strtolower(trim((string) $request->input('office_code')))]);   // as stored, so «Ahmed» and «ahmed» are one
        $data = $request->validate([
            'office_code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', User::USERNAME_RULE],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('open_for_signup', 1)->where('is_admin', 0)->where('is_client', 0)],
        ]);
        $office = Office::byCode($data['office_code']);
        if (! $office || ! $office->isActive()) {
            throw ValidationException::withMessages(['office_code' => __('auth_pages.register.office_unknown')]);
        }
        if (User::where('office_id', $office->id)->where('username', $data['username'])->exists()) {
            throw ValidationException::withMessages(['username' => __('validation.custom.username.unique')]);
        }

        User::create([
            'office_id' => $office->id, 'name' => $data['name'], 'username' => $data['username'], 'password' => $data['password'],
            'requested_role_id' => $data['role_id'], 'status' => User::PENDING, 'locale' => app()->getLocale(),
        ]);

        return redirect()->route('login')->with('status', __('auth_pages.register.done'));
    }

    private function openRoles()
    {
        return Role::where('open_for_signup', true)->where('is_admin', false)->where('is_client', false);
    }
}
