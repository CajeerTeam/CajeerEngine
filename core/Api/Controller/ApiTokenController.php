<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Database\Repository\ApiTokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApiTokenController
{
    public function __construct(private ApiTokenRepository $tokens)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        $userId = is_array($identity) && !empty($identity['user_id']) ? (string) $identity['user_id'] : null;
        if ($userId === null) { return new JsonResponse(['error' => ['code'=>'forbidden', 'message'=>'Требуется пользовательская сессия.']], 403); }
        $items = $this->tokens->all($userId);
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]], 200, ['Cache-Control'=>'private, no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $identity = $request->attributes->get('auth');
        $userId = is_array($identity) && !empty($identity['user_id']) ? (string) $identity['user_id'] : null;
        $scopes = $payload['scopes'] ?? ['content:read'];
        $name = $payload['name'] ?? 'api-token';
        if (!is_string($name) || strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,99}$/D', $name)
            || !is_array($scopes) || !array_is_list($scopes) || $scopes === [] || count($scopes) > 64
            || array_filter($scopes, static fn (mixed $scope): bool => !is_string($scope) || preg_match('/^(?:\*|[a-z0-9][a-z0-9_.:-]{0,63})$/D', $scope) !== 1)) {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Некорректное имя или список прав токена.']], 422);
        }
        if ($name === 'admin-session' || $userId === null
            || \CajeerEngine\Security\AuthService::intersectScopes($scopes, $identity['scopes'] ?? []) !== $scopes) {
            return new JsonResponse(['error' => ['code' => 'forbidden', 'message' => 'Недопустимое имя или права токена.']], 403);
        }
        $expiresAt = null;
        if (array_key_exists('expires_at', $payload) && $payload['expires_at'] !== null) {
            if (!is_string($payload['expires_at']) || strlen($payload['expires_at']) > 64) {
                return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Некорректный срок действия токена.']], 422);
            }
            try { $expiresAt = new \DateTimeImmutable($payload['expires_at']); }
            catch (\Throwable) { return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Некорректный срок действия токена.']], 422); }
            if ($expiresAt->getTimestamp() <= time()) {
                return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Срок действия токена должен быть в будущем.']], 422);
            }
        }
        $token = $this->tokens->create($userId, $name, $scopes, $expiresAt);
        return new JsonResponse(['data' => $token], Response::HTTP_CREATED, ['Cache-Control'=>'private, no-store']);
    }

    /** @param array<string,mixed> $parameters */
    public function revoke(Request $request, array $parameters): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        $owner = is_array($identity) && is_string($identity['user_id'] ?? null) ? $identity['user_id'] : '';
        $ok = $this->tokens->revokeOwned((string) ($parameters['id'] ?? ''), $owner);
        return new JsonResponse(['data' => ['revoked' => $ok]], 200, ['Cache-Control'=>'private, no-store']);
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        return is_array($parsed) ? $parsed : [];
    }
}
