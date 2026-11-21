#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\GitHubEndpoints;
use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\UpdateManager;
use CajeerEngine\Update\UpdateOperator;

function assertCenter(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $reason);
    echo 'PASS ' . $reason . PHP_EOL;
}
function cleanCenter(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new FilesystemIterator($dir) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) cleanCenter($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($dir);
}
function refusesCenter(callable $fn, string $message): void
{
    try { $fn(); } catch (RuntimeException) { assertCenter(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$root = sys_get_temp_dir() . '/ce-center-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
file_put_contents($root . '/VERSION', "2.0.3\n");
$config = new ConfigRepository($root);
$config->set('updates', ['core'=> ['channel'=>'stable', 'releases_url'=>GitHubEndpoints::RELEASES_URL],
    'operator'=> ['enabled'=>true], 'web_upgrade'=> ['enabled'=>true]]);
$manager = new UpdateManager($root, $config);
try {
    $approved = static fn (string $version): array => [
        'update_available'=>true, 'metadata_status'=>'fresh', 'metadata_source'=>GitHubEndpoints::RELEASES_URL,
        'checked_at'=>gmdate(DATE_ATOM), 'latest'=>['version'=>$version],
    ];
    $path = $root . '/storage/app/updates/last-check.json';
    OperationFiles::dir(dirname($path));
    OperationFiles::json($path, $approved('2.0.4'));
    refusesCenter(fn () => $manager->request('download',['version'=>'2.0.4'],'admin'), 'explicit approval required');
    refusesCenter(fn () => $manager->request('download',['confirm'=>true,'version'=>'2.0.5'],'admin'), 'unadvertised release rejected');
    refusesCenter(fn () => $manager->request('download',['confirm'=>true,'version'=>'../2.0.4'],'admin'), 'unsafe version rejected');
    $stale = $approved('2.0.4'); $stale['checked_at']=gmdate(DATE_ATOM,time()-3700);
    OperationFiles::json($path, $stale);
    refusesCenter(fn () => $manager->request('download',['confirm'=>true,'version'=>'2.0.4'],'admin'), 'stale release check rejected');
    OperationFiles::json($path, $approved('2.0.4'));
    $request = $manager->request('download',['confirm'=>true,'version'=>'2.0.4'],'admin-a');
    assertCenter(($request['action']??'')==='download' && ($request['state']??'')==='pending' && isset($request['confirmed_at']), 'approval produces durable queued download');
    $listed = $manager->recentRequests();
    assertCenter(count($listed)===1 && $listed[0]['id']===$request['id'] && !isset($listed[0]['stage']), 'browser status has only bounded public-safe request fields');
    refusesCenter(fn () => $manager->request('download',['confirm'=>true,'version'=>'2.0.4'],'admin-b'), 'duplicate installation/download queue rejected');
    if (function_exists('posix_geteuid') && posix_geteuid()===0) {
        echo 'SKIP worker execution: run this regression as non-root to exercise the operator';
        echo PHP_EOL;
    } else {
        $executions = [];
        $service = new UpdateOperator($root, $config, static function (string $id) use ($root, &$executions): array {
            $record = OperationFiles::read($root . '/storage/app/update-requests/request-' . $id . '.json');
            assertCenter($record['action']==='download' && $record['version']==='2.0.4', 'operator consumes validated download request');
            $record['state'] = 'completed'; $record['finished_at'] = gmdate(DATE_ATOM);
            OperationFiles::json($root . '/storage/app/update-requests/request-' . $id . '.json', $record);
            $executions[] = $id;
            return ['ok'=>true,'id'=>'mock-signed-stage'];
        });
        $result = $service->runOnce();
        assertCenter(($result['status']??'')==='completed' && $result['request_id']===$request['id'], 'one-shot operator completes queued download');
        assertCenter(count($executions)===1 && $service->runOnce()['status']==='idle', 'download request never replays');
        // Exercise the genuine CLI runRequest dispatch, not just an injected
        // callback: an invalid server catalog must fail from the downloader,
        // never from an 'Unknown request' branch.
        $second = $manager->request('download', ['confirm'=>true, 'version'=>'2.0.4'], 'admin-a');
        $config->set('updates.core.releases_url', 'https://invalid.example/releases');
        $denied = false;
        try { $manager->runRequest($second['id']); }
        catch (RuntimeException $e) { $denied = str_contains($e->getMessage(), 'canonical GitHub release catalog'); }
        assertCenter($denied, 'real CLI dispatcher reaches secure GitHub downloader');
        assertCenter(OperationFiles::read($root.'/storage/app/update-requests/request-'.$second['id'].'.json')['state']==='failed', 'real downloader failure persists terminal failure');
    }
    $state = $manager->wizardState();
    assertCenter($state['operator_ready']===true && $state['current_version']==='2.0.3', 'admin state exposes operator readiness and installed version');
    assertCenter($manager->downloaded()===[], 'no unsigned artifact is advertised as verified download');
    echo 'Admin Update Center regression: PASS' . PHP_EOL;
} finally { cleanCenter($root); }
