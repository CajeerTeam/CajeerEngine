<?php

declare(strict_types=1);

namespace Cajeer\Http\Middleware;

use Cajeer\Http\Request;
use Cajeer\Http\Response;

final class SecurityHeadersMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        return $response
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }
}
