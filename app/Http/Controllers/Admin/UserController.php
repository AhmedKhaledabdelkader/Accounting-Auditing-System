<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\KeepAnAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The admin's users page: approve sign-ups (with the asked role or another), change a role, disable, set a password. */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = $this->staff($request)->with(['role', 'requestedRole'])->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")->orderBy('name')->get();

        return view('admin.users', ['users' => $users, 'office' => $request->user()->office, 'roles' => Role::where('is_client', false)->orderBy('name_ar')->get()]);
    }

    /** Role and status in one save; approving a pending user is a save with status active. */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->own($request, $user);
        $data = $request->validate([
            'role_id' => ['nullable', Rule::exists('roles', 'id')->where('is_client', 0)],
            'status' => ['required', Rule::in([User::PENDING, User::ACTIVE, User::DISABLED])],
        ]);
        if ($data['status'] === User::ACTIVE && ! $data['role_id']) {
            throw ValidationException::withMessages(['role_id' => __('auth_pages.users.role_required')]);
        }
        if ($user->is($request->user()) && ($data['status'] !== User::ACTIVE || ! Role::find($data['role_id'])?->is_admin)) {
            throw ValidationException::withMessages(['admin' => __('auth_pages.users.self')]);
        }
        $approving = $user->status === User::PENDING && $data['status'] === User::ACTIVE;
        KeepAnAdmin::run(fn () => $user->update($data), __('auth_pages.users.last_admin'), $user->office_id);

        return back()->with('status', __($approving ? 'auth_pages.users.approved' : 'auth_pages.users.saved'));
    }

    public function password(Request $request, User $user): RedirectResponse
    {
        $this->own($request, $user);
        $data = $request->validate(['password' => ['required', Password::min(8)->letters()->numbers()]]);
        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        return back()->with('status', __('auth_pages.users.password_set'));
    }

    /** The office's own staff: a client's portal user is managed from the client's page. */
    private function staff(Request $request)
    {
        return User::where('office_id', $request->user()->office_id)->whereNull('company_id');
    }

    /** Another office's user, or a client's, is not this page's to touch. */
    private function own(Request $request, User $user): void
    {
        abort_unless($user->office_id === $request->user()->office_id && $user->company_id === null, 404);
    }
}
