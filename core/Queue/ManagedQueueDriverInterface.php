<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

interface ManagedQueueDriverInterface extends QueueDriverInterface
{
    public function complete(QueuedJob $job): void;

    public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void;

    public function retry(string $id, ?string $queue = null): bool;

    /** @return list<array<string, mixed>> */
    public function failed(?string $queue = null): array;

    /** @return array<string, mixed> */
    public function diagnostics(): array;

    public function cleanupProcessed(int $olderThanSeconds = 604800): int;
}
