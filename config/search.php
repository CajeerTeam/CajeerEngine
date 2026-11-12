<?php

declare(strict_types=1);

return [
    // CMS search follows CONTENT_STORAGE and DB_DRIVER. This setting is a legacy adapter preference.
    'default' => getenv('SEARCH_DRIVER') ?: 'file',
    'engines' => [
        'file' => ['path' => 'storage/app/search-legacy-index.json'],
        'pgsql' => ['language' => 'russian'],
        'meilisearch' => ['host' => getenv('MEILISEARCH_HOST'), 'key' => getenv('MEILISEARCH_KEY')],
        'opensearch' => ['host' => getenv('OPENSEARCH_HOST')],
    ],
];
