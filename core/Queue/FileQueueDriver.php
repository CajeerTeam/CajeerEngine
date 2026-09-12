<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use CajeerEngine\Support\Uuid;

final readonly class FileQueueDriver implements ManagedQueueDriverInterface
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $payload */
    public function push(string $job, array $payload = [], ?string $queue = null): string
    {
        $id = Uuid::v4();
        $queueName = $this->normalizeQueue($queue);
        $now = $this->now();
        $data = [
            'id' => $id,
            'name' => $job,
            'payload' => $payload,
            'queue' => $queueName,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => max(1, (int) ($payload['_max_attempts'] ?? 3)),
            'available_at' => (string) ($payload['_available_at'] ?? $now),
            'reserved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'last_error' => null,
        ];
        $this->write($this->pendingPath($queueName, $id), $data);
        return $id;
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $queueName = $this->normalizeQueue($queue);
        $now = strtotime($this->now()) ?: time();
        foreach ($this->pendingFiles($queueName) as $file) {
            $data = $this->read($file);
            if (($data['status'] ?? 'pending') !== 'pending') {
                continue;
            }
            $availableAt = strtotime((string) ($data['available_at'] ?? 'now')) ?: 0;
            if ($availableAt > $now) {
                continue;
            }
            $data['attempts'] = ((int) ($data['attempts'] ?? 0)) + 1;
            $data['reserved_at'] = $this->now();
            $data['status'] = 'reserved';
            $data['updated_at'] = $this->now();
            $this->write($file, $data);
            return $this->toJob($data, $file);
        }
        return null;
    }

    public function complete(QueuedJob $job): void
    {
        $file = $this->findPendingFile($job->queue, $job->id);
        if ($file === null) {
            return;
        }
        $data = $this->read($file);
        $data['status'] = 'processed';
        $data['processed_at'] = $this->now();
        $data['updated_at'] = $this->now();
        $target = $this->processedPath($job->queue, $job->id);
        $this->write($target, $data);
        @unlink($file);
    }

    public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void
    {
        $file = $this->findPendingFile($job->queue, $job->id);
        if ($file === null) {
            return;
        }
        $data = $this->read($file);
        $attempts = (int) ($data['attempts'] ?? $job->attempts);
        $max = (int) ($data['max_attempts'] ?? $job->maxAttempts);
        $data['last_error'] = $error;
        $data['updated_at'] = $this->now();

        if ($attempts < $max) {
            $data['status'] = 'pending';
            $data['reserved_at'] = null;
            $data['available_at'] = (new \DateTimeImmutable('+' . max(1, $backoffSeconds) . ' seconds'))->format(DATE_ATOM);
            $this->write($file, $data);
            return;
        }

        $data['status'] = 'failed';
        $data['failed_at'] = $this->now();
        $target = $this->failedPath($job->queue, $job->id);
        $this->write($target, $data);
        @unlink($file);
    }

    public function retry(string $id, ?string $queue = null): bool
    {
        $queueName = $this->normalizeQueue($queue);
        $failed = $this->failedPath($queueName, $id);
        if (!is_file($failed)) {
            return false;
        }
        $data = $this->read($failed);
        $data['status'] = 'pending';
        $data['reserved_at'] = null;
        $data['available_at'] = $this->now();
        $data['updated_at'] = $this->now();
        $this->write($this->pendingPath($queueName, $id), $data);
        @unlink($failed);
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function failed(?string $queue = null): array
    {
        $queueName = $this->normalizeQueue($queue);
        $items = [];
        foreach (glob($this->dir('failed', $queueName) . '/*.json') ?: [] as $file) {
            $items[] = $this->read($file);
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['failed_at'] ?? ''), (string) ($a['failed_at'] ?? '')));
        return $items;
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $queues = [];
        foreach (['pending', 'processed', 'failed'] as $bucket) {
            $base = $this->rootPath . '/storage/queue/' . $bucket;
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $queue = basename($dir);
                $queues[$queue][$bucket] = count(glob($dir . '/*.json') ?: []);
            }
        }
        ksort($queues);
        return [
            'driver' => 'file',
            'root' => $this->rootPath . '/storage/queue',
            'writable' => is_writable($this->rootPath . '/storage/queue') || is_writable($this->rootPath . '/storage'),
            'queues' => $queues,
        ];
    }

    public function cleanupProcessed(int $olderThanSeconds = 604800): int
    {
        $removed = 0;
        $threshold = time() - $olderThanSeconds;
        foreach (glob($this->rootPath . '/storage/queue/processed/*/*.json') ?: [] as $file) {
            if (filemtime($file) !== false && filemtime($file) < $threshold && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function toJob(array $data, string $file): QueuedJob
    {
        return new QueuedJob(
            (string) $data['id'],
            (string) $data['name'],
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            (string) ($data['queue'] ?? 'default'),
            (int) ($data['attempts'] ?? 0),
            (int) ($data['max_attempts'] ?? 3),
            isset($data['available_at']) ? (string) $data['available_at'] : null,
            isset($data['reserved_at']) ? (string) $data['reserved_at'] : null,
            $file,
        );
    }

    /** @return list<string> */
    private function pendingFiles(string $queue): array
    {
        $files = glob($this->dir('pending', $queue) . '/*.json') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function findPendingFile(string $queue, string $id): ?string
    {
        $path = $this->pendingPath($this->normalizeQueue($queue), $id);
        return is_file($path) ? $path : null;
    }

    private function pendingPath(string $queue, string $id): string
    {
        return $this->dir('pending', $queue) . '/' . $id . '.json';
    }

    private function processedPath(string $queue, string $id): string
    {
        return $this->dir('processed', $queue) . '/' . $id . '.json';
    }

    private function failedPath(string $queue, string $id): string
    {
        return $this->dir('failed', $queue) . '/' . $id . '.json';
    }

    private function dir(string $bucket, string $queue): string
    {
        $dir = $this->rootPath . '/storage/queue/' . $bucket . '/' . $this->normalizeQueue($queue);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function normalizeQueue(?string $queue): string
    {
        $queue = trim((string) ($queue ?: 'default'));
        $queue = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $queue) ?: 'default';
        return trim($queue, '-') ?: 'default';
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Файл задачи повреждён: ' . $file);
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function write(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
        rename($tmp, $file);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
