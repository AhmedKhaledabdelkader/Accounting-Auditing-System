<?php

namespace App\Http\Middleware;

use App\Support\Llm\AiBudget;
use Closure;
use Illuminate\Http\Request;

/**
 * On a request that would start AI work (classifying, reading a PDF or a registration, a follow-up draft, preparing papers…): a user whose
 * monthly AI limit is used up is sent to the «limit reached» page instead (a page), or answered 402 with where that page is (an API call;
 * the pages' scripts go there on the `X-AI-Limit` header). Nothing is started, nothing is half done.
 */
class EnsureAiBudget
{
    public function handle(Request $request, Closure $next)
    {
        $why = AiBudget::refusal($request->user()?->id);
        if ($why === null) {
            return $next($request);
        }
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $why, 'code' => 'ai_limit_reached', 'page' => route('ai-limit', [], false)], 402)->header('X-AI-Limit', '1');
        }

        return redirect()->route('ai-limit')->header('X-AI-Limit', '1');
    }
}
