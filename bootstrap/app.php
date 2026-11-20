<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',   // versioned: a breaking change gets /api/v2 next to it
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Arabic validation messages for clients that ask for them (the pages send Accept-Language: ar).
        $middleware->api(append: [\App\Http\Middleware\SetLocaleFromHeader::class]);
        // The pages call the API with their session (Sanctum's first-party cookies, CSRF checked); other clients use an API key.
        $middleware->statefulApi();
        $middleware->web(append: [\App\Http\Middleware\SetPageLocale::class, \App\Http\Middleware\RememberClient::class]);
        $middleware->validateCsrfTokens(except: ['mail/inbound']);   // the mail service's webhook: no session, its secret is the way in
        $middleware->alias(['active' => \App\Http\Middleware\EnsureUserIsActive::class, 'ai.payer' => \App\Http\Middleware\SetAiPayer::class, 'ai.budget' => \App\Http\Middleware\EnsureAiBudget::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        // Behind a forwarding tunnel (VS Code ports, dev tunnels) or a reverse proxy: take the visitor's https host from X-Forwarded-*.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes always get JSON errors, even without an Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // A file over PHP's request limit (post_max_size): said in the page's language, not as a crash.
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => __('report_messages.errors.request_too_large', ['max' => ini_get('post_max_size')])], 413)
                : null;
        });

        // A page not found in the browser gets the app's page (errors/404); the API, JSON.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Route not found'
            ], 404);
        });

        // Exceptions Laravel already maps to a proper status (422, 401, 403, 405, ...)
        // fall through to the framework; everything else becomes a JSON 500.
        $exceptions->render(function (Throwable $e, $request) {
            $handledByFramework = [
                ValidationException::class,
                AuthenticationException::class,
                AuthorizationException::class,
                HttpExceptionInterface::class,
                HttpResponseException::class,
                ModelNotFoundException::class,
                TokenMismatchException::class,
            ];

            foreach ($handledByFramework as $type) {
                if ($e instanceof $type) {
                    return null;
                }
            }

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        });
    })->create();
