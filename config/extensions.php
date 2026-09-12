<?php

declare(strict_types=1);

return [
    'paths' => [
        'modules' => 'modules',
        'plugins' => 'plugins',
        'themes' => 'themes',
    ],
    'manifest' => 'cajeer.extension.json',
    'engine_api_version' => '1.1',
    'registry_driver' => 'file',
    'state_file' => 'storage/app/extensions/registry.json',
    'events_log' => 'storage/app/extensions/events.jsonl',
    'lifecycle_log' => 'storage/app/extensions/lifecycle.jsonl',
    'allow_unsigned_in_dev' => filter_var(getenv('EXTENSIONS_ALLOW_UNSIGNED_IN_DEV') ?: true, FILTER_VALIDATE_BOOLEAN),
    'require_signature' => filter_var(getenv('EXTENSIONS_REQUIRE_SIGNATURE') ?: false, FILTER_VALIDATE_BOOLEAN),
];
