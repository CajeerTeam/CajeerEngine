<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\RuntimeLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RuntimeLogMiddleware implements MiddlewareInterface
{
    public function __construct(private RuntimeLogger $logger)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $started = microtime(true);
        $response = $next($request);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->logger->info('HTTP request handled', [
            'request_id' => (string) $request->attributes->get('request_id', ''),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip' => $request->getClientIp(),
        ]);

        $response->headers->set('X-Runtime-Ms', (string) $durationMs);
        return $response;
    }
}
