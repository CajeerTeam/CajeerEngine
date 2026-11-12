<?php
/** Publication-consistent search against the real repositories; --database uses PDO SQLite, --pgsql a disposable PostgreSQL database. */
declare(strict_types=1);
use CajeerEngine\Cms\{CmsBootstrapService,ThemeConfigRepository};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\{SearchFactory,PublicSearchPage,SearchDocument};
use CajeerEngine\Update\OperationFiles as F;
$root=dirname(__DIR__);require $root.'/core/Console/NativeCli.php';
function check(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function reject(callable $fn,string $s):void{try{$fn();}catch(Throwable){check(true,$s);return;}throw new RuntimeException('Expected rejection: '.$s);}
function clean(string $dir):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);}
$site=sys_get_temp_dir().'/ce-search-'.bin2hex(random_bytes(8));mkdir($site,0700);
$pg=in_array('--pgsql',$argv,true);$sql=$pg||in_array('--database',$argv,true);$driver=$pg?'pgsql':'sqlite';
try{
    if($pg&&getenv('CE_SEARCH_PG_TEST')!=='1')throw new RuntimeException('Set CE_SEARCH_PG_TEST=1 and use a dedicated empty database');
    foreach(['APP_ENV=testing','APP_URL=https://site.example','CONTENT_STORAGE='.($sql?'database':'file'),'CMS_STORAGE='.($sql?'database':'file'),'MEDIA_STORAGE=file','DB_DRIVER='.$driver,'STORAGE_DRIVER=local','SEARCH_DRIVER='.($pg?'pgsql':'file')]as $e)putenv($e);
    if(!$pg)putenv('SQLITE_DATABASE='.$site.'/storage/database/search.sqlite');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/config',FilesystemIterator::SKIP_DOTS))as $f)if($f->isFile())F::copy($f->getPathname(),$site.'/config/'.substr($f->getPathname(),strlen($root.'/config/')),0644);
    $cfg=new ConfigRepository($site);$db=new DatabaseManager($cfg);
    if($sql){$pdo=$db->connection();if($pg&&(int)$pdo->query("SELECT CASE WHEN to_regclass('ce_content_entries') IS NULL THEN 0 ELSE 1 END")->fetchColumn()===1)throw new RuntimeException('Use an empty PostgreSQL test database');(new MigrationRunner($pdo,$root.'/migrations'))->run($driver);}
    (new CmsBootstrapService($site))->run();$types=new ContentTypeRepository($site,$db);$entries=new ContentEntryRepository($site,$db);$theme=new ThemeConfigRepository($site,$db);$search=(new SearchFactory($site,$cfg,$db))->make();
    $theme->save(['locale_strategy'=>'prefix','enabled_locales'=>['ru','en']]);
    $schema=$types->find('pages');$schema['fields'][]=['handle'=>'private_note','type'=>'text','label'=>'Private note','settings'=>['private'=>true]];$schema['fields'][]=['handle'=>'hidden_text','type'=>'text','label'=>'Hidden text','settings'=>['searchable'=>false]];$types->update('pages',['fields'=>$schema['fields']]);
    $make=fn($title,$slug,$status='published',$data=[],$locale='ru')=>$entries->create('pages',['title'=>$title,'slug'=>$slug,'status'=>$status,'locale'=>$locale,'data'=>$data]);
    $home=$make('Home portal','home','published',['path'=>'/','body'=>'Welcome']);
    $parent=$make('Космос','space','published',['body'=>'<p>Orbit stellar</p>','private_note'=>'credentialsecret','hidden_text'=>'hiddenphrase']);
    $child=$make('Child stellar','child','published',['parent_id'=>$parent['id'],'body'=>'<p>galaxy</p>','blocks'=>[['handle'=>'text','data'=>['title'=>'Nebula','content'=>'<p>Interstellar cloud</p>']]]]);
    $draft=$make('Draft unpublishedsecret','draft','draft',['body'=>'stellar']);
    $noindex=$make('Noindex stellar','noindex','published',['seo_noindex'=>true]);
    $en=$make('English stellar','english','published',['body'=>'Galaxy','path'=>'/english'],'en');
    $body=$make('Another topic','body','published',['body'=>'<p>stellar</p><p>galaxy</p>']);
    $page=$search->page('stellar',null,2);
    check($page['meta']['total']===4&&count($page['data'])===2,'published-only index and exact total before pagination');
    check($page['meta']['facets']['locale']['ru']===3&&$page['meta']['facets']['locale']['en']===1,'language facet counts');
    check($page['meta']['facets']['type']['pages']===4,'type facet counts');
    $next=$search->page('stellar',null,2,2);check(count($next['data'])===2&&count(array_intersect(array_column($page['data'],'id'),array_column($next['data'],'id')))===0,'stable pagination with no duplicate results');
    check($search->page('stellar',null,100,0,'pages','en')['meta']['total']===1,'combined type and locale filters');
    check($search->page('stellar',null,100,0,'missing')['meta']['total']===0,'unknown type produces no results');
    check($search->page('КОСМОС')['data'][0]['source_id']===$parent['id'],'Russian case insensitive search');
    check($search->page('nebula')['data'][0]['url']==='/space/child','nested block text indexed with real hierarchical URL');
    check($search->page('galaxy')['meta']['total']===3,'rich text and block content searched');
    check($search->page('credentialsecret')['meta']['total']===0&&$search->page('hiddenphrase')['meta']['total']===0,'private and non-searchable fields never indexed');
    check($search->page('unpublishedsecret')['meta']['total']===0,'draft title never indexed');
    check($search->page('noindex')['meta']['total']===0,'noindex content excluded');
    check($search->page('English')['data'][0]['url']==='/en/english','localized canonical route');
    check(!isset($search->page('stellar')['data'][0]['raw'])&&!isset($search->page('stellar')['data'][0]['body']),'results do not disclose raw content');
    $entries->update('pages',$parent['id'],['data'=>['path'=>'/moved']]);check($search->page('nebula')['data'][0]['url']==='/moved/child','moving parent updates child URL without reindex');
    $entries->unpublish('pages',$parent['id']);check($search->page('nebula')['meta']['total']===0&&$search->page('КОСМОС')['meta']['total']===0,'unpublishing parent removes child and parent immediately');
    $entries->publish('pages',$parent['id']);check($search->page('nebula')['meta']['total']===1,'republishing parent restores child search');
    $entries->update('pages',$child['id'],['title'=>'Revised comet','data'=>['body'=>'replacement','blocks'=>[]]]);check($search->page('nebula')['meta']['total']===0&&$search->page('comet')['meta']['total']===1,'editing removes old terms and adds new title');
    $entries->restoreRevision('pages',$child['id'],1);check($search->page('nebula')['meta']['total']===0&&$search->page('comet')['meta']['total']===0,'revision restore creates draft and removes prior index');
    $entries->publish('pages',$child['id']);check($search->page('nebula')['meta']['total']===1,'republish restored revision indexes restored text');
    $entries->archive('pages',$child['id']);check($search->page('nebula')['meta']['total']===0,'archive removes document');
    $entries->publish('pages',$child['id']);$entries->delete('pages',$child['id']);check($search->page('nebula')['meta']['total']===0,'delete removes document');
    $entries->publish('pages',$draft['id']);check($search->page('unpublishedsecret')['meta']['total']===1,'publish adds draft immediately');
    $theme->save(['error_404_path'=>'/draft']);check($search->page('unpublishedsecret')['meta']['total']===0,'error page is excluded after theme config change');
    $theme->save(['error_404_path'=>'']);$theme->save(['enabled_locales'=>['ru']]);check($search->page('English')['meta']['total']===0,'disabled locale excluded');
    $theme->save(['enabled_locales'=>['ru','en']]);check($search->page('English')['meta']['total']===1,'reenabled locale indexed');
    reject(fn()=>$make('Reserved','search','published',['path'=>'/search']),'search route reserved from CMS content');
    reject(fn()=>$search->page(str_repeat('x',513)),'oversized query rejected');
    reject(fn()=>$search->page('stellar',null,20,0,'bad filter'),'invalid filter rejected');
    $html=PublicSearchPage::render($search->page('stellar',null,1));check(str_contains($html,'Найдено:')&&str_contains($html,'offset=1')&&str_contains($html,'noindex,follow'),'public search HTML with pagination and robots policy');
    $html=PublicSearchPage::render($search->page('<img src=x onerror=alert(1)>'));check(!str_contains($html,'<img src=x')&&str_contains($html,'&lt;img'),'query escaped in search form');
    if(!$sql){
        $path=$site.'/storage/app/search-index.json';$old=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        F::write($path,'{invalid');reject(fn()=>$entries->update('pages',$parent['id'],['title'=>'Broken write']),'corrupt projection aborts mutation');
        check($entries->findById('pages',$parent['id'])['title']==='Космос','failed projection rolls back content');
        $search->rebuild();check($search->page('КОСМОС')['meta']['total']===1,'explicit rebuild repairs corrupt file index');
        $pathContent=$site.'/storage/app/content/pages.json';$rows=json_decode(file_get_contents($pathContent),true,flags:JSON_THROW_ON_ERROR);foreach($rows['entries'] as &$row)if($row['id']===$parent['id'])$row['status']='draft';unset($row);F::json($pathContent,$rows,0644);
        check($search->page('КОСМОС')['meta']['total']===0,'out-of-band atomic edit detected before search returns stale result');
    }else{
        // Stale index row is removed by full reconcile. SQL transactions roll back the content write when projection fails.
        $pdo=$db->connection();$pdo->exec("UPDATE ce_cms_search SET digest='broken' WHERE entry_id=".$pdo->quote($parent['id']));$search->rebuild();check($pdo->query('SELECT COUNT(*) FROM ce_cms_search WHERE digest=\'broken\'')->fetchColumn()==0,'SQL rebuild replaces damaged projection row');
        if(!$pg){$pdo->exec("CREATE TRIGGER ce_search_fail BEFORE INSERT ON ce_cms_search BEGIN SELECT RAISE(ABORT,'projection failure'); END");reject(fn()=>$entries->update('pages',$parent['id'],['title'=>'Broken write']),'SQL projection failure aborts edit');check($entries->findById('pages',$parent['id'])['title']==='Космос','SQL rollback restores content');$pdo->exec('DROP TRIGGER ce_search_fail');}
    }
    $report=$search->rebuild();check($report['diagnostics']['healthy']&&$report['indexed']===$report['diagnostics']['documents'],'rebuild and diagnostics report actual catalog');
    check(SearchDocument::text('<p>Alpha</p><script>bad</script><p>Beta &amp; Gamma</p>')==='Alpha Beta & Gamma','search strips HTML and executable content');
    echo 'Search regression complete ('.($sql?$driver:'file').")\n";
}finally{clean($site);}
