<?php

declare(strict_types=1);

return [
    'default' => getenv('QUEUE_DRIVER') ?: 'file',
    'workers' => [
        'default_limit' => (int) (getenv('QUEUE_WORKER_LIMIT') ?: 10),
        'retry_backoff_seconds' => (int) (getenv('QUEUE_RETRY_BACKOFF') ?: 60),
    ],
    'connections' => [
        'file' => ['path' => 'storage/queue'],
        'redis' => ['queue' => 'cajeerengine:queue:default'],
        'pgsql' => ['table' => 'ce_jobs'],
        'rabbitmq' => [
            'dsn' => getenv('RABBITMQ_DSN') ?: 'amqp://guest:guest@127.0.0.1:5672/%2f',
            'exchange' => getenv('RABBITMQ_EXCHANGE') ?: 'cajeerengine.jobs',
            'queue_prefix' => getenv('RABBITMQ_QUEUE_PREFIX') ?: 'cajeerengine',
        ],
    ],
];
