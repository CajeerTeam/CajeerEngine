<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

interface LeaseQueueDriverInterface extends ManagedQueueDriverInterface
{
    /** Extend a live reservation. False means ownership has been lost. */
    public function heartbeat(QueuedJob $job): bool;
    /** Reclaim abandoned reservations, bounded per call. */
    public function recover(?string $queue = null, int $limit = 100): int;
}
