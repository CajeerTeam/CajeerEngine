<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserController
{
    public function __construct(private UserRepository $users, private PasswordHasher $passwords)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->users->all(), 'meta' => ['total' => count($this->users->all())]]);
    }

    /** @param array<string,mixed> $parameters */
    public function show(Request $request, array $parameters): JsonResponse
    {
        $user = $this->users->findById((string) ($parameters['id'] ?? ''));
        return $user === null ? $this->notFound() : new JsonResponse(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $roles = isset($payload['roles']) && is_array($payload['roles']) ? array_values(array_map('strval', $payload['roles'])) : ['viewer'];
        $hash = $this->passwords->hash((string) ($payload['password'] ?? ''));
        $id = $this->users->create((string) ($payload['email'] ?? ''), (string) ($payload['name'] ?? ''), $hash, $roles);
        return new JsonResponse(['data' => $this->users->findById($id)], Response::HTTP_CREATED);
    }

    /** @param array<string,mixed> $parameters */
    public function update(Request $request, array $parameters): JsonResponse
    {
        $user = $this->users->update((string) ($parameters['id'] ?? ''), $this->json($request));
        return $user === null ? $this->notFound() : new JsonResponse(['data' => $user]);
    }

    /** @param array<string,mixed> $parameters */
    public function disable(Request $request, array $parameters): JsonResponse
    {
        if (!$this->users->disable((string) ($parameters['id'] ?? ''))) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => ['disabled' => true]]);
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        return is_array($parsed) ? $parsed : [];
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'user_not_found', 'message' => 'Пользователь не найден.']], Response::HTTP_NOT_FOUND);
    }
}
