<?php

declare(strict_types=1);

return [
    'default' => getenv('SEARCH_DRIVER') ?: 'file',
    'engines' => [
        'file' => ['path' => 'storage/app/search-index.json'],
        'pgsql' => ['language' => 'russian'],
        'meilisearch' => ['host' => getenv('MEILISEARCH_HOST'), 'key' => getenv('MEILISEARCH_KEY')],
        'opensearch' => ['host' => getenv('OPENSEARCH_HOST')],
    ],
];
