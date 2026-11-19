#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Cron-safe cleanup of expired cost leases. Preview by default; --apply is explicit. */
$root=dirname(__DIR__);
require_once $root.'/core/Update/OperationFiles.php';
require_once $root.'/core/Update/OperationLock.php';
require_once $root.'/core/Console/OperationsCli.php';
if (is_file($root.'/vendor/autoload.php')) require_once $root.'/vendor/autoload.php';
else require_once $root.'/core/Console/NativeCli.php';

use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\AiCostControl;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Update\OperationLock;

try {
    $apply=in_array('--apply',array_slice($argv,1),true);
    $limit=50;
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--apply') continue;
        if (preg_match('/^--limit=([0-9]{1,3})$/D',$arg,$match))$limit=(int)$match[1];
        else throw new InvalidArgumentException('Use --limit=1..100 [--apply].');
    }
    if ($limit<1 || $limit>100)throw new InvalidArgumentException('Limit must be 1..100.');
    OperationsCli::loadEnv($root);
    if (OperationLock::maintenance($root) || is_file($root.'/storage/app/backup-restore.json'))
        throw new RuntimeException('Maintenance or restore is active.');
    $lock=OperationLock::acquire($root,$apply);
    try {
        $db=new DatabaseManager(new ConfigRepository($root));
        $pdo=$db->connection();$now=time();
        $q=$pdo->prepare('SELECT COUNT(*) FROM ce_ai_cost_reservations WHERE expires_at < ?');
        $q->execute([$now]);$pending=(int)$q->fetchColumn();
        $reconciled=$apply ? $db->transaction(fn(PDO $p)=>(new AiCostControl($db,new AiSettings($root)))->reap($p,$now,$limit)) : 0;
        echo json_encode(['preview'=>!$apply,'expired_reservations'=>$pending,'reconciled'=>$reconciled,
            'remaining_estimate'=>max(0,$pending-$reconciled),'note'=>'Expired leases are accounted as unverified estimated costs, never silently forgiven. Review provider invoices.'],
            JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
    } finally {OperationLock::release($lock);}
} catch(Throwable $error) {
    fwrite(STDERR,"[FAIL] AI cost reconciliation: ".$error->getMessage().PHP_EOL);exit(1);
}
