<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetPageLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The user's own page: password, interface language, the tables' column order and API keys (Sanctum tokens, with the account's permissions). */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account', ['user' => $request->user()->load('role'), 'tokens' => $request->user()->tokens()->latest()->get()]);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('status', __('auth_pages.account.password_saved'));
    }

    /** Signed in: saved on the user. A visitor (sign-in page): kept in the session. */
    public function locale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SetPageLocale::LOCALES)]]);
        $request->user()?->update(['locale' => $data['locale']]);
        $request->session()->put('locale', $data['locale']);

        return back();
    }

    /**
     * One of the user's own arrangements, saved as the page sends it: a table's column order (`columns.<table>`, the
     * columns' keys in their new order). No value: back to the page's own order. Every page gets them (partials/theme-head).
     */
    public function preference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^columns\.[a-z0-9_-]{1,40}$/'],
            'value' => ['nullable', 'array', 'max:60'],
            'value.*' => ['string', 'max:40'],
        ]);
        $user = $request->user();
        $prefs = $user->preferences ?? [];
        [, $table] = explode('.', $data['key'], 2);
        if (empty($data['value'])) {
            unset($prefs['columns'][$table]);
        } else {
            $prefs['columns'][$table] = array_values(array_unique($data['value']));
        }
        $user->update(['preferences' => $prefs ?: null]);

        return response()->json(['preferences' => $user->preferences ?? (object) []]);
    }

    /** Every table back to its own column order. */
    public function resetColumns(Request $request): RedirectResponse
    {
        $prefs = $request->user()->preferences ?? [];
        unset($prefs['columns']);
        $request->user()->update(['preferences' => $prefs ?: null]);

        return back()->with('status', __('auth_pages.account.columns_reset_done'));
    }

    public function createToken(Request $request): RedirectResponse
    {
        Gate::authorize('api_tokens.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return back()->with('token', $request->user()->createToken($data['name'])->plainTextToken);
    }

    public function revokeToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('status', __('auth_pages.account.revoked'));
    }
}
