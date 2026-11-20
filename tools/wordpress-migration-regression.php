<?php
declare(strict_types=1);
use CajeerEngine\Migration\{WordPressContent,DleImporter,MigrationCenter,MigrationPlanner,PlanningSource,SourceDiscovery};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Security\OutboundHttpTransport;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function reject(callable $fn,string $class,string $name):void{try{$fn();}catch(Throwable $e){if(!$e instanceof $class)throw $e;check(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
check(WordPressContent::date('2020-02-29T12:34:56')==='2020-02-29 12:34:56'&&WordPressContent::date('2020-02-29T12:34:56Z')==='2020-02-29 12:34:56','REST and WXR UTC dates');
check(WordPressContent::date('2021-02-29 12:34:56')===null&&WordPressContent::date('0000-00-00 00:00:00')===null,'invalid WordPress UTC dates rejected');
$row=['id'=>1,'title'=>'WP news','body'=>'Plain body','slug'=>'wp-news','date'=>'2020-02-29 15:34:56','date_gmt'=>'2020-02-29 12:34:56','status'=>'publish','author'=>'7'];
$protected=WordPressContent::record('post',$row+['password'=>'private-password'],['meta:wp_protected'=>false]);
check($protected['status']==='draft'&&$protected['fields']['meta:wp_protected']===true&&!str_contains(json_encode($protected),'private-password'),'source password never stored; protected post remains draft');
$future=WordPressContent::record('post',array_replace($row,['date_gmt'=>'2099-01-01 00:00:00']));check($future['status']==='draft','future-dated published source stays draft');
$plain=WordPressContent::record('post',$row);check($plain['status']==='publish'&&$plain['fields']['meta:wp_date_gmt']==='2020-02-29 12:34:56','public record and UTC date normalized');
$type=['handle'=>'articles','fields'=>[['handle'=>'body','type'=>'richtext','required'=>true],['handle'=>'rating','type'=>'number'],['handle'=>'parent','type'=>'text']]];
$rule=['target'=>'articles','fields'=>['data:body'=>['from'=>'body','transform'=>'copy'],'data:rating'=>['from'=>'meta:rating','transform'=>'number'],'data:parent'=>['from'=>'meta:wp_parent','transform'=>'copy']]];
$mapping=['types'=>['post'=>$rule,'page'=>$rule,'book'=>$rule],'statuses'=>['publish'=>'published','draft'=>'draft','private'=>'draft'],'users'=>['7'=>'skip','writer'=>'skip'],'locale'=>'ru','conflict'=>'fail'];
$record=WordPressContent::record('post',$row,['meta:rating'=>'4.5','meta:wp_parent'=>'0']);$inv=PlanningSource::inventory([$record],[]);
$plan=MigrationPlanner::build($inv,$mapping,['articles'=>$type],[],[],fn($e)=>null);check($plan['approvable']&&$plan['records'][0]['payload_sha256']===MigrationPlanner::fingerprint(MigrationPlanner::payload($record,$rule,$type,'ru','published')),'real mapped payload and approval hash');
$http=new class implements OutboundHttpTransport{
 public bool $changed=false;public array $requests=[];
 public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array{
  $this->requests[]=[$url,$method,$headers];$kind=basename(parse_url($url,PHP_URL_PATH));
  $r=['id'=>9,'status'=>'publish','author'=>7,'title'=>['rendered'=>'REST article'],'content'=>['rendered'=>$this->changed?'Changed':'REST body','protected'=>false],'excerpt'=>['rendered'=>'Excerpt'],'slug'=>'wp-rest','date'=>'2020-01-01T15:00:00','date_gmt'=>'2020-01-01T12:00:00','categories'=>[2],'tags'=>[3],'meta'=>['rating'=>'4.5']];
  return [200,json_encode(match($kind){'posts'=>[$r], 'pages'=>[array_replace($r,['id'=>10,'slug'=>'wp-rest-page','content'=>['rendered'=>'Protected page','protected'=>true],'parent'=>9])], 'users'=>[['id'=>7,'email'=>'writer@example.com']],default=>[]},JSON_THROW_ON_ERROR)];
 }
};
putenv('MIGRATION_HTTP_ALLOWED_HOSTS=old.example.com');
$rest=(new PlanningSource($http))->rest(['url'=>'https://old.example.com'],['username'=>'reader','password'=>'app-password']);
check(count($rest['records'])===2&&$rest['records'][0]['status']==='draft'&&$rest['records'][0]['fields']['meta:wp_parent']==='9'&&$rest['records'][1]['fields']['meta:rating']==='4.5','REST reads pages, protection, metadata and parent');
check(str_contains($http->requests[0][0],'date_gmt')&&$http->requests[0][2]['Authorization']==='Basic '.base64_encode('reader:app-password'),'authenticated REST GET requests include UTC fields');
$xml=<<<'XML'
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><wp:wxr_version>1.2</wp:wxr_version><wp:author><wp:author_login>writer</wp:author_login><wp:author_email>writer@example.com</wp:author_email></wp:author>
<item><title>WordPress article</title><wp:post_id>41</wp:post_id><wp:post_type>post</wp:post_type><wp:status>publish</wp:status><wp:post_name>wp-article</wp:post_name><wp:post_date>2020-01-01 15:00:00</wp:post_date><wp:post_date_gmt>2020-01-01 12:00:00</wp:post_date_gmt><dc:creator>writer</dc:creator><content:encoded><![CDATA[<!-- wp:paragraph --><p>Real WordPress content</p><!-- /wp:paragraph --><script>bad()</script>]]></content:encoded><category domain="category" nicename="news">News</category><wp:postmeta><wp:meta_key>rating</wp:meta_key><wp:meta_value>4.5</wp:meta_value></wp:postmeta></item>
<item><title>WordPress page</title><wp:post_id>42</wp:post_id><wp:post_type>page</wp:post_type><wp:status>publish</wp:status><wp:post_name>wp-page</wp:post_name><wp:post_password>secret-page-password</wp:post_password><wp:post_parent>41</wp:post_parent><dc:creator>writer</dc:creator><content:encoded><![CDATA[<p>Page body</p>]]></content:encoded></item>
<item><title>WordPress book</title><wp:post_id>43</wp:post_id><wp:post_type>book</wp:post_type><wp:status>publish</wp:status><wp:post_name>wp-book</wp:post_name><dc:creator>writer</dc:creator><content:encoded><![CDATA[<p>Book body</p>]]></content:encoded></item>
</channel></rss>
XML;
$xmlInv=null;
if(class_exists(XMLReader::class)){
 $xmlInv=(new PlanningSource())->wxr($xml);$byType=array_column($xmlInv['records'],null,'type');
 check(count($byType)===3&&$byType['post']['fields']['meta:rating']==='4.5'&&$byType['post']['fields']['categories']==='news'&&$byType['book']['fields']['body']==='<p>Book body</p>','WXR real custom type, taxonomy and meta parsed');
 check($byType['page']['status']==='draft'&&$byType['page']['fields']['meta:wp_parent']==='41'&&!str_contains(json_encode($xmlInv),'secret-page-password'),'WXR password not retained and page parent available');
}elseif(in_array('--require-xml',$argv,true))throw new RuntimeException('XMLReader required');else echo "SKIP WXR parsing: XMLReader unavailable\n";
if(class_exists(DOMDocument::class)){
 $html=WordPressContent::html('<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph --><script>bad()</script><img src="javascript:bad()" onerror="bad()">');check(str_contains($html,'<p>Body</p>')&&!str_contains($html,'script')&&!str_contains($html,'onerror')&&!str_contains($html,'wp:paragraph'),'Gutenberg static HTML retained; dangerous HTML removed');
}else {reject(fn()=>WordPressContent::html('<script>bad()</script>'),RuntimeException::class,'unsafe HTML fails closed without DOM');}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)||!class_exists(DOMDocument::class)){if(in_array('--require-db',$argv,true))throw new RuntimeException('PDO SQLite and DOM required');echo "SKIP WordPress SQL execution: PDO SQLite/DOM unavailable\n";exit(0);}
$work=sys_get_temp_dir().'/ce-wp-'.bin2hex(random_bytes(8));mkdir($work,0700);mkdir($work.'/config');foreach(glob($root.'/config/*.php') as $f)copy($f,$work.'/config/'.basename($f));
putenv('CONTENT_STORAGE=database');putenv('CMS_STORAGE=database');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$work.'/test.sqlite');
try{
 $db=new DatabaseManager(new ConfigRepository($work));$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $types=new ContentTypeRepository($work,$db);$entries=new ContentEntryRepository($work,$db);$users=new UserRepository($db,new RoleRepository($db));
 foreach(['articles','pages','books'] as $h)$types->create(['handle'=>$h,'name'=>ucfirst($h),'fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext','required'=>true],['handle'=>'rating','label'=>'Rating','type'=>'number'],['handle'=>'parent','label'=>'Parent','type'=>'text']]]);
 $author=$users->create('writer@example.com','Writer',password_hash('fixture-secret',PASSWORD_DEFAULT),[]);$mapping['users']=['7'=>$author,'writer'=>$author];$mapping['types']['page']['target']='pages';$mapping['types']['book']['target']='books';
 $planner=new MigrationPlanner($types,$entries,$users,$db);$executor=new DleImporter($work,$db,$types,$entries,$planner);$vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));$center=new MigrationCenter($work,$vault,new SourceDiscovery($http),planner:$planner,dle:$executor);
 $prepare=function(array $body,?string $xml=null,array $map=[])use($center,$mapping){$p=$center->create($body);$p=$center->scan($p['id'],$p['revision'],$xml);check($p['status']==='discovered','source project discovered');$p=$center->saveMapping($p['id'],$p['revision'],$map?:$mapping);$p=$center->dryRun($p['id'],$p['revision']);check($p['plan']['approvable'],'source plan approvable');return $center->approve($p['id'],$p['revision'],$p['plan']['sha256']);};
 $p=$prepare(['name'=>'REST fixture','source'=>'wordpress-rest','config'=>['url'=>'https://old.example.com'],'credential'=>['username'=>'reader','password'=>'app-password']]);$id=$p['id'];$hash=$p['plan']['sha256'];$http->changed=true;reject(fn()=>$center->executeWordPress($id,$hash),RevisionConflict::class,'REST source change blocks execution');check($entries->list('articles')===[],'stale source creates no content');$http->changed=false;
 reject(fn()=>$center->executeDle($id,$hash),InvalidArgumentException::class,'DLE command cannot execute WordPress project');
 $r=$center->executeWordPress($id,$hash);$article=$entries->find('articles','wp-rest');$page=$entries->find('pages','wp-rest-page');
 check($r['counts']['create']===2&&$article['data']['body']===WordPressContent::html('REST body')&&str_contains($article['data']['body'],'REST body')&&$page['status']==='draft'&&$page['data']['parent']==='9','REST actual persisted content and protected page');
 check((new RouteIndex($work,$db))->lookup('/wp-rest')['id']===$article['id']&&(new RouteIndex($work,$db))->lookup('/wp-rest-page')===null,'published route exists and protected route is not public');
 check($center->show($id)['status']==='completed'&&$center->executeWordPress($id,$hash)===$r&&count($entries->list('articles'))===1,'completed receipt visible; replay is idempotent');
 reject(fn()=>$center->delete($id,$p['revision']),RevisionConflict::class,'completed WordPress project immutable');
 if($xmlInv!==null){
  $p=$prepare(['name'=>'WXR fixture','source'=>'wordpress-wxr','config'=>[],'credential'=>[]],$xml);$r=$center->executeWordPress($p['id'],$p['plan']['sha256']);
  $a=$entries->find('articles','wp-article');$b=$entries->find('books','wp-book');$pg=$entries->find('pages','wp-page');
  check($r['counts']['create']===3&&$a['data']['body']==='<p>Real WordPress content</p>'&&$a['data']['rating']==4.5&&$b['data']['body']==='<p>Book body</p>'&&$pg['status']==='draft','WXR actual article, custom type and protected page persisted');
  $q=$pdo->prepare('SELECT author_id,created_at,published_at FROM ce_content_entries WHERE id=?');$q->execute([$a['id']]);$m=$q->fetch();check($m['author_id']===$author&&$m['created_at']==='2020-01-01 12:00:00'&&$m['published_at']==='2020-01-01 12:00:00','WXR author and UTC date persisted');
  $update=$mapping;$update['conflict']='update';$p=$prepare(['name'=>'WXR update','source'=>'wordpress-wxr','config'=>[],'credential'=>[]],str_replace('Real WordPress content','Updated WP content',$xml),$update);$r=$center->executeWordPress($p['id'],$p['plan']['sha256']);check($r['counts']['update']===3&&$entries->find('articles','wp-article')['id']===$a['id']&&$entries->find('articles','wp-article')['data']['body']==='<p>Updated WP content</p>','explicit update preserves UUID and changes content');
  $collision=str_replace(['wp-book','wp-article','wp-page'],['wp-rest','unique-wp-test','unique-wp-page'],$xml);$p=$prepare(['name'=>'WXR rollback','source'=>'wordpress-wxr','config'=>[],'credential'=>[]],$collision);reject(fn()=>$center->executeWordPress($p['id'],$p['plan']['sha256']),DomainException::class,'cross-type public route conflict aborts batch');check($entries->find('articles','unique-wp-test')===null&&$executor->receipt($p['id'],'wordpress-wxr')===null,'rollback removes content and receipt');
 }
 if(getenv('MIGRATION_TEST_MYSQL')==='1'){
  $sourceDb='ce_wp_'.bin2hex(random_bytes(5));$reader='ce_wp_'.bin2hex(random_bytes(4));$pw='Readonly'.bin2hex(random_bytes(8));$admin=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('MIGRATION_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  try{
   $admin->exec("CREATE DATABASE `$sourceDb` CHARACTER SET utf8mb4");$admin->exec("USE `$sourceDb`");$admin->exec("CREATE USER '$reader'@'%' IDENTIFIED BY '$pw'");$admin->exec("GRANT SELECT ON `$sourceDb`.* TO '$reader'@'%'");
   foreach(["CREATE TABLE wp_posts (ID INT PRIMARY KEY,post_type VARCHAR(30),post_status VARCHAR(30),post_title VARCHAR(100),post_content TEXT,post_excerpt TEXT,post_name VARCHAR(100),post_date DATETIME,post_date_gmt DATETIME,post_password VARCHAR(100),post_parent INT,post_author INT)","INSERT INTO wp_posts VALUES(1,'post','publish','DB article','<p>DB body</p>','','wp-db','2020-01-01 15:00:00','2020-01-01 12:00:00','',0,7),(2,'book','publish','DB book','<p>Private book</p>','','wp-db-book','2020-01-01 15:00:00','2020-01-01 12:00:00','secret',0,7)","CREATE TABLE wp_postmeta (meta_id INT PRIMARY KEY,post_id INT,meta_key VARCHAR(100),meta_value TEXT)","INSERT INTO wp_postmeta VALUES(1,1,'rating','4.5')","CREATE TABLE wp_options(option_name VARCHAR(100),option_value TEXT)","CREATE TABLE wp_users(ID INT PRIMARY KEY,user_email VARCHAR(100))","INSERT INTO wp_users VALUES(7,'writer@example.com')","CREATE TABLE wp_terms(term_id INT PRIMARY KEY,slug VARCHAR(100))","INSERT INTO wp_terms VALUES(1,'news')","CREATE TABLE wp_term_taxonomy(term_taxonomy_id INT PRIMARY KEY,term_id INT,taxonomy VARCHAR(100))","INSERT INTO wp_term_taxonomy VALUES(1,1,'category')","CREATE TABLE wp_term_relationships(object_id INT,term_taxonomy_id INT)","INSERT INTO wp_term_relationships VALUES(1,1)"] as $sql)$admin->exec($sql);
   putenv('MIGRATION_DB_ALLOWED_ENDPOINTS=127.0.0.1:3306');$p=$prepare(['name'=>'DB fixture','source'=>'wordpress-db','config'=>['host'=>'127.0.0.1','port'=>3306,'database'=>$sourceDb,'prefix'=>'wp_','charset'=>'utf8mb4'],'credential'=>['username'=>$reader,'password'=>$pw]]);$r=$center->executeWordPress($p['id'],$p['plan']['sha256']);
   check($r['counts']['create']===2&&$entries->find('articles','wp-db')['data']['rating']==4.5&&$entries->find('books','wp-db-book')['status']==='draft','real MariaDB to SQLite WordPress transfer');check((int)$admin->query('SELECT COUNT(*) FROM wp_posts')->fetchColumn()===2,'source unchanged');
  }finally{$admin->exec("DROP DATABASE IF EXISTS `$sourceDb`");$admin->exec("DROP USER IF EXISTS '$reader'@'%'");}
 }elseif(in_array('--require-mysql',$argv,true))throw new RuntimeException('MIGRATION_TEST_MYSQL=1 required');else echo "SKIP MariaDB source fixture\n";
}finally{RecoveryBundle::cleanup($work);}
