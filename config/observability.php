<?php
declare(strict_types=1);
return [
    'metrics_token'=>getenv('OBSERVABILITY_METRICS_TOKEN')?:'',
    'min_free_bytes'=>(int)(getenv('OBSERVABILITY_MIN_FREE_BYTES')?:104857600),
    'backup_max_age'=>(int)(getenv('OBSERVABILITY_BACKUP_MAX_AGE')?:86400),
    'queue_max_age'=>(int)(getenv('OBSERVABILITY_QUEUE_MAX_AGE')?:300),
    'worker_max_age'=>(int)(getenv('OBSERVABILITY_WORKER_MAX_AGE')?:300),
];
