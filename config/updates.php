<?php

declare(strict_types=1);

use CajeerEngine\Update\GitFlicEndpoints;

return [
    'core' => [
        'channel' => getenv('UPDATE_CHANNEL') ?: 'stable',
        'releases_url' => GitFlicEndpoints::RELEASES_URL,
        'timeout' => (int) (getenv('UPDATE_HTTP_TIMEOUT') ?: 5),
    ],
    'registry' => [
        'url' => GitFlicEndpoints::REGISTRY_URL,
        'signature_required' => filter_var(getenv('UPDATE_SIGNATURE_REQUIRED') ?: true, FILTER_VALIDATE_BOOLEAN),
    ],
    'web_upgrade' => [
        'enabled' => filter_var(getenv('UPDATE_WEB_UPGRADE_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'secret_configured' => trim((string) (getenv('UPDATE_WEB_UPGRADE_SECRET') ?: getenv('UPGRADE_SECRET') ?: '')) !== '',
        'session_unlock' => true,
    ],
];
