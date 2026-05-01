<?php

declare(strict_types=1);

namespace Cajeer\Http\Middleware;

use Cajeer\Http\Request;
use Cajeer\Http\Response;

final class TrustProxyMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
