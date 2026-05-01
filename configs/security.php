<?php

return [
    'headers' => [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
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
    ],
];
