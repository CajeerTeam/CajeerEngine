<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigRepository $config, private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();
        $bucket = str_starts_with($path, '/api/v1/auth/') ? 'auth' : 'global';
        $limit = $this->config->int('security.rate_limits.' . $bucket, $bucket === 'auth' ? 20 : 120);
        $window = $this->config->int('security.rate_limits.window_seconds', 60);
        $identity = ($request->getClientIp() ?: 'unknown') . '|' . $path;
        $hit = $this->limiter->hit($bucket, $identity, $limit, $window);

        if (!$hit['allowed']) {
            $response = new JsonResponse([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Превышен лимит запросов.',
                    'retry_after' => $hit['retry_after'],
                    'request_id' => (string) $request->attributes->get('request_id', ''),
                ],
            ], 429);
            $this->applyHeaders($response, $hit);
            return $response;
        }

        $response = $next($request);
        $this->applyHeaders($response, $hit);
        return $response;
    }

    /** @param array{limit:int,remaining:int,reset_at:int,retry_after:int,key:string} $hit */
    private function applyHeaders(Response $response, array $hit): void
    {
        $response->headers->set('X-RateLimit-Limit', (string) $hit['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $hit['remaining']);
        $response->headers->set('X-RateLimit-Reset', (string) $hit['reset_at']);
        if ($hit['retry_after'] > 0) {
            $response->headers->set('Retry-After', (string) $hit['retry_after']);
        }
    }
}
