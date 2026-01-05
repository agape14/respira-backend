<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Configuración de API sin prefijo automático para que funcione con el alias /api de Apache
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', // Sin prefijo automático - Apache ya maneja /api
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Evitar redirects en APIs cuando el request NO espera JSON (ej. abrir URL en navegador/PowerShell)
        // En lugar de redirigir a route('login') (que no existe aquí), dejar que responda 401/Unauthenticated.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

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
