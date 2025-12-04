<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DebugAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        \Log::info('=== DEBUG AUTH MIDDLEWARE ===', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'has_bearer' => $request->bearerToken() ? 'SI' : 'NO',
            'bearer_preview' => $request->bearerToken() ? substr($request->bearerToken(), 0, 20) . '...' : null,
            'auth_header' => $request->header('Authorization'),
            'all_headers' => $request->headers->all(),
        ]);

        return $next($request);
    }
}

