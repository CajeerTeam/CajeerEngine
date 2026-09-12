<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

final readonly class QueueFactory
{
    public function __construct(private string $rootPath, private ConfigRepository $config, private ?DatabaseManager $database = null)
    {
    }

    public function make(): ManagedQueueDriverInterface
    {
        $driver = strtolower($this->config->string('queue.default', 'file'));
        return match ($driver) {
            'redis' => $this->redis(),
            'pgsql', 'postgres', 'postgresql' => new PostgresQueueDriver($this->database()->connection()),
            'rabbitmq', 'amqp' => new RabbitMqQueueDriver($this->config->array('queue.connections.rabbitmq', [])),
            default => new FileQueueDriver($this->rootPath),
        };
    }

    private function redis(): RedisQueueDriver
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('QUEUE_DRIVER=redis требует PHP extension redis.');
        }
        $redis = new \Redis();
        $redis->connect($this->config->string('cache.redis.host', getenv('REDIS_HOST') ?: '127.0.0.1'), $this->config->int('cache.redis.port', (int) (getenv('REDIS_PORT') ?: 6379)), 2.5);
        $password = getenv('REDIS_PASSWORD') ?: '';
        if ($password !== '') {
            $redis->auth($password);
        }
        $database = (int) (getenv('REDIS_DATABASE') ?: 0);
        if ($database > 0) {
            $redis->select($database);
        }
        return new RedisQueueDriver($redis, 'ce:queue:');
    }

    private function database(): DatabaseManager
    {
        return $this->database ?? new DatabaseManager($this->config);
    }
}
