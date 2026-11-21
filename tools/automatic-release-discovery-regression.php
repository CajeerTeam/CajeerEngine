#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Scheduler\TaskRunStore;
use CajeerEngine\Update\AutomaticReleaseDiscovery;
use CajeerEngine\Update\GitHubEndpoints;
use CajeerEngine\Update\GitHubReleaseMonitor;
use CajeerEngine\Update\UpdateResolver;

function verifyDiscovery(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS ' . $description . PHP_EOL;
}

/** @return array<string,mixed> */
function fixtureRelease(string $version, bool $prerelease = false): array
{
    $name = "cajeerengine-$version-dist.zip";
    $assets = [];
    foreach (['', '.sha256', '.sig'] as $suffix) {
        $assets[] = ['name' => $name . $suffix, 'state' => 'uploaded', 'size' => 150, 'digest' => 'sha256:' . str_repeat('a', 64)];
    }
    return ['tag_name' => 'v' . $version, 'draft' => false, 'prerelease' => $prerelease,
        'published_at' => '2026-11-21T00:00:00Z', 'body' => 'Signed distribution', 'assets' => $assets];
}

function removeDiscoveryFixtures(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) removeDiscoveryFixtures($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/cajeer-discovery-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
file_put_contents($root . '/VERSION', "2.0.1\n");
$payload = json_encode([fixtureRelease('2.0.2'), fixtureRelease('2.1.0-rc.1', true)], JSON_THROW_ON_ERROR);
$requests = [];
$mode = 'new';
$transport = static function (string $url, array $headers) use (&$requests, &$mode, $payload): array {
    $requests[] = [$url, $headers];
    return match ($mode) {
        'new' => [200, $payload, ['etag' => 'W/"release-etag-123"']],
        'unchanged' => [304, '', []],
        'rate' => [429, '{"message":"rate limit"}', ['retry-after' => '600']],
        'offline' => throw new RuntimeException('API_SECRET_SHOULD_NOT_LEAK'),
        'invalid' => [200, '{"message":"not a release list"}', []],
        'empty' => [200, '[]', ['etag' => '"empty-release-list"']],
        default => throw new LogicException('Fixture mode invalid'),
    };
};
try {
    $monitor = new GitHubReleaseMonitor($root, $transport);
    $config = ['core' => ['channel' => 'stable', 'releases_url' => GitHubEndpoints::RELEASES_URL]];
    $resolver = new UpdateResolver($config, $root, $monitor);
    $fresh = $resolver->check('2.0.1');
    verifyDiscovery($fresh['github_reachable'] && $fresh['metadata_status'] === 'fresh'
        && $fresh['update_available'] && $fresh['latest']['version'] === '2.0.2', 'fresh release is discovered and persisted');
    verifyDiscovery($requests[0][0] === GitHubEndpoints::RELEASES_URL
        && !isset($requests[0][1]['If-None-Match']), 'first request uses canonical GitHub endpoint without ETag');
    $snapshot = $resolver->diagnostics('2.0.1');
    verifyDiscovery(count($requests) === 1 && $snapshot['last_check']['latest']['version'] === '2.0.2'
        && $snapshot['release_count'] === 2, 'admin diagnostics are network-free and show saved releases');

    $mode = 'unchanged';
    $notModified = $resolver->check('2.0.1');
    verifyDiscovery($notModified['metadata_status'] === 'not_modified' && $notModified['github_reachable']
        && $requests[1][1]['If-None-Match'] === 'W/"release-etag-123"', 'conditional HTTP 304 reuses validated cache');

    $mode = 'offline';
    $stale = $resolver->check('2.0.1');
    verifyDiscovery($stale['metadata_status'] === 'stale' && !$stale['github_reachable']
        && $stale['latest']['version'] === '2.0.2' && $stale['metadata_failure'] === 'network_error',
        'offline GitHub exposes stale metadata but not successful poll');
    verifyDiscovery(!str_contains(json_encode($stale, JSON_THROW_ON_ERROR), 'API_SECRET'), 'transport secrets never reach persistent status');

    $mode = 'invalid';
    verifyDiscovery($resolver->check('2.0.1')['metadata_status'] === 'stale', 'invalid API payload does not overwrite cache');
    $mode = 'rate';
    $limited = $resolver->check('2.0.1');
    $requestCount = count($requests);
    verifyDiscovery($limited['metadata_failure'] === 'rate_limited' && is_string($limited['retry_at']), 'API rate limit is recorded');
    verifyDiscovery($resolver->check('2.0.1')['metadata_failure'] === 'rate_limited'
        && count($requests) === $requestCount, 'rate-limited GitHub is not queried again during cooldown');
    $cachePath = $root . '/storage/app/updates/github-release-cache.json';
    $cache = json_decode((string) file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR);
    $cache['rate_limited_until'] = 0;
    file_put_contents($cachePath, json_encode($cache, JSON_THROW_ON_ERROR));
    $mode = 'empty';
    $empty = $resolver->check('2.0.1');
    verifyDiscovery($empty['metadata_status'] === 'fresh' && !$empty['update_available']
        && $empty['latest'] === null, 'valid empty response removes obsolete update offer');

    $mode = 'new';
    $rc = (new UpdateResolver(['core' => ['channel' => 'rc']], $root, $monitor))->check('2.0.1');
    verifyDiscovery($rc['latest']['version'] === '2.1.0-rc.1', 'prereleases remain isolated to selected channel');

    $settings = new ConfigRepository($root);
    $settings->set('updates', ['core' => ['channel' => 'stable', 'releases_url' => GitHubEndpoints::RELEASES_URL,
        'auto_check_enabled' => true, 'auto_check_interval_seconds' => 21600]]);
    $task = new AutomaticReleaseDiscovery($settings, $monitor);
    $store = new TaskRunStore($root);
    $now = 1795219200;
    $result = $store->run('updates.discover_releases', $task->intervalSeconds(), fn () => $task->run(), $now);
    verifyDiscovery($result['ok'] && $result['latest_version'] === '2.0.2', 'scheduler executes real release discovery task');
    $again = $store->run('updates.discover_releases', $task->intervalSeconds(), fn () => $task->run(), $now);
    verifyDiscovery(($again['skipped'] ?? '') === 'already run', 'scheduler executes only once per interval slot');
    $mode = 'offline';
    $next = $store->run('updates.discover_releases', $task->intervalSeconds(), fn () => $task->run(), $now + 21600);
    verifyDiscovery($next['ok'] === false && $store->status('updates.discover_releases')['status'] === 'failed',
        'GitHub outages are registered as failed scheduler checks');
    verifyDiscovery($task->intervalSeconds() === 21600 && $task->enabled(), 'six-hour automatic check enabled by default');
    $settings->set('updates.core.auto_check_enabled', false);
    verifyDiscovery(!$task->enabled() && ($task->run()['skipped'] ?? null) === 'disabled', 'operator can disable scheduled discovery');

    // The production SchedulerRunner must actually expose and execute the task,
    // not merely instantiate a discovery service that is never scheduled.
    $runnerRoot = $root . '/runtime-runner';
    mkdir($runnerRoot, 0700);
    mkdir($runnerRoot . '/storage/tmp', 0700, true);
    file_put_contents($runnerRoot . '/VERSION', "2.0.1\n");
    $runnerConfig = new ConfigRepository($runnerRoot);
    $runnerConfig->set('updates', ['core' => ['channel' => 'stable', 'releases_url' => GitHubEndpoints::RELEASES_URL,
        'auto_check_enabled' => true, 'auto_check_interval_seconds' => 21600]]);
    $mode = 'new';
    $runnerTask = new AutomaticReleaseDiscovery($runnerConfig, new GitHubReleaseMonitor($runnerRoot, $transport));
    $search = new \CajeerEngine\Search\SearchIndexer(
        new \CajeerEngine\Content\ContentEntryRepository($runnerRoot),
        new \CajeerEngine\Search\FileSearchIndex($runnerRoot),
    );
    $queue = new class implements \CajeerEngine\Queue\ManagedQueueDriverInterface {
        public function push(string $job, array $payload = [], ?string $queue = null): string { return 'fixture'; }
        public function pop(?string $queue = null): ?\CajeerEngine\Queue\QueuedJob { return null; }
        public function complete(\CajeerEngine\Queue\QueuedJob $job): void {}
        public function fail(\CajeerEngine\Queue\QueuedJob $job, string $error, int $backoffSeconds = 60): void {}
        public function retry(string $id, ?string $queue = null): bool { return false; }
        public function failed(?string $queue = null): array { return []; }
        public function diagnostics(): array { return []; }
        public function cleanupProcessed(int $olderThanSeconds = 604800): int { return 0; }
    };
    $runner = new \CajeerEngine\Scheduler\SchedulerRunner(
        $runnerRoot, $search, new \CajeerEngine\Integration\WebhookRepository($runnerRoot),
        $queue, null, new TaskRunStore($runnerRoot), null, null, $runnerTask,
    );
    verifyDiscovery(in_array('updates.discover_releases', array_column($runner->tasks(), 'name'), true),
        'actual scheduler exposes discovery task for operator diagnostics');
    $scheduled = $runner->run();
    verifyDiscovery(($scheduled['tasks']['updates.discover_releases']['ok'] ?? false) === true
        && ($scheduled['tasks']['updates.discover_releases']['latest_version'] ?? null) === '2.0.2',
        'actual scheduler executes and records GitHub release discovery');

    echo "Automatic release discovery regression passed\n";
} finally {
    removeDiscoveryFixtures($root);
}
