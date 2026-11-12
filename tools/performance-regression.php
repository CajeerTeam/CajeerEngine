<?php
/** Real repository and database regression. --database requires PDO SQLite; --pgsql uses an empty dedicated DB. */
declare(strict_types=1);
use CajeerEngine\Cms\{CmsBootstrapService,ThemeConfigRepository,NavigationRepository};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner,StorageMode,MigrationLock};
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles as F;
$root=dirname(__DIR__);require $root.'/core/Console/NativeCli.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function reject(callable $fn,string $name,?string $class=null):void{try{$fn();}catch(Throwable $e){if($class!==null&&!($e instanceof $class))throw $e;check(true,$name);return;}throw new RuntimeException('Expected failure: '.$name);}
function clean(string $path):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($path);}
$site=sys_get_temp_dir().'/ce-performance-'.bin2hex(random_bytes(8));mkdir($site,0700);
$pg=in_array('--pgsql',$argv,true);$sql=$pg||in_array('--database',$argv,true);$driver=$pg?'pgsql':'sqlite';
try{
    if($pg&&getenv('CE_PERFORMANCE_PG_TEST')!=='1')throw new RuntimeException('Use an explicitly opted-in empty PostgreSQL database');
    foreach(['APP_ENV=testing','CONTENT_STORAGE='.($sql?'database':'file'),'CMS_STORAGE='.($sql?'database':'file'),'MEDIA_STORAGE=file','DB_DRIVER='.$driver,'APP_URL=https://site.example','DB_SQLITE_WAL=true']as $env)putenv($env);
    if(!$pg)putenv('SQLITE_DATABASE='.$site.'/storage/database/test.sqlite');
    foreach(glob($root.'/config/*.php') as $file)F::copy($file,$site.'/config/'.basename($file),0644);
    $cfg=new ConfigRepository($site);$db=new DatabaseManager($cfg);
    putenv('APP_ENV=production');putenv('CONTENT_STORAGE=auto');check(StorageMode::resolve('CONTENT_STORAGE')==='database','production auto never falls back to JSON');
    putenv('CONTENT_STORAGE=file');check(StorageMode::resolve('CONTENT_STORAGE')==='file','explicit file mode is preserved');
    putenv('CONTENT_STORAGE=invalid');reject(fn()=>StorageMode::resolve('CONTENT_STORAGE'),'unknown storage mode rejected',InvalidArgumentException::class);
    putenv('CONTENT_STORAGE=auto');
    if(!$sql){$repo=new ContentEntryRepository($site,$db);reject(fn()=>$repo->list('pages'),'database unavailable rejects first attempt');reject(fn()=>$repo->list('pages'),'database unavailable rejects repeated attempt');}
    putenv('APP_ENV=testing');putenv('CONTENT_STORAGE='.($sql?'database':'file'));
    $json=new JsonFile($site.'/storage/app/test.json');$json->writeObject(['a'=>1]);check($json->readObject()===['a'=>1],'durable JSON round-trip');file_put_contents($site.'/storage/app/test.json','');reject(fn()=>$json->readObject(['default'=>true]),'empty existing JSON fails closed');$json->writeObject(['a'=>2]);check($json->readObject()===['a'=>2],'atomic replacement recovers JSON');
    $before=['id'=>'1','type'=>'pages','slug'=>'a','locale'=>'ru','status'=>'published','data'=>['parent_id'=>'p','path'=>'/a','body'=>'a']];$after=$before;$after['title']='changed';$after['data']['body']='b';check(RouteIndex::sameTopology($before,$after),'body and title edits have stable topology');
    foreach(['slug','locale','status'] as $k){$after=$before;$after[$k]='new';check(!RouteIndex::sameTopology($before,$after),'structural '.$k.' triggers graph rebuild');}
    foreach(['parent_id','path','_path'] as $k){$after=$before;$after['data'][$k]='changed';check(!RouteIndex::sameTopology($before,$after),'structural '.$k.' triggers graph rebuild');}
    if($sql){
        $pdo=$db->connection();if($pg&&(int)$pdo->query("SELECT CASE WHEN to_regclass('ce_content_entries') IS NULL THEN 0 ELSE 1 END")->fetchColumn()===1)throw new RuntimeException('Use an empty PostgreSQL database');
        $migrationFiles=glob($root.'/migrations/'.$driver.'/*.sql') ?: [];
        sort($migrationFiles,SORT_STRING);
        $expected=[];
        foreach($migrationFiles as $file){$digest=hash_file('sha256',$file);if($digest===false)throw new RuntimeException('Cannot hash migration fixture');$expected[basename($file)]=$digest;}
        check(count($expected)>=($pg?14:13)&&isset($expected['0013_database_performance.sql']),'required migration inventory present');
        if($pg)check(isset($expected['0014_queue_leases.sql']),'PostgreSQL queue lease migration present');
        $runner=new MigrationRunner($pdo,$root.'/migrations');
        $result=$runner->run($driver);
        check($result['applied']===count($expected)&&array_column($result['migrations'],'checksum','migration')===$expected,'all '.count($expected).' real '.$driver.' migrations applied with correct checksums');
        $status=$runner->status($driver);
        check($status['pending']===[]&&$status['failed']===[]&&array_column($status['applied'],'checksum','migration')===$expected,'migration ledger matches all SQL files');
        check($runner->run($driver)['applied']===0,'migration rerun idempotent');
        if(!$pg){check((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()===1,'SQLite foreign keys enabled');check($pdo->query('PRAGMA journal_mode')->fetchColumn()==='wal','SQLite WAL opt-in applied');check((int)$pdo->query('PRAGMA busy_timeout')->fetchColumn()===5000,'SQLite busy timeout applied');}
        $pdo->exec('CREATE TABLE ce_perf_probe (id INTEGER PRIMARY KEY)');
        $db->transaction(function($pdo)use($db){$pdo->exec('INSERT INTO ce_perf_probe VALUES (1)');try{$db->transaction(function($p){$p->exec('INSERT INTO ce_perf_probe VALUES (2)');throw new RuntimeException('rollback nested');});}catch(RuntimeException){}$pdo->exec('INSERT INTO ce_perf_probe VALUES (3)');});
        check(array_map('intval',$pdo->query('SELECT id FROM ce_perf_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))===[1,3],'nested failure rolls back savepoint without losing outer writes');
        reject(fn()=>$db->transaction(function($pdo){$pdo->exec('INSERT INTO ce_perf_probe VALUES (4)');throw new RuntimeException('rollback outer');}),'outer transaction propagates failure');check((int)$pdo->query('SELECT COUNT(*) FROM ce_perf_probe')->fetchColumn()===2,'outer rollback removes write');
        $migrationCopy=$site.'/migration-test/'.$driver;mkdir($migrationCopy,0700,true);
        foreach(glob($root.'/migrations/'.$driver.'/*.sql') as $file)F::copy($file,$migrationCopy.'/'.basename($file));
        $copyRunner=new MigrationRunner($pdo,$site.'/migration-test');
        file_put_contents($migrationCopy.'/0013_database_performance.sql',"\n-- tampered\n",FILE_APPEND);
        $probeMigration=$migrationCopy.'/9999_should_not_run.sql';
        check(!is_file($probeMigration),'pending migration probe has unique name');
        file_put_contents($probeMigration,'CREATE TABLE ce_perf_should_not_run (id INTEGER PRIMARY KEY);');
        $checksumRejected=false;
        try{$copyRunner->run($driver);}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'Applied migration checksum mismatch: 0013_database_performance.sql'))throw $e;$checksumRejected=true;}
        check($checksumRejected,'changed applied checksum blocks all pending migrations');
        check(!$db->tableExists('ce_perf_should_not_run'),'preflight did not apply pending SQL');
        $reader=new DatabaseManager($cfg);$writer=new DatabaseManager($cfg);
        $reader->readSnapshot(function($p)use($writer){check((int)$p->query('SELECT COUNT(*) FROM ce_perf_probe')->fetchColumn()===2,'snapshot begins at expected count');$writer->connection()->exec('INSERT INTO ce_perf_probe VALUES (5)');check((int)$p->query('SELECT COUNT(*) FROM ce_perf_probe')->fetchColumn()===2,'read snapshot remains consistent during concurrent write');});
        check((int)$pdo->query('SELECT COUNT(*) FROM ce_perf_probe')->fetchColumn()===3,'new snapshot observes committed write');
        // Confirm session-scoped lock serializes separate connections without actually waiting 30 seconds.
        if($pg){$other=(new DatabaseManager($cfg))->connection();MigrationLock::run($pdo,function()use($other){check((int)$other->query("SELECT CASE WHEN pg_try_advisory_lock(hashtext(current_database()),1128352325) THEN 1 ELSE 0 END")->fetchColumn()===0,'PostgreSQL migration lock excludes second connection');});}
        else{$path=getenv('SQLITE_DATABASE').'.migration.lock';MigrationLock::run($pdo,function()use($path){$h=fopen($path,'c+b');check(!flock($h,LOCK_EX|LOCK_NB),'SQLite migration lock excludes second handle');fclose($h);});}
    }
    (new CmsBootstrapService($site))->run();$entries=new ContentEntryRepository($site,$db);$routes=new RouteIndex($site,$db);$search=(new SearchFactory($site,$cfg,$db))->make();
    $make=fn($i)=>$entries->create('pages',['title'=>'Benchmark '.$i,'slug'=>'item-'.$i,'status'=>'published','data'=>['body'=>'body token'.$i]]);
    $rows=[];for($i=0;$i<12;$i++)$rows[]=$make($i);
    $a=$entries->page('pages',[],5,0);$b=$entries->page('pages',[],5,5);check($a['meta']['total']===12&&count($a['data'])===5&&count($b['data'])===5,'bounded pages with correct total');check(!array_intersect(array_column($a['data'],'id'),array_column($b['data'],'id')),'deterministic paging without duplicates');
    check($entries->page('pages',['q'=>'Benchmark 1'],5)['meta']['total']===3,'filtered pagination total');check($entries->page('pages',['q'=>'%'],5)['meta']['total']===0,'literal wildcard not expanded');reject(fn()=>$entries->page('pages',[],201),'oversize page rejected',InvalidArgumentException::class);
    $e=$rows[0];$e=$entries->update('pages',$e['id'],['title'=>'Incremental comet','expected_revision'=>1,'data'=>['body'=>'uniqueprojection']]);
    check($e['revision']===2&&$search->page('uniqueprojection')['meta']['total']===1,'editor update immediately refreshes search');check($routes->forEntry($e['id'])['title']==='Incremental comet','route metadata updated');check($search->page('token1')['meta']['total']>=1,'unrelated search documents survive update');
    reject(fn()=>$entries->update('pages',$e['id'],['title'=>'lost','expected_revision'=>1]),'stale revision rejected',RevisionConflict::class);check($entries->findById('pages',$e['id'])['title']==='Incremental comet','stale revision cannot overwrite');
    $entries->update('pages',$e['id'],['data'=>['body'=>'uniqueprojection','seo_noindex'=>true]]);check($search->page('uniqueprojection')['meta']['total']===0,'noindex removes search document on neutral update');
    $entries->update('pages',$e['id'],['data'=>['body'=>'uniqueprojection','path'=>'/new-place']]);check($routes->forEntry($e['id'])['path']==='/new-place'&&$search->page('uniqueprojection')['data'][0]['url']==='/new-place','structural change rebuilds correct route and search');check($routes->check()['ok'],'route graph consistent after mixed edit modes');
    if($sql){
        $entries->delete('pages',$e['id']);$new=$entries->create('pages',['title'=>'Reuse','slug'=>'item-0','status'=>'draft']);check($new['slug']==='item-0-2','soft-deleted SQL slug is not reused against unique constraint');
        $locale=$entries->createLocalization('pages',$rows[1]['id'],['title'=>'English','slug'=>'item-1','locale'=>'en','status'=>'draft']);check(count($entries->localizations('pages',$rows[1]['id']))===2,'indexed translation lookup returns only group');
    }
    echo 'Performance regression complete ('.($sql?$driver:'file').")\n";
}finally{clean($site);}
