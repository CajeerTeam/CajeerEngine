<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class SecurityController
{
    public function __construct(private ConfigRepository $config, private DatabaseManager $database, private UserRepository $users, private RoleRepository $roles, private ApiTokenRepository $tokens)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $health = $this->database->health();
        return new JsonResponse([
            'data' => [
                'security_core_ready' => $this->database->securityCoreReady(),
                'api_auth_required' => $this->config->bool('security.api_auth_required', false),
                'protect_content_writes' => $this->config->bool('security.protect_content_writes', true),
                'protect_system_routes' => $this->config->bool('security.protect_system_routes', false),
                'users' => $this->safeCount(fn () => $this->users->count()),
                'roles' => $this->safeCount(fn () => count($this->roles->all())),
                'api_tokens' => $this->safeCount(fn () => $this->tokens->count()),
                'database_security_columns' => $health['security_core']['columns'] ?? [],
            ],
        ]);
    }

    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return 0;
        }
    }
}
