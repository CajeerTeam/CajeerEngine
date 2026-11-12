<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Http\Router;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigRepository $config, private AuthService $auth, private Router $router) {}

    public function process(Request $request, callable $next): Response
    {
        $required = $this->router->accessPolicy($request);
        if ($required === null) { return $next($request); }
        $identity = $this->auth->authenticateRequest($request);
        if ($identity === null) { return $this->error($request, 'unauthenticated', 401); }
        if (!$this->auth->hasScopes($identity, $required)) { return $this->error($request, 'forbidden', 403); }
        if (($identity['cookie'] ?? false) && !in_array($request->getMethod(), ['GET','HEAD','OPTIONS'], true)
            && !hash_equals((string) $identity['csrf_token'], (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error($request, 'csrf_failed', 403);
        }
        $request->attributes->set('auth', $identity);
        $request->attributes->set('user', $identity['user'] ?? null);
        return $next($request);
    }

    private function error(Request $request, string $code, int $status): JsonResponse
    {
        $response = new JsonResponse(['error' => ['code' => $code, 'message' => match ($code) {
            'unauthenticated' => 'Требуется авторизация.', 'csrf_failed' => 'Некорректный CSRF token.', default => 'Недостаточно прав.',
        }, 'request_id' => (string) $request->attributes->get('request_id', '')]], $status);
        if ($status === 401) { $response->headers->set('WWW-Authenticate', 'Bearer'); }
        return $response;
    }
}
