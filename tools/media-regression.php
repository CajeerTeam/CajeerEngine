<?php
/** Real file, content, signed-link and replacement round-trip; --database repeats using PDO SQLite. */
declare(strict_types=1);
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Storage\S3CompatibleDisk;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$f=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($f))require $f;}});
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
function rejects(callable $f,string $name):void{try{$f();}catch(Throwable){check(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
function png(int $w,int $h,string $rgb): string {$chunk=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.pack('N',crc32($t.$d));return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$w,$h,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat($rgb,$w),$h))).$chunk('IEND','');}
function clean(string $p):void{if(!is_dir($p))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $i){if($i->isDir()&&!$i->isLink())rmdir($i->getPathname());else unlink($i->getPathname());}rmdir($p);}
$work=sys_get_temp_dir().'/ce-media-'.bin2hex(random_bytes(8));F::dir($work);$db=in_array('--database',$argv,true);
try{
    putenv('MEDIA_STORAGE=file');putenv('CONTENT_STORAGE=file');putenv('CMS_STORAGE=file');putenv('STORAGE_DRIVER=local');putenv('APP_ENV=testing');putenv('APP_KEY='.bin2hex(random_bytes(32)));putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');putenv('QUEUE_DRIVER=file');putenv('SEARCH_DRIVER=file');
    if(!function_exists('imagecreatetruecolor')&&!getenv('MEDIA_CONVERT_BINARY'))foreach(['/opt/imagemagick/bin/convert','/usr/bin/convert'] as $bin)if(is_executable($bin)){putenv('MEDIA_CONVERT_BINARY='.$bin);break;}
    if(!function_exists('imagecreatetruecolor')&&!getenv('MEDIA_CONVERT_BINARY'))throw new RuntimeException('GD or ImageMagick required for media regression');
    F::dir($work.'/config');foreach(glob($root.'/config/*.php')as $f)copy($f,$work.'/config/'.basename($f));copy($root.'/VERSION',$work.'/VERSION');
    $config=new ConfigRepository($work);$repo=new MediaRepository($work,new StorageManager($work,$config));
    $red=$work.'/red.png';$blue=$work.'/blue.png';F::write($red,png(640,360,"\xff\0\0"));F::write($blue,png(480,640,"\0\0\xff"));
    $a=$repo->createFromPath($red,'red.png',['title'=>'Sunset','folder'=>'Photos/2026','tags'=>['landscape'],'collections'=>['hero'],'alt'=>'Red']);
    check($a['width']===640&&$a['height']===360&&F::hash($repo->sourcePath($a))===$a['checksum'],'streamed original stored and inspected');
    check(count($a['variants'])>=4&&$a['variants']['thumbnail']['width']===240&&getimagesize($work.'/public/uploads/'.$a['variants']['thumbnail']['path'])[0]===240,'physical resized thumbnail created');
    foreach($a['variants'] as $v)check(F::hash($work.'/public/uploads/'.$v['path'])===$v['checksum'],'variant checksum '.$v['width'].'x'.$v['height']);
    check($repo->createFromPath($red,'same.png',['deduplicate'=>true])['id']===$a['id']&&$repo->count()===1,'opt-in dedup returns existing UUID');
    $private=$repo->createFromPath($red,'secret.png',['visibility'=>'private','deduplicate'=>true,'folder'=>'Private']);
    check($private['id']!==$a['id']&&!is_file($work.'/public/uploads/'.$private['path'])&&is_file($repo->sourcePath($private)),'private original is outside public root and not deduped with public');
    check(!is_file($work.'/public/uploads/'.$private['variants']['thumbnail']['path'])&&count($repo->publicItems())===1,'private derivatives and renderer catalog stay private');
    $link=$repo->signedUrl($private['id'],60);parse_str(parse_url($link['url'],PHP_URL_QUERY),$q);
    check($repo->signedDownload($private['id'],(int)$q['expires'],$q['signature'],$q['variant'],$q['version'])['checksum']===$private['checksum'],'valid signed URL resolves private bytes');
    rejects(fn()=>$repo->signedDownload($a['id'],(int)$q['expires'],$q['signature']),'signed link cannot be reused for another record');
    rejects(fn()=>$repo->signedDownload($private['id'],time()-1,$q['signature']),'expired link rejected');
    rejects(fn()=>$repo->signedDownload($private['id'],(int)$q['expires'],str_repeat('0',64)),'tampered signature rejected');
    $repo->update($a['id'],['title'=>'Evening','caption'=>'A sunset','folder'=>'Gallery','tags'=>['sky'],'collections'=>['homepage']]);
    check($repo->page(['q'=>'homepage','folder'=>'Gallery','visibility'=>'public'],1)['meta']['total']===1&&$repo->page(['q'=>'nonexistent'])['meta']['total']===0,'metadata search and folder/visibility filters');
    check(count($repo->page([],1,1)['data'])===1&&count($repo->folders())===2,'stable pagination and folder list');
    if($db){
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required');
        F::dir($work.'/storage/database');$cfg=require $work.'/config/database.php';$cfg['connections']['sqlite']['database']=$work.'/storage/database/media.sqlite';F::write($work.'/config/database.php','<?php return '.var_export($cfg,true).';');
        $mgr=new \CajeerEngine\Database\DatabaseManager(new ConfigRepository($work));(new \CajeerEngine\Database\MigrationRunner($mgr->connection(),$root.'/migrations'))->run('sqlite');
         $repo=new MediaRepository($work,new StorageManager($work,new ConfigRepository($work)),$mgr);
        check($repo->migrate()['migrated']===2,'legacy JSON migrated to actual SQL catalog');putenv('MEDIA_STORAGE=database');$repo=new MediaRepository($work,new StorageManager($work,new ConfigRepository($work)));
        check($repo->count()===2&&$repo->find($private['id'])['visibility']==='private','database records preserve UUID and privacy');
        check($repo->page(['folder'=>'Gallery','q'=>'sky'])['meta']['total']===1,'database filters and search');
        check($repo->migrate()['already_migrated'],'migration is idempotent');
    }
    $old=$repo->find($a['id']);$new=$repo->replace($a['id'],$blue,'blue.png');
    check($new['id']===$old['id']&&$new['url']===$old['url']&&$new['checksum']!==$old['checksum']&&F::hash($repo->sourcePath($new))===$new['checksum'],'replace retains UUID and URL while changing bytes');
    check(is_file($work.'/public/uploads/'.$old['variants']['thumbnail']['path'])&&$new['variants']['thumbnail']['height']===240,'old variant URLs retained; new variants generated');
    $repo->replace($private['id'],$blue,'blue.png');rejects(fn()=>$repo->signedDownload($private['id'],(int)$q['expires'],$q['signature']),'replacement revokes old local signed URL');
    // Simulate an interrupted original replacement after atomic file rename but before metadata save.
    $stage=$work.'/storage/app/media-work';F::copy($repo->sourcePath($new),$stage.'/replacement.original');F::json($stage.'/replacement.json',['id'=>$new['id'],'path'=>$new['path'],'old'=>$new['checksum'],'new'=>F::hash($red)]);F::copy($red,$repo->sourcePath($new),0644);
    check($repo->recover()['recovered']&&F::hash($repo->sourcePath($new))===$new['checksum'],'interrupted replacement recovered from journal');
    $types=new ContentTypeRepository($work);$types->create(['handle'=>'photos','name'=>'Photos','fields'=>[['handle'=>'photo','type'=>'media','label'=>'Photo'],['handle'=>'body','type'=>'richtext','label'=>'Body']]]);$entries=new ContentEntryRepository($work);
    $e=$entries->create('photos',['title'=>'Gallery','slug'=>'gallery','data'=>['photo'=>$a['id'],'body'=>'<img src="'.$a['url'].'">']]);
    check(count($repo->usage($a['id']))>=1,'usage detects typed media and embedded URL');rejects(fn()=>$repo->delete($a['id']),'referenced original cannot be deleted');
    $entries->update('photos',$e['id'],['data'=>['photo'=>null,'body'=>'none']]);check(count($repo->usage($a['id']))>=1,'historical revision keeps media reference');
    rejects(fn()=>$entries->create('photos',['title'=>'Missing','slug'=>'missing','data'=>['photo'=>'00000000-0000-4000-8000-000000000001']]),'new missing media UUID rejected');
    $thumb=$new['variants']['thumbnail']['path'];unlink($work.'/public/uploads/'.$thumb);check($repo->diagnostics(true)['missing_files']===1,'missing derivative detected');$repo->regenerate($a['id']);check($repo->diagnostics(true)['missing_files']===0,'repair regenerates missing derivative');
    F::write($work.'/fake.png','<html>not image</html>');rejects(fn()=>$repo->createFromPath($work.'/fake.png','fake.png'),'fake MIME rejected');
    rejects(fn()=>$repo->update($a['id'],['folder'=>'../etc']),'folder traversal rejected');
    rejects(fn()=>$repo->replace($a['id'],$red,'red.jpg'),'replacement MIME/extension mismatch rejected');
    check($repo->delete($private['id'])&&$repo->find($private['id'])===null&&!is_file($repo->sourcePath($private)),'unused private media deleted with files');
    $s3=new S3CompatibleDisk(['endpoint'=>'https://s3.example.test','bucket'=>'media','region'=>'eu-west-1','access_key'=>'example-key','secret_key'=>'example-secret']);$url=$s3->presignedUrl('media/example/file.png','GET',60,true);parse_str(parse_url($url,PHP_URL_QUERY),$params);check(strlen($params['X-Amz-Signature'])===64&&$params['X-Amz-Expires']==='60'&&$params['response-content-disposition']==='attachment','S3 SigV4 signed delivery URL generated');
    rejects(fn()=>$s3->presignedUrl('../secret'),'S3 path traversal rejected');
    // Portable package retains private visibility; no local replica credentials/keys exported.
    $secret=$repo->createFromPath($red,'private.png',['visibility'=>'private','title'=>'Private','folder'=>'Confidential']);
    $exp=(new \CajeerEngine\ImportExport\ImportExportService($work))->createExport(['media'],'media.zip');$zip=new PharData($exp['path']);$rows=json_decode($zip['data/media.json']->getContent(),true)['items'];$row=array_values(array_filter($rows,fn($r)=>$r['id']===$secret['id']))[0];check($row['visibility']==='private'&&isset($zip['media/'.$secret['checksum']]),'private media exported as portable binary with privacy metadata');unset($zip);
    $target=$work.'/target';F::dir($target.'/config');foreach(glob($root.'/config/*.php') as $f)copy($f,$target.'/config/'.basename($f));copy($root.'/VERSION',$target.'/VERSION');F::dir($target.'/storage/database');
    $targetDb=$target.'/storage/database/cajeer.sqlite';
    if(in_array('sqlite',PDO::getAvailableDrivers(),true)){$pdo=new PDO('sqlite:'.$targetDb);$pdo->exec('CREATE TABLE marker(value TEXT)');unset($pdo);}else{foreach(['/usr/local/bin/python3','/usr/bin/python3'] as $py)if(is_executable($py)){putenv('BACKUP_SQLITE_PYTHON='.$py);break;}\CajeerEngine\Backup\DatabaseBackup::run([getenv('BACKUP_SQLITE_PYTHON'),'-I','-c','import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); c.execute("CREATE TABLE marker(value TEXT)"); c.commit(); c.close()',$targetDb]);}
    if($db){$cfg=require $target.'/config/database.php';$cfg['connections']['sqlite']['database']=$targetDb;F::write($target.'/config/database.php','<?php return '.var_export($cfg,true).';');$mgr2=new \CajeerEngine\Database\DatabaseManager(new ConfigRepository($target));(new \CajeerEngine\Database\MigrationRunner($mgr2->connection(),$root.'/migrations'))->run('sqlite');$mgr2->disconnect();}
    F::write($work.'/backup.key',base64_encode(random_bytes(32)));putenv('BACKUP_KEY_FILE='.$work.'/backup.key');
    $targetRepo=new MediaRepository($target,new StorageManager($target,new ConfigRepository($target)));$same=$targetRepo->createFromPath($red,'public.png');
    $transfer=new \CajeerEngine\ImportExport\ImportExportService($target);$plan=$transfer->diff($exp['path'],['strategy'=>'update']);check($plan['ok']&&$plan['mapping']['media'][$secret['id']]!==$same['id'],'import never maps private bytes to public duplicate');
    $result=$transfer->import($exp['path'],['strategy'=>'update','plan'=>$plan['plan']]);check($result['ok'],'private media ZIP imported with verified backup');
    $copied=$targetRepo->find($plan['mapping']['media'][$secret['id']]);check($copied['visibility']==='private'&&F::hash($targetRepo->sourcePath($copied))===$secret['checksum']&&!is_file($target.'/public/uploads/'.$copied['path']),'imported private original remains outside public root');
    check($transfer->diff($exp['path'],['strategy'=>'update'])['summary']['create']===0,'private media reimport idempotent');
    check($repo->diagnostics(true)['ok'],'final catalog checksums consistent');
}finally{clean($work);}
