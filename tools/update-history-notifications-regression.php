#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\UpdateHistory;
use CajeerEngine\Update\UpdateNotificationPublisher;
use CajeerEngine\Integration\WebhookRepository;

function expectHistory(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}
function cleanHistory(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) cleanHistory($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
}
$root = sys_get_temp_dir().'/cajeer-update-events-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
try {
    $history = new UpdateHistory($root);
    expectHistory($history->recent() === [], 'empty history returns an empty list');
    $one=$history->record('update.available',['channel'=>'stable','version'=>'2.0.6','ignored_secret'=>'never persist'], 'release:stable:2.0.6');
    $two=$history->record('update.available',['channel'=>'stable','version'=>'2.0.6'], 'release:stable:2.0.6');
    expectHistory($one['id']===$two['id'] && count($history->recent())===1, 'repeated checks notify once per release');
    expectHistory(!isset($one['details']['ignored_secret']) && !str_contains(json_encode($one),'never persist'), 'untrusted and sensitive fields not included in audit');
    $before=$history->notifications('admin-a');
    expectHistory($before['unread_count']===1 && $before['items'][0]['read']===false, 'new release has an unread notification');
    $history->markRead('admin-a');
    expectHistory($history->notifications('admin-a')['unread_count']===0 && $history->notifications('admin-b')['unread_count']===1, 'read state is isolated per authenticated administrator');
    $history->record('update.failed',['request_id'=>str_repeat('1',32),'reason_code'=>'operation_failed'],'request:1:failed');
    expectHistory($history->notifications('admin-a')['unread_count']===1, 'failure triggers a new notification after read acknowledgement');

    $requestId=str_repeat('b',32);
    $path=OperationFiles::path($root,'storage/app/update-requests/request-'.$requestId.'.json');
    OperationFiles::json($path,['id'=>$requestId,'action'=>'download','actor'=>'admin-a','state'=>'completed','version'=>'2.0.7',
      'requested_at'=>gmdate(DATE_ATOM), 'confirmed_at'=>gmdate(DATE_ATOM), 'started_at'=>gmdate(DATE_ATOM), 'finished_at'=>gmdate(DATE_ATOM)]);
    $history->reconcile(); $initial=count($history->recent());
    $history->reconcile();
    expectHistory(count($history->recent())===$initial && count(array_filter($history->recent(), fn($r)=>$r['event']==='update.downloaded'))===1, 'reconciliation of completed requests is idempotent');

    $tx='20261121-084000-'.str_repeat('a',16);
    OperationFiles::json(OperationFiles::path($root,'storage/app/updates/last-update.json'),[
      'id'=>$tx,'ok'=>true,'state'=>'committed','previous_version'=>'2.0.5','target_version'=>'2.0.6','sha256'=>str_repeat('c',64)]);
    $history->reconcile();
    expectHistory(count(array_filter($history->recent(),fn($r)=>$r['event']==='update.installed'))===1, 'committed transaction is restored to history after interrupted event emission');

    $publisher=new UpdateNotificationPublisher($root,new WebhookRepository($root));
    $first=$publisher->dispatch(); $again=$publisher->dispatch();
    expectHistory($first['queued']>=3 && $again['queued']===0,'notification publisher durably queues events once');
    $outbox=(new WebhookRepository($root))->outbox(100);
    $all=(new WebhookRepository($root))->outbox(100);
    expectHistory(count($all)===count(array_unique(array_column($all,'id'))) && count($outbox)>0, 'signed webhook outbox has distinct stable event IDs');

    $page=$history->recent(2);
    $next=$history->recent(50,$page[1]['id']);
    expectHistory(count($page)===2 && count($next)>0 && $page[1]['id']!==$next[0]['id'], 'cursor pagination uses stable event IDs');
    $rejected=false;
    try{$history->recent(20,'../../etc/passwd');}catch(InvalidArgumentException){$rejected=true;}
    expectHistory($rejected,'unsafe history cursor rejected');
    $rejected=false;
    try{$history->record('update.available',['version'=>"bad\nsecret"]);}catch(InvalidArgumentException){$rejected=true;}
    expectHistory($rejected,'control characters are excluded from audit fields');
    $eventDir=OperationFiles::path($root,'storage/app/updates/history/events');
    symlink('/etc/passwd',$eventDir.'/'.str_repeat('f',40).'.json');
    $rejected=false;
    try{$history->recent();}catch(RuntimeException){$rejected=true;}
    expectHistory($rejected,'history refuses symlinked event records');
    echo 'Update history and notifications regression: PASS'.PHP_EOL;
} finally { cleanHistory($root); }
