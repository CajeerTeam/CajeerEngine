<?php

declare(strict_types=1);

return [
    'default' => getenv('DB_DRIVER') ?: 'pgsql',
    'migrations' => [
        'table' => 'ce_migrations',
        'path' => 'migrations',
    ],
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => getenv('SQLITE_DATABASE') ?: getenv('DB_DATABASE') ?: 'storage/database/cajeer.sqlite',
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'database' => getenv('DB_DATABASE') ?: 'cajeerengine',
            'username' => getenv('DB_USERNAME') ?: 'cajeerengine',
            'password' => getenv('DB_PASSWORD') ?: '',
            'sslmode' => getenv('DB_SSLMODE') ?: 'prefer',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'cajeerengine',
            'username' => getenv('DB_USERNAME') ?: 'cajeerengine',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => getenv('MYSQL_CHARSET') ?: 'utf8mb4',
            'collation' => getenv('MYSQL_COLLATION') ?: 'utf8mb4_unicode_ci',
        ],
    ],
];
