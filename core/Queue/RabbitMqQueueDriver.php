<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use CajeerEngine\Support\Uuid;

/**
 * RabbitMQ driver через PHP ext-amqp. Не требует внешней composer-библиотеки.
 */
final class RabbitMqQueueDriver implements ManagedQueueDriverInterface
{
    /** @var array<string, array{queue: object, envelope: object}> */
    private array $inflight = [];

    private object $connection;
    private object $channel;
    private object $exchange;
    private string $exchangeName;
    private string $queuePrefix;
    private int $maxAttempts;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        if (!class_exists('AMQPConnection')) {
            throw new \RuntimeException('RabbitMQ driver требует установленное PHP-расширение ext-amqp.');
        }

        $dsn = (string) ($config['dsn'] ?? 'amqp://guest:guest@127.0.0.1:5672/%2f');
        $parts = parse_url($dsn);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Некорректный RabbitMQ DSN.');
        }

        $this->queuePrefix = (string) ($config['queue_prefix'] ?? 'cajeerengine');
        $this->exchangeName = (string) ($config['exchange'] ?? 'cajeerengine.jobs');
        $this->maxAttempts = max(1, (int) ($config['max_attempts'] ?? 3));
        $vhost = isset($parts['path']) ? trim(rawurldecode($parts['path']), '/') : '/';
        $vhost = $vhost === '' ? '/' : $vhost;

        $this->connection = new \AMQPConnection([
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? 5672),
            'login' => rawurldecode((string) ($parts['user'] ?? 'guest')),
            'password' => rawurldecode((string) ($parts['pass'] ?? 'guest')),
            'vhost' => $vhost,
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 3),
            'read_timeout' => (float) ($config['read_timeout'] ?? 3),
            'write_timeout' => (float) ($config['write_timeout'] ?? 3),
        ]);
        $this->connection->connect();
        $this->channel = new \AMQPChannel($this->connection);
        $this->exchange = new \AMQPExchange($this->channel);
        $this->exchange->setName($this->exchangeName);
        $this->exchange->setType(AMQP_EX_TYPE_DIRECT);
        $this->exchange->setFlags(AMQP_DURABLE);
        $this->exchange->declareExchange();
    }

    public function push(string $job, array $payload = [], ?string $queue = null): string
    {
        $id = Uuid::v4();
        $queue = $this->queueName($queue);
        $this->queue($queue);
        $message = json_encode([
            'id' => $id,
            'name' => $job,
            'payload' => $payload,
            'queue' => $queue,
            'attempts' => 0,
            'max_attempts' => $this->maxAttempts,
            'available_at' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->exchange->publish($message, $queue, AMQP_NOPARAM, [
            'content_type' => 'application/json',
            'delivery_mode' => 2,
            'message_id' => $id,
            'timestamp' => time(),
        ]);

        return $id;
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $queueName = $this->queueName($queue);
        $queueObject = $this->queue($queueName);
        $envelope = $queueObject->get(AMQP_NOPARAM);
        if (!$envelope) {
            return null;
        }

        $payload = json_decode((string) $envelope->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            $queueObject->reject($envelope->getDeliveryTag(), AMQP_REQUEUE);
            throw new \RuntimeException('RabbitMQ job payload должен быть JSON-объектом.');
        }

        $job = new QueuedJob(
            (string) ($payload['id'] ?? Uuid::v4()),
            (string) ($payload['name'] ?? 'unknown'),
            is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            $queueName,
            (int) ($payload['attempts'] ?? 0) + 1,
            (int) ($payload['max_attempts'] ?? $this->maxAttempts),
            (string) ($payload['available_at'] ?? gmdate(DATE_ATOM)),
            gmdate(DATE_ATOM),
            'rabbitmq',
        );
        $this->inflight[$job->id] = ['queue' => $queueObject, 'envelope' => $envelope];

        return $job;
    }

    public function complete(QueuedJob $job): void
    {
        $this->ack($job);
    }

    public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void
    {
        $entry = $this->inflight[$job->id] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->inflight[$job->id]);

        $requeue = $job->attempts < $job->maxAttempts;
        $entry['queue']->reject($entry['envelope']->getDeliveryTag(), $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
    }

    public function retry(string $id, ?string $queue = null): bool
    {
        // RabbitMQ не хранит failed-set внутри драйвера: retry выполняется через broker requeue в fail().
        return false;
    }

    public function failed(?string $queue = null): array
    {
        return [];
    }

    public function diagnostics(): array
    {
        $queue = $this->queue($this->queueName(null));
        return [
            'driver' => 'rabbitmq',
            'connected' => $this->connection->isConnected(),
            'exchange' => $this->exchangeName,
            'default_queue' => $this->queueName(null),
            'default_queue_messages' => $queue->declareQueue(),
            'failed_supported' => false,
        ];
    }

    public function cleanupProcessed(int $olderThanSeconds = 604800): int
    {
        return 0;
    }

    private function ack(QueuedJob $job): void
    {
        $entry = $this->inflight[$job->id] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->inflight[$job->id]);
        $entry['queue']->ack($entry['envelope']->getDeliveryTag());
    }

    private function queueName(?string $queue): string
    {
        $queue = trim($queue ?: (string) ($this->config['queue'] ?? 'default'));
        $queue = $queue === '' ? 'default' : $queue;
        return str_starts_with($queue, $this->queuePrefix . '.') ? $queue : $this->queuePrefix . '.' . $queue;
    }

    private function queue(string $queue): object
    {
        $q = new \AMQPQueue($this->channel);
        $q->setName($queue);
        $q->setFlags(AMQP_DURABLE);
        $q->declareQueue();
        $q->bind($this->exchangeName, $queue);
        return $q;
    }
}
