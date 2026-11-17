<?php
declare(strict_types=1);
use CajeerEngine\Migration\{SeoMigration,SeoMetadata,PlanningSource};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Public\{RouteIndex,PublicRouteResolver};
$root=dirname(__DIR__);spl_autoload_register(static function(string $c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$file=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($file))require $file;}});
function seoCheck(bool $cond,string $label):void{if(!$cond)throw new RuntimeException($label);echo "PASS $label\n";}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)||!class_exists(DOMDocument::class)||!function_exists('mb_strlen')){
    if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite, DOM and mbstring required');
    echo "SKIP PHP SQL SEO integration (pdo_sqlite, DOM or mbstring unavailable)\n";exit(0);
}
$work=sys_get_temp_dir().'/ce-seo-sql-'.bin2hex(random_bytes(8));mkdir($work,0700,true);mkdir($work.'/config');foreach(glob($root.'/config/*.php') as $file)copy($file,$work.'/config/'.basename($file));
putenv('APP_URL=https://new.example.com');putenv('CONTENT_STORAGE=database');putenv('CMS_STORAGE=database');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$work.'/target.sqlite');
try{
    $db=new DatabaseManager(new ConfigRepository($work));(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
    $types=new ContentTypeRepository($work,$db);$types->create(['handle'=>'articles','name'=>'Articles','fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext','required'=>true]]]);
    $entries=new ContentEntryRepository($work,$db);$routes=new RouteIndex($work,$db);$redir=new RedirectRepository($work,$db);
    $seo=new SeoMigration($work,$db,$entries);
    $entry=$entries->create('articles',['title'=>'Target post','slug'=>'new','status'=>'published','locale'=>'ru','data'=>['body'=>'<a href="https://old.example.com/old-post.html">old</a>']]);
    $origin=['id'=>'42','title'=>'Source','body'=>'Old','slug'=>'old-post','date'=>'2025-01-01 12:00:00','author'=>'','status'=>'publish'];
    $record=PlanningSource::record('post',$origin,['meta:dle_seo_title'=>'SEO Title','meta:dle_seo_description'=>'Migrated description']);$record['origin']='dle';
    $import=['source_type'=>'post','source_id'=>'42','target_type'=>'articles','target_id'=>$entry['id'],'action'=>'create','revision'=>$entry['revision']];
    $project=['id'=>str_repeat('a',32),'status'=>'completed','source'=>'dle-db','execution'=>['plan_sha256'=>str_repeat('b',64),'records'=>[$import]]];
    $inv=PlanningSource::inventory([$record],[]);
    $q=$db->connection()->prepare('INSERT INTO ce_dle_migration_runs(project_id,plan_sha256,completed_at,result) VALUES(?,?,?,?)');
    $q->execute([$project['id'],$project['execution']['plan_sha256'],gmdate(DATE_ATOM),json_encode($project['execution'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $report=$seo->execute($project,$inv,'https://old.example.com',['post:42'=>['/old-post.html']]);
    seoCheck($report['new_redirects']===1&&$report['rewritten_links']===1&&$report['metadata_entries']===1,'real SQL SEO, redirect and in-content URL changes committed');
    $m=(new SeoMetadata($work,$db))->get($entry['id']);seoCheck($m['title']==='SEO Title'&&$m['description']==='Migrated description'&&$m['canonical_url']==='https://new.example.com/new','SEO overlay persists and points canonical to destination');
    seoCheck($redir->byPath('/old-post.html')['target_url']==='/new'&&(new PublicRouteResolver($routes,$redir))->resolve('/old-post.html')['status']===301,'public request resolves migrated old URL to 301');
    $updated=$entries->findById('articles',$entry['id']);seoCheck(str_contains($updated['data']['body'],'href="/new"'),'verified real content link rewrite');
    seoCheck($seo->execute($project,$inv,'https://old.example.com',['post:42'=>['/old-post.html']])===$report,'completed stage is idempotent');
    try{$seo->execute($project,$inv,'https://old.example.com',['post:42'=>['/other.html']]);throw new RuntimeException('changed import rerun incorrectly accepted');}catch(RevisionConflict){seoCheck(true,'changed legacy URL map rejected after committed SEO migration');}
    $wp=$entries->create('articles',['title'=>'WP Target','slug'=>'wp-target','status'=>'published','locale'=>'ru','data'=>['body'=>'WordPress']]);
    $wrec=PlanningSource::record('post',['id'=>'99','title'=>'WP Source','slug'=>'wp-old','body'=>'WP Body','date'=>'2025-01-01 12:00:00'],['meta:_yoast_wpseo_meta-robots-noindex'=>'1']);$wrec['origin']='wordpress';
    $wproject=['id'=>str_repeat('c',32),'status'=>'completed','source'=>'wordpress-db','execution'=>['plan_sha256'=>str_repeat('d',64),'records'=>[['source_type'=>'post','source_id'=>'99','target_type'=>'articles','target_id'=>$wp['id'],'action'=>'create','revision'=>$wp['revision']]]]];
    $q=$db->connection()->prepare('INSERT INTO ce_wordpress_migration_runs(project_id,plan_sha256,completed_at,result) VALUES(?,?,?,?)');
    $q->execute([$wproject['id'],$wproject['execution']['plan_sha256'],gmdate(DATE_ATOM),json_encode($wproject['execution'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $wr=$seo->execute($wproject,PlanningSource::inventory([$wrec],[]),'https://old.example.com',['post:99'=>['/?p=99']]);
    seoCheck($wr['new_query_redirects']===1&&$redir->legacyQueryTarget('p:99')==='/wp-target','WordPress plain permalink resolves to migrated page');
    seoCheck(isset((new SeoMetadata($work,$db))->noindexIds()[$wp['id']]),'WordPress noindex is persisted and indexed for sitemap exclusion');
    $stale=$entries->create('articles',['title'=>'Stale','slug'=>'stale','status'=>'published','locale'=>'ru','data'=>['body'=>'Stale']]);
    $r=['source_type'=>'post','source_id'=>'5','target_type'=>'articles','target_id'=>$stale['id'],'action'=>'create','revision'=>$stale['revision']];
    $entries->update('articles',$stale['id'],['title'=>'Edited','slug'=>'stale','status'=>'published','locale'=>'ru','data'=>['body'=>'Edited'],'expected_revision'=>$stale['revision']]);
    $sc=PlanningSource::record('post',['id'=>'5','title'=>'Old','slug'=>'stale','body'=>'Old','date'=>'2025-01-01 12:00:00']);$sc['origin']='dle';
    $staleProject=['id'=>str_repeat('f',32),'status'=>'completed','source'=>'dle-db','execution'=>['plan_sha256'=>str_repeat('b',64),'records'=>[$r]]];
    $q=$db->connection()->prepare('INSERT INTO ce_dle_migration_runs(project_id,plan_sha256,completed_at,result) VALUES(?,?,?,?)');
    $q->execute([$staleProject['id'],$staleProject['execution']['plan_sha256'],gmdate(DATE_ATOM),json_encode($staleProject['execution'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    try{$seo->execute(['id'=>str_repeat('f',32),'status'=>'completed','source'=>'dle-db','execution'=>['plan_sha256'=>str_repeat('b',64),'records'=>[$r]]],PlanningSource::inventory([$sc],[]),'https://old.example.com',['post:5'=>['/stale-old.html']]);throw new RuntimeException('stale entry update accepted');}catch(RevisionConflict){seoCheck(true,'edited destination causes pre-write rejection');}
    seoCheck($redir->byPath('/stale-old.html')===null&&$db->connection()->query("SELECT COUNT(*) FROM ce_migration_seo_runs WHERE project_id='".str_repeat('f',32)."'")->fetchColumn()==0,'conflict leaves no half-applied SEO/redirect result');
    echo "PHP SQL SEO integration complete.\n";
}finally{
    // Test-only temporary files are not part of the delivered patch.
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $path)$path->isDir()?rmdir($path->getPathname()):unlink($path->getPathname());rmdir($work);
}
