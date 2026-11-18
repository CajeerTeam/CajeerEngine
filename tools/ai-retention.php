#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Run from the CMS host/cron. Defaults to preview; --apply explicitly deletes data. */
$root = dirname(__DIR__);
require_once $root.'/core/Update/OperationFiles.php';
require_once $root.'/core/Update/OperationLock.php';
require_once $root.'/core/Console/OperationsCli.php';
$autoload = $root.'/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
else require_once $root.'/core/Console/NativeCli.php';

use CajeerEngine\AI\AiDataRetention;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Update\OperationLock;

try {
    $days = 90; $batch = 100; $apply = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') $apply = true;
        elseif (preg_match('/^--days=([0-9]{1,4})$/D', $argument, $m)) $days = (int)$m[1];
        elseif (preg_match('/^--batch=([0-9]{1,3})$/D', $argument, $m)) $batch = (int)$m[1];
        else throw new InvalidArgumentException('Unknown argument. Use --days=N --batch=N [--apply].');
    }
    OperationsCli::loadEnv($root);
    if (OperationLock::maintenance($root) || is_file($root.'/storage/app/backup-restore.json'))
        throw new RuntimeException('Maintenance or recovery is active; AI retention is disabled.');
    $lock = OperationLock::acquire($root, $apply);
    try {
        $result = (new AiDataRetention(new DatabaseManager(new ConfigRepository($root))))->run($days, $batch, $apply);
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    } finally { OperationLock::release($lock); }
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] AI retention: '.$error->getMessage().PHP_EOL);
    exit(1);
}
