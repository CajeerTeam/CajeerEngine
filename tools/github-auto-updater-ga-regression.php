#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\GitHubEndpoints;
use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\UpdateManager;
use CajeerEngine\Update\UpdateOperator;

function gaAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}
function gaReject(callable $callback, string $message): void
{
    try { $callback(); }
    catch (RuntimeException) { gaAssert(true, $message); return; }
    throw new RuntimeException('FAIL: expected rejection: ' . $message);
}
function gaClean(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new FilesystemIterator($dir) as $file) {
        if ($file->isDir() && !$file->isLink()) gaClean($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($dir);
}
$root = sys_get_temp_dir() . '/ce-ga-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
file_put_contents($root . '/VERSION', "2.0.7\n");
$config = new ConfigRepository($root);
$config->set('updates', [
    'core' => ['channel' => 'stable', 'releases_url' => GitHubEndpoints::RELEASES_URL],
    'operator' => ['enabled' => true, 'max_heartbeat_age_seconds' => 180],
    'web_upgrade' => ['enabled' => true],
]);
$manager = new UpdateManager($root, $config);
$statusFile = $root . '/storage/app/updates/operator-status.json';
$write = static function (string $state, string $when) use ($statusFile): void {
    OperationFiles::json($statusFile, ['ok' => $state !== 'failed', 'status' => $state,
        'checked_at' => $when, 'processed' => 0], 0644);
};
try {
    $missing = UpdateOperator::health($root, $config);
    gaAssert($missing['ready'] === false && $missing['reason'] === 'heartbeat_missing', 'configured but stopped operator is not healthy');
    gaAssert($manager->wizardState()['operator_ready'] === false, 'admin wizard rejects missing systemd timer heartbeat');

    $write('idle', gmdate(DATE_ATOM, time() - 400));
    gaAssert(UpdateOperator::health($root, $config)['reason'] === 'heartbeat_stale', 'expired heartbeat refuses queue');
    $write('idle', gmdate(DATE_ATOM, time() + 360));
    gaAssert(UpdateOperator::health($root, $config)['reason'] === 'heartbeat_invalid', 'future heartbeat rejected');
    $write('running', gmdate(DATE_ATOM));
    gaAssert(UpdateOperator::health($root, $config)['reason'] === 'operator_busy', 'running operator refuses another approval');
    $write('recovery_required', gmdate(DATE_ATOM));
    gaAssert(UpdateOperator::health($root, $config)['reason'] === 'recovery_required', 'recovery state refuses another approval');
    $write('idle', gmdate(DATE_ATOM));
    gaAssert(UpdateOperator::health($root, $config)['ready'] === true, 'fresh idle service heartbeat enables panel');
    gaAssert($manager->wizardState()['operator_ready'] === true, 'wizard reads same readiness result');

    OperationFiles::json($root . '/storage/app/updates/active.json', ['id' => 'incomplete']);
    gaAssert(UpdateOperator::health($root, $config)['reason'] === 'recovery_marker_present', 'active recovery blocks even fresh heartbeat');
    unlink($root . '/storage/app/updates/active.json');

    $config->set('updates.web_upgrade.enabled', false);
    gaAssert(UpdateOperator::health($root, $config)['reason'] === 'operator_disabled', 'kill switch overrides heartbeat');
    $config->set('updates.web_upgrade.enabled', true);
    unlink($statusFile);
    $sentinel = $root . '/sentinel'; file_put_contents($sentinel, 'do not follow');
    symlink($sentinel, $statusFile);
    gaAssert(UpdateOperator::health($root, $config)['ready'] === false, 'symlink status never constitutes liveness');
    unlink($statusFile);
    gaAssert(file_get_contents($sentinel) === 'do not follow', 'operator liveness check never writes through symlink');
    $write('idle', gmdate(DATE_ATOM));

    $stagedId = str_repeat('a', 32);
    $stage = ['ok'=>true, 'id'=>$stagedId, 'version'=>'2.0.7', 'sha256'=>str_repeat('b',64)];
    $requests = $root . '/storage/app/update-requests';
    OperationFiles::dir($requests);
    OperationFiles::json($requests . '/staged.json', $stage);
    gaAssert(!$manager->wizardState()['staged_installable'], 'same-version stage is not installable');
    gaReject(static fn() => $manager->request('apply', ['confirm'=>true, 'package'=>$stagedId,'sha256'=>$stage['sha256']], 'admin'), 'old stage cannot be queued');
    $stage['version'] = '2.1.0';
    OperationFiles::json($requests . '/staged.json', $stage);
    gaAssert(!$manager->wizardState()['staged_installable'], 'missing signed files disable installation');
    $inbox = $root . '/storage/app/updates/inbox/' . $stagedId;
    OperationFiles::dir($inbox);
    file_put_contents($inbox . '/package.zip', 'fake');
    file_put_contents($inbox . '/package.zip.sig', 'fake');
    gaAssert($manager->wizardState()['staged_installable'], 'newer stage with existing signed files is eligible for operator re-verification');
    // Approval does not assert package authenticity: runRequest() re-verifies in CLI.
    $stage['version'] = '2.0.7';
    OperationFiles::json($requests . '/staged.json', $stage);
    $eligible = ['update_available'=>true, 'metadata_status'=>'fresh', 'metadata_source'=>GitHubEndpoints::RELEASES_URL,
        'checked_at'=>gmdate(DATE_ATOM), 'latest'=>['version'=>'2.1.0']];
    OperationFiles::json($root . '/storage/app/updates/last-check.json', $eligible);
    $request = $manager->request('download', ['confirm'=>true, 'version'=>'2.1.0'], 'admin');
    gaAssert(($request['state'] ?? null) === 'pending' && ($request['action'] ?? null) === 'download', 'real approved download enters durable queue');
    gaReject(static fn() => $manager->request('backup',['confirm'=>true],'other-admin'), 'concurrent approvals rejected');
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $calls = 0;
        $operator = new UpdateOperator($root, $config, static function (string $id) use ($root, &$calls): array {
            ++$calls;
            $path = $root . '/storage/app/update-requests/request-' . $id . '.json';
            $r = OperationFiles::read($path);
            gaAssert($r['state'] === 'pending', 'operator consumes pending approval');
            $r['state'] = 'completed'; $r['finished_at'] = gmdate(DATE_ATOM);
            OperationFiles::json($path, $r, 0640);
            return ['ok'=>true, 'id'=>'mock-verified-download'];
        });
        $result = $operator->runOnce();
        gaAssert($result['status'] === 'completed' && $result['request_id'] === $request['id'], 'background operator completes one approved job');
        gaAssert($operator->runOnce()['status'] === 'idle' && $calls === 1, 'completed job not repeated on next timer tick');
        gaAssert(UpdateOperator::health($root, $config)['ready'], 'real operator run refreshes readiness heartbeat');
    } else {
        echo "SKIP non-root operator execution (must also run under a dedicated Linux user)\n";
    }
    echo "GitHub Auto-Updater GA regression: PASS\n";
} finally { gaClean($root); }
