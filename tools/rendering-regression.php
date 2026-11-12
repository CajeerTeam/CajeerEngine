<?php
/** Real CMS repository, media and template rendering; use --database for migrated PDO SQLite. */
declare(strict_types=1);
use CajeerEngine\Cms\{CmsBootstrapService,ThemeConfigRepository,NavigationRepository};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Public\PublicPageRenderer;
use CajeerEngine\Template\{CajeerTemplateEngine,TemplateSandbox};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
$root=dirname(__DIR__);require $root.'/core/Console/NativeCli.php';
function check(bool $ok,string $msg):void { if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n"; }
function cleanup(string $p):void {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($p);}
$site=sys_get_temp_dir().'/ce-render-'.bin2hex(random_bytes(8));mkdir($site,0700);
$dbMode=in_array('--database',$argv,true);
try{
    foreach(['APP_ENV=testing','APP_URL=https://site.example','CONTENT_STORAGE='.($dbMode?'database':'file'),'CMS_STORAGE='.($dbMode?'database':'file'),'MEDIA_STORAGE='.($dbMode?'database':'file'),'DB_DRIVER=sqlite','STORAGE_DRIVER=local','PUBLIC_RENDER_CACHE_TTL=60','MEDIA_REQUIRE_IMAGE_PROCESSOR=false'] as $e)putenv($e);
    foreach(['config','templates'] as $dir)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS))as $f)if($f->isFile())F::copy($f->getPathname(),$site.'/'.substr($f->getPathname(),strlen($root)+1),0644);
    if($dbMode){F::dir($site.'/storage/database');$cfg=require $site.'/config/database.php';$cfg['connections']['sqlite']['database']=$site.'/storage/database/cajeer.sqlite';F::write($site.'/config/database.php','<?php return '.var_export($cfg,true).';');}
    $cfg=new ConfigRepository($site);$db=new DatabaseManager($cfg);if($dbMode)(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
    $types=new ContentTypeRepository($site,$db);$entries=new ContentEntryRepository($site,$db);$theme=new ThemeConfigRepository($site,$db);$nav=new NavigationRepository($site,$db);
    $boot=new CmsBootstrapService($site);$boot->run();check(count($types->find('pages')['blocks'])===6,'default page block definitions');
    $old=$types->find('pages')['blocks'];$types->update('pages',['blocks'=>array_slice($old,0,1)]);$boot->run();check(count($types->find('pages')['blocks'])===6,'existing pages schema extended without replacement');
    $media=new MediaRepository($site,new StorageManager($site,$cfg),$db);
    $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZsAAAAASUVORK5CYII=';
    $image=$media->createFromBase64(['filename'=>'public.png','content_base64'=>$png,'alt'=>'Example image']);
    $private=$media->createFromBase64(['filename'=>'private.png','content_base64'=>$png,'visibility'=>'private']);
    $data=['path'=>'/','body'=>'<p>Introduction</p>','blocks'=>[
      ['handle'=>'hero','data'=>['title'=>'Main hero','content'=>'<p>Hero content</p>','image'=>$image['id'],'button_label'=>'Go','button_url'=>'/about']],
      ['handle'=>'cta','data'=>['title'=>'Contact','content'=>'Call us','button_label'=>'Bad','button_url'=>'javascript:alert(1)']],
      ['handle'=>'gallery','data'=>['images'=>[$image['id'],$private['id']]]],
      ['handle'=>'columns','data'=>['left'=>'Left side','right'=>'Right side']],
    ]];
    $home=$entries->create('pages',['title'=>'Actual homepage','slug'=>'home','status'=>'published','data'=>$data]);
    $engine=new CajeerTemplateEngine(new TemplateSandbox($site.'/templates'),$site.'/templates',$site.'/storage/cache/templates');
    $renderer=new PublicPageRenderer($site,$types,$entries,$engine,$media,$theme,$nav);
    $html=$renderer->renderHome()['html'];
    check(str_contains($html,'Main hero')&&str_contains($html,'Hero content')&&str_contains($html,'Contact')&&str_contains($html,'Left side'),'structured blocks render actual data');
    check(str_contains($html,'Example image')&&!str_contains($html,'javascript:')&&!str_contains($html,$private['id']),'public media and safe CTA; no private media');
    check(str_contains($html,'href="/about"'),'CTA renders valid link');
    $cache=$site.'/storage/cache/public/'.hash('sha256','/').'.json';check(is_file($cache),'public cache written');
    $before=file_get_contents($cache);$renderer->renderHome();check(file_get_contents($cache)===$before,'unchanged render cache reused');
    $entry=$entries->update('pages',$home['id'],['title'=>'Changed homepage','expected_revision'=>$home['revision']]);
    check(str_contains($renderer->renderHome()['html'],'Changed homepage'),'revision invalidates HTML cache');
    try{$entries->update('pages',$home['id'],['title'=>'Lost update','expected_revision'=>$home['revision']]);throw new RuntimeException('stale write accepted');}catch(RevisionConflict){}check($entries->findById('pages',$home['id'])['title']==='Changed homepage','stale edit rejected');
    $theme->save(['active_theme'=>'editorial']);check(str_contains($renderer->renderHome()['html'],'ce-editorial'),'selected built-in theme renders');
    $theme->save(['active_theme'=>'default']);check(!str_contains($renderer->renderHome()['html'],'ce-editorial'),'switch back to default');
    $nav->save('primary','Primary',[['label'=>'New menu','url'=>'/about']]);check(str_contains($renderer->renderHome()['html'],'New menu'),'navigation invalidates cache');
    $media->update($image['id'],['alt'=>'Updated alt']);check(str_contains($renderer->renderHome()['html'],'Updated alt'),'referenced media invalidates cache');
    $draft=$entries->create('pages',['title'=>'Private draft','slug'=>'draft','status'=>'draft','data'=>['path'=>'/draft']]);
    check($renderer->renderByPath('/draft')===null&&str_contains($renderer->renderPreviewId('pages',$draft['id'])['html'],'ce-preview'),'preview only by ID');
    check(!is_file($site.'/storage/cache/public/'.hash('sha256','/draft').'.json'),'preview never cached');
    $rev=$entries->restoreRevision('pages',$home['id'],1);check($rev['title']==='Actual homepage','revision restore real repository');$entries->publish('pages',$home['id']);
    $translation=$entries->createLocalization('pages',$home['id'],['title'=>'English','slug'=>'home','locale'=>'en','status'=>'draft','data'=>[]]);check($translation['translation_group']===$home['translation_group'],'translation group preserved');
    $noindex=$entries->create('pages',['title'=>'Noindex page','slug'=>'secret-seo','status'=>'published','data'=>['seo_noindex'=>true]]);
    $export=(new CajeerEngine\StaticExport\SiteExport($site))->run();$target=$export['path'];
    check(str_contains(file_get_contents($target.'/index.html'),'Actual homepage'),'static export preserves actual homepage');
    check(is_file($target.'/secret-seo/index.html')&&!is_file($target.'/draft/index.html'),'static export includes published noindex, excludes draft');
    check(is_file($target.'/uploads/'.$image['path'])&&!is_file($target.'/uploads/'.$private['path']),'static export only public media');
    // Public/private same bytes are distinct paths; check no private directory.
    $entries->unpublish('pages',$noindex['id']);(new CajeerEngine\StaticExport\SiteExport($site))->run();check(!is_file($target.'/secret-seo/index.html'),'second export removes stale pages');
    $p=$site.'/templates/default/page.cjr';$source=file_get_contents($p);$mtime=filemtime($p);file_put_contents($p,$source."\n<!-- new-template -->\n");touch($p,$mtime);check(str_contains($renderer->renderHome()['html'],'new-template'),'template content change invalidates compiled and rendered cache even same mtime');
    echo "Rendering regression complete\n";
}finally{cleanup($site);}
