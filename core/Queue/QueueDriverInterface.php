<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

interface QueueDriverInterface
{
    /** @param array<string, mixed> $payload */
    public function push(string $job, array $payload = [], ?string $queue = null): string;

    public function pop(?string $queue = null): ?QueuedJob;
}
