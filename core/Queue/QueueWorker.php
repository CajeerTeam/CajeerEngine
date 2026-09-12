<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Search\SearchIndexer;

final readonly class QueueWorker
{
    public function __construct(
        private ManagedQueueDriverInterface $queue,
        private SearchIndexer $searchIndexer,
        private WebhookRepository $webhooks,
        private WebhookDispatcher $dispatcher,
    ) {
    }

    /** @return array<string, mixed> */
    public function work(?string $queue = null, int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;
        $errors = [];

        for ($i = 0; $i < max(1, $limit); $i++) {
            $job = $this->queue->pop($queue);
            if ($job === null) {
                break;
            }

            try {
                $this->handle($job);
                $this->queue->complete($job);
                $processed++;
            } catch (\Throwable $e) {
                $this->queue->fail($job, $e->getMessage(), min(3600, 30 * max(1, $job->attempts)));
                $failed++;
                $errors[] = ['id' => $job->id, 'name' => $job->name, 'error' => $e->getMessage()];
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
            'diagnostics' => $this->queue->diagnostics(),
        ];
    }

    private function handle(QueuedJob $job): void
    {
        match ($job->name) {
            'log.message' => $this->logMessage($job->payload),
            'search.index_all' => $this->searchIndexer->indexAll(),
            'webhooks.dispatch_pending' => $this->dispatchPendingWebhooks(),
            'webhook.dispatch' => $this->dispatchWebhookJob($job->payload),
            default => throw new \RuntimeException('Неизвестная задача очереди: ' . $job->name),
        };
    }

    /** @param array<string, mixed> $payload */
    private function logMessage(array $payload): void
    {
        $path = dirname(__DIR__, 2) . '/storage/logs/queue.log';
        $message = (string) ($payload['message'] ?? 'empty');
        file_put_contents($path, '[' . (new \DateTimeImmutable())->format(DATE_ATOM) . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function dispatchPendingWebhooks(): void
    {
        foreach ($this->webhooks->outbox(20) as $event) {
            $this->dispatcher->dispatchEvent((string) $event['event'], is_array($event['payload'] ?? null) ? $event['payload'] : []);
            $this->webhooks->markOutboxProcessed((string) $event['id']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function dispatchWebhookJob(array $payload): void
    {
        $event = (string) ($payload['event'] ?? 'manual.test');
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $this->dispatcher->dispatchEvent($event, $body);
    }
}
