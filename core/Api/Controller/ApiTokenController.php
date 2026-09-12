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
        $items = $this->tokens->all($userId);
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $identity = $request->attributes->get('auth');
        $userId = is_array($identity) && !empty($identity['user_id']) ? (string) $identity['user_id'] : null;
        $scopes = isset($payload['scopes']) && is_array($payload['scopes']) ? array_values(array_map('strval', $payload['scopes'])) : ['content:read'];
        $expiresAt = null;
        if (!empty($payload['expires_at'])) {
            $expiresAt = new \DateTimeImmutable((string) $payload['expires_at']);
        }
        $token = $this->tokens->create($userId, (string) ($payload['name'] ?? 'api-token'), $scopes, $expiresAt);
        return new JsonResponse(['data' => $token], Response::HTTP_CREATED);
    }

    /** @param array<string,mixed> $parameters */
    public function revoke(Request $request, array $parameters): JsonResponse
    {
        $ok = $this->tokens->revoke((string) ($parameters['id'] ?? ''));
        return new JsonResponse(['data' => ['revoked' => $ok]]);
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        return is_array($parsed) ? $parsed : [];
    }
}
