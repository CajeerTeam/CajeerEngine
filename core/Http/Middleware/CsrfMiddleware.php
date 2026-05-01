<?php

declare(strict_types=1);

namespace Cajeer\Http\Middleware;

use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;

final class CsrfMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        if ($request->is('api/*')) {
            return $next($request);
        }

        $csrf = new CsrfTokenManager();
        if (!$csrf->validate((string) $request->input('_token', ''))) {
            return $request->wantsJson()
                ? Response::json(['error' => 'csrf_token_mismatch'], 419)
                : Response::html('<h1>419</h1><p>CSRF-токен недействителен.</p>', 419);
        }

        return $next($request);
    }
}
