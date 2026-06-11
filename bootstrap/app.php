<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: [
            __DIR__.'/../routes/api.php',
            __DIR__.'/../routes/admin.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        // Sanctum SPA: cookies de sesión + VerifyCsrfToken en rutas api/* para dominios stateful.
        $middleware->statefulApi();
        // Panel admin: autenticación Bearer (Sanctum admin); sin cookie CSRF del SPA usuario.
        $middleware->validateCsrfTokens(except: [
            'api/admin/*',
            'api/webhooks/*',
            'api/guest/*',
        ]);
        $middleware->alias([
            'admin.active' => \App\Http\Middleware\EnsureAdminIsActive::class,
            'registration.checkout' => \App\Http\Middleware\EnsureRegistrationCheckoutCompleted::class,
            'subscription.current' => \App\Http\Middleware\EnsureSubscriptionIsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores 4xx/5xx sin cabeceras CORS → el navegador muestra "blocked by CORS policy".
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*', 'sanctum/csrf-cookie')) {
                return $response;
            }

            $cors = app(\Illuminate\Http\Middleware\HandleCors::class);

            return $cors->handle($request, fn () => $response);
        });
    })->create();
