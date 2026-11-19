<?php
/** Production recovery protocol: actual encrypted SQLite backup, durable operator requests and timeout handling. */
declare(strict_types=1);

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Update\UpdateManager;

require dirname(__DIR__).'/core/Console/NativeCli.php';

function must(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "PASS ".$message."\n"; }
function refuses(callable $task, string $message): void {
    try { $task(); } catch (Throwable) { must(true, $message); return; }
    throw new RuntimeException('Unsafe operation accepted: '.$message);
}
$workspace=sys_get_temp_dir().'/cajeer-187-'.bin2hex(random_bytes(12));
F::dir($workspace);
try {
    $project=dirname(__DIR__); $site=$workspace.'/site';
    F::dir($site.'/config'); F::dir($site.'/storage/database');
    foreach (['database','storage','queue'] as $config) F::copy($project.'/config/'.$config.'.php',$site.'/config/'.$config.'.php');
    F::write($site.'/VERSION',"1.8.7\n",0644);
    F::write($site.'/.env',"APP_KEY=deployment-recovery-test\n",0600);
    F::write($site.'/storage/app/content/record.json','{"before":true}');
    putenv('DB_DRIVER=sqlite'); putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');
    putenv('STORAGE_DRIVER=local'); putenv('STORAGE_LOCAL_PATH=storage/app'); putenv('QUEUE_DRIVER=file');
    if (!in_array('sqlite',PDO::getAvailableDrivers(),true) && !getenv('BACKUP_SQLITE_PYTHON')) {
        foreach (['/usr/local/bin/python3','/usr/bin/python3'] as $python) if (is_executable($python)) { putenv('BACKUP_SQLITE_PYTHON='.$python); break; }
    }
    $db=$site.'/storage/database/cajeer.sqlite';
    DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON') ?: '/usr/bin/python3','-I','-c',
        'import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); c.execute("CREATE TABLE proof (value TEXT)"); c.execute("INSERT INTO proof VALUES (?)",("before",)); c.commit()', $db]);
    F::write($workspace.'/backup.key',base64_encode(random_bytes(32))); putenv('BACKUP_KEY_FILE='.$workspace.'/backup.key');
    $config=new ConfigRepository($site);
    $backup=new BackupManager($site,$config);
    $manager=new UpdateManager($site,$config);
    $request=$manager->request('backup',['confirm'=>true],'operator');
    $result=$manager->runRequest($request['id']);
    must(($result['ok']??false) && $backup->verify($result['id'])['ok'], 'Operator request creates and verifies an actual database backup');
    $stored=F::read($site.'/storage/app/update-requests/request-'.$request['id'].'.json');
    must(($stored['state']??null)==='completed' && isset($stored['started_at'],$stored['finished_at']), 'Operator request durably records its lifecycle');
    refuses(fn()=>$manager->runRequest($request['id']), 'Completed request is not replayed');
    $bundle=$workspace.'/isolated.cebackup';
    $backup->exportBundle($result['id'],$bundle);
    must($backup->drill($bundle,$workspace.'/drill',300,3600)['ok'], 'Encrypted offsite recovery drill restores SQLite in isolation');
    F::write($site.'/storage/app/content/record.json','{"before":false}');
    $lease=OperationLock::acquire($site,true);
    try { $backup->restoreCaptured($result['id']); } finally { OperationLock::release($lease); }
    must(file_get_contents($site.'/storage/app/content/record.json')==='{"before":true}', 'Verified recovery restores original content');
    $bad=$manager->request('backup',['confirm'=>true],'operator');
    chmod($workspace.'/backup.key',0644);
    refuses(fn()=>$manager->runRequest($bad['id']),'Invalid backup-key permissions reject the request');
    $stored=F::read($site.'/storage/app/update-requests/request-'.$bad['id'].'.json');
    must($stored['state']==='failed'&&!isset($stored['exception']), 'Failed request records terminal state without leaking secrets');
    refuses(fn()=>$manager->runRequest($bad['id']),'Failed request is not automatically replayed');
    chmod($workspace.'/backup.key',0600);
    $interrupted=$manager->request('backup',['confirm'=>true],'operator');
    $record=$site.'/storage/app/update-requests/request-'.$interrupted['id'].'.json';
    $state=F::read($record); $state['state']='running'; F::json($record,$state);
    refuses(fn()=>$manager->runRequest($interrupted['id']), 'Interrupted operator request requires manual reconciliation');
    try {
        DatabaseBackup::run([PHP_BINARY,'-r','usleep(2000000);'],[],null,null,1);
        throw new RuntimeException('Timed-out backup subprocess incorrectly accepted');
    } catch (RuntimeException $e) {
        must(str_contains($e->getMessage(),'timed out after 1 seconds'), 'Hung backup subprocess is terminated at its deadline');
    }
    DatabaseBackup::run([PHP_BINARY,'-r','exit(0);'],[],null,null,5);
    must(true,'Subsequent subprocess executes successfully');
    $processLease=$workspace.'/process.lock';
    DatabaseBackup::useProcessLock($processLease);
    try { DatabaseBackup::run([PHP_BINARY,'-r','exit(0);'],[],null,null,5); }
    finally { DatabaseBackup::useProcessLock(null); }
    must(true,'Database subprocess respects the inherited recovery lease');
    echo "Deployment and recovery acceptance passed (real SQLite, encrypted bundle, operator journal, command timeout)\n";
} finally {
    // The fixture is independent of the checkout and cannot remove project files.
    CajeerEngine\Backup\RecoveryBundle::cleanup($workspace);
}
