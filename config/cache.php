<?php

declare(strict_types=1);

return [
    'default' => getenv('CACHE_DRIVER') ?: 'redis',
    'stores' => [
        'redis' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
        ],
    ],
];
