<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A signed-in user whose account is pending or disabled is signed out (pages) or refused (API). */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => __('auth_pages.inactive')], 403);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => __("auth_pages.login.{$user->status}")]);
        }

        return $next($request);
    }
}
