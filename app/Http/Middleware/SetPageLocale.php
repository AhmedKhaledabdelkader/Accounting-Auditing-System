<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The pages' language: the user's choice, or the visitor's (kept in the session), Arabic by default. */
class SetPageLocale
{
    public const LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->session()->get('locale');
        app()->setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'ar');

        return $next($request);
    }
}
