<?php

declare(strict_types=1);

namespace CajeerEngine\Cache;

final readonly class RedisCacheStore implements CacheStoreInterface
{
    public function __construct(private \Redis $redis, private string $prefix = 'ce:cache:')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($this->prefix . $key);
        if ($value === false) {
            return $default;
        }

        return unserialize($value, ['allowed_classes' => false]);
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void
    {
        $this->redis->setex($this->prefix . $key, $ttlSeconds, serialize($value));
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
