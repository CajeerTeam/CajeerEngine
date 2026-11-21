<?php

declare(strict_types=1);

use CajeerEngine\Update\GitHubEndpoints;

return [
    'core' => [
        'channel' => getenv('UPDATE_CHANNEL') ?: 'stable',
        'releases_url' => GitHubEndpoints::RELEASES_URL,
        'timeout' => (int) (getenv('UPDATE_HTTP_TIMEOUT') ?: 5),
        'auto_check_enabled' => filter_var(getenv('UPDATE_AUTO_CHECK_ENABLED') !== false ? getenv('UPDATE_AUTO_CHECK_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN),
        'auto_check_interval_seconds' => (int) (getenv('UPDATE_AUTO_CHECK_INTERVAL_SECONDS') ?: 21600),
        'download_timeout_seconds' => (int) (getenv('UPDATE_DOWNLOAD_TIMEOUT_SECONDS') ?: 300),
        'download_max_bytes' => (int) (getenv('UPDATE_DOWNLOAD_MAX_BYTES') ?: 2147483648),
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
