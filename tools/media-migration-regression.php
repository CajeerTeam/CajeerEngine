<?php

declare(strict_types=1);
use CajeerEngine\Migration\{MediaMigration,WordPressMediaMetadata};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository,RevisionConflict};
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{$prefix='CajeerEngine\\';if(str_starts_with($class,$prefix)){$path=$root.'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path;}});
function verify(bool $condition,string $description):void{if(!$condition)throw new RuntimeException($description);echo "PASS $description\n";}
function refuse(callable $action,string $exception,string $description):void{try{$action();}catch(Throwable $error){if(!$error instanceof $exception)throw $error;verify(true,$description);return;}throw new RuntimeException('Expected rejection: '.$description);}
$base=sys_get_temp_dir().'/ce-media-migrate-'.bin2hex(random_bytes(6));mkdir($base,0700,true);mkdir($base.'/uploads/2020/01',0700,true);mkdir($base.'/uploads/posts',0700,true);
file_put_contents($base.'/uploads/2020/01/story.txt','A migration attachment');file_put_contents($base.'/uploads/posts/document.pdf',"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");file_put_contents($base.'/uploads/posts/unsupported.exe','do not import');
try{
    $scan=MediaMigration::discover($base.'/uploads');verify($scan['total_files']===3&&count($scan['files'])===2&&$scan['unsupported']===['posts/unsupported.exe'],'actual upload tree discovery, explicit unsupported inventory');
    verify(!MediaMigration::safeRelative('../secret')&&!MediaMigration::safeRelative('/etc/passwd')&&MediaMigration::safeRelative('2020/01/photo one.jpg'),'path traversal denied');
    refuse(fn()=>MediaMigration::validateOrigin('https://user:pass@old.example.com'),InvalidArgumentException::class,'credentials denied in site origin');
    refuse(fn()=>MediaMigration::validateOrigin('https://old.example.com:8443'),InvalidArgumentException::class,'nonstandard port denied in site origin');
    $map=MediaMigration::replacementMap(['2020/01/story.txt'=>'/uploads/2026/11/new.txt','posts/document.pdf'=>'/uploads/2026/11/document.pdf','2026/11/new.txt'=>'/uploads/2027/01/unrelated.txt'],'https://old.example.com',true);
    $input=['body'=>'<a href="https://old.example.com/wp-content/uploads/2020/01/story.txt">Story</a> <a href="/uploads/posts/document.pdf?dl=1">PDF</a> <a href="https://evil.example/uploads/posts/document.pdf">External</a>'];
    $changed=0;$after=MediaMigration::rewrite($input,$map,$changed);
    verify($changed===2&&str_contains($after['body'],'/uploads/2026/11/new.txt')&&!str_contains($after['body'],'unrelated.txt')&&str_contains($after['body'],'https://evil.example/uploads/posts/document.pdf'),'exact and non-cascading URL rewrite; external URL preserved');
    $nested=0;$data=MediaMigration::rewrite(['a'=>['x'=>'/wp-content/uploads/2020/01/story.txt']],$map,$nested);verify($nested===1&&$data['a']['x']==='/uploads/2026/11/new.txt','nested JSON field rewrites');
    verify(WordPressMediaMetadata::relative('https://old.example.com/wp-content/uploads/2020/01/story.txt')==='2020/01/story.txt'&&WordPressMediaMetadata::relative('2020/01/story.txt')==='2020/01/story.txt','WordPress attachment path normalization');
    verify(WordPressMediaMetadata::relative('../danger')===null,'unsafe WordPress attachment path rejected');
    putenv('MEDIA_STORAGE=file');putenv('MEDIA_MAX_UPLOAD_MB=5');putenv('MEDIA_REQUIRE_IMAGE_PROCESSOR=false');
    mkdir($base.'/media-test');mkdir($base.'/media-test/config');
    foreach(glob($root.'/config/*.php') as $configFile)copy($configFile,$base.'/media-test/config/'.basename($configFile));
    $mediaConfig=new ConfigRepository($base.'/media-test');
    $mediaRepo=new MediaRepository($base.'/media-test',new StorageManager($base.'/media-test',$mediaConfig));
    $item=$mediaRepo->createFromPath($base.'/uploads/2020/01/story.txt','story.txt',['deduplicate'=>true]);
    verify($item['checksum']===hash_file('sha256',$base.'/uploads/2020/01/story.txt')&&is_file($mediaRepo->sourcePath($item)),'actual target MediaRepository stores original attachment');
    $dupe=$mediaRepo->createFromPath($base.'/uploads/2020/01/story.txt','story.txt',['deduplicate'=>true]);
    verify($dupe['id']===$item['id']&&$mediaRepo->count()===1,'SHA-256 deduplication reuses verified physical file');
    $refs=MediaMigration::referencedPaths(['body'=>'<img src="https://old.example.com/wp-content/uploads/2020/01/story.txt"> <img src="https://evil.example/uploads/posts/document.pdf">'], 'https://old.example.com');
    verify($refs===['2020/01/story.txt'],'public media classification only trusts the configured source host');
    $missing=MediaMigration::missingReferences('<img src="/uploads/posts/missing.jpg"> <img src="https://evil.example/uploads/posts/document.pdf">',['/uploads/2026/11/new.txt'],'https://old.example.com');
    verify($missing===['/uploads/posts/missing.jpg'],'missing legacy attachment detection avoids unrelated hosts');
    if(class_exists(XMLReader::class)){
        $wxr=<<<'XML'
<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:wxr_version>1.2</wp:wxr_version><item><title>Attachment</title><wp:post_id>77</wp:post_id><wp:post_type>attachment</wp:post_type><wp:attachment_url>https://old.example.com/wp-content/uploads/2020/01/story.txt</wp:attachment_url></item><item><title>Post</title><wp:post_id>10</wp:post_id><wp:post_type>post</wp:post_type><wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value>77</wp:meta_value></wp:postmeta></item></channel></rss>
XML;
        $w=WordPressMediaMetadata::fromWxr($wxr);
        verify($w['attachments']['77']==='https://old.example.com/wp-content/uploads/2020/01/story.txt'&&$w['featured']['10']==='77','real WXR attachment and featured image metadata parser');
    }elseif(in_array('--require-xml',$argv,true))throw new RuntimeException('XMLReader is required');
    else echo "SKIP WXR parser: ext-xmlreader unavailable\n";
    @symlink('/etc/passwd',$base.'/uploads/posts/leak.txt');
    if(is_link($base.'/uploads/posts/leak.txt')){refuse(fn()=>MediaMigration::discover($base.'/uploads'),RuntimeException::class,'symlink inside source tree rejected');unlink($base.'/uploads/posts/leak.txt');}
    if(!in_array('sqlite',PDO::getAvailableDrivers(),true)||!class_exists(DOMDocument::class)||!function_exists('mb_strlen')){
        if(in_array('--require-db',$argv,true))throw new RuntimeException('PDO SQLite, DOM and mbstring required for media migration SQL regression.');
        echo "SKIP full PHP media SQLite round-trip: PDO SQLite, DOM or mbstring unavailable\n";exit(0);
    }
    mkdir($base.'/target');mkdir($base.'/target/config');
    foreach(glob($root.'/config/*.php') as $file)copy($file,$base.'/target/config/'.basename($file));
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$base.'/target/site.sqlite');putenv('CONTENT_STORAGE=database');putenv('CMS_STORAGE=database');putenv('MEDIA_STORAGE=database');putenv('MEDIA_REQUIRE_IMAGE_PROCESSOR=false');putenv('MEDIA_MAX_UPLOAD_MB=5');
    $config=new ConfigRepository($base.'/target');$db=new DatabaseManager($config);$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
    $types=new ContentTypeRepository($base.'/target',$db);$entries=new ContentEntryRepository($base.'/target',$db);
    $types->create(['handle'=>'articles','name'=>'Articles','fields'=>[['handle'=>'body','label'=>'Body','type'=>'textarea','required'=>true],['handle'=>'cover','label'=>'Cover','type'=>'media']]]);
    $entry=$entries->create('articles',['title'=>'Imported article','slug'=>'imported-story','status'=>'published','locale'=>'ru','data'=>['body'=>'<a href="https://old.example.com/wp-content/uploads/2020/01/story.txt">Story</a> <a href="/uploads/posts/document.pdf">PDF</a>']]);
    $id=str_repeat('a',32);$plan=str_repeat('b',64);
    $receipt=['project_id'=>$id,'plan_sha256'=>$plan,'records'=>[['source_type'=>'post','source_id'=>'10','target_type'=>'articles','target_id'=>$entry['id'],'revision'=>$entry['revision'],'action'=>'create']]];
    $stmt=$pdo->prepare('INSERT INTO ce_wordpress_migration_runs (project_id,plan_sha256,completed_at,result) VALUES (:id,:sha,:date,:result)');
    $stmt->execute(['id'=>$id,'sha'=>$plan,'date'=>gmdate(DATE_ATOM),'result'=>json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $media=new MediaRepository($base.'/target',new StorageManager($base.'/target',$config),$db);
    $service=new MediaMigration($db,$entries,$media,$types);$project=['id'=>$id,'source'=>'wordpress-db','plan'=>['sha256'=>$plan]];
    $result=$service->execute($project,$base.'/uploads','https://old.example.com');
    $after=$entries->find('articles',$entry['id']);
    verify($result['new_media_files']===2&&$result['rewritten_links']===2&&$result['rewritten_entries']===1,'real media assets imported and content links rewritten');
    verify(!str_contains($after['data']['body'],'old.example.com')&&str_contains($after['data']['body'],'/uploads/'),'stored content references target media URLs');
    verify($media->count()===2&&$service->report($id)['rewritten_links']===2,'media records and durable receipts persisted');
    $second=$service->execute($project,$base.'/uploads','https://old.example.com');
    verify($second['new_media_files']===0&&$second['rewritten_entries']===0&&$media->count()===2,'replayed media import creates no duplicate files or revisions');
    file_put_contents($base.'/uploads/2020/01/story.txt','source was changed');
    refuse(fn()=>$service->execute($project,$base.'/uploads','https://old.example.com'),RevisionConflict::class,'changed media file blocked from overwriting prior migration');
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir()&&!$item->isLink())@rmdir($item->getPathname());else @unlink($item->getPathname());}
    @rmdir($base);
}
