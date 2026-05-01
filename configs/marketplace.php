<?php

require_once __DIR__ . '/../core/Support/helpers.php';

return [
    'enabled' => env_value('MARKETPLACE_ENABLED', true),
    'public_routes' => [
        '/marketplace',
        '/marketplace/themes',
        '/marketplace/plugins',
    ],
    'signature_verification' => true,
    'allow_local_uploads' => true,
];
