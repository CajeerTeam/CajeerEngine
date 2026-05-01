<?php

require_once __DIR__ . '/../core/Support/helpers.php';

return [
    'name' => env_value('APP_NAME', 'Cajeer Engine'),
    'env' => env_value('APP_ENV', 'production'),
    'debug' => env_value('APP_DEBUG', false),
    'url' => env_value('APP_URL', 'http://localhost'),
    'timezone' => 'Europe/Moscow',
    'locale' => 'ru',
];
