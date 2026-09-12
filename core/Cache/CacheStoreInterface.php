<?php

declare(strict_types=1);

namespace CajeerEngine\Cache;

interface CacheStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void;

    public function forget(string $key): void;
}
