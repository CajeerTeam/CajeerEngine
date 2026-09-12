<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigRepository $config, private AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $identity = $this->auth->authenticateRequest($request);
        if ($identity !== null) {
            $request->attributes->set('auth', $identity);
            if (isset($identity['user'])) {
                $request->attributes->set('user', $identity['user']);
            }
        }

        $required = $this->requiredScopes($request);
        $mustAuthenticate = $required !== [] || ($this->config->bool('security.api_auth_required', false) && $this->isApiRequest($request) && !$this->isPublicRoute($request));

        if (!$mustAuthenticate) {
            return $next($request);
        }

        if ($identity === null) {
            return $this->error($request, 'unauthenticated', 'Требуется Bearer token или X-API-Token.', 401);
        }

        if (!$this->auth->hasScopes($identity, $required)) {
            return $this->error($request, 'forbidden', 'Недостаточно прав для выполнения операции.', 403, ['required_scopes' => $required]);
        }

        return $next($request);
    }

    /** @return list<string> */
    private function requiredScopes(Request $request): array
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();

        if ($path === '/api/v1/auth/me' || $path === '/api/v1/auth/logout' || str_starts_with($path, '/api/v1/auth/2fa')) {
            return [];
        }
        if (str_starts_with($path, '/api/v1/users')) {
            return $method === 'GET' ? ['users:read'] : ['users:write'];
        }
        if (str_starts_with($path, '/api/v1/roles')) {
            return $method === 'GET' ? ['users:read'] : ['users:write'];
        }
        if (str_starts_with($path, '/api/v1/api-tokens')) {
            return ['users:write'];
        }
        if ($this->config->bool('security.protect_content_writes', true) && str_starts_with($path, '/api/v1/content') && $method !== 'GET') {
            return ['content:write'];
        }
        if (str_starts_with($path, '/api/v1/cms')) {
            return $method === 'GET' ? ['content:read'] : ['content:write'];
        }
        if (str_starts_with($path, '/api/v1/media')) {
            return $method === 'GET' ? ['media:read'] : ['media:write'];
        }
        if (str_starts_with($path, '/api/v1/webhooks') || str_starts_with($path, '/api/v1/webhook-deliveries') || str_starts_with($path, '/api/v1/events')) {
            return $method === 'GET' ? ['system:read'] : ['system:write'];
        }
        if (str_starts_with($path, '/api/v1/extensions')) {
            return $method === 'GET' ? ['extensions:read'] : ['extensions:write'];
        }
        if (str_starts_with($path, '/api/v1/import-export')) {
            return $method === 'GET' ? ['imports:read'] : ['imports:write'];
        }
        if (str_starts_with($path, '/api/v1/updates')) {
            return $method === 'GET' ? ['updates:read'] : ['updates:write'];
        }
        if (str_starts_with($path, '/api/v1/admin')) {
            return $path === '/api/v1/admin/bootstrap' ? [] : ['system:read'];
        }
        if (str_starts_with($path, '/api/v1/installer') && $method !== 'GET') {
            return ['installer:run'];
        }
        if (str_starts_with($path, '/api/v1/search')) {
            return $method === 'GET' ? [] : ['system:write'];
        }
        if (str_starts_with($path, '/api/v1/queue') || str_starts_with($path, '/api/v1/scheduler')) {
            return ['system:write'];
        }
        if ($this->config->bool('security.protect_system_routes', false) && ($path === '/api/v1/system' || str_starts_with($path, '/api/v1/database') || $path === '/api/v1/runtime' || $path === '/api/v1/admin/dashboard')) {
            return ['system:read'];
        }
        return [];
    }

    private function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }

    private function isPublicRoute(Request $request): bool
    {
        $path = $request->getPathInfo();
        return $path === '/api/v1/health' || $path === '/api/v1/auth/login';
    }

    /** @param array<string, mixed> $extra */
    private function error(Request $request, string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
                'request_id' => (string) $request->attributes->get('request_id', ''),
            ], $extra),
        ], $status);
    }
}
