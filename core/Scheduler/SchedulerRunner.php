<?php

declare(strict_types=1);

namespace CajeerEngine\Scheduler;

use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Search\SearchIndexer;

final readonly class SchedulerRunner
{
    public function __construct(
        private string $rootPath,
        private SearchIndexer $searchIndexer,
        private WebhookRepository $webhooks,
        private WebhookDispatcher $dispatcher,
        private ManagedQueueDriverInterface $queue,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        return [
            ['name' => 'tmp.cleanup', 'description' => 'Удаление старых временных файлов.', 'interval' => 'hourly'],
            ['name' => 'queue.cleanup_processed', 'description' => 'Удаление старых обработанных file-queue задач.', 'interval' => 'daily'],
            ['name' => 'search.index_published', 'description' => 'Переиндексация опубликованного контента.', 'interval' => 'every_run'],
            ['name' => 'webhooks.dispatch_pending', 'description' => 'Отправка pending-событий Event API.', 'interval' => 'every_run'],
        ];
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $results = [];
        $results['tmp.cleanup'] = ['removed' => $this->cleanupTmp()];
        $results['queue.cleanup_processed'] = ['removed' => $this->queue->cleanupProcessed()];
        $results['search.index_published'] = $this->searchIndexer->indexAll();
        $dispatch = ['events' => 0, 'deliveries' => 0];
        foreach ($this->webhooks->outbox(50) as $event) {
            $result = $this->dispatcher->dispatchEvent((string) $event['event'], is_array($event['payload'] ?? null) ? $event['payload'] : []);
            $dispatch['events']++;
            $dispatch['deliveries'] += (int) ($result['deliveries'] ?? 0);
            $this->webhooks->markOutboxProcessed((string) $event['id']);
        }
        $results['webhooks.dispatch_pending'] = $dispatch;
        $this->writeLog($results);
        return ['ran_at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'tasks' => $results];
    }

    private function cleanupTmp(): int
    {
        $dir = $this->rootPath . '/storage/tmp';
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $threshold = time() - 86400;
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }
            if ($file->getMTime() < $threshold && @unlink($file->getPathname())) {
                $removed++;
            }
        }
        return $removed;
    }

    /** @param array<string, mixed> $results */
    private function writeLog(array $results): void
    {
        $path = $this->rootPath . '/storage/logs/scheduler.jsonl';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode(['created_at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
