<?php

namespace App\Http\Middleware;

use App\Support\Llm\AiBudget;
use Closure;
use Illuminate\Http\Request;

/** The signed-in user is who the AI work of this request is for, the queued jobs it starts included (AiBudget). */
class SetAiPayer
{
    public function handle(Request $request, Closure $next)
    {
        AiBudget::forUser($request->user()?->id);

        return $next($request);
    }
}
