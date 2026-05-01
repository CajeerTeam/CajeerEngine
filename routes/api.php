<?php

$router->get('/api/health', static function () {
    return [
        'status' => 'ok',
        'engine' => 'Cajeer Engine',
        'time' => date(DATE_ATOM),
        'compatibility' => [
            'dle' => true,
            'wordpress' => true,
        ],
    ];
});

$router->get('/api/version', static fn () => ['version' => '0.1.0-dev']);
