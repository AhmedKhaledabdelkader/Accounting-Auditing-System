<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API messages in Arabic for a client that asks for them (`Accept-Language: ar`, as the pages do): Laravel's validation
 * messages with Arabic field names (lang/ar/validation.php). Other clients keep the default locale. Messages are not part
 * of the API contract; the response shapes don't change.
 */
class SetLocaleFromHeader
{
    /** The configured locale, kept at boot before any request changes it (setLocale also rewrites config('app.locale')). */
    private static ?string $default = null;

    public static function rememberDefault(string $locale): void
    {
        self::$default = $locale;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Set on every request, so one request's choice never carries over to the next in the same process.
        self::$default ??= config('app.locale');
        $arabic = $request->headers->has('Accept-Language') && $request->getPreferredLanguage(['en', 'ar']) === 'ar';
        app()->setLocale($arabic ? 'ar' : self::$default);

        return $next($request);
    }
}
