<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Queue;

use CajeerEngine\Api\Controller\QueueController;
use CajeerEngine\Api\Controller\SchedulerController;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Queue\QueuedJob;
use CajeerEngine\Queue\QueueWorker;
use CajeerEngine\Scheduler\SchedulerRunner;
use CajeerEngine\Search\FileSearchIndex;
use CajeerEngine\Search\SearchIndexer;
use CajeerEngine\Security\SignedPayload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RuntimeResilienceAcceptanceTest extends TestCase
{
    private string $root;
    private array $previousEnv = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ce_runtime_accept_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/tmp', 0770, true);
        foreach (['CONTENT_STORAGE', 'CMS_STORAGE', 'SEARCH_DRIVER'] as $name) {
            $this->previousEnv[$name] = getenv($name);
            putenv($name . '=file');
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        foreach ($this->previousEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    private function services(): array
    {
        $queue = new class implements ManagedQueueDriverInterface {
            public function push(string $job, array $payload = [], ?string $queue = null): string { throw new \RuntimeException('DSN password=SECRET'); }
            public function pop(?string $queue = null): ?QueuedJob { throw new \RuntimeException('DSN password=SECRET'); }
            public function complete(QueuedJob $job): void { throw new \RuntimeException('Offline'); }
            public function fail(QueuedJob $job, string $error, int $backoffSeconds = 60): void { throw new \RuntimeException('Offline'); }
            public function retry(string $id, ?string $queue = null): bool { throw new \RuntimeException('Offline'); }
            public function failed(?string $queue = null): array { throw new \RuntimeException('Offline'); }
            public function diagnostics(): array { throw new \RuntimeException('Offline'); }
            public function cleanupProcessed(int $olderThanSeconds = 604800): int { throw new \RuntimeException('Offline'); }
        };
        $search = new SearchIndexer(new ContentEntryRepository($this->root), new FileSearchIndex($this->root));
        $repository = new WebhookRepository($this->root);
        $dispatcher = new WebhookDispatcher(new SignedPayload(), $repository);
        return [$queue, new QueueWorker($queue, $search, $repository, $dispatcher), new SchedulerRunner($this->root, $search, $repository, $queue)];
    }

    public function testQueueEndpointsReturn503WithoutTransportSecrets(): void
    {
        [$queue, $worker] = $this->services();
        $controller = new QueueController($queue, $worker);
        $push = Request::create('/api/v1/queue/jobs', 'POST');
        $push->attributes->set('json', ['name' => 'log.message', 'payload' => ['message' => 'test']]);
        $work = Request::create('/api/v1/queue/work', 'POST');
        $work->attributes->set('json', ['queue' => 'default']);
        $requests = [
            $controller->diagnostics(Request::create('/api/v1/queue/diagnostics'), []),
            $controller->push($push, []),
            $controller->work($work, []),
            $controller->failed(Request::create('/api/v1/queue/failed'), []),
            $controller->retry(Request::create('/api/v1/queue/retry'), ['id' => '00000000-0000-4000-8000-000000000001']),
        ];
        foreach ($requests as $response) {
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('5', $response->headers->get('Retry-After'));
            self::assertStringNotContainsString('SECRET', $response->getContent());
        }
    }

    public function testSchedulerHttpReportsPartialFailureWithoutSuppressingOtherTasks(): void
    {
        [, , $runner] = $this->services();
        $response = (new SchedulerController($runner))->run(Request::create('/api/v1/scheduler/run', 'POST'), []);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($body['data']['ok']);
        self::assertFalse($body['data']['tasks']['queue.cleanup_processed']['ok']);
        self::assertArrayHasKey('webhooks.dispatch_pending', $body['data']['tasks']);
    }
}
