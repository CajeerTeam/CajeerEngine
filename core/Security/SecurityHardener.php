<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\EnvironmentFile;

final readonly class SecurityHardener
{
    public function __construct(private string $rootPath, private ConfigRepository $config)
    {
    }

    /** @return array<string, mixed> */
    public function diagnose(): array
    {
        $env = $this->config->string('app.env', 'production');
        $productionLike = in_array(strtolower($env), ['production', 'prod', 'staging'], true);
        $checks = [
            'api_auth_required' => $this->config->bool('security.api_auth_required', true),
            'protect_content_writes' => $this->config->bool('security.protect_content_writes', true),
            'protect_system_routes' => $this->config->bool('security.protect_system_routes', true),
            'debug_disabled' => !$this->config->bool('app.debug', false),
            'password_min_length_12' => $this->config->int('security.password.min_length', 12) >= 12,
        ];

        $failures = [];
        foreach ($checks as $name => $ok) {
            if (!$ok && ($productionLike || $name !== 'debug_disabled')) {
                $failures[] = $name;
            }
        }

        return [
            'ok' => $failures === [],
            'environment' => $env,
            'production_like' => $productionLike,
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /** @return array<string, mixed> */
    public function hardenEnv(bool $createIfMissing = true): array
    {
        $env = new EnvironmentFile($this->rootPath . '/.env');
        if (!$env->exists() && $createIfMissing) {
            $env->ensureFromExample($this->rootPath . '/.env.example');
        }
        if (!$env->exists()) {
            throw new \RuntimeException('.env не найден. Передайте createIfMissing=true или создайте .env вручную.');
        }

        $changes = $env->setMany([
            'APP_DEBUG' => 'false',
            'SECURITY_REQUIRE_AUTH' => 'true',
            'SECURITY_PROTECT_CONTENT_WRITES' => 'true',
            'SECURITY_PROTECT_SYSTEM_ROUTES' => 'true',
            'MEDIA_ALLOW_SVG' => 'false',
            'PASSWORD_MIN_LENGTH' => '12',
        ]);

        return [
            'ok' => true,
            'env_path' => $this->rootPath . '/.env',
            'changed' => $changes,
            'changed_count' => count($changes),
        ];
    }
}
