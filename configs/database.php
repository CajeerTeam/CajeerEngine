<?php

require_once __DIR__ . '/../core/Support/helpers.php';

return [
    'default' => env_value('DB_DEFAULT', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env_value('MYSQL_HOST', '127.0.0.1'),
            'port' => env_value('MYSQL_PORT', '3306'),
            'database' => env_value('MYSQL_DATABASE', 'cajeer'),
            'username' => env_value('MYSQL_USERNAME', 'cajeer'),
            'password' => env_value('MYSQL_PASSWORD', ''),
            'charset' => env_value('MYSQL_CHARSET', 'utf8mb4'),
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env_value('PGSQL_HOST', '127.0.0.1'),
            'port' => env_value('PGSQL_PORT', '5432'),
            'database' => env_value('PGSQL_DATABASE', 'cajeer'),
            'username' => env_value('PGSQL_USERNAME', 'cajeer'),
            'password' => env_value('PGSQL_PASSWORD', ''),
            'charset' => env_value('PGSQL_CHARSET', 'utf8'),
        ],
    ],
];
