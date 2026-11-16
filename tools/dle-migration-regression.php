<?php
declare(strict_types=1);
use CajeerEngine\Migration\{DleContent,DleImporter,MigrationCenter,MigrationPlanner,PlanningSource,SourceDiscovery};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Public\RouteIndex;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function reject(callable $fn,string $class,string $name):void{try{$fn();}catch(Throwable $e){if(!$e instanceof $class)throw $e;check(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
check(DleImporter::date('2020-02-29 12:34:56')==='2020-02-29 12:34:56','valid leap date');
check(DleImporter::date('2021-02-29 12:34:56')===null&&DleImporter::date('2020-01-01 25:00:00')===null,'invalid date rejected');
check(DleImporter::date('1577836800')==='2020-01-01 00:00:00','Unix source date');
$record=PlanningSource::record('post',['id'=>'1','title'=>'News','body'=>'[b]Body[/b]<script>bad()</script>','slug'=>'news','date'=>'2020-01-01 12:00:00','status'=>'publish','author'=>'writer'],['meta:category_tree'=>[[['id'=>'1','name'=>'Root'],['id'=>'2','name'=>'Child']]],'meta:rating'=>'4.5']);$record['origin']='dle';
$type=['handle'=>'articles','fields'=>[['handle'=>'body','type'=>'richtext','required'=>true],['handle'=>'rating','type'=>'number'],['handle'=>'tree','type'=>'json']]];
$rule=['target'=>'articles','fields'=>['data:body'=>['from'=>'body','transform'=>'copy'],'data:rating'=>['from'=>'meta:rating','transform'=>'number'],'data:tree'=>['from'=>'meta:category_tree','transform'=>'copy']]];
$mapping=['types'=>['post'=>$rule,'page'=>$rule],'statuses'=>['publish'=>'published','draft'=>'draft'],'users'=>['writer'=>'skip'],'locale'=>'ru','conflict'=>'fail'];
if(class_exists(DOMDocument::class)){
 $html=DleContent::html('[b]bold[/b]<img src="javascript:bad()" onerror="bad()"><script>bad()</script>');check(str_contains($html,'<strong>bold</strong>')&&!str_contains($html,'javascript:')&&!str_contains($html,'onerror')&&!str_contains($html,'bad()'),'BBCode converted and unsafe HTML removed');
 $plan=MigrationPlanner::build(PlanningSource::inventory([$record],[]),$mapping,['articles'=>$type],[],[],fn($e)=>null);
 $payload=MigrationPlanner::payload($record,$rule,$type,'ru','published');
 check($plan['approvable']&&$plan['records'][0]['payload_sha256']===MigrationPlanner::fingerprint($payload)&&str_contains($payload['data']['body'],'<strong>Body</strong>')&&!str_contains($payload['data']['body'],'<script')&&$payload['data']['tree'][0][1]['name']==='Child','planned payload matches safe executable payload and categories');
}else {reject(fn()=>DleContent::html('unsafe'),RuntimeException::class,'DLE fails closed without DOM');if(in_array('--require-db',$argv,true))throw new RuntimeException('DOM required');}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)||!class_exists(DOMDocument::class)){if(in_array('--require-db',$argv,true))throw new RuntimeException('PDO SQLite and DOM required');echo "SKIP DLE SQL execution (pdo_sqlite/DOM unavailable)\n";exit(0);}
$work=sys_get_temp_dir().'/ce-dle-'.bin2hex(random_bytes(8));mkdir($work,0700);mkdir($work.'/config');foreach(glob($root.'/config/*.php') as $f)copy($f,$work.'/config/'.basename($f));
putenv('CONTENT_STORAGE=database');putenv('CMS_STORAGE=database');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$work.'/test.sqlite');
try{
 $db=new DatabaseManager(new ConfigRepository($work));$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $types=new ContentTypeRepository($work,$db);$entries=new ContentEntryRepository($work,$db);$users=new UserRepository($db,new RoleRepository($db));
 $types->create(['handle'=>'articles','name'=>'Articles','fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext','required'=>true],['handle'=>'rating','label'=>'Rating','type'=>'number'],['handle'=>'tree','label'=>'Tree','type'=>'json']]]);
 $types->create(['handle'=>'pages','name'=>'Pages','fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext','required'=>true]]]);
 $author=$users->create('writer@example.com','Writer',password_hash('fixture-secret',PASSWORD_DEFAULT),[]);$mapping['users']['writer']=$author;$mapping['types']['page']=['target'=>'pages','fields'=>['data:body'=>['from'=>'body','transform'=>'copy']]];
 $planner=new MigrationPlanner($types,$entries,$users,$db);$executor=new DleImporter($work,$db,$types,$entries,$planner);$vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));
 $page=PlanningSource::record('page',['id'=>'1','title'=>'About','body'=>'Page body','slug'=>'about','date'=>'2020-01-02 10:00:00','status'=>'publish','author'=>'writer']);$page['origin']='dle';
 $inv=PlanningSource::inventory([$record,$page],[['id'=>'writer','email'=>'writer@example.com']]);
 $project=function(string $id,array $inventory,array $map)use($planner){$plan=$planner->plan($inventory,$map);check($plan['approvable'],'plan approvable '.$id);return ['id'=>$id,'source'=>'dle-db','status'=>'approved','mapping'=>$map,'plan'=>$plan+['approved_at'=>gmdate(DATE_ATOM)]];};
 $p=$project(str_repeat('a',32),$inv,$mapping);
 $changed=$inv;$changed['records'][0]['fields']['title']='Changed';reject(fn()=>$executor->execute($p,$changed),RevisionConflict::class,'changed source blocks execution');check($entries->list('articles')===[],'stale plan writes nothing');
 $r=$executor->execute($p,$inv);check($r['counts']===['create'=>2,'update'=>0,'skip'=>0]&&count($r['records'])===2,'real news and static page created');
 $news=$entries->find('articles','news');$about=$entries->find('pages','about');check($news['data']['body']===DleContent::html($record['fields']['body'])&&$news['data']['rating']==4.5&&$news['data']['tree'][0][1]['name']==='Child'&&$about['data']['body']===DleContent::html($page['fields']['body']),'content, xfields and hierarchy stored in target');
 $q=$pdo->prepare('SELECT author_id,created_at,published_at FROM ce_content_entries WHERE id=?');$q->execute([$news['id']]);$meta=$q->fetch();check($meta['author_id']===$author&&$meta['created_at']==='2020-01-01 12:00:00'&&$meta['published_at']==='2020-01-01 12:00:00','historical dates and existing author persisted');
 check((new RouteIndex($work,$db))->lookup('/news')['id']===$news['id'],'real public route projected');
 check($executor->execute($p,$inv)===$r&&count($entries->list('articles'))===1&&$entries->find('articles','news')['revision']===1,'repeat returns SQL receipt without rewriting');
 $update=$mapping;$update['conflict']='update';$updateInv=$inv;$updateInv['records'][1]['fields']['body']='Updated';$p2=$project(str_repeat('b',32),$updateInv,$update);$r2=$executor->execute($p2,$updateInv);check($r2['counts']['update']===2&&$entries->find('articles','news')['id']===$news['id']&&$entries->find('articles','news')['revision']===2,'update preserves UUID and revision');
 $bad=$record;$bad['id']='3';$bad['fields']['slug']='about';$bad['fields']['title']='Colliding public route';$badInv=PlanningSource::inventory([$bad],$inv['users']);$p3=$project(str_repeat('c',32),$badInv,$mapping);reject(fn()=>$executor->execute($p3,$badInv),DomainException::class,'cross-type route collision aborts batch');check($entries->find('articles','about')===null&&$executor->receipt($p3['id'])===null,'rollback content and receipt together');
 // A real source database is used in release CI, with SELECT-only credentials.
 if(getenv('MIGRATION_TEST_MYSQL')==='1'){
  $sourceDb='ce_dle_'.bin2hex(random_bytes(5));$reader='ce_dle_'.bin2hex(random_bytes(4));$pw='Readonly'.bin2hex(random_bytes(8));$admin=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('MIGRATION_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  try{
   $admin->exec("CREATE DATABASE `$sourceDb` CHARACTER SET utf8mb4");$admin->exec("USE `$sourceDb`");$admin->exec("CREATE USER '$reader'@'%' IDENTIFIED BY '$pw'");$admin->exec("GRANT SELECT ON `$sourceDb`.* TO '$reader'@'%'");
   foreach(["CREATE TABLE dle_post (id INT PRIMARY KEY,title VARCHAR(100),full_story TEXT,short_story TEXT,alt_name VARCHAR(100),date DATETIME,autor VARCHAR(100),approve INT,category VARCHAR(100),tags VARCHAR(100),xfields TEXT,access VARCHAR(100))","INSERT INTO dle_post VALUES(1,'Source news','[b]Real DLE[/b]','Excerpt','real-dle','2020-01-01 12:00:00','writer',1,'2','tag','rating|4.5',''),(2,'Restricted','Hidden','','restricted','2020-01-01 12:00:00','writer',1,'2','','','1')","CREATE TABLE dle_category(id INT PRIMARY KEY,name VARCHAR(100),parentid INT,alt_name VARCHAR(100))","INSERT INTO dle_category VALUES(1,'Root',0,'root'),(2,'Child',1,'child')","CREATE TABLE dle_static(id INT PRIMARY KEY,name VARCHAR(100),descr VARCHAR(100),template TEXT,date INT,grouplevel VARCHAR(100))","INSERT INTO dle_static VALUES(1,'real-page','Real Page','Static content',1577836800,'all')","CREATE TABLE dle_users(name VARCHAR(100),email VARCHAR(100))","INSERT INTO dle_users VALUES('writer','writer@example.com')"] as $sql)$admin->exec($sql);
   putenv('MIGRATION_DB_ALLOWED_ENDPOINTS=127.0.0.1:3306');$center=new MigrationCenter($work,$vault,new SourceDiscovery(),planner:$planner,dle:$executor);
   $p=$center->create(['name'=>'DLE fixture','source'=>'dle-db','config'=>['host'=>'127.0.0.1','port'=>3306,'database'=>$sourceDb,'prefix'=>'dle_','charset'=>'utf8mb4'],'credential'=>['username'=>$reader,'password'=>$pw]]);$id=$p['id'];$p=$center->scan($id,1);check($p['status']==='discovered','actual DLE source discovered');$p=$center->saveMapping($id,2,$mapping);$p=$center->dryRun($id,3);$p=$center->approve($id,4,$p['plan']['sha256']);$r=$center->executeDle($id,$p['plan']['sha256']);
   check($r['counts']['create']===3&&$entries->find('articles','real-dle')['data']['tree'][0][1]['name']==='Child'&&$entries->find('pages','real-page')['title']==='Real Page'&&$entries->find('articles','restricted')['status']==='draft','real MariaDB to SQLite DLE transfer, categories, static title and ACL');
   check($center->show($id)['status']==='completed'&&$center->executeDle($id,$p['plan']['sha256'])===$r,'completed project visible and repeat safe');reject(fn()=>$center->delete($id,5),RevisionConflict::class,'completed project immutable');
   check((int)$admin->query('SELECT COUNT(*) FROM dle_post')->fetchColumn()===2,'source unchanged');
  }finally{$admin->exec("DROP DATABASE IF EXISTS `$sourceDb`");$admin->exec("DROP USER IF EXISTS '$reader'@'%'");}
 }elseif(in_array('--require-mysql',$argv,true))throw new RuntimeException('MIGRATION_TEST_MYSQL=1 required');else echo "SKIP MariaDB source fixture\n";
}finally{RecoveryBundle::cleanup($work);}
