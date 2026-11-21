#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\UpdateOperator;

function assertOperator(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}
function removeOperatorTestTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isLink() || $item->isFile()) unlink($item->getPathname());
        elseif ($item->isDir()) removeOperatorTestTree($item->getPathname());
    }
    rmdir($path);
}
function sampleRequest(string $id, string $date): array
{
    return ['ok' => true, 'id' => $id, 'action' => 'apply', 'actor' => 'admin-test',
        'requested_at' => $date, 'confirmed_at' => $date, 'state' => 'pending', 'stage' => str_repeat('b', 32), 'sha256' => str_repeat('c', 64)];
}

$root = sys_get_temp_dir() . '/cajeer-operator-test-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
$requests = $root . '/storage/app/update-requests';
$updates = $root . '/storage/app/updates';
mkdir($requests, 0700, true);
mkdir($updates, 0700, true);
$config = new ConfigRepository($root);
$executed = [];
$run = static function (string $id) use (&$executed, $requests): array {
    $executed[] = $id;
    $file = $requests . '/request-' . $id . '.json';
    $record = OperationFiles::read($file);
    assertOperator($record['state'] === 'pending', 'queued operation remains pending until real executor claims it');
    $record['state'] = 'completed';
    OperationFiles::json($file, $record);
    return ['ok' => true, 'id' => '20261121-084400-0123456789abcdef'];
};
try {
    $config->set('updates', ['operator' => ['enabled' => false], 'web_upgrade' => ['enabled' => true]]);
    $operator = new UpdateOperator($root, $config, $run);
    assertOperator($operator->runOnce()['status'] === 'disabled', 'disabled operator never consumes queue');
    $config->set('updates', ['operator' => ['enabled' => true], 'web_upgrade' => ['enabled' => true]]);
    $operator = new UpdateOperator($root, $config, $run);
    $config->set('updates', ['operator' => ['enabled' => true], 'web_upgrade' => ['enabled' => false]]);
    assertOperator($operator->runOnce()['status'] === 'disabled', 'disabling browser approvals also disables automatic consumption');
    $config->set('updates', ['operator' => ['enabled' => true], 'web_upgrade' => ['enabled' => true]]);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $rejected = false;
        try { $operator->runOnce(); } catch (RuntimeException $e) { $rejected = str_contains($e->getMessage(), 'non-root'); }
        assertOperator($rejected, 'root execution is rejected');
        echo 'PASS operator queue smoke tests run by non-root CI and installed service' . PHP_EOL;
        exit(0);
    }
    assertOperator($operator->runOnce()['status'] === 'idle', 'empty queue is idle');

    $later = str_repeat('2', 32); $earlier = str_repeat('1', 32);
    OperationFiles::json($requests . '/request-' . $later . '.json', sampleRequest($later, '2026-11-21T06:05:00Z'));
    OperationFiles::json($requests . '/request-' . $earlier . '.json', sampleRequest($earlier, '2026-11-21T06:00:00Z'));
    $r = $operator->runOnce();
    assertOperator($r['status'] === 'completed' && $r['request_id'] === $earlier && $r['processed'] === 1, 'oldest approved installation executed exactly once');
    assertOperator(count($executed) === 1 && $executed[0] === $earlier, 'newer request does not run in same timer invocation');
    assertOperator(OperationFiles::read($updates . '/operator-status.json')['status'] === 'completed', 'durable operator status written');

    $lock = fopen($updates . '/operator.lock', 'r+');
    assertOperator(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'test holds operator lease');
    assertOperator($operator->runOnce()['status'] === 'busy', 'overlapping operator invocation is harmless');
    flock($lock, LOCK_UN); fclose($lock);
    $r = $operator->runOnce();
    assertOperator($r['status'] === 'completed' && $r['request_id'] === $later && count($executed) === 2, 'next timer invocation runs next approved request');
    assertOperator($operator->runOnce()['status'] === 'idle', 'completed requests never replay');

    $blocked = str_repeat('3', 32);
    $record = sampleRequest($blocked, '2026-11-21T06:10:00Z'); $record['state'] = 'running';
    OperationFiles::json($requests . '/request-' . $blocked . '.json', $record);
    $blockedNext = str_repeat('4', 32);
    OperationFiles::json($requests . '/request-' . $blockedNext . '.json', sampleRequest($blockedNext, '2026-11-21T06:15:00Z'));
    assertOperator($operator->runOnce()['status'] === 'recovery_required' && count($executed) === 2, 'interrupted running request blocks all automatic retries');
    $record['state'] = 'failed'; OperationFiles::json($requests . '/request-' . $blocked . '.json', $record);
    OperationFiles::json($updates . '/active.json', ['id' => 'interrupted']);
    assertOperator($operator->runOnce()['status'] === 'recovery_required' && count($executed) === 2, 'active transaction journal blocks worker');
    unlink($updates . '/active.json');

    $file = $requests . '/request-' . $blockedNext . '.json';
    $bad = OperationFiles::read($file); $bad['sha256'] = str_repeat('g', 64);
    OperationFiles::json($file, $bad);
    assertOperator($operator->runOnce()['status'] === 'recovery_required', 'malformed approval fails closed');
    $bad['sha256'] = str_repeat('c', 64);
    unset($bad['confirmed_at']);
    OperationFiles::json($file, $bad);
    assertOperator($operator->runOnce()['status'] === 'recovery_required', 'unstamped request cannot trigger unattended installation');
    $bad['confirmed_at'] = '2026-11-21T06:15:00Z';
    OperationFiles::json($file, $bad);

    $fail = str_repeat('0', 32);
    OperationFiles::json($requests . '/request-' . $fail . '.json', sampleRequest($fail, '2026-11-21T05:00:00Z'));
    $real = new UpdateOperator($root, $config);
    // The true CLI request consumer tries applying an impossible staged package;
    // it must durably fail before any install and refuse replay.
    $r = $real->runOnce();
    assertOperator($r['status'] === 'failed' && $r['request_id'] === $fail, 'real UpdateManager::runRequest path invoked by service');
    assertOperator(OperationFiles::read($requests . '/request-' . $fail . '.json')['state'] === 'failed', 'real request consumer persists failed terminal state');
    assertOperator($operator->runOnce()['request_id'] === $blockedNext, 'failure does not cause replay or permanently stall later approved requests');
    assertOperator(count($executed) === 3, 'one-shot consumes only approved remaining request');
    echo 'Update Execution Service regression: PASS' . PHP_EOL;
} finally {
    removeOperatorTestTree($root);
}
