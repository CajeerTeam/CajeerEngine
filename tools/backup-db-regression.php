<?php
/** Destructive DB round-trip, only an explicitly named disposable CI database. */
declare(strict_types=1);
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void { if(str_starts_with($class,'CajeerEngine\\')) { $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php'; if(is_file($file))require $file; } });
if (getenv('CE_BACKUP_DB_TEST')!=='1'||getenv('DB_DATABASE')!=='cajeer_backup_test'||!in_array(getenv('DB_DRIVER'),['pgsql','mysql'],true)) throw new RuntimeException('Explicit disposable cajeer_backup_test database required');
$config=new ConfigRepository($root); $db=new DatabaseBackup($root,$config); $db->assertRecoveryReady();
$pdo=(new DatabaseManager($config))->connection();
$pdo->exec('CREATE TABLE ce_recovery_fixture (id INTEGER PRIMARY KEY, value VARCHAR(100)); INSERT INTO ce_recovery_fixture VALUES (1,\'before\')'); $pdo=null;
$file=tempnam(sys_get_temp_dir(),'ce-db-roundtrip-'); if($file===false) throw new RuntimeException('Cannot create temp file'); unlink($file);
try {
    $db->create($file); $db->verify($file);
    $pdo=(new DatabaseManager($config))->connection(); $pdo->exec("UPDATE ce_recovery_fixture SET value='after'; CREATE TABLE ce_recovery_extra (id INTEGER)"); $pdo=null;
    $db->restore($file,$db->identity());
    $pdo=(new DatabaseManager($config))->connection();
    if($pdo->query('SELECT value FROM ce_recovery_fixture WHERE id=1')->fetchColumn()!=='before') throw new RuntimeException('Restored row mismatch');
    $exists=true; try { $pdo->query('SELECT * FROM ce_recovery_extra'); } catch(PDOException) { $exists=false; }
    if($exists) throw new RuntimeException('Post-backup table survived restore');
    echo 'Database round-trip passed: '.getenv('DB_DRIVER')."\n";
} finally { if(is_file($file))unlink($file); }
