<?php

require_once __DIR__ . '/../core/Support/helpers.php';

return [
    'dle' => [
        'enabled' => env_value('COMPAT_DLE_ENABLED', true),
        'template_extensions' => ['tpl'],
        'supported_tags' => [
            'title',
            'short-story',
            'full-story',
            'date',
            'category',
            'link',
            'author',
        ],
    ],
    'wordpress' => [
        'enabled' => env_value('COMPAT_WORDPRESS_ENABLED', true),
        'api_level' => 'basic',
        'hooks' => true,
        'shortcodes' => true,
        'template_functions' => true,
    ],
];
