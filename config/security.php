<?php

declare(strict_types=1);

return [
    'api_auth_required' => filter_var(getenv('SECURITY_REQUIRE_AUTH') ?: true, FILTER_VALIDATE_BOOLEAN),
    'protect_content_writes' => filter_var(getenv('SECURITY_PROTECT_CONTENT_WRITES') ?: true, FILTER_VALIDATE_BOOLEAN),
    'protect_system_routes' => filter_var(getenv('SECURITY_PROTECT_SYSTEM_ROUTES') ?: true, FILTER_VALIDATE_BOOLEAN),
    'password' => [
        'algorithm' => 'argon2id',
        'min_length' => (int) (getenv('PASSWORD_MIN_LENGTH') ?: 12),
        'require_mixed' => filter_var(getenv('PASSWORD_REQUIRE_MIXED') ?: true, FILTER_VALIDATE_BOOLEAN),
        'options' => [
            'memory_cost' => (int) (getenv('PASSWORD_ARGON2_MEMORY') ?: 65536),
            'time_cost' => (int) (getenv('PASSWORD_ARGON2_TIME') ?: 4),
            'threads' => (int) (getenv('PASSWORD_ARGON2_THREADS') ?: 2),
        ],
    ],
    'sessions' => [
        'ttl_days' => (int) (getenv('SESSION_TTL_DAYS') ?: 14),
    ],
    'api_tokens' => [
        'prefix' => 'ce_',
        'hash' => 'sha256',
    ],
    'rate_limits' => [
        'global' => (int) (getenv('RATE_LIMIT_GLOBAL') ?: 120),
        'auth' => (int) (getenv('RATE_LIMIT_AUTH') ?: 20),
        'window_seconds' => (int) (getenv('RATE_LIMIT_WINDOW_SECONDS') ?: 60),
    ],
    'scopes' => [
        'content:read',
        'content:write',
        'media:read',
        'media:write',
        'users:read',
        'users:write',
        'settings:read',
        'settings:write',
        'extensions:read',
        'extensions:write',
        'system:read',
        'system:write',
        'scheduler:run',
        'search:write',
        'webhooks:write',
        'webhooks:read',
        'queue:write',
        'queue:read',
        'release:build',
        'installer:run',
        'updates:write',
        'updates:read',
        'imports:write',
        'imports:read',
    ],
];
