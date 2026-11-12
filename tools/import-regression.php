<?php
/** End-to-end portable site transfer between separate installations, using real ZIP and backup recovery. */
declare(strict_types=1);
use CajeerEngine\ImportExport\ImportExportService;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;
$root=dirname(__DIR__);
spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $v,string $msg):void{if(!$v)throw new RuntimeException($msg);echo "PASS $msg\n";}
function reject(callable $f,string $msg):void{try{$f();}catch(Throwable){check(true,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $i){if($i->isDir()&&!$i->isLink())rmdir($i->getPathname());else unlink($i->getPathname());}rmdir($p);}
$dbMode=in_array('--database',$argv,true);
if($dbMode&&!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required for database regression');
$work=sys_get_temp_dir().'/ce-import-'.bin2hex(random_bytes(8));mkdir($work,0700);
try {
    putenv('CONTENT_STORAGE='.($dbMode?'database':'file'));putenv('CMS_STORAGE='.($dbMode?'database':'file'));putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');putenv('STORAGE_DRIVER=local');putenv('QUEUE_DRIVER=file');putenv('SEARCH_DRIVER=file');
    if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){foreach(['/usr/local/bin/python3','/usr/bin/python3']as $py)if(is_executable($py)){putenv('BACKUP_SQLITE_PYTHON='.$py);break;}}
    F::write($work.'/backup.key',base64_encode(random_bytes(32)));putenv('BACKUP_KEY_FILE='.$work.'/backup.key');
    $init=function(string $name)use($root,$work,$dbMode):string{
        $r=$work.'/'.$name;F::dir($r);F::dir($r.'/config');foreach(glob($root.'/config/*.php') as $file)copy($file,$r.'/config/'.basename($file));copy($root.'/VERSION',$r.'/VERSION');F::dir($r.'/storage/database');
        $db=$r.'/storage/database/cajeer.sqlite';if(in_array('sqlite',PDO::getAvailableDrivers(),true)){$p=new PDO('sqlite:'.$db);$p->exec('CREATE TABLE marker(value TEXT); INSERT INTO marker VALUES ("keep")');}else DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c','import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); c.executescript("CREATE TABLE marker(value TEXT); INSERT INTO marker VALUES (\"keep\");"); c.commit(); c.close()',$db]);
        if($dbMode){
            $cfg=require $r.'/config/database.php';$cfg['connections']['sqlite']['database']=$db;
            F::write($r.'/config/database.php','<?php return '.var_export($cfg,true).';');
            $mgr=new DatabaseManager(new ConfigRepository($r));
            (new \CajeerEngine\Database\MigrationRunner($mgr->connection(),$root.'/migrations'))->run('sqlite');
            $mgr->disconnect();
        }
        return $r;
    };
    $a=$init('source');$b=$init('target');$bad=$init('failure');
    $type=['handle'=>'pages','name'=>'Pages','localized'=>true,'fields'=>[
        ['handle'=>'body','label'=>'Body','type'=>'richtext'],['handle'=>'parent_id','label'=>'Parent','type'=>'relation'],['handle'=>'photo','label'=>'Photo','type'=>'media'],['handle'=>'path','label'=>'Path','type'=>'text'],
    ]];
    $at=new ContentTypeRepository($a);$at->create($type);$ae=new ContentEntryRepository($a);
    $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==';
    $media=function($r)use($png){return (new MediaRepository($r,new StorageManager($r,new ConfigRepository($r))))->createFromBase64(['filename'=>'pixel.png','content_base64'=>$png,'title'=>'Photo']);};
    $am=$media($a);$bm=$media($b);
    $parent=$ae->create('pages',['title'=>'Parent','slug'=>'home','locale'=>'ru','status'=>'published','data'=>['body'=>'<p>first</p>','path'=>'/']]);
    $ae->update('pages',$parent['id'],['data'=>['body'=>'<p>new</p>','path'=>'/']]);
    $child=$ae->create('pages',['title'=>'Child','slug'=>'child','locale'=>'ru','status'=>'published','data'=>['parent_id'=>$parent['id'],'photo'=>$am['id'],'body'=>'<img src="'.$am['url'].'">','path'=>'/child']]);
    $en=$ae->create('pages',['title'=>'English','slug'=>'home','locale'=>'en','translation_group'=>$parent['translation_group'],'status'=>'draft','data'=>['path'=>'/en']]);
    (new NavigationRepository($a))->save('primary','Primary',[['label'=>'Child','url'=>'/child','entry_type'=>'pages','entry_id'=>$child['id']]]);
    (new ThemeConfigRepository($a))->save(['site_name'=>'Transferred','favicon_url'=>$am['url']]);
    (new RedirectRepository($a))->save(['source_path'=>'/old','target_url'=>'/child']);
    if($dbMode){$settings=new \CajeerEngine\Database\Repository\SettingsRepository(new DatabaseManager(new ConfigRepository($a)));$settings->set('app.name','Portable site','string');$settings->set('private.secret','DO NOT EXPORT','string');unset($settings);}
    $source=new ImportExportService($a);$target=new ImportExportService($b);
    $export=$source->createExport($dbMode?['content','media','cms','settings']:['content','media','cms']);$file=$export['path'];check(is_file($file)&&str_ends_with($file,'.zip'),'physical portable ZIP created');
    $z=new PharData($file);check(isset($z['media/'.$am['checksum']])&&!str_contains($z['data/media.json']->getContent(),'content_base64'),'media is binary member, not base64');unset($z);
    $bt=new ContentTypeRepository($b);$bt->create($type);$be=new ContentEntryRepository($b);$existing=$be->create('pages',['title'=>'Old','slug'=>'home','locale'=>'ru','status'=>'draft','data'=>['path'=>'/']]);
    $plan=$target->diff($file,['strategy'=>'update']);check($plan['ok']&&$plan['mapping']['content'][$parent['id']]===$existing['id']&&$plan['mapping']['media'][$am['id']]===$bm['id'],'locale-aware identity and media dedup mapping');
    check($target->diff($file,['strategy'=>'fail'])['ok']===false,'conflict fail produces a blocked plan');
    check($be->find('pages',$existing['id'])['title']==='Old','dry run has no content writes');
    reject(fn()=>$target->import($file,['strategy'=>'update','plan'=>str_repeat('0',64)]),'stale plan rejected');
    $res=$target->import($file,['strategy'=>'update','plan'=>$plan['plan']]);check($res['ok']&&isset($res['backup_id']),'import applies with verified backup');
    $got=$be->find('pages',$child['id']);check($got['data']['parent_id']===$existing['id']&&$got['data']['photo']===$bm['id']&&str_contains($got['data']['body'],$bm['url']),'relation, parent and rich media URLs remapped');
    check($be->find('pages',$en['id'])['translation_group']===$existing['translation_group'],'translation group remapped');
    check((new NavigationRepository($b))->find('primary')['items'][0]['entry_id']===$child['id'],'menu entry relationship preserved');
    check((new ThemeConfigRepository($b))->get()['favicon_url']===$bm['url']&&(new RedirectRepository($b))->find('/old')['target_url']==='/child','theme and redirects persisted through CMS repositories');
    if($dbMode){$settings=new \CajeerEngine\Database\Repository\SettingsRepository(new DatabaseManager(new ConfigRepository($b)));check($settings->get('app.name')==='Portable site'&&$settings->get('private.secret')===null,'DB settings transferred without private keys');unset($settings);}
    $again=$target->diff($file,['strategy'=>'update']);check($again['summary']['create']===0&&$again['summary']['update']===0,'second import is idempotent');
    check($target->import($file,['strategy'=>'update','plan'=>$again['plan']])['unchanged']===true,'unchanged import does not create backup or revisions');
    $fresh=new ImportExportService($bad);$freshPlan=$fresh->diff($file,['strategy'=>'update']);
    // Force an error in the actual search projection, after content/media writes.
    if($dbMode){$mgr=new DatabaseManager(new ConfigRepository($bad));$mgr->connection()->exec("CREATE TRIGGER ce_search_fail BEFORE INSERT ON ce_cms_search BEGIN SELECT RAISE(ABORT, 'projection failure'); END");$mgr->disconnect();unset($mgr);}
    else F::write($bad.'/storage/app/search-index.json','{invalid json');
    $freshPlan=$fresh->diff($file,['strategy'=>'update']);$failed=$fresh->import($file,['strategy'=>'update','plan'=>$freshPlan['plan']]);
    check(!$failed['ok']&&$failed['rolled_back']===true&&!is_file($bad.'/storage/app/content-types.json')&&!is_file($bad.'/public/uploads/import/'.$am['id'].'.png'),'post-write error restores original files and removes new uploads');
    if($dbMode){$mgr=new DatabaseManager(new ConfigRepository($bad));check((int)$mgr->connection()->query('SELECT COUNT(*) FROM ce_content_entries')->fetchColumn()===0,'database rollback leaves no imported entries');$mgr->disconnect();}
    check(!is_file($bad.'/storage/app/backup-restore.json'),'successful recovery clears maintenance gate');
    if($dbMode){$mgr=new DatabaseManager(new ConfigRepository($bad));$mgr->connection()->exec('DROP TRIGGER IF EXISTS ce_search_fail');$mgr->disconnect();unset($mgr);}
    else F::remove($bad.'/storage/app/search-index.json');
    $freshPlan=$fresh->diff($file,['strategy'=>'update']);$result=$fresh->import($file,['strategy'=>'update','plan'=>$freshPlan['plan']]);check($result['ok'],'retry succeeds after fixing failure');
    $fe=new ContentEntryRepository($bad);check(count($fe->revisions('pages',$parent['id']))>=2,'historical revisions restored for new entries');
    $legacy=$source->createExport(['media'],'legacy.json');check($target->diff($legacy['path'])['ok'],'checksummed JSON v2 remains importable');
    $tamper=$work.'/tampered.zip';copy($file,$tamper);$zip=new PharData($tamper);$zip['media/'.$am['checksum']]='wrong';unset($zip);reject(fn()=>$target->diff($tamper),'tampered media rejected');
    $extra=$work.'/extra.zip';copy($file,$extra);$zip=new PharData($extra);$zip['extra.php']='<?php';unset($zip);reject(fn()=>$target->diff($extra),'unlisted executable rejected');
    $partial=$source->createExport(['content'],'partial.zip');$empty=$init('empty');reject(fn()=>(new ImportExportService($empty))->diff($partial['path']),'unresolved media relation blocks selective import');
    $upload=$target->upload($file,'incoming.zip');$p=$target->diff($upload['file'],['strategy'=>'update']);$req=$target->request($upload['file'],['strategy'=>'update','plan'=>$p['plan']]);check($req['request']['state']==='pending','validated admin request persisted');
    check($target->executeRequest($req['request']['id'])['ok'],'operator executes confirmed request');reject(fn()=>$target->executeRequest($req['request']['id']),'request cannot be replayed');
    $lease=OperationLock::acquire($b,false);reject(fn()=>$target->diff($file),'active application lease blocks import planning');OperationLock::release($lease);
    check($target->diff($file,['sections'=>['media']])['summary']['skip']===1,'selective media diff');
    $conflict=$init('schema-conflict');$changed=$type;$changed['fields'][0]['label']='Different schema';(new ContentTypeRepository($conflict))->create($changed);
    check(!(new ImportExportService($conflict))->diff($file,['strategy'=>'skip'])['ok'],'incompatible type schema blocks even skip strategy');
    $poison=$work.'/poison.zip';copy($file,$poison);$zip=new PharData($poison);$meta=json_decode($zip['data/media.json']->getContent(),true,flags:JSON_THROW_ON_ERROR);$meta['items'][0]['url']='a';$bytes=\CajeerEngine\ImportExport\SitePackage::encode($meta);$manifest=json_decode($zip['manifest.json']->getContent(),true,flags:JSON_THROW_ON_ERROR);$manifest['files']['data/media.json']=['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];$zip['data/media.json']=$bytes;$zip['manifest.json']=\CajeerEngine\ImportExport\SitePackage::encode($manifest);unset($zip);
    reject(fn()=>$target->diff($poison,['strategy'=>'update']),'checksummed package with poisoned URL mapping rejected');
    $upload=$target->upload($file,'changed.zip');$p=$target->diff($upload['file'],['strategy'=>'update']);$req=$target->request($upload['file'],['strategy'=>'update','plan'=>$p['plan']]);F::write($b.'/storage/app/imports/'.$upload['file'],'changed');reject(fn()=>$target->executeRequest($req['request']['id']),'package changed after confirmation rejected');
}finally{clean($work);}
