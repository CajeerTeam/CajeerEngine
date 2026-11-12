<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

/** Handlers performing long work should checkpoint before irreversible effects and between batches. */
final readonly class JobContext
{
    public function __construct(public QueuedJob $job,private ManagedQueueDriverInterface $queue) {}
    public function heartbeat(): void {if($this->queue instanceof LeaseQueueDriverInterface&&!$this->queue->heartbeat($this->job))throw new \RuntimeException('Queue reservation lost');}
    public function idempotencyKey(): string {return $this->job->id;}
}
