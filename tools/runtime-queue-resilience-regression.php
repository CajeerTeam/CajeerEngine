<?php
declare(strict_types=1);

use CajeerEngine\Content\{ContentEntryRepository, ContentTypeRepository};
use CajeerEngine\Integration\{WebhookDispatcher, WebhookRepository};
use CajeerEngine\Queue\{FileQueueDriver, ManagedQueueDriverInterface, QueueWorker, QueuedJob};
use CajeerEngine\Scheduler\{SchedulerRunner, TaskRunStore};
use CajeerEngine\Search\{FileSearchIndex, SearchIndexer};
use CajeerEngine\Security\SignedPayload;

require dirname(__DIR__) . '/core/Console/NativeCli.php';

function assertRuntime(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function cleanupRuntime(string $root): void
{
    if (!is_dir($root)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($root);
}

/** Transport failures injected after real file-queue reservations and writes. */
final class FaultQueue implements ManagedQueueDriverInterface
{
    public bool $popDown = false;
    public bool $completeDown = false;
    public bool $failDown = false;
    public bool $cleanupDown = false;
    public bool $diagnosticsDown = false;
    public int $failCalls = 0;
    public int $completeCalls = 0;
    public function __construct(public readonly FileQueueDriver $inner) {}
    public function push(string $job, array $payload = [], ?string $queue = null): string { return $this->inner->push($job, $payload, $queue); }
    public function pop(?string $queue = null): ?QueuedJob { if ($this->popDown) throw new RuntimeException('redis connection string password=SECRET'); return $this->inner->pop($queue); }
    public function complete(QueuedJob $job): void { $this->completeCalls++; if ($this->completeDown) throw new RuntimeException('lost connection'); $this->inner->complete($job); }
    public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void { $this->failCalls++; if ($this->failDown) throw new RuntimeException('lost connection'); $this->inner->fail($job, $error, $backoffSeconds); }
    public function retry(string $id, ?string $queue = null): bool { return $this->inner->retry($id, $queue); }
    public function failed(?string $queue = null): array { return $this->inner->failed($queue); }
    public function diagnostics(): array { if ($this->diagnosticsDown) throw new RuntimeException('diagnostics offline'); return $this->inner->diagnostics(); }
    public function cleanupProcessed(int $olderThanSeconds = 604800): int { if ($this->cleanupDown) throw new RuntimeException('disk or queue backend offline'); return $this->inner->cleanupProcessed($olderThanSeconds); }
}

$root = sys_get_temp_dir() . '/ce_runtime_183_' . bin2hex(random_bytes(6));
mkdir($root . '/storage/tmp', 0770, true);
putenv('APP_ENV=testing');
putenv('CONTENT_STORAGE=file');
putenv('CMS_STORAGE=file');
putenv('SEARCH_DRIVER=file');
try {
    $file = new FileQueueDriver($root, 30);
    $transport = new FaultQueue($file);
    $search = new SearchIndexer(new ContentTypeRepository($root), new ContentEntryRepository($root), new FileSearchIndex($root));
    $webhooks = new WebhookRepository($root);
    $dispatcher = new WebhookDispatcher(new SignedPayload(), $webhooks);
    $worker = new QueueWorker($transport, $search, $webhooks, $dispatcher);
    $executed = 0;
    $worker->registry()->register('test.run', function () use (&$executed): void { $executed++; });
    $file->push('test.run', [], 'down');
    $transport->popDown = true;
    $result = $worker->work('down', 1);
    assertRuntime(!$result['ok'] && $result['backend_unavailable'] && $result['processed'] === 0 && $executed === 0, 'pop outage exits without invoking handler');
    assertRuntime(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'SECRET'), 'backend credentials never reach worker result');
    $transport->popDown = false;
    assertRuntime($worker->work('down', 1)['processed'] === 1 && $executed === 1, 'job survives transport recovery');

    $file->push('test.run', [], 'ack');
    $transport->completeDown = true;
    $result = $worker->work('ack', 1);
    assertRuntime($executed === 2 && $result['lost_reservations'] === 1 && !$result['ok'] && $result['backend_unavailable'], 'ack loss reported after successful handler');
    assertRuntime($transport->completeCalls === 2 && $transport->failCalls === 0, 'successful handler never deliberately requeued after uncertain ack');
    assertRuntime(($file->diagnostics()['queues']['ack']['reserved'] ?? 0) === 1, 'uncertain acknowledgement leaves real reservation for lease recovery');
    $transport->completeDown = false;

    $file->push('test.run', [], 'diagnostics');
    $transport->diagnosticsDown = true;
    $result = $worker->work('diagnostics', 1);
    assertRuntime($executed === 3 && $result['processed'] === 1 && !$result['ok'] && $result['backend_unavailable'], 'diagnostics outage preserves confirmed job result');
    $transport->diagnosticsDown = false;

    $file->push('test.fails', ['_max_attempts' => 1], 'failed-ack');
    $worker->registry()->register('test.fails', function (): void { throw new RuntimeException('api_key=SECRET'); });
    $transport->failDown = true;
    $result = $worker->work('failed-ack', 1);
    assertRuntime($result['failed'] === 1 && $result['lost_reservations'] === 1 && $result['backend_unavailable'], 'failure persistence outage is not reported as success');
    assertRuntime(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'SECRET'), 'job exception messages are not exposed');
    $transport->failDown = false;

    $transport->cleanupDown = true;
    $runner = new SchedulerRunner($root, $search, $webhooks, $dispatcher, $transport);
    $result = $runner->run();
    assertRuntime(!$result['ok'] && ($result['tasks']['queue.cleanup_processed']['ok'] ?? true) === false, 'failed queue cleanup fails scheduler run');
    assertRuntime(isset($result['tasks']['webhooks.dispatch_pending']), 'failure does not starve later scheduler tasks');
    $store = new TaskRunStore($root);
    assertRuntime($store->status('queue.cleanup_processed')['status'] === 'failed', 'failed scheduled slot persisted');
    $notRetried = $store->run('queue.cleanup_processed', 86400, fn() => throw new RuntimeException('should not rerun'));
    assertRuntime(($notRetried['skipped'] ?? '') === 'already run' && ($notRetried['ok'] ?? true) === false, 'failed slot remains failed on same-slot rescan');
    $store->run('sensitive', 60, fn() => throw new RuntimeException('password=SECRET'), 60);
    assertRuntime(!str_contains(json_encode($store->status('sensitive'), JSON_THROW_ON_ERROR), 'SECRET'), 'scheduler state and response redact failure details');

    $path = $root . '/storage/app/scheduler/' . hash('sha256', 'tmp.cleanup') . '.json';
    file_put_contents($path, '{incomplete');
    $tasks = $runner->tasks();
    assertRuntime(($tasks[0]['state']['status'] ?? '') === 'unavailable' && count($tasks) >= 4, 'corrupt scheduler ledger does not break task listing');
    echo "Runtime & queue resilience regression complete\n";
} finally {
    cleanupRuntime($root);
}
