<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/** Opening a client's page makes them the client the work pages (trial balance, statements, zakat) are about, until another is opened. */
class RememberClient
{
    public function handle(Request $request, Closure $next)
    {
        $company = $request->route('company');
        if ($request->isMethod('GET') && $company instanceof Company && $request->user() && ! $request->user()->isClient()) {
            $request->session()->put('current_client', $company->id);
        }

        return $next($request);
    }
}