<?php

declare(strict_types=1);

return [
    'default' => getenv('STORAGE_DRIVER') ?: 'local',
    'public_uploads_url' => getenv('STORAGE_PUBLIC_UPLOADS_URL') ?: '/uploads',
    'disks' => [
        'local' => ['root' => getenv('STORAGE_LOCAL_PATH') ?: 'storage/app'],
        's3' => [
            'endpoint' => getenv('S3_ENDPOINT'),
            'bucket' => getenv('S3_BUCKET'),
            'region' => getenv('S3_REGION') ?: 'auto',
            'access_key' => getenv('S3_ACCESS_KEY'),
            'secret_key' => getenv('S3_SECRET_KEY'),
            'path_style' => filter_var(getenv('S3_PATH_STYLE') ?: true, FILTER_VALIDATE_BOOLEAN),
        ],
    ],
];
