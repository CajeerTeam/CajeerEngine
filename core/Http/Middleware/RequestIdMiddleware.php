<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $requestId = trim((string) $request->headers->get('X-Request-Id'));
        if ($requestId === '' || strlen($requestId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }

        $request->attributes->set('request_id', $requestId);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        return $response;
    }
}
