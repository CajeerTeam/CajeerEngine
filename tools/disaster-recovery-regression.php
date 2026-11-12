<?php
declare(strict_types=1);
use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
require dirname(__DIR__).'/core/Console/NativeCli.php';
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function reject(callable $call,string $message): void { try { $call(); } catch (Throwable) { check(true,$message); return; } throw new RuntimeException('Accepted: '.$message); }
$work=sys_get_temp_dir().'/ce-dr-test-'.bin2hex(random_bytes(12)); F::dir($work);
try {
    $root=$work.'/site'; F::dir($root.'/storage/database'); F::dir($root.'/config');
    $driver=in_array('--pgsql',$argv,true)?'pgsql':'sqlite';
    if ($driver==='pgsql'&&(getenv('DB_DATABASE')!=='cajeer_backup_test'||getenv('CE_BACKUP_DB_TEST')!=='1')) throw new RuntimeException('Explicit disposable PostgreSQL database required');
    putenv('DB_DRIVER='.$driver); putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite'); putenv('STORAGE_DRIVER=local'); putenv('STORAGE_LOCAL_PATH=storage/app'); putenv('QUEUE_DRIVER=file');
    if (!in_array('sqlite',PDO::getAvailableDrivers(),true)&&!getenv('BACKUP_SQLITE_PYTHON')) foreach (['/usr/bin/python3','/usr/local/bin/python3'] as $p) if (is_executable($p)) { putenv('BACKUP_SQLITE_PYTHON='.$p); break; }
    foreach (['database','storage','queue'] as $name) F::copy(dirname(__DIR__).'/config/'.$name.'.php',$root.'/config/'.$name.'.php');
    F::write($root.'/VERSION',"1.2.4\n"); F::write($root.'/.env',"APP_KEY=private-dr-secret\n");
    F::write($work.'/key',base64_encode(random_bytes(32))); putenv('BACKUP_KEY_FILE='.$work.'/key');
    F::write($root.'/public/uploads/media.bin',random_bytes(2*1048576+9)); F::write($root.'/storage/app/content/entry.json','original'); F::write($root.'/storage/queue/job.json','pending'); F::write($root.'/public/uploads/empty','');
    $dbpath=$root.'/storage/database/cajeer.sqlite';
    $sql=static function(string $statement,bool $query=false) use ($dbpath,$root,$driver,$work): string {
        if ($driver==='pgsql') { $pdo=(new CajeerEngine\Database\DatabaseManager(new ConfigRepository($root)))->connection(); return $query?(string)$pdo->query($statement)->fetchColumn():(string)$pdo->exec($statement); }
        if (in_array('sqlite',PDO::getAvailableDrivers(),true)) { $pdo=new PDO('sqlite:'.$dbpath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); return $query?(string)$pdo->query($statement)->fetchColumn():(string)$pdo->exec($statement); }
        DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c','import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); q=c.execute(sys.argv[2]) if sys.argv[3]=="1" else c.executescript(sys.argv[2]); print(q.fetchone()[0] if sys.argv[3]=="1" else "ok"); c.commit(); c.close()',$dbpath,$statement,$query?'1':'0'],[],$work.'/sql.txt'); return trim(file_get_contents($work.'/sql.txt'));
    };
    $sql('CREATE TABLE ce_dr_fixture (id INTEGER PRIMARY KEY, val VARCHAR(100)); INSERT INTO ce_dr_fixture VALUES (1,\'before\');');
    $manager=new BackupManager($root,new ConfigRepository($root)); $backup=$manager->create(); $id=$backup['id']; $bundle=$work.'/offsite.cebackup';
    $manager->exportBundle($id,$bundle); check((fileperms($bundle)&0777)===0600&&!str_contains(file_get_contents($bundle),'private-dr-secret'),'encrypted offsite bundle, private mode');
    reject(fn()=>$manager->exportBundle($id,$bundle),'no overwrite');
    reject(fn()=>$manager->exportBundle($id,$root.'/internal.bundle'),'offsite path required');
    $sql("UPDATE ce_dr_fixture SET val='after'"); F::write($root.'/storage/app/content/entry.json','changed');
    $drill=$manager->drill($bundle,$work.'/drill',300,3600); check($drill['ok']&&$drill['database']['tables_read']>=1&&$drill['restored_files']>=4&&!is_dir($work.'/drill'),'real isolated restore and cleanup');
    check($sql('SELECT val FROM ce_dr_fixture',true)==='after'&&file_get_contents($root.'/storage/app/content/entry.json')==='changed','live database and files unchanged by drill');
    $raw=file_get_contents($bundle); F::write($work.'/bad',substr($raw,0,-1)); reject(fn()=>$manager->importBundle($work.'/bad'),'truncated bundle rejected');
    $raw[100]=chr(ord($raw[100])^1); F::write($work.'/bad',$raw); reject(fn()=>$manager->importBundle($work.'/bad'),'tampering rejected');
    F::write($work.'/bad',file_get_contents($bundle).'x'); reject(fn()=>$manager->importBundle($work.'/bad'),'trailing bytes rejected');
    F::write($work.'/wrong',base64_encode(random_bytes(32))); putenv('BACKUP_KEY_FILE='.$work.'/wrong'); reject(fn()=>$manager->importBundle($bundle),'wrong key rejected'); putenv('BACKUP_KEY_FILE='.$work.'/key');
    sleep(2);
    $expired=$manager->drill($bundle,$work.'/expired-drill',300,1); check(!$expired['ok']&&$expired['snapshot_age_seconds']>1&&!is_dir($work.'/expired-drill'),'RPO budget failure is reported and cleaned');
    check($manager->importBundle($bundle)['already_present'],'idempotent import');
    RecoveryBundle::cleanup($backup['target']); check($manager->list()['backups']===[],'local backup destroyed');
    // Lose the entire installation, not merely the backup directory. Bootstrap only config and the empty database parent.
    RecoveryBundle::cleanup($root); F::dir($root.'/storage/database'); F::dir($root.'/config');
    foreach (['database','storage','queue'] as $name) F::copy(dirname(__DIR__).'/config/'.$name.'.php',$root.'/config/'.$name.'.php');
    $manager=new BackupManager($root,new ConfigRepository($root));
    check(!$manager->importBundle($bundle)['already_present'],'cold import published verified backup on lost installation');
    check($manager->verify($id)['ok'],'imported backup verifies');
    $lease=\CajeerEngine\Update\OperationLock::acquire($root,true);
    try { $manager->restoreCaptured($id); } finally { \CajeerEngine\Update\OperationLock::release($lease); } // full postcheck is exercised by dist smoke.
    check($sql('SELECT val FROM ce_dr_fixture',true)==='before'&&file_get_contents($root.'/storage/app/content/entry.json')==='original'&&file_get_contents($root.'/.env')==="APP_KEY=private-dr-secret\n"&&filesize($root.'/public/uploads/media.bin')===2*1048576+9&&file_get_contents($root.'/storage/queue/job.json')==='pending','actual data, media/queue inventory and environment restored');
    echo "Disaster recovery regression passed ($driver)\n";
} finally { RecoveryBundle::cleanup($work); }
