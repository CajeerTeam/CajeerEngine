<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use PDO;
use Symfony\Component\Uid\Uuid;

final readonly class PostgresQueueDriver implements ManagedQueueDriverInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function push(string $job, array $payload = [], ?string $queue = null): string
    {
        $id = (string) Uuid::v7();
        $stmt = $this->pdo->prepare('INSERT INTO ce_jobs (id, queue, name, payload, available_at, created_at, updated_at) VALUES (:id, :queue, :name, CAST(:payload AS jsonb), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute([
            'id' => $id,
            'queue' => $queue ?: 'default',
            'name' => $job,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return $id;
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("SELECT * FROM ce_jobs WHERE queue = :queue AND status = 'pending' AND reserved_at IS NULL AND available_at <= CURRENT_TIMESTAMP ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $stmt->execute(['queue' => $queue ?: 'default']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->pdo->commit();
            return null;
        }
        $update = $this->pdo->prepare("UPDATE ce_jobs SET status = 'reserved', reserved_at = CURRENT_TIMESTAMP, attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update->execute(['id' => $row['id']]);
        $this->pdo->commit();
        return $this->jobFromRow($row, ((int) ($row['attempts'] ?? 0)) + 1);
    }

    public function complete(QueuedJob $job): void
    {
        $stmt = $this->pdo->prepare("UPDATE ce_jobs SET status = 'processed', processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute(['id' => $job->id]);
    }

    public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void
    {
        $max = max(1, $job->maxAttempts);
        if ($job->attempts < $max) {
            $stmt = $this->pdo->prepare("UPDATE ce_jobs SET status = 'pending', reserved_at = NULL, available_at = CURRENT_TIMESTAMP + (:backoff || ' seconds')::interval, last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $job->id, 'backoff' => max(1, $backoffSeconds), 'error' => $error]);
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE ce_jobs SET status = 'failed', failed_at = CURRENT_TIMESTAMP, last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute(['id' => $job->id, 'error' => $error]);
    }

    public function retry(string $id, ?string $queue = null): bool
    {
        $sql = "UPDATE ce_jobs SET status = 'pending', reserved_at = NULL, available_at = CURRENT_TIMESTAMP, failed_at = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'failed'";
        $params = ['id' => $id];
        if ($queue !== null && $queue !== '') {
            $sql .= ' AND queue = :queue';
            $params['queue'] = $queue;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function failed(?string $queue = null): array
    {
        $sql = "SELECT * FROM ce_jobs WHERE status = 'failed'";
        $params = [];
        if ($queue !== null && $queue !== '') {
            $sql .= ' AND queue = :queue';
            $params['queue'] = $queue;
        }
        $sql .= ' ORDER BY failed_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function diagnostics(): array
    {
        $rows = $this->pdo->query('SELECT queue, status, COUNT(*) AS count FROM ce_jobs GROUP BY queue, status ORDER BY queue, status')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $queues = [];
        foreach ($rows as $row) {
            $queues[(string) $row['queue']][(string) $row['status']] = (int) $row['count'];
        }
        return ['driver' => 'pgsql', 'queues' => $queues];
    }

    public function cleanupProcessed(int $olderThanSeconds = 604800): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM ce_jobs WHERE status = 'processed' AND processed_at < CURRENT_TIMESTAMP - (:age || ' seconds')::interval");
        $stmt->execute(['age' => max(1, $olderThanSeconds)]);
        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $row */
    private function jobFromRow(array $row, int $attempts): QueuedJob
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        return new QueuedJob((string) $row['id'], (string) $row['name'], is_array($payload) ? $payload : [], (string) $row['queue'], $attempts, (int) ($row['max_attempts'] ?? 3));
    }
}
