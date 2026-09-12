<?php

declare(strict_types=1);

return [
    'storage' => getenv('CMS_STORAGE') ?: 'auto',
    'public' => [
        'home_slug' => getenv('CMS_HOME_SLUG') ?: 'home',
        'sitemap_enabled' => filter_var(getenv('CMS_SITEMAP_ENABLED') ?: true, FILTER_VALIDATE_BOOLEAN),
        'robots_index' => filter_var(getenv('CMS_ROBOTS_INDEX') ?: true, FILTER_VALIDATE_BOOLEAN),
    ],
    'preview' => [
        'ttl_seconds' => (int) (getenv('CMS_PREVIEW_TTL_SECONDS') ?: 3600),
    ],
    'navigation' => [
        'primary_menu' => getenv('CMS_PRIMARY_MENU') ?: 'primary',
    ],
];
