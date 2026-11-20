<?php

declare(strict_types=1);

namespace CajeerEngine\Scheduler;

use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Search\SearchIndexer;

final readonly class SchedulerRunner
{
    public function __construct(
        private string $rootPath,
        private SearchIndexer $searchIndexer,
        private WebhookRepository $webhooks,
        private ManagedQueueDriverInterface $queue,
        private ?\CajeerEngine\Extension\Runtime\PlatformServices $platform = null,
        private ?TaskRunStore $runStore = null,
        private ?\CajeerEngine\Analytics\Reporting\AnalyticsHub $analyticsHub = null,
        private ?\CajeerEngine\AI\AiBackgroundAutomation $aiAutomations = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        $store = $this->runStore ?? new TaskRunStore($this->rootPath);
        $extensionNames = [];
        $extensionRegistryUnavailable = false;
        try {
            $extensionNames = $this->platform?->tasks() ?? [];
        } catch (\Throwable) {
            $extensionRegistryUnavailable = true;
        }
        $tasks = array_merge(
            [
                ['name' => 'tmp.cleanup', 'description' => 'Удаление старых временных файлов.', 'interval' => 'hourly'],
                ['name' => 'queue.cleanup_processed', 'description' => 'Удаление старых обработанных file-queue задач.', 'interval' => 'daily'],
                ['name' => 'search.index_published', 'description' => 'Проверка и восстановление актуальности поискового каталога.', 'interval' => 'every_minute'],
                ['name' => 'webhooks.dispatch_pending', 'description' => 'Отправка pending-событий Event API.', 'interval' => 'every_minute'],
            ],
            $this->aiAutomations === null ? [] : [['name' => 'ai.automation.dispatch', 'description' => 'Dispatch scheduled AI generation runs to queue', 'interval' => 'every_minute']],
            $this->analyticsHub === null ? [] : [['name' => 'analytics.sync_reports', 'description' => 'Queue analytics report refresh', 'interval' => 'hourly']],
            array_map(static fn($name) => ['name' => $name, 'description' => 'Extension task', 'interval' => 'every_minute'], $extensionNames),
        );
        $internal = ['tmp.cleanup', 'queue.cleanup_processed', 'search.index_published', 'webhooks.dispatch_pending', 'analytics.sync_reports', 'ai.automation.dispatch'];
        if ($extensionRegistryUnavailable) {
            $tasks[] = ['name' => 'extensions.registry', 'description' => 'Extension task registry unavailable', 'interval' => 'every_minute', 'state' => ['status' => 'unavailable']];
        }
        foreach ($tasks as &$task) {
            if ($task['name'] === 'extensions.registry' && $extensionRegistryUnavailable) continue;
            try {
                $task['state'] = $store->status(in_array($task['name'], $internal, true) ? $task['name'] : 'extension.' . $task['name']);
            } catch (\Throwable) {
                // A corrupt ledger must not make the entire scheduler diagnostics endpoint unavailable.
                $task['state'] = ['status' => 'unavailable', 'error' => 'Task state unavailable'];
            }
        }
        unset($task);
        return $tasks;
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $store = $this->runStore ?? new TaskRunStore($this->rootPath);
        $results = [];
        // Each task is isolated. A failing queue/backend cannot starve unrelated maintenance,
        // and a failed slot is never silently marked successful.
        $results['tmp.cleanup'] = $this->runTask($store, 'tmp.cleanup', 3600, fn() => ['removed' => $this->cleanupTmp()]);
        $results['queue.cleanup_processed'] = $this->runTask($store, 'queue.cleanup_processed', 86400, fn() => ['removed' => $this->queue->cleanupProcessed()]);
        $results['search.index_published'] = $this->runTask($store, 'search.index_published', 60, fn() => $this->searchIndexer->reconcile());
        $results['webhooks.dispatch_pending'] = $this->runTask($store, 'webhooks.dispatch_pending', 60, fn() => $this->webhooks->enqueuePending($this->queue, 50));
        if (\CajeerEngine\Observability\TraceExporter::enabled()) {
            $results['observability.export_traces'] = $this->runTask($store, 'observability.export_traces', 60, fn() => (new \CajeerEngine\Observability\TraceExporter($this->rootPath))->flush());
        }
        if ($this->aiAutomations !== null) {
            $results['ai.automation.dispatch'] = $this->runTask($store, 'ai.automation.dispatch', 60, fn() => $this->aiAutomations->dispatch());
        }
        if ($this->analyticsHub !== null) {
            $results['analytics.sync_reports'] = $this->runTask($store, 'analytics.sync_reports', 3600, fn() => $this->analyticsHub->schedule());
        }
        try {
            $results['extensions'] = $this->platform?->runTasks($this->rootPath, $store) ?? [];
        } catch (\Throwable) {
            $results['extensions'] = [['ok' => false, 'error' => 'Extension scheduler unavailable']];
        }
        $ok = true;
        foreach ($results as $name => $result) {
            if ($name === 'extensions') {
                foreach ($result as $entry) if (($entry['ok'] ?? true) === false) $ok = false;
            } elseif (($result['ok'] ?? true) === false) {
                $ok = false;
            }
        }
        try {
            $this->writeLog($results);
        } catch (\Throwable) {
            // Telemetry must not hide completed task results.
        }
        return ['ok' => $ok, 'ran_at' => gmdate(DATE_ATOM), 'tasks' => $results];
    }

    private function runTask(TaskRunStore $store, string $name, int $interval, callable $handler): array
    {
        try {
            return $store->run($name, $interval, $handler);
        } catch (\Throwable) {
            // Failure to acquire/persist a task slot is not a successful skip.
            return ['ok' => false, 'error' => 'Task scheduler unavailable'];
        }
    }

    private function cleanupTmp(): int
    {
        $dir = $this->rootPath . '/storage/tmp';
        if (!is_dir($dir)) return 0;
        $removed = 0;
        $threshold = time() - 86400;
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot() || !$file->isFile()) continue;
            if ($file->getMTime() < $threshold && @unlink($file->getPathname())) $removed++;
        }
        return $removed;
    }

    /** @param array<string, mixed> $results */
    private function writeLog(array $results): void
    {
        $summary = [];
        foreach ($results as $name => $result) {
            if ($name !== 'extensions') $summary[$name] = ['ok' => $result['ok'] ?? true, 'skipped' => isset($result['skipped'])];
        }
        (new \CajeerEngine\Runtime\RuntimeLogger($this->rootPath))->info('Scheduled task finished', ['status' => in_array(false, array_column($summary, 'ok'), true) ? 'failure' : 'success']);
    }
}
