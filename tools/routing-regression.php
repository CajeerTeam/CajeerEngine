<?php
/** Functional route tests against real repositories; --database uses migrated PDO SQLite. */
declare(strict_types=1);
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Cms\{CmsBootstrapService,ThemeConfigRepository,RedirectRepository,PreviewTokenService,NavigationRepository};
use CajeerEngine\Public\{RouteIndex,PublicRouteResolver,PublicPageRenderer,PublicPath,RouteGraph};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
$root=dirname(__DIR__);require $root.'/core/Console/NativeCli.php';
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function reject(callable $fn,string $msg):void{try{$fn();}catch(InvalidArgumentException|DomainException){check(true,$msg);return;}throw new RuntimeException('Expected validation failure: '.$msg);}
function cleanup(string $p):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($p);}
$dbMode=in_array('--database',$argv,true);if($dbMode&&!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required');
$site=sys_get_temp_dir().'/ce-routing-'.bin2hex(random_bytes(8));mkdir($site,0700);
try {
    putenv('APP_ENV=testing');putenv('APP_URL=https://site.example');putenv('CONTENT_STORAGE='.($dbMode?'database':'file'));putenv('CMS_STORAGE='.($dbMode?'database':'file'));putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE=storage/database/cajeer.sqlite');putenv('STORAGE_DRIVER=local');
    F::dir($site.'/config');F::dir($site.'/storage/database');foreach(glob($root.'/config/*.php')as $f)copy($f,$site.'/config/'.basename($f));
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/templates',FilesystemIterator::SKIP_DOTS))as $f)if($f->isFile())F::copy($f->getPathname(),$site.'/templates/'.substr($f->getPathname(),strlen($root.'/templates/') ),0644);
    if($dbMode){$cfg=require $site.'/config/database.php';$cfg['connections']['sqlite']['database']=$site.'/storage/database/cajeer.sqlite';F::write($site.'/config/database.php','<?php return '.var_export($cfg,true).';');}
    $cfg=new ConfigRepository($site);$db=new DatabaseManager($cfg);if($dbMode)(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
    (new CmsBootstrapService($site))->run();$types=new ContentTypeRepository($site,$db);$entries=new ContentEntryRepository($site,$db);$theme=new ThemeConfigRepository($site,$db);$redirects=new RedirectRepository($site,$db);$idx=new RouteIndex($site,$db);$router=new PublicRouteResolver($idx,$redirects);
    $renderer=new PublicPageRenderer($site,$types,$entries,new CajeerEngine\Template\CajeerTemplateEngine(new CajeerEngine\Template\TemplateSandbox($site.'/templates'),$site.'/templates'),new CajeerEngine\Media\MediaRepository($site,new CajeerEngine\Storage\StorageManager($site,$cfg)),$theme,new NavigationRepository($site,$db),$idx);
    $make=fn($slug,$data=[],$locale='ru',$status='published',$extra=[])=>$entries->create('pages',['title'=>'Page '.$slug,'slug'=>$slug,'locale'=>$locale,'status'=>$status,'data'=>$data]+$extra);
    $resolve=fn($path)=>$router->resolve($path);$page=fn($path)=>$idx->lookup($path)['id']??null;
    $parent=$make('company',['path'=>'/company']);check($resolve('/')['status']===404&&$renderer->renderHome()===null,'no arbitrary homepage fallback');
    $home=$make('home',['path'=>'/']);check($page('/')===$home['id']&&str_contains($renderer->renderHome()['html'],'Page home'),'indexed homepage rendered');
    $child=$make('team',['parent_id'=>$parent['id']]);$leaf=$make('people',['parent_id'=>$child['id']]);check($page('/company/team/people')===$leaf['id'],'nested tree path');
    check($resolve('/company/team/')['status']===308&&$resolve('/company/team/')['target']==='/company/team','canonical slash redirect');
    $entries->update('pages',$parent['id'],['data'=>['path'=>'/about']]);check($resolve('/company/team')['target']==='/about/team'&&$page('/about/team/people')===$leaf['id'],'move rewrites descendants and creates aliases');
    $entries->update('pages',$parent['id'],['data'=>['path'=>'/group']]);check($resolve('/company/team')['target']==='/group/team'&&$resolve('/about/team')['target']==='/group/team','aliases collapse directly to current route');
    reject(fn()=>$make('collision',['path'=>'/group/team']),'publication path collision rejected');check($entries->find('pages','collision')===null,'collision rolls back source write');
    reject(fn()=>$entries->update('pages',$parent['id'],['data'=>['parent_id'=>$leaf['id']]]),'parent cycle rejected');check($page('/group')===$parent['id'],'cycle leaves prior route intact');
    reject(fn()=>$entries->delete('pages',$parent['id']),'cannot delete parent with children');
    reject(fn()=>$make('bad',['path'=>'/api/v1/private']),'reserved system path rejected');reject(fn()=>$make('bad',['path'=>'/%2fadmin']),'encoded separator rejected');
    $theme->save(['locale_strategy'=>'prefix','locale'=>'ru','enabled_locales'=>['ru','en']]);$en=$make('home',['path'=>'/'],'en','published',['translation_group'=>$home['translation_group']]);check($page('/en')===$en['id']&&$page('/')===$home['id'],'locale homepage prefixes');
    $enTeam=$make('team',['path'=>'/team'],'en');check($page('/en/team')===$enTeam['id'],'same slug different locale');reject(fn()=>$make('cross',['parent_id'=>$parent['id']],'en'),'cross-locale parent rejected');
    reject(fn()=>$entries->delete('pages','team'),'ambiguous deletion requires UUID');check($entries->findById('pages',$child['id'])!==null&&$entries->findById('pages',$enTeam['id'])!==null,'ambiguous deletion preserves translations');
    $draft=$make('hidden',['parent_id'=>$child['id']],'ru','draft');check($resolve('/group/team/hidden')['status']===404&&$idx->previewPath($draft['id'])==='/group/team/hidden','draft excluded from public index');
    $token=(new PreviewTokenService($site,$db))->create('pages',$draft['id']);check((new PreviewTokenService($site,$db))->resolve($token['token'])['entry_id']===$draft['id']&&$renderer->renderPreviewId('pages',$draft['id'])['path']==='/group/team/hidden','preview token resolves exact draft ID');
    $entries->unpublish('pages',$parent['id']);check($resolve('/group/team')['status']===404&&$resolve('/company/team')['status']===404,'unpublished ancestor removes descendants and aliases');$entries->publish('pages',$parent['id']);check($page('/group/team')===$child['id'],'republish restores descendant routes');
    $redirects->save(['source_path'=>'/old','target_url'=>'/group/team','status_code'=>302]);check($resolve('/old')['status']===302&&$redirects->byPath('/old')['hit_count']===1,'explicit redirect and hit counter');$redirects->save(['source_path'=>'/old','target_url'=>'/group/team','status_code'=>307]);check($redirects->byPath('/old')['hit_count']===1&&$resolve('/old')['status']===307,'redirect update retains hit counter');
    $redirects->save(['source_path'=>'/gone','status_code'=>410]);check($resolve('/gone')['status']===410,'410 gone rule');reject(fn()=>$redirects->save(['source_path'=>'/self','target_url'=>'https://site.example/self']),'same-origin loop rejected');$redirects->save(['source_path'=>'/a','target_url'=>'/b']);reject(fn()=>$redirects->save(['source_path'=>'/b','target_url'=>'/a']),'multi-hop loop rejected');
    $redirects->save(['source_path'=>'/group','target_url'=>'/gone']);check($resolve('/group')['status']===200,'live route wins over redirect');
    $error=$make('missing',['path'=>'/not-found']);$theme->save(['error_404_path'=>'/not-found']);check($renderer->errorStatus('/not-found')===404&&str_contains($renderer->errorPage(404)['html'],'Page missing'),'custom 404 rendered');
    $no=$make('private-index',['seo_noindex'=>true]);$canonical=$make('alternate',['canonical_url'=>'/group']);$xml=$renderer->sitemapXml($renderer->baseUrl());check(str_contains($xml,'https://site.example/en')&&str_contains($xml,'hreflang="x-default"')&&!str_contains($xml,'/not-found')&&!str_contains($xml,'/private-index')&&!str_contains($xml,'/alternate'),'sitemap locale alternates, error and noindex exclusion');
    check($renderer->renderByPath('/alternate')['seo']['canonical_url']==='https://site.example/group','canonical configured origin');
    $nav=new NavigationRepository($site,$db);$nav->save('primary','Primary',[['label'=>'Group menu','url'=>'/old-group','entry_id'=>$parent['id'],'entry_type'=>'pages']]);check(str_contains($renderer->renderHome()['html'],'href="/group"'),'menu links follow indexed path');
    $first=$idx->page(2,0);$second=$idx->page(2,2);check(count($first['data'])===2&&count($second['data'])===2&&$first['data'][1]['path']!==$second['data'][0]['path']&&$first['meta']['total']>4,'route list pagination');
    check($idx->check()['ok']&&$idx->rebuild()['ok'],'consistent route index and rebuild');
    $theme->save(['robots_index'=>false]);check($renderer->sitemap()===[]&&!str_contains($renderer->robotsTxt($renderer->baseUrl()),'Sitemap:'),'robots disables sitemap exposure');
    check(PublicPath::normalize('/привет/')==='/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82'&&$resolve('/%2e%2e')['status']===404,'UTF-8 path canonicalization and traversal rejected');
    if($dbMode){$plan=$db->connection()->query("EXPLAIN QUERY PLAN SELECT payload FROM ce_public_routes WHERE route_key='".hash('sha256','/group')."'")->fetchAll(PDO::FETCH_ASSOC);check(str_contains(json_encode($plan),'INDEX'),'SQL hot lookup uses route primary key index');}
    else { $file=$site.'/storage/app/content/pages.json';$raw=F::read($file);foreach($raw['entries']as &$r)if($r['id']===$parent['id'])$r['status']='draft';unset($r);F::json($file,$raw,0644);check($resolve('/group/team')['status']===404,'file index detects out-of-band publication change'); }
    $theme->save(['robots_index'=>true]);
    $idx->mutate(function()use($make){for($i=0;$i<501;$i++)$make('bulk-'.$i);},['storage/app/content/pages.json']);
    $siteMapIndex=$renderer->sitemapXml($renderer->baseUrl());$part1=$renderer->sitemapXml($renderer->baseUrl(),1);$part2=$renderer->sitemapXml($renderer->baseUrl(),2);
    check(str_contains($siteMapIndex,'<sitemapindex')&&str_contains($siteMapIndex,'?page=2')&&substr_count($part1,'<url>')===500&&substr_count($part2,'<url>')>0,'real sitemap pagination over 500 entries');
    reject(fn()=>$renderer->sitemapXml($renderer->baseUrl(),999),'invalid sitemap page rejected');
    $large=[];for($i=0;$i<10000;$i++)$large[]=['id'=>'p'.$i,'type'=>'pages','title'=>'Page '.$i,'slug'=>'p'.$i,'status'=>'published','locale'=>'ru','data'=>[]];check(count(RouteGraph::build($large,['locale'=>'ru']))===10000,'10,000 routes build without request-time entry scanning');
    echo "Routing regression complete (".($dbMode?'database':'file').")\n";
}finally{cleanup($site);}
