<?php

declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'CajeerEngine',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
    'url' => getenv('APP_URL') ?: 'http://127.0.0.1:8080',
    'locale' => getenv('APP_LOCALE') ?: 'ru',
    'version' => getenv('APP_VERSION') ?: '1.1.1',
    'api_version' => 'v1',
    'engine_api_version' => '1.1',
];
