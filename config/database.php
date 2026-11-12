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
            'busy_timeout_ms' => getenv('DB_SQLITE_BUSY_TIMEOUT_MS') !== false ? getenv('DB_SQLITE_BUSY_TIMEOUT_MS') : 5000,
            'wal' => filter_var(getenv('DB_SQLITE_WAL') ?: false, FILTER_VALIDATE_BOOLEAN),
            'database' => getenv('SQLITE_DATABASE') ?: getenv('DB_DATABASE') ?: 'storage/database/cajeer.sqlite',
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'connect_timeout' => getenv('DB_CONNECT_TIMEOUT') ?: 5,
            'statement_timeout_ms' => getenv('DB_STATEMENT_TIMEOUT_MS') !== false ? getenv('DB_STATEMENT_TIMEOUT_MS') : 30000,
            'lock_timeout_ms' => getenv('DB_LOCK_TIMEOUT_MS') !== false ? getenv('DB_LOCK_TIMEOUT_MS') : 5000,
            'idle_transaction_timeout_ms' => getenv('DB_IDLE_TRANSACTION_TIMEOUT_MS') !== false ? getenv('DB_IDLE_TRANSACTION_TIMEOUT_MS') : 60000,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'database' => getenv('DB_DATABASE') ?: 'cajeerengine',
            'username' => getenv('DB_USERNAME') ?: 'cajeerengine',
            'password' => getenv('DB_PASSWORD') ?: '',
            'sslmode' => getenv('DB_SSLMODE') ?: 'prefer',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'connect_timeout' => getenv('DB_CONNECT_TIMEOUT') ?: 5,
            'lock_timeout_seconds' => getenv('DB_MYSQL_LOCK_TIMEOUT_SECONDS') ?: 5,
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
