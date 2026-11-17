<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign-in and sign-out: office code, username and password. A username belongs to its office, so the same one can exist
 * in two offices. Only an active account of an active office signs in; a pending or disabled one is told why. A wrong
 * code, username or password gets the same answer, so the page doesn't say which offices exist.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['office_code' => ['required', 'string', 'max:20'], 'username' => ['required', 'string', 'max:30'], 'password' => ['required', 'string']]);
        $code = Str::lower(trim($data['office_code']));
        $username = Str::lower(trim($data['username']));
        $key = $code.'|'.$username.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => __('auth_pages.login.throttled', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $office = Office::byCode($code);
        if (! $office || ! Auth::attempt(['office_id' => $office->id, 'username' => $username, 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['username' => __('auth_pages.login.failed')]);
        }
        RateLimiter::clear($key);

        /** @var User $user */
        $user = Auth::user();
        if (! $office->isActive()) {
            Auth::logout();
            throw ValidationException::withMessages(['username' => __('auth_pages.login.office_disabled')]);
        }
        if (! $user->isActive()) {
            Auth::logout();
            throw ValidationException::withMessages(['username' => __("auth_pages.login.{$user->status}")]);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
