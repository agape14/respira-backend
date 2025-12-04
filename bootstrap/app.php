<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // COMENTADO: Este middleware fuerza autenticación con cookies
        // Si quieres usar Bearer tokens, debe estar comentado
        // $middleware->api(prepend: [
        //     \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        // ]);

        // Middleware para verificar expiración de tokens y logging
        $middleware->alias([
            'check.token.expiration' => \App\Http\Middleware\CheckTokenExpiration::class,
            'log.auth' => \App\Http\Middleware\LogAuthAttempts::class,
            'debug.auth' => \App\Http\Middleware\DebugAuth::class,
        ]);

        // Habilitar CORS para todas las rutas
        $middleware->validateCsrfTokens(except: [
            'api/*'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
