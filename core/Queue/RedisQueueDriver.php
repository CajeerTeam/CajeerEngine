<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use Symfony\Component\Uid\Uuid;

final readonly class RedisQueueDriver implements ManagedQueueDriverInterface
{
    public function __construct(private \Redis $redis, private string $prefix = 'ce:queue:')
    {
    }

    public function push(string $job, array $payload = [], ?string $queue = null): string
    {
        $id = (string) Uuid::v7();
        $data = [
            'id' => $id,
            'name' => $job,
            'payload' => $payload,
            'queue' => $queue ?: 'default',
            'attempts' => 0,
            'max_attempts' => 3,
            'status' => 'pending',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ];
        $this->redis->hSet($this->jobKey($id), 'data', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->redis->rPush($this->pendingKey($queue), $id);
        return $id;
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $queueName = $queue ?: 'default';
        while (($id = $this->redis->lPop($this->pendingKey($queueName))) !== false) {
            $data = $this->readJob((string) $id);
            if ($data === null || ($data['status'] ?? '') !== 'pending') {
                continue;
            }
            $data['status'] = 'reserved';
            $data['attempts'] = ((int) ($data['attempts'] ?? 0)) + 1;
            $data['reserved_at'] = $this->now();
            $data['updated_at'] = $this->now();
            $this->writeJob($data);
            return $this->toJob($data);
        }
        return null;
    }

    public function complete(QueuedJob $job): void
    {
        $data = $this->readJob($job->id);
        if ($data === null) {
            return;
        }
        $data['status'] = 'processed';
        $data['processed_at'] = $this->now();
        $data['updated_at'] = $this->now();
        $this->writeJob($data);
    }

    public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void
    {
        $data = $this->readJob($job->id);
        if ($data === null) {
            return;
        }
        $data['last_error'] = $error;
        $data['updated_at'] = $this->now();
        $data['attempts'] = max((int) ($data['attempts'] ?? 0), $job->attempts);
        $max = (int) ($data['max_attempts'] ?? $job->maxAttempts);
        if ((int) $data['attempts'] < $max) {
            $data['status'] = 'pending';
            $data['reserved_at'] = null;
            $this->writeJob($data);
            $this->redis->rPush($this->pendingKey($job->queue), $job->id);
            return;
        }
        $data['status'] = 'failed';
        $data['failed_at'] = $this->now();
        $this->writeJob($data);
        $this->redis->sAdd($this->failedKey($job->queue), $job->id);
    }

    public function retry(string $id, ?string $queue = null): bool
    {
        $data = $this->readJob($id);
        if ($data === null || ($data['status'] ?? '') !== 'failed') {
            return false;
        }
        $queueName = $queue ?: (string) ($data['queue'] ?? 'default');
        $data['status'] = 'pending';
        $data['reserved_at'] = null;
        $data['failed_at'] = null;
        $data['last_error'] = null;
        $data['updated_at'] = $this->now();
        $this->writeJob($data);
        $this->redis->sRem($this->failedKey($queueName), $id);
        $this->redis->rPush($this->pendingKey($queueName), $id);
        return true;
    }

    public function failed(?string $queue = null): array
    {
        $queueName = $queue ?: 'default';
        $ids = $this->redis->sMembers($this->failedKey($queueName)) ?: [];
        $items = [];
        foreach ($ids as $id) {
            $data = $this->readJob((string) $id);
            if ($data !== null && ($data['status'] ?? '') === 'failed') {
                $items[] = $data;
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['failed_at'] ?? ''), (string) ($a['failed_at'] ?? '')));
        return $items;
    }

    public function diagnostics(): array
    {
        return ['driver' => 'redis', 'prefix' => $this->prefix, 'connected' => $this->redis->isConnected()];
    }

    public function cleanupProcessed(int $olderThanSeconds = 604800): int
    {
        return 0;
    }

    /** @return array<string, mixed>|null */
    private function readJob(string $id): ?array
    {
        $raw = $this->redis->hGet($this->jobKey($id), 'data');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    private function writeJob(array $data): void
    {
        $this->redis->hSet($this->jobKey((string) $data['id']), 'data', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private function toJob(array $data): QueuedJob
    {
        return new QueuedJob((string) $data['id'], (string) ($data['name'] ?? 'unknown'), is_array($data['payload'] ?? null) ? $data['payload'] : [], (string) ($data['queue'] ?? 'default'), (int) ($data['attempts'] ?? 0), (int) ($data['max_attempts'] ?? 3));
    }

    private function pendingKey(?string $queue): string { return $this->prefix . 'pending:' . ($queue ?: 'default'); }
    private function failedKey(?string $queue): string { return $this->prefix . 'failed:' . ($queue ?: 'default'); }
    private function jobKey(string $id): string { return $this->prefix . 'job:' . $id; }
    private function now(): string { return (new \DateTimeImmutable())->format(DATE_ATOM); }
}
