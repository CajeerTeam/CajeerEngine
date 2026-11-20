<?php
declare(strict_types=1);
use CajeerEngine\Analytics\Reporting\{AnalyticsHub,SearchReports};
use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Queue\{FileQueueDriver,QueueWorker};
use CajeerEngine\Integration\{SecretVault,WebhookRepository,WebhookDispatcher};
use CajeerEngine\Search\{SearchIndexer,FileSearchIndex};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Backup\RecoveryBundle;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $f,string $class,string $name):void{try{$f();}catch(Throwable $e){check($e instanceof $class,$name);return;}throw new RuntimeException('Expected failure: '.$name);}
$t=new class implements OutboundHttpTransport {
 public array $calls=[];public int $status=200;public bool $malformed=false;
 public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array{
  $this->calls[]=compact('url','method','headers','body');if($this->status!==200)return [$this->status,'credential secret'];if($this->malformed)return [200,'<invalid>'];$q=$body===''?[]:json_decode($body,true);
  if(str_contains($url,'oauth2.googleapis.com'))return [200,'{"access_token":"issued"}'];
  if(str_contains($url,'/searchAnalytics/query')){$dim=$q['dimensions'][0]??null;$key=match($dim){'date'=>'2026-10-01','page'=>'https://example.com/page?secret=1','query'=>'search term','device'=>'DESKTOP','country'=>'pol',default=>'Total'};return [200,json_encode(['rows'=>[['keys'=>[$key],'clicks'=>2,'impressions'=>10,'ctr'=>.2,'position'=>3.5]],'responseAggregationType'=>'byProperty'])];}
  if(str_contains($url,'urlInspection'))return [200,'{"inspectionResult":{"indexStatusResult":{"verdict":"PASS","coverageState":"Submitted and indexed","googleCanonical":"https://example.com/page"}}}'];
  if(str_contains($url,'www.googleapis.com')&&str_contains($url,'/sitemaps'))return $method==='PUT'?[204,'']:[200,'{"sitemap":[{"path":"https://example.com/sitemap.xml","errors":"0","warnings":"1","isPending":false}]}'];
  if(str_ends_with($url,'/v4/user'))return [200,'{"user_id":42}'];
  if(str_contains($url,'/all/history'))return [200,'{"indicators":{"TOTAL_CLICKS":[{"date":"2026-10-01T00:00:00+03:00","value":2},{"date":"2026-10-02T00:00:00+03:00","value":3}],"TOTAL_SHOWS":[{"date":"2026-10-01T00:00:00+03:00","value":10},{"date":"2026-10-02T00:00:00+03:00","value":30}],"AVG_SHOW_POSITION":[{"date":"2026-10-01T00:00:00+03:00","value":2},{"date":"2026-10-02T00:00:00+03:00","value":4}]}}'];
  if(str_contains($url,'/popular'))return [200,'{"queries":[{"query_text":"alpha","indicators":{"TOTAL_CLICKS":3,"TOTAL_SHOWS":15}},{"query_text":"beta","indicators":{"TOTAL_SHOWS":7}}],"date_from":"2026-10-01","date_to":"2026-10-02","count":101}'];
  if(str_contains($url,'/query-analytics/list'))return [200,json_encode(['count'=>1,'text_indicator_to_statistics'=>[['text_indicator'=>['type'=>'URL','value'=>'https://example.com/page?secret=1'],'statistics'=>[['date'=>gmdate('Y-m-d',time()-86400),'field'=>'CLICKS','value'=>2],['date'=>gmdate('Y-m-d',time()-86400),'field'=>'IMPRESSIONS','value'=>10],['date'=>gmdate('Y-m-d',time()-86400),'field'=>'POSITION','value'=>4]]]]])];
  if(str_contains($url,'/user-added-sitemaps'))return [200,'{"sitemap_id":"abc"}'];
  if(str_contains($url,'/sitemaps'))return [200,'{"sitemaps":[{"sitemap_url":"https://example.com/sitemap.xml","sitemap_type":"SITEMAP","errors_count":0,"urls_count":8}]}'];
  if(str_contains($url,'/indexing/samples'))return [200,'{"count":150,"samples":[{"url":"https://example.com/page?secret=1","status":"HTTP_2XX","http_code":200}]}'];
  throw new RuntimeException('Unexpected test request');
 }
};
$y=new SearchReports('webmaster',$t);$g=new SearchReports('search_console',$t);$c=['type'=>'oauth_token','token'=>'private-token'];$yr='https:example.com:443';$gr='https://example.com/';$dates=['2026-10-01','2026-10-02'];
check(SearchReports::validResource('webmaster',$yr)&&SearchReports::validResource('search_console','sc-domain:example.com')&&SearchReports::validResource('search_console',$gr),'supported resource identifiers');
foreach(['https://example.com','https://user@example.com/','https://example.com/?secret=1','https://example.com/#x','https://example.com:8080/','https://127.0.0.1/'] as $bad)check(!SearchReports::validResource('search_console',$bad),'reject invalid property '.$bad);
check(!SearchReports::validResource('webmaster','https:example.com:80'),'reject mismatched host port');
rejects(fn()=>$y->validateCredential(['type'=>'refresh_token','client_id'=>'a','client_secret'=>'b','refresh_token'=>'c']),InvalidArgumentException::class,'Yandex credentials require OAuth token');
$r=$g->report($gr,$c,...[...$dates,null]);check(count($r['sections'])===6&&$r['sections']['overview']['rows'][0]['values']===[2.0,10.0,0.2,3.5],'Google report dimensions and metrics');
check($r['sections']['pages']['rows'][0]['label']==='https://example.com/page'&&$r['webmaster']['sitemaps'][0]['warnings']===1.0,'Google sitemap and redacted page URLs');
check(str_contains($t->calls[0]['url'],rawurlencode($gr))&&json_decode($t->calls[0]['body'],true)['dataState']==='final','Google property path and final data request');
$r=$y->report($yr,$c,...[...$dates,null]);check($r['sections']['overview']['rows'][0]['values']===[5.0,40.0,0.125,3.5],'Yandex weighted position and CTR');
check($r['sections']['queries']['rows'][1]['values']===[null,7.0,null,null]&&$r['sections']['queries']['truncated'],'missing indicators remain null and truncation flagged');
check($r['webmaster']['index_sample_count']===150&&$r['webmaster']['index_samples'][0]['url']==='https://example.com/page','Yandex indexing samples and sitemap');
check(!isset($r['sections']['pages']),'historical Yandex URL monitoring unavailable');
$recent=gmdate('Y-m-d',time()-86400);$r=$y->report($yr,$c,$recent,$recent,null);check($r['sections']['pages']['rows'][0]['values']===[2.0,10.0,0.2,4.0],'Yandex recent URL monitoring');
check($g->action($gr,$c,'inspect','https://example.com/page')['verdict']==='PASS','Google URL inspection');
check($g->action($gr,$c,'submit_sitemap','https://example.com/sitemap.xml')['accepted']===true&&end($t->calls)['method']==='PUT','Google sitemap submission handles 204');
// The real transport must admit PUT before its SSRF/DNS checks; mocking the provider alone is insufficient.
$client = new CajeerEngine\Security\PublicHttpClient();
try {
 $client->requestWithHeaders('https://127.0.0.1/','PUT',[], '', 1024);
 throw new RuntimeException('Private IP was permitted');
} catch (InvalidArgumentException|RuntimeException $e) {
 check(in_array($e->getMessage(), ['Non-public destination denied','Secure outbound HTTP requires ext-curl'], true), 'real transport admits PUT but rejects private IP');
}
rejects(fn()=>$client->requestWithHeaders('https://127.0.0.1/','PATCH',[], '', 1024),InvalidArgumentException::class,'unsupported mutation method rejected');
rejects(fn()=>$client->requestWithHeaders('https://127.0.0.1/','GET',[],'payload',1024),InvalidArgumentException::class,'GET with body rejected');
check($y->action($yr,$c,'submit_sitemap','https://example.com/sitemap.xml')['accepted']===true&&json_decode(end($t->calls)['body'],true)['url']==='https://example.com/sitemap.xml','Yandex sitemap submission');
$n=count($t->calls);rejects(fn()=>$g->action('sc-domain:example.com',$c,'inspect','https://example.com.evil.org/page'),InvalidArgumentException::class,'reject out-of-property URL');check(count($t->calls)===$n,'invalid URL no network');
rejects(fn()=>$y->action($yr,$c,'inspect','https://example.com/'),InvalidArgumentException::class,'unsupported Yandex inspection rejected');
$t->status=429;rejects(fn()=>$g->report($gr,$c,...[...$dates,null]),RuntimeException::class,'provider quota failure');$t->status=200;$t->malformed=true;rejects(fn()=>$y->report($yr,$c,...[...$dates,null]),RuntimeException::class,'invalid provider response');$t->malformed=false;
$refresh=['type'=>'refresh_token','client_id'=>'id','client_secret'=>'secret','refresh_token'=>'refresh'];$g->report($gr,$refresh,...[...$dates,null]);$calls=array_slice($t->calls,-8);check(str_contains($calls[0]['url'],'oauth2.googleapis.com')&&$calls[1]['headers']['Authorization']==='Bearer issued','Google refresh token');
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$pem);$sa=['type'=>'service_account','client_email'=>'svc@example.com','private_key'=>$pem];$g->report($gr,$sa,...[...$dates,null]);$calls=array_slice($t->calls,-8);parse_str($calls[0]['body'],$params);$jwt=explode('.',$params['assertion']);$claims=json_decode(base64_decode(strtr($jwt[1],'-_','+/')),true);check($claims['scope']==='https://www.googleapis.com/auth/webmasters.readonly','Google service account read-only scope');
$g->action($gr,$sa,'submit_sitemap','https://example.com/sitemap.xml');$calls=array_slice($t->calls,-2);parse_str($calls[0]['body'],$params);$jwt=explode('.',$params['assertion']);$claims=json_decode(base64_decode(strtr($jwt[1],'-_','+/')),true);check($claims['scope']==='https://www.googleapis.com/auth/webmasters','Google service account sitemap write scope');
$routes=array_column(CajeerEngine\Http\RouteRegistry::all(),5,2);check($routes['api.v1.analytics.reports.webmaster']===['analytics:write','analytics:run'],'webmaster actions RBAC');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite required');echo "SKIP SQL + worker regression (pdo_sqlite unavailable)\n";exit(0);}
$work=sys_get_temp_dir().'/ce-search-hub-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
 $cfg=new ConfigRepository($work);$cfg->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);$db=new DatabaseManager($cfg);(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');$q=new FileQueueDriver($work);$vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));$hub=new AnalyticsHub($db,$vault,$q,$cfg,null,null,$y,$g);
 $input=['provider'=>'search_console','name'=>'Site','resource'=>$gr,'goal'=>null,'enabled'=>true,'credential'=>$c];$src=$hub->save(null,$input);$id=$src['id'];check(!str_contains(json_encode($src),'private-token'),'API never returns credential');$hub->enqueue($id,...$dates);$wh=new WebhookRepository($work);$idx=new SearchIndexer(new ContentEntryRepository($work),new FileSearchIndex($work));$worker=new QueueWorker($q,$idx,$wh,new WebhookDispatcher(new SignedPayload(),$wh),null,null,$hub);check($worker->work(null,1)['processed']===1,'queue worker sync');$old=$hub->report($id,...$dates)['report'];check($old['provider']==='search_console'&&$old['sections']['overview']['rows'][0]['values'][0]===2,'SQL stored report');
 $job=['id'=>$id,'revision'=>1,'from'=>$dates[0],'to'=>$dates[1]];$t->status=429;rejects(fn()=>$hub->sync($job),RuntimeException::class,'quota error');check($hub->report($id,...$dates)['report']===$old&&$hub->overview()['items'][0]['last_error']==='Analytics provider HTTP 429','old report preserved');$t->status=200;
 check($hub->webmasterAction($id,1,'inspect','https://example.com/page')['verdict']==='PASS','hub action');rejects(fn()=>$hub->webmasterAction($id,2,'inspect','https://example.com/page'),RevisionConflict::class,'stale revision rejected');$cfg->set('integrations',['enabled'=>true,'allowed_hosts'=>['example.com']]);rejects(fn()=>$hub->webmasterAction($id,1,'inspect','https://example.com/page'),RuntimeException::class,'host policy enforced');
}finally{RecoveryBundle::cleanup($work);}
