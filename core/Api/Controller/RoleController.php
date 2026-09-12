<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Database\Repository\RoleRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RoleController
{
    public function __construct(private RoleRepository $roles)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->roles->all();
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $permissions = isset($payload['permissions']) && is_array($payload['permissions']) ? array_values(array_map('strval', $payload['permissions'])) : [];
        $id = $this->roles->upsert((string) ($payload['handle'] ?? ''), (string) ($payload['name'] ?? ''), $permissions);
        return new JsonResponse(['data' => $this->roles->find($id)], Response::HTTP_CREATED);
    }

    /** @param array<string,mixed> $parameters */
    public function show(Request $request, array $parameters): JsonResponse
    {
        $role = $this->roles->find((string) ($parameters['id'] ?? ''));
        if ($role === null) {
            return new JsonResponse(['error' => ['code' => 'role_not_found', 'message' => 'Роль не найдена.']], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse(['data' => $role]);
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        return is_array($parsed) ? $parsed : [];
    }
}
