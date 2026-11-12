<?php
/** Real ZIP, Ed25519, SQLite data and file recovery tests. A synthetic dist is used only for failure injection. */
declare(strict_types=1);
use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Release\ReleaseDependencyInventory;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Update\ReleaseSignature;
use CajeerEngine\Update\UpdateManager;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void { if (str_starts_with($class,'CajeerEngine\\')) { $p=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php'; if(is_file($p))require $p; } });
function check(bool $v,string $s):void { if(!$v)throw new RuntimeException($s); echo "PASS $s\n"; }
function reject(callable $f,string $s):void { try{$f();}catch(Throwable){check(true,$s);return;}throw new RuntimeException('Not rejected: '.$s); }
function clean(string $p):void { if(!is_dir($p))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $i){if($i->isDir()&&!$i->isLink())rmdir($i->getPathname());else unlink($i->getPathname());}rmdir($p); }
function sql(string $db,string $sql,bool $query=false):string {
    if(in_array('sqlite',PDO::getAvailableDrivers(),true)) { $p=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); return $query?(string)$p->query($sql)->fetchColumn():(string)$p->exec($sql); }
    $python=getenv('BACKUP_SQLITE_PYTHON')?:''; if(!$python)throw new RuntimeException('SQLite regression needs pdo_sqlite or BACKUP_SQLITE_PYTHON');
    $tmp=tempnam(sys_get_temp_dir(),'ce-sql-');
    try { DatabaseBackup::run([$python,'-I','-c','import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); q=c.execute(sys.argv[2]) if sys.argv[3]=="1" else c.executescript(sys.argv[2]); print(q.fetchone()[0] if sys.argv[3]=="1" else "ok"); c.commit(); c.close()',$db,$sql,$query?'1':'0'],[],$tmp);return trim((string)file_get_contents($tmp)); } finally {unlink($tmp);}
}
$work=sys_get_temp_dir().'/ce-update-test-'.bin2hex(random_bytes(8));mkdir($work,0700);
try {
    if(!in_array('sqlite',PDO::getAvailableDrivers(),true)&&!getenv('BACKUP_SQLITE_PYTHON')) { foreach(['/usr/local/bin/python3','/usr/bin/python3']as $p)if(is_executable($p)){putenv('BACKUP_SQLITE_PYTHON='.$p);break;} }
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');putenv('STORAGE_DRIVER=local');putenv('STORAGE_LOCAL_PATH=storage/app');putenv('QUEUE_DRIVER=file');
    ReleaseSignature::generate($work.'/secret.key',$work.'/public.key');ReleaseSignature::generate($work.'/other.key',$work.'/other.pub');
    F::write($work.'/backup.key',base64_encode(random_bytes(32))."\n");putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE='.$work.'/public.key');putenv('BACKUP_KEY_FILE='.$work.'/backup.key');
    $source=(new ReleaseBuilder($root))->build(false,'source',$work.'/source');
    $sig=ReleaseSignature::sign($source['target'],$work.'/secret.key');check($sig['ok'],'physical source ZIP signed');
    check(ReleaseSignature::verify($source['target'],$source['target'].'.sig',$work.'/public.key')['sha256']===$source['sha256'],'trusted Ed25519 signature');
    reject(fn()=>ReleaseSignature::verify($source['target'],$source['target'].'.sig',$work.'/other.pub'),'wrong publisher rejected');
    reject(fn()=>ReleaseSignature::verify($source['target'],$source['target'].'.sig',$work.'/public.key',str_repeat('0',64)),'wrong external digest rejected');
    copy($source['target'],$work.'/tampered.zip');copy($source['target'].'.sig',$work.'/tampered.zip.sig');file_put_contents($work.'/tampered.zip','x',FILE_APPEND);
    reject(fn()=>ReleaseSignature::verify($work.'/tampered.zip',$work.'/tampered.zip.sig',$work.'/public.key'),'tampered ZIP rejected before extraction');
    $old=$work.'/old';(new PharData($source['target']))->extractTo($old);$old.='/cajeerengine';
    $config=new ConfigRepository($old);$manager=new UpdateManager($old,$config);
    check($manager->verifyPackage($source['target'],$source['sha256'])['ok'],'signed release inventory and checksums verified');
    reject(fn()=>$manager->apply(['package'=>$source['target'],'no_backup'=>true]),'backup cannot be bypassed');
    reject(fn()=>$manager->apply(['package'=>$source['target'],'force'=>true]),'source cannot be applied even with force');
    $shared=OperationLock::acquire($old,false);reject(fn()=>OperationLock::acquire($old,true),'active HTTP lease blocks exclusive update');OperationLock::release($shared);
    $ex=OperationLock::acquire($old,true);reject(fn()=>OperationLock::acquire($old,false),'exclusive update blocks HTTP/worker lease');OperationLock::release($ex);
    $childPath=$work.'/child.lock';$ch=OperationLock::childGuard($childPath);reject(fn()=>OperationLock::childGuard($childPath),'orphan subprocess lease blocks recovery');OperationLock::release($ch);
    // Create an actual SQLite database, with WAL mode and persisted data, and a real file tree.
    $db=$old.'/storage/database/cajeer.sqlite';sql($db,'PRAGMA journal_mode=WAL; CREATE TABLE example(id INTEGER PRIMARY KEY, value TEXT); INSERT INTO example VALUES(1,"before");');
    F::write($old.'/.env',"DB_DRIVER=sqlite\nSQLITE_DATABASE=storage/database/cajeer.sqlite\nAPP_KEY=secret-example\n",0640);
    F::write($old.'/storage/app/installed.lock','{}');F::write($old.'/storage/app/content/test.json','{"value":"before"}');F::write($old.'/public/uploads/test.txt','before',0644);
    F::write($old.'/storage/queue/job.json','{"pending":true}');
    // Synthetic test postcheck: the actual updater invokes this in a new process. No fake production dependencies are shipped.
    $fixtureCheck=<<<'CODE'
<?php
if (($argv[1]??'')==='migrate') {
    $db=dirname(__DIR__).'/storage/database/cajeer.sqlite';
    if (in_array('sqlite',PDO::getAvailableDrivers(),true)) { $pdo=new PDO('sqlite:'.$db); $pdo->exec("UPDATE example SET value='after'"); }
    else { $py=getenv('BACKUP_SQLITE_PYTHON'); $p=proc_open([$py,'-I','-c',"import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); c.execute(\"UPDATE example SET value='after'\"); c.commit()",$db],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes); if(proc_close($p)!==0)exit(8); }
    file_put_contents(dirname(__DIR__).'/storage/app/content/test.json','{"value":"after"}');
    if (is_file(dirname(__DIR__).'/core/fail-update.php')) exit(9);
}
exit(0);
CODE;
    F::write($old.'/tools/update-postcheck.php',$fixtureCheck,0644);
    $b=new BackupManager($old,new ConfigRepository($old));$backup=$b->create();check($backup['ok']&&$b->verify($backup['id'])['ok'],'actual SQLite and file backup verified');
    $bdir=$backup['target'];check(!str_contains(file_get_contents($bdir.'/environment.enc'),'secret-example')&&!is_file($bdir.'/files/.env'),'environment encrypted');
    $mf=file_get_contents($bdir.'/manifest.json');file_put_contents($bdir.'/manifest.json',$mf."\n");reject(fn()=>$b->verify($backup['id']),'backup manifest MAC detects tampering');file_put_contents($bdir.'/manifest.json',$mf);
    $data=$bdir.'/files/storage/app/content/test.json';$original=file_get_contents($data);file_put_contents($data,'corrupt');reject(fn()=>$b->verify($backup['id']),'backup file checksum detects corruption');file_put_contents($data,$original);
    sql($db,"UPDATE example SET value='changed'; CREATE TABLE extra(x);" );F::write($old.'/storage/app/content/test.json','changed');F::write($old.'/public/uploads/new.txt','new');F::remove($old.'/storage/queue/job.json');
    check($b->restore($backup['id'])['ok'],'standalone restore completes');check(sql($db,'SELECT value FROM example',true)==='before'&&sql($db,"SELECT count(*) FROM sqlite_master WHERE name='extra'",true)==='0','database contents and schema restored');check(file_get_contents($old.'/storage/app/content/test.json')===$original&&!is_file($old.'/public/uploads/new.txt')&&is_file($old.'/storage/queue/job.json'),'files restored, new files removed, queue restored');check((fileperms($old.'/.env')&0777)===0640,'environment permissions restored');
    check($manager->maintenanceState()['enabled'],'standalone restore leaves maintenance enabled');$manager->disableMaintenance();
    // Synthetic dist only for testing transactional mechanics without requiring Composer packages in this environment.
    $target=$work.'/target';(new PharData($source['target']))->extractTo($target);$target.='/cajeerengine';
    F::write($target.'/VERSION',"1.1.7\n",0644);$release=F::read($target.'/release.json');$release['version']='1.1.7';F::json($target.'/release.json',$release,0644);
    $admin=F::read($target.'/public/admin/assets/admin-manifest.json');$admin['version']='1.1.7';F::json($target.'/public/admin/assets/admin-manifest.json',$admin,0644);
    // Real inventory verification is exercised; these are intentionally test-only stub package files.
    $pkg=['name'=>'fixture/only','version'=>'1.0.0','type'=>'library','dist'=>['reference'=>'test']];
    F::json($target.'/composer.lock',['packages'=>[$pkg]],0644);F::json($target.'/vendor/composer/installed.json',['packages'=>[$pkg],'dev'=>false],0644);
    foreach(ReleaseDependencyInventory::AUTOLOAD_FILES as $p)if(!is_file($target.'/'.$p))F::write($target.'/'.$p,"<?php // test fixture only\n",0644);
    F::json($target.'/vendor/fixture/only/composer.json',['name'=>'fixture/only'],0644);
    F::write($target.'/tools/update-postcheck.php',$fixtureCheck,0644);F::write($target.'/core/new-update.php',"<?php // test only\n",0644);
    F::write($old.'/vendor/old-package/obsolete.php','<?php // old',0644);
    $dist=(new ReleaseBuilder($target))->build(false,'dist',$work.'/target-release');ReleaseSignature::sign($dist['target'],$work.'/secret.key');
    $dry=$manager->apply(['package'=>$dist['target'],'dry_run'=>true]);check($dry['ok']&&in_array('core/new-update.php',$dry['copy_plan']['files'],true),'signed dist physical staging dry-run');check(!is_file($old.'/core/new-update.php'),'dry-run does not modify live code');
    $stage=$manager->publish($dist['target']);$request=$manager->request('apply',['package'=>$stage['id'],'sha256'=>$stage['sha256'],'confirm'=>true],'admin-test');
    reject(fn()=>$manager->request('apply',['package'=>$stage['id'],'sha256'=>str_repeat('0',64),'confirm'=>true],'admin-test'),'web request bound to exact staged digest');
    $applied=$manager->runRequest($request['id']);check($applied['ok']&&trim(file_get_contents($old.'/VERSION'))==='1.1.7','queued signed update commits');check(sql($db,'SELECT value FROM example',true)==='after'&&is_file($old.'/core/new-update.php')&&!is_file($old.'/vendor/old-package/obsolete.php'),'update changes data/code and removes stale vendor');
    reject(fn()=>$manager->rollback(),'committed rollback requires confirmation');$rollback=$manager->rollback(null,true);check($rollback['ok']&&trim(file_get_contents($old.'/VERSION'))==='1.1.6','committed rollback restores release');check(sql($db,'SELECT value FROM example',true)==='before'&&!is_file($old.'/core/new-update.php')&&is_file($old.'/vendor/old-package/obsolete.php'),'rollback restores DB and deleted files, removes additions');
    F::write($target.'/core/fail-update.php','<?php // failure injection',0644);$bad=(new ReleaseBuilder($target))->build(false,'dist',$work.'/failed-release');ReleaseSignature::sign($bad['target'],$work.'/secret.key');
    $failed=$manager->apply(['package'=>$bad['target']]);check(!$failed['ok']&&$failed['state']==='rolled_back','failed postcheck triggers automatic recovery');check(sql($db,'SELECT value FROM example',true)==='before'&&!is_file($old.'/core/fail-update.php')&&!OperationLock::maintenance($old),'automatic recovery restores DB/files and reopens only after verify');
    $backupRequest=$manager->request('backup',['confirm'=>true],'admin-test');check($manager->runRequest($backupRequest['id'])['ok'],'operator runs queued backup request');
    // Journal recovery after process death: set a durable interrupted state using the real committed transaction backup.
    $tx=$old.'/storage/app/updates/transactions/'.$failed['id'];$journal=F::read($tx.'/journal.json');$journal['state']='migrating';F::json($old.'/storage/app/updates/active.json',$journal);F::write($old.'/core/new-update.php','<?php // interrupted',0644);sql($db,"UPDATE example SET value='interrupted'");
    reject(fn()=>$manager->disableMaintenance(),'active recovery cannot be reopened');
    $child=OperationLock::childGuard($tx.'/'.$journal['child_lock']);reject(fn()=>$manager->rollback(),'recovery refuses while orphan child holds lease');OperationLock::release($child);
    $snapshot=$old.'/storage/app/backups/production/'.$journal['backup'].'/files/core/Update/UpdateManager.php';
    $originalCode=file_get_contents($snapshot); file_put_contents($snapshot,'<?php throw new RuntimeException("tampered");');
    reject(fn()=>DatabaseBackup::run([PHP_BINARY,$tx.'/recover.php']),'emergency launcher rejects altered recovery code before execution');
    file_put_contents($snapshot,$originalCode);
    F::write($old.'/core/Update/UpdateManager.php','<?php // interrupted PHP replacement',0644);
    DatabaseBackup::run([PHP_BINARY,$tx.'/recover.php']);
    check(sql($db,'SELECT value FROM example',true)==='before'&&!is_file($old.'/core/new-update.php')&&str_contains(file_get_contents($old.'/core/Update/UpdateManager.php'),'final class UpdateManager'),'emergency launcher restores prior code and DB without live updater');
    echo "Update regression passed (SQLite; synthetic dist only for failure-injection, not CMS E2E)\n";
}finally{clean($work);}
