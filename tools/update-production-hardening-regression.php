#!/usr/bin/env php
<?php
/** Production updater fault injection against actual filesystem and request code. */
declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\GitHubAssetTransport;
use CajeerEngine\Update\OperationFiles as Files;
use CajeerEngine\Update\OperationLock as Locks;
use CajeerEngine\Update\UpdateManager;
use CajeerEngine\Update\UpdateOperator;

function productionCheck(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}
function productionReject(Closure $operation, string $message): void
{
    try { $operation(); } catch (Throwable) { productionCheck(true, $message); return; }
    throw new RuntimeException('FAIL expected rejection: ' . $message);
}
function productionClean(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) productionClean($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
}
function productionRequest(string $id, string $action = 'backup'): array
{
    $time = gmdate(DATE_ATOM);
    $request = ['ok' => true, 'id' => $id, 'state' => 'pending', 'action' => $action,
        'actor' => 'admin-integration', 'requested_at' => $time, 'confirmed_at' => $time];
    if ($action === 'apply') { $request['stage'] = str_repeat('a', 32); $request['sha256'] = str_repeat('b', 64); }
    if ($action === 'download') $request['version'] = '2.0.7';
    if ($action === 'rollback') $request['transaction'] = '20261121-070000-' . str_repeat('c', 16);
    return $request;
}

$root = sys_get_temp_dir() . '/cajeer-update-hardening-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
try {
    $requests = $root . '/storage/app/update-requests';
    Files::dir($requests, 0700);
    Files::dir($root . '/storage/app/updates', 0700);
    Files::write($root . '/VERSION', "2.0.6\n");
    $conf = new ConfigRepository($root);
    $conf->set('updates', ['web_upgrade' => ['enabled' => true], 'operator' => ['enabled' => true]]);
    $manager = new UpdateManager($root, $conf);

    // Reject directory traversal before a new child is created. This also
    // protects backup, restore and package extraction destinations.
    Files::dir($root . '/safe/sub');
    $outside = $root . '/outside';
    Files::dir($outside);
    symlink($outside, $root . '/safe/jump');
    productionReject(static fn () => Files::dir($root . '/safe/jump/created'), 'symlink in directory ancestor blocks writes');
    productionCheck(!file_exists($outside . '/created'), 'symlink target was not modified');
    productionReject(static fn () => Files::write($root . '/safe/jump/evil.txt', 'payload'), 'atomic write refuses symlink ancestor');
    productionReject(static fn () => Files::copy($root . '/VERSION', $root . '/safe/jump/evil.txt'), 'atomic copy refuses symlink ancestor');
    productionCheck(!file_exists($outside . '/evil.txt'), 'forbidden target remains untouched');

    // Existing lock files must be checked, not implicitly opened with c+.
    $operations = $root . '/storage/app/operations.lock';
    $sentinel = $root . '/sentinel';
    Files::write($sentinel, 'unchanged', 0600);
    symlink($sentinel, $operations);
    productionReject(static fn () => Locks::acquire($root, true), 'operation lock cannot point at a symlink');
    unlink($operations);
    link($sentinel, $operations);
    productionReject(static fn () => Locks::acquire($root, true), 'hard-linked operation lock is rejected');
    unlink($operations);
    Files::write($operations, 'lock', 0666);
    productionReject(static fn () => Locks::acquire($root, false), 'world-writable operation lock is rejected');
    unlink($operations);
    productionCheck(file_get_contents($sentinel) === 'unchanged', 'malicious lock targets were not overwritten');
    $shared = Locks::acquire($root, false);
    productionReject(static fn () => Locks::acquire($root, true), 'exclusive update cannot bypass shared active lease');
    Locks::release($shared);
    $exclusive = Locks::acquire($root, true);
    productionReject(static fn () => Locks::acquire($root, false), 'HTTP-like shared lease cannot bypass active update');
    Locks::release($exclusive);

    // Direct CLI consumers must not bypass approval validation. Invalid
    // requests remain pending, with no side effect or active recovery marker.
    foreach ([
        'missing approval' => static function (array &$r): void { unset($r['confirmed_at']); },
        'fake approval text' => static function (array &$r): void { $r['confirmed_at'] = 'tomorrow'; },
        'approval predates request' => static function (array &$r): void { $r['confirmed_at'] = '2020-01-01T00:00:00Z'; },
        'approval in future' => static function (array &$r): void { $r['confirmed_at'] = gmdate(DATE_ATOM, time() + 3600); },
        'untrusted actor' => static function (array &$r): void { $r['actor'] = "root\nidentity"; },
        'unknown action' => static function (array &$r): void { $r['action'] = 'command'; },
        'invalid sha' => static function (array &$r): void { $r['action'] = 'apply'; $r['stage'] = str_repeat('a', 32); $r['sha256'] = '../attack'; },
    ] as $label => $mutate) {
        $id = bin2hex(random_bytes(16));
        $request = productionRequest($id);
        $mutate($request);
        $path = $requests . '/request-' . $id . '.json';
        Files::json($path, $request, 0640);
        productionReject(static fn () => $manager->runRequest($id), 'direct CLI refuses ' . $label);
        productionCheck(Files::read($path)['state'] === 'pending' && !file_exists($root . '/storage/app/updates/active.json'), 'rejected request has no update side effect');
        unlink($path);
    }

    // The transport is only allowed to receive canonical GitHub release URLs,
    // even when a remote service offers an unexpected redirect target.
    foreach (['http://github.com/cajeerteam/cajeerengine/releases/download/v2.0.7/x',
        'https://127.0.0.1/a', 'https://evil.invalid/a', 'https://github.com/other/repo/releases/download/v1/x',
        'https://github.com:8443/cajeerteam/cajeerengine/releases/download/v1/x',
        'https://user@github.com/cajeerteam/cajeerengine/releases/download/v1/x'] as $url) {
        productionReject(static fn () => GitHubAssetTransport::validateUrl($url), 'transport refuses untrusted URL ' . parse_url($url, PHP_URL_HOST));
    }
    GitHubAssetTransport::validateUrl('https://github.com/cajeerteam/cajeerengine/releases/download/v2.0.7/cajeerengine-2.0.7-dist.zip');
    productionCheck(true, 'canonical HTTPS production asset remains permitted');

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && PHP_OS_FAMILY === 'Linux') {
        $id = bin2hex(random_bytes(16));
        $path = $requests . '/request-' . $id . '.json';
        Files::json($path, productionRequest($id), 0640);
        $runs = 0;
        $worker = new UpdateOperator($root, $conf, static function (string $id) use (&$runs): array {
            ++$runs;
            // Deliberately return a lie: no durable success journal exists.
            return ['ok' => true, 'id' => 'fake-transaction'];
        });
        $result = $worker->runOnce();
        productionCheck($result['status'] === 'recovery_required', 'operator rejects uncommitted callback success');
        productionCheck(Files::read($path)['state'] === 'running', 'ambiguous execution is marked for manual recovery');
        productionCheck($worker->runOnce()['status'] === 'recovery_required' && $runs === 1, 'operator never replays ambiguous operation');
        unlink($path);
        $throwId = bin2hex(random_bytes(16));
        $throwPath = $requests . '/request-' . $throwId . '.json';
        Files::json($throwPath, productionRequest($throwId), 0640);
        $throws = 0;
        $crasher = new UpdateOperator($root, $conf, static function (string $id) use (&$throws): array {
            ++$throws;
            throw new RuntimeException('simulated worker crash before terminal commit');
        });
        productionCheck($crasher->runOnce()['status'] === 'recovery_required', 'exception before terminal commit requires recovery');
        productionCheck(Files::read($throwPath)['state'] === 'running', 'crashed request is durably quarantined');
        productionCheck($crasher->runOnce()['status'] === 'recovery_required' && $throws === 1, 'timer cannot replay interrupted request');
        unlink($throwPath);
        unlink($root . '/storage/app/updates/operator.lock');
        symlink($sentinel, $root . '/storage/app/updates/operator.lock');
        productionReject(static fn () => $worker->runOnce(), 'operator refuses symlinked lease');
        productionCheck(file_get_contents($sentinel) === 'unchanged', 'operator does not write through lease symlink');
    } else {
        echo 'SKIP non-root operator subprocess tests (CI executes these with an unprivileged runner)' . PHP_EOL;
    }
    echo 'Update production hardening: PASS' . PHP_EOL;
} finally {
    productionClean($root);
}
