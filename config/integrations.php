<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(getenv('INTEGRATIONS_ENABLED') === false ? 'true' : getenv('INTEGRATIONS_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', getenv('INTEGRATIONS_ALLOWED_HOSTS') ?: '')))),
    'max_payload_bytes' => 65536,
    'max_connections' => 200,
];
