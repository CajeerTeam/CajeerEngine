<?php
/** Actual SQLite recovery and referential integrity acceptance with PDO or isolated Python. */
declare(strict_types=1);

require dirname(__DIR__).'/core/Console/NativeCli.php';

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as Files;
use CajeerEngine\Update\OperationLock;

function expect(bool $valid, string $label): void {
    if (!$valid) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS '.$label."\n";
}
function mustReject(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable $e) { expect(true,$label); return; }
    throw new RuntimeException('FAIL: '.$label.' was accepted');
}
$python=(string)(getenv('BACKUP_SQLITE_PYTHON')?:'');
if ($python==='' || !is_executable($python)) {
    foreach (['/usr/local/bin/python3','/usr/bin/python3'] as $candidate) {
        if (is_executable($candidate)) { $python=$candidate; break; }
    }
}
if (!in_array('sqlite',PDO::getAvailableDrivers(),true) && ($python==='' || !is_executable($python))) {
    throw new RuntimeException('Acceptance requires PDO SQLite or an executable Python 3 SQLite fallback');
}
$work=sys_get_temp_dir().'/ce-recovery-integrity-'.bin2hex(random_bytes(12));
Files::dir($work);
try {
    $root=$work.'/site';
    Files::dir($root.'/config'); Files::dir($root.'/storage/database');
    foreach (['database','queue','storage'] as $file) {
        Files::copy(dirname(__DIR__).'/config/'.$file.'.php',$root.'/config/'.$file.'.php');
    }
    Files::write($root.'/VERSION',"1.9.8\n",0644);
    Files::write($root.'/.env',"APP_KEY=dr-fixture\n",0600);
    Files::write($root.'/storage/app/content/item.json','published-1');
    Files::write($root.'/public/uploads/photo.txt','media-1');
    Files::write($root.'/storage/queue/task.json','queued-1');
    putenv('DB_DRIVER=sqlite'); putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');
    putenv('STORAGE_DRIVER=local'); putenv('STORAGE_LOCAL_PATH=storage/app'); putenv('QUEUE_DRIVER=file');
    if ($python!=='') putenv('BACKUP_SQLITE_PYTHON='.$python);
    $dbpath=$root.'/storage/database/cajeer.sqlite';
    // Force an actual on-disk SQLite database with real relational constraints.
    if ($python!=='') {
        DatabaseBackup::run([$python,'-I','-c', <<<'PYTHON'
import sqlite3,sys
c=sqlite3.connect(sys.argv[1]);c.execute('PRAGMA foreign_keys=ON')
c.executescript('CREATE TABLE owner (id INTEGER PRIMARY KEY); CREATE TABLE object (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owner(id)); INSERT INTO owner VALUES (1); INSERT INTO object VALUES (1,1);')
c.commit();c.close()
PYTHON,$dbpath]);
    } else {
        $pdo=new PDO('sqlite:'.$dbpath);
        $pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE owner (id INTEGER PRIMARY KEY); CREATE TABLE object (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owner(id)); INSERT INTO owner VALUES (1); INSERT INTO object VALUES (1,1);');
        $pdo=null;
    }
    $key=$work.'/key'; Files::write($key,base64_encode(random_bytes(32)),0600); putenv('BACKUP_KEY_FILE='.$key);
    $config=new ConfigRepository($root);
    $db=new DatabaseBackup($root,$config);
    $db->verify($dbpath); expect(true,'SQLite fixture has valid integrity and foreign keys');
    $backups=new BackupManager($root,$config);
    $backup=$backups->create(); expect(($backup['ok']??false) && $backups->verify($backup['id'])['ok'],'backup includes real DB, CMS data, media and queued work');
    $bundle=$work.'/offsite.cebackup'; $backups->exportBundle($backup['id'],$bundle);
    $drill=$backups->drill($bundle,$work.'/isolated-drill',120,3600);
    expect($drill['ok'] && $drill['database']['tables_read']===2 && $drill['restored_files']>=3,'offsite encrypted bundle passes an isolated relational restore');
    // Corrupt a *validly formatted* SQLite database without breaking integrity_check.
    if ($python!=='') {
        DatabaseBackup::run([$python,'-I','-c', <<<'PYTHON'
import sqlite3,sys
c=sqlite3.connect(sys.argv[1]);c.execute('DELETE FROM owner WHERE id=1');c.commit();c.close()
PYTHON,$dbpath]);
    } else {
        $pdo=new PDO('sqlite:'.$dbpath); $pdo->exec('DELETE FROM owner WHERE id=1');$pdo=null;
    }
    mustReject(fn()=>$db->verifyRestored(),'recovery refuses an orphan foreign key despite successful SQLite integrity_check');
    mustReject(fn()=>$db->create($work.'/invalid.dump'),'invalid live database cannot become a verified backup');
    Files::write($root.'/storage/app/content/item.json','changed');
    Files::write($root.'/public/uploads/photo.txt','changed');
    Files::write($root.'/storage/queue/task.json','changed');
    $lease=OperationLock::acquire($root,true);
    try { $restored=$backups->restoreCaptured($backup['id']); }
    finally { OperationLock::release($lease); }
    expect($restored['ok'] && $db->identity()['database']===$dbpath,'actual on-disk database restored from authenticated backup');
    expect(hash_equals(Files::hash($dbpath),$backupDbHash=Files::hash($backups->directory($backup['id']).'/database.dump')),'restored SQLite bytes match the verified snapshot exactly');
    expect(file_get_contents($root.'/storage/app/content/item.json')==='published-1' && file_get_contents($root.'/public/uploads/photo.txt')==='media-1' && file_get_contents($root.'/storage/queue/task.json')==='queued-1', 'CMS, media and queued records restored with the DB');
    $db->verifyRestored(); expect(true,'restored DB passes referential integrity acceptance');
    echo "Full-platform recovery integrity regression passed\n";
} finally { RecoveryBundle::cleanup($work); }
