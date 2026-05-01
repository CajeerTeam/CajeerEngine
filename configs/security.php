<?php

return [
    'headers' => [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    ],
    'csrf' => [
        'enabled' => true,
    ],
    'session' => [
        'name' => 'cajeer_session',
        'secure' => false,
        'http_only' => true,
        'same_site' => 'Lax',
    ],
    'plugin_capabilities' => [
        'content.read',
        'content.write',
        'users.read',
        'users.write',
        'settings.read',
        'settings.write',
        'files.read',
        'files.write',
        'db.raw_query',
        'admin.menu',
        'http.client',
    ],
    'dangerous_capabilities' => [
        'db.raw_query',
        'files.write',
        'users.write',
        'settings.write',
    ],
];
