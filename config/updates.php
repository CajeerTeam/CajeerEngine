<?php

declare(strict_types=1);

use CajeerEngine\Update\GitHubEndpoints;

return [
    'core' => [
        'channel' => getenv('UPDATE_CHANNEL') ?: 'stable',
        'releases_url' => GitHubEndpoints::RELEASES_URL,
        'timeout' => (int) (getenv('UPDATE_HTTP_TIMEOUT') ?: 5),
    ],
    'registry' => [
        'url' => GitHubEndpoints::REGISTRY_URL,
        'signature_required' => true,
    ],
    'web_upgrade' => [
        'enabled' => filter_var(getenv('UPDATE_WEB_UPGRADE_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'operator_required' => true,
    ],
];
