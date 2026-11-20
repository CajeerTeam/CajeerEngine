<?php
declare(strict_types=1);
use CajeerEngine\Migration\{MigrationCenter,SourceDiscovery,WxrDiscovery};
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Backup\RecoveryBundle;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);echo "PASS $what\n";}
function reject(callable $fn,string $type,string $what):void{try{$fn();}catch(Throwable $e){check($e instanceof $type,$what);return;}throw new RuntimeException('Expected rejection: '.$what);}
$work=sys_get_temp_dir().'/ce-migration-'.bin2hex(random_bytes(8));mkdir($work,0700);
$vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));
putenv('MIGRATION_HTTP_ALLOWED_HOSTS=old.example.com');putenv('MIGRATION_DB_ALLOWED_ENDPOINTS=127.0.0.1:3306');
$transport=new class implements OutboundHttpTransport {
    public array $calls=[];public bool $fail=false;
    public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array{
        $this->calls[]=[$url,$method,$headers];$kind=basename((string)parse_url($url,PHP_URL_PATH));
        if($this->fail&&$kind==='posts')return [503,'error'];if($kind==='users')return [403,'forbidden'];
        if($kind==='posts')return [200,'[{"id":1},{"id":2}]',['x-wp-total'=>'200']];
        if($kind==='pages')return [200,json_encode(array_map(fn($i)=>['id'=>$i],range(1,100)))];return [200,'[]'];
    }
};
try{
 $center=new MigrationCenter($work,$vault,new SourceDiscovery($transport));
 $body=['name'=>'Legacy <site>','source'=>'wordpress-rest','config'=>['url'=>'https://old.example.com/blog'],'credential'=>['username'=>'reader','password'=>'secret-app-password']];
 $p=$center->create($body);$id=$p['id'];check($p['status']==='draft'&&!isset($p['credential'])&&$p['revision']===1,'persistent project created with write-only credential');
 $raw=file_get_contents($work.'/storage/app/migration/'.$id.'.json');check(!str_contains($raw,'secret-app-password')&&!str_contains($raw,'reader'),'credentials encrypted at rest');
 $center=new MigrationCenter($work,$vault,new SourceDiscovery($transport));check($center->show($id)['name']===$body['name']&&count($center->list()['projects'])===1,'project reopened across service instances');
 $p=$center->scan($id,1);check($p['status']==='discovered'&&$p['report']['counts']['posts']===200&&$p['report']['counts']['pages']===100&&$p['report']['counts']['users']===null,'REST real totals, lower bounds and unavailable collections');
 check($p['report']['details']['lower_bound_counts']===['pages']&&$p['report']['target_modified']===false,'partial counts explicitly marked');
 check(count($transport->calls)===7&&str_contains($transport->calls[0][0],'/blog/wp-json/wp/v2/posts?')&&$transport->calls[0][2]['Authorization']==='Basic '.base64_encode('reader:secret-app-password'),'fixed REST GET and Basic application password');
 check(!str_contains(json_encode($p),'secret-app-password')&&!str_contains(json_encode($p),'Authorization'),'reports do not expose secrets');
 reject(fn()=>$center->scan($id,1),RevisionConflict::class,'stale scan revision rejected');
 $transport->fail=true;$p=$center->scan($id,2);check($p['status']==='failed'&&$p['report']['counts']['posts']===200&&str_contains($p['error'],'503'),'failed refresh retains last good report');
 reject(fn()=>$center->update($id,$body+['revision'=>1]),RevisionConflict::class,'stale edit rejected');
 $p=$center->update($id,$body+['revision'=>3]);check($p['status']==='draft'&&$p['report']===null&&$p['revision']===4,'source edit invalidates report');
 $exclusive=\CajeerEngine\Update\OperationLock::acquire($work,true);try{reject(fn()=>$center->show($id),RuntimeException::class,'backup lease blocks concurrent project access');}finally{\CajeerEngine\Update\OperationLock::release($exclusive);}
 reject(fn()=>$center->delete($id,3),RevisionConflict::class,'stale delete rejected');$center->delete($id,4);check($center->list()['projects']===[],'delete removes project');
 reject(fn()=>SourceDiscovery::dbConfig(['host'=>'8.8.8.8','port'=>3306,'database'=>'source']),InvalidArgumentException::class,'unapproved DB host denied');
 reject(fn()=>SourceDiscovery::dbConfig(['host'=>'127.0.0.1','port'=>3306,'database'=>'test;host=other']),InvalidArgumentException::class,'DSN injection denied');
 reject(fn()=>SourceDiscovery::dbConfig(['host'=>'127.0.0.1','port'=>3306,'database'=>'source','prefix'=>'x`']),InvalidArgumentException::class,'SQL identifier injection denied');
 check(SourceDiscovery::dbConfig(['host'=>'127.0.0.1','port'=>3306,'database'=>'source','charset'=>'cp1251'])['charset']==='cp1251','DLE legacy connection charset supported');
 reject(fn()=>SourceDiscovery::restConfig(['url'=>'http://old.example.com']),InvalidArgumentException::class,'REST HTTPS enforced');
 reject(fn()=>SourceDiscovery::restConfig(['url'=>'https://old.example.com@other.example.com']),InvalidArgumentException::class,'REST URL credentials denied');
 reject(fn()=>SourceDiscovery::restConfig(['url'=>'https://other.example.com']),InvalidArgumentException::class,'REST operator allowlist enforced');
 reject(fn()=>(new WxrDiscovery())->scan('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>'),InvalidArgumentException::class,'WXR XXE rejected before parsing');
 reject(fn()=>(new WxrDiscovery())->scan(str_repeat('a',WxrDiscovery::MAX_BYTES+1)),InvalidArgumentException::class,'WXR size cap');
 if(class_exists(XMLReader::class)){
  $xml=<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><wp:wxr_version>1.2</wp:wxr_version><wp:author><wp:author_login>private-author</wp:author_login></wp:author><wp:category><wp:category_nicename>news</wp:category_nicename></wp:category><wp:tag><wp:tag_slug>test</wp:tag_slug></wp:tag><item><title>Привет</title><wp:post_type>post</wp:post_type><wp:status>publish</wp:status><content:encoded><![CDATA[private-content <script>alert(1)</script>]]></content:encoded><wp:postmeta><wp:meta_key>field_one</wp:meta_key><wp:meta_value>private-meta</wp:meta_value></wp:postmeta><wp:comment><wp:comment_id>1</wp:comment_id></wp:comment></item><item><wp:post_type>page</wp:post_type><wp:status>draft</wp:status></item><item><wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status></item><item><wp:post_type>product</wp:post_type><wp:status>publish</wp:status></item></channel></rss>
XML;
  $w=$center->create(['name'=>'WXR','source'=>'wordpress-wxr','config'=>[],'credential'=>[]]);$w=$center->scan($w['id'],1,$xml);check($w['status']==='discovered','real XMLReader WXR scan');$r=$w['report'];check($r['counts']===['posts'=>1,'pages'=>1,'media'=>1,'users'=>1,'comments'=>1,'categories'=>1,'tags'=>1]&&$r['types']['product']===1&&$r['fields']===['wp_date_gmt','wp_protected','wp_parent','field_one'],'WXR counts, custom type, author, taxonomy, comments and fields');
  check(!str_contains(json_encode($w),'private-content')&&!str_contains(json_encode($w),'private-author')&&!str_contains(json_encode($w),'private-meta'),'WXR report excludes source content and PII');
  $files=glob($work.'/storage/app/migration/*.wxr.enc');check(count($files)===1&&!str_contains(file_get_contents($files[0]),'private-content'),'WXR encrypted artifact stored separately');
  $w=$center->scan($w['id'],2);check($w['status']==='discovered'&&$w['report']['details']['sha256']===hash('sha256',$xml)&&count(glob($work.'/storage/app/migration/*.wxr.enc'))===1,'WXR rescan saved artifact and cleanup');
  $w=$center->scan($w['id'],3,'<rss><channel>');check($w['status']==='failed'&&$w['report']['details']['sha256']===hash('sha256',$xml),'invalid XML preserves old report and artifact');
  reject(fn()=>(new WxrDiscovery())->scan('<rss><channel><item/></channel></rss>'),InvalidArgumentException::class,'ordinary RSS rejected');
  $center->delete($w['id'],4);check(glob($work.'/storage/app/migration/*.wxr.enc')===[],'WXR deletion removes artifact');
 }elseif(in_array('--require-xml',$argv,true))throw new RuntimeException('XMLReader required');else echo "SKIP real WXR XMLReader (extension unavailable)\n";
 if(getenv('MIGRATION_TEST_MYSQL')==='1'){
  if(!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_mysql required');
  $db='ce_disc_'.bin2hex(random_bytes(5));$user='ce_read_'.bin2hex(random_bytes(4));$pw='ReadOnly'.bin2hex(random_bytes(8));$admin=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('MIGRATION_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  try{
   $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");$admin->exec("USE `$db`");$admin->exec("CREATE USER '$user'@'%' IDENTIFIED BY '$pw'");$admin->exec("GRANT SELECT ON `$db`.* TO '$user'@'%'");
   foreach(["CREATE TABLE custom_posts (ID INT PRIMARY KEY,post_type VARCHAR(30),post_status VARCHAR(30),post_title VARCHAR(100),post_content TEXT,post_name VARCHAR(100),post_author INT)","INSERT INTO custom_posts VALUES (1,'post','publish','Title','Body','title',1),(2,'page','draft','Page','Page body','page',2),(3,'attachment','inherit','Media','','media',1),(4,'product','publish','Product','Product body','product',1)","CREATE TABLE custom_postmeta (meta_id INT PRIMARY KEY,post_id INT,meta_key VARCHAR(100),meta_value TEXT)","INSERT INTO custom_postmeta VALUES (1,1,'_thumbnail_id','3'),(2,1,'custom_field','value')","CREATE TABLE custom_options (option_name VARCHAR(100),option_value VARCHAR(100))","INSERT INTO custom_options VALUES ('db_version','58975')","CREATE TABLE custom_users (ID INT,user_email VARCHAR(100))","INSERT INTO custom_users VALUES (1,'a@example.com'),(2,'b@example.com')","CREATE TABLE custom_term_taxonomy (taxonomy VARCHAR(30))","INSERT INTO custom_term_taxonomy VALUES ('category'),('post_tag')","CREATE TABLE dle_post (id INT PRIMARY KEY,title VARCHAR(100),full_story TEXT,approve INT,xfields TEXT)","INSERT INTO dle_post VALUES (1,'Новость','secret content',1,'city|Warsaw||rating|5'),(2,'Draft','body',0,'city|Orem')","CREATE TABLE dle_category (id INT)","INSERT INTO dle_category VALUES (1)","CREATE TABLE dle_static (id INT)","INSERT INTO dle_static VALUES (1)","CREATE TABLE dle_users (user_id INT,name VARCHAR(100),email VARCHAR(100))","INSERT INTO dle_users VALUES (1,'writer','writer@example.com')"] as $sql)$admin->exec($sql);
   $conf=['host'=>'127.0.0.1','port'=>3306,'database'=>$db,'prefix'=>'','charset'=>'utf8mb4'];$credentials=['username'=>$user,'password'=>$pw];
   $w=$center->create(['name'=>'WP db','source'=>'wordpress-db','config'=>$conf,'credential'=>$credentials]);$w=$center->scan($w['id'],1);check($w['status']==='discovered','real MariaDB WordPress scan: '.($w['error']??''));check($w['report']['counts']['posts']===1&&$w['report']['counts']['users']===2&&$w['report']['types']['product']===1&&$w['report']['details']['prefix']==='custom_','WordPress DB counts, custom type and detected prefix');
   $d=$center->create(['name'=>'DLE db','source'=>'dle-db','config'=>$conf,'credential'=>$credentials]);$d=$center->scan($d['id'],1);check($d['status']==='discovered','real MariaDB DLE scan: '.($d['error']??''));check($d['report']['counts']['posts']===2&&$d['report']['counts']['media']===null&&in_array('city',$d['report']['fields'],true)&&$d['report']['details']['prefix']==='dle_','DLE DB counts, prefix, extra fields and unknown media');
   $reader=new SourceDiscovery();$wpPlan=$reader->inventory('wordpress-db',$conf,$credentials);$dlePlan=$reader->inventory('dle-db',$conf,$credentials);check(count($wpPlan['records'])===3&&count($wpPlan['users'])===2&&array_values(array_filter($wpPlan['records'],fn($r)=>$r['id']==='1'))[0]['fields']['meta:custom_field']==='value','real WordPress database planning inventory and postmeta');check(count($dlePlan['records'])===3&&count($dlePlan['users'])===1&&array_values(array_filter($dlePlan['records'],fn($r)=>$r['type']==='post'&&$r['id']==='1'))[0]['fields']['meta:rating']==='5','real DLE database planning inventory and xfields');
   check((int)$admin->query('SELECT COUNT(*) FROM dle_post')->fetchColumn()===2,'source records unchanged');
   $ro=new PDO('mysql:host=127.0.0.1;dbname='.$db,$user,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);reject(fn()=>$ro->exec('DELETE FROM dle_post'),PDOException::class,'fixture source account is SELECT-only');
   $center->delete($w['id'],2);$center->delete($d['id'],2);
  }finally{$admin->exec("DROP DATABASE IF EXISTS `$db`");$admin->exec("DROP USER IF EXISTS '$user'@'%'");}
 }elseif(in_array('--require-mysql',$argv,true))throw new RuntimeException('MIGRATION_TEST_MYSQL=1 required');else echo "SKIP real MariaDB fixtures (not configured)\n";
}finally{RecoveryBundle::cleanup($work);}
