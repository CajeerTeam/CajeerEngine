<?php
declare(strict_types=1);
use CajeerEngine\Analytics\Reporting\{AnalyticsHub,ApiReports};
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
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);echo "PASS $what\n";}
function reject(callable $f,string $class,string $what):void{try{$f();}catch(Throwable $e){check($e instanceof $class,$what);return;}throw new RuntimeException('Expected failure: '.$what);}
$transport=new class implements OutboundHttpTransport {
    public array $calls=[];public int $status=200;public bool $malformed=false;
    public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array {
        $this->calls[]=compact('url','method','headers','body');if($this->status!==200)return [$this->status,'secret error body'];if($this->malformed)return [200,'{}'];
        if(str_contains($url,'oauth2.googleapis.com'))return [200,'{"access_token":"issued-token"}'];
        if(str_contains($url,'analyticsdata.googleapis.com')){$q=json_decode($body,true);$metrics=array_column($q['metrics'],'name');$dim=$q['dimensions'][0]['name']??null;$r=['metricHeaders'=>array_map(fn($n)=>['name'=>$n],$metrics),'rows'=>[['metricValues'=>array_map(fn($v)=>['value'=>(string)$v],[10,12,20,2])]],'rowCount'=>1,'metadata'=>['timeZone'=>'Europe/Warsaw']];if($dim!==null){$r['dimensionHeaders']=[['name'=>$dim]];$r['rows'][0]['dimensionValues']=[['value'=>$dim==='date'?'20261001':($dim==='landingPage'?'/home?secret=1':'Organic Search')]];}return [200,json_encode($r)];}
        parse_str(parse_url($url,PHP_URL_QUERY),$q);$dim=$q['dimensions']??null;$values=count(explode(',',$q['metrics']))===4?[9,11,19,3]:[9,11,19];$r=['data'=>[['dimensions'=>[['name'=>$dim==='ym:s:date'?'2026-10-01':($dim==='ym:s:startURL'?'https://example.org/home?secret=1':'Organic')]],'metrics'=>$values]],'totals'=>$values,'total_rows'=>1,'sampled'=>false];return [200,json_encode($r)];
    }
};
$m=new ApiReports('metrika',$transport);$g=new ApiReports('ga4',$transport);$cred=['type'=>'oauth_token','token'=>'private-token'];$dates=['2026-10-01','2026-10-05'];
$r=$m->report('1234',$cred,...[...$dates,'42']);check(count($transport->calls)===4&&$r['sections']['overview']['rows'][0]['values']===[9.0,11.0,19.0,3.0],'Metrika four actual reports, totals and goal');check(str_contains($transport->calls[0]['url'],'ids=1234')&&$transport->calls[0]['headers']['Authorization']==='OAuth private-token','Metrika correct endpoint and auth');check($r['sections']['pages']['rows'][0]['label']==='https://example.org/home','landing query redacted');
$transport->calls=[];$r=$g->report('456',['type'=>'refresh_token','client_id'=>'id','client_secret'=>'secret','refresh_token'=>'refresh'],... [...$dates,null]);check(count($transport->calls)===5&&str_contains($transport->calls[0]['body'],'grant_type=refresh_token'),'GA4 OAuth refresh');check(str_ends_with($transport->calls[1]['url'],'properties/456:runReport')&&$transport->calls[1]['headers']['Authorization']==='Bearer issued-token','GA4 correct resource and bearer');check($r['sections']['daily']['rows'][0]['label']==='2026-10-01'&&$r['timezone']==='Europe/Warsaw','GA4 date normalization and timezone');check($r['sections']['overview']['rows'][0]['values']===[10.0,12.0,20.0,2.0],'GA4 totals not derived from daily users');
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$pem);$transport->calls=[];$g->report('456',['type'=>'service_account','client_email'=>'svc@example.org','private_key'=>$pem],... [...$dates,null]);parse_str($transport->calls[0]['body'],$form);[$head,$claims,$signature]=explode('.',$form['assertion']);$b64=fn($s)=>base64_decode(strtr($s,'-_','+/'));$c=json_decode($b64($claims),true);check($c['scope']==='https://www.googleapis.com/auth/analytics.readonly'&&openssl_verify($head.'.'.$claims,$b64($signature),openssl_pkey_get_details($key)['key'],OPENSSL_ALGO_SHA256)===1,'service account JWT cryptographic signature and read-only scope');
reject(fn()=>$m->validateCredential(['type'=>'oauth_token','token'=>"x\r\ny"]),InvalidArgumentException::class,'header injection rejected');reject(fn()=>$m->report('invalid',$cred,... [...$dates,null]),InvalidArgumentException::class,'invalid resource rejected');
$transport->status=429;try{$m->report('1234',$cred,... [...$dates,null]);throw new LogicException('Expected failure');}catch(RuntimeException $e){check($e->getMessage()==='Analytics provider HTTP 429','quota error sanitized');}$transport->status=200;$transport->malformed=true;reject(fn()=>$m->report('1234',$cred,... [...$dates,null]),RuntimeException::class,'malformed response rejected');$transport->malformed=false;
check(AnalyticsHub::period('2026-10-01','2026-10-05')===$dates,'valid date range');reject(fn()=>AnalyticsHub::period('2026-02-30','2026-03-01'),InvalidArgumentException::class,'invalid calendar date');reject(fn()=>AnalyticsHub::period('2025-01-01','2025-07-01'),InvalidArgumentException::class,'oversize period');
$routes=array_column(CajeerEngine\Http\RouteRegistry::all(),5,2);check($routes['api.v1.analytics.reports.create']===['analytics:write','integrations:secrets']&&$routes['api.v1.analytics.reports.sync']===['analytics:run'],'analytics RBAC');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite required');echo "SKIP SQL + worker regression (pdo_sqlite unavailable)\n";exit(0);}
$work=sys_get_temp_dir().'/ce-hub-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
 $config=new ConfigRepository($work);$config->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);$db=new DatabaseManager($config);(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');$q=new FileQueueDriver($work);$vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));$hub=new AnalyticsHub($db,$vault,$q,$config,$m,$g);
 $input=['provider'=>'metrika','name'=>'Site','resource'=>'1234','goal'=>'42','enabled'=>true,'credential'=>$cred];$source=$hub->save(null,$input);$id=$source['id'];check(!str_contains(json_encode($source),'private-token')&&!str_contains((string)$db->connection()->query('SELECT credential FROM analytics_connections')->fetchColumn(),'private-token'),'credentials encrypted and excluded from API');
 $receipt=$hub->enqueue($id,...$dates);check($receipt===$hub->enqueue($id,...$dates),'stable queue identity');$wh=new WebhookRepository($work);$idx=new SearchIndexer(new ContentTypeRepository($work),new ContentEntryRepository($work),new FileSearchIndex($work));$worker=new QueueWorker($q,$idx,$wh,new WebhookDispatcher(new SignedPayload(),$wh),null,null,$hub);
 check($worker->work(null,1)['processed']===1&&$hub->report($id,...$dates)['report']['sections']['overview']['rows'][0]['values'][1]===11,'real worker saves SQL report');$old=$hub->report($id,...$dates)['report'];
 $transport->status=503;$job=['id'=>$id,'revision'=>1,'from'=>$dates[0],'to'=>$dates[1]];reject(fn()=>$hub->sync($job),RuntimeException::class,'remote failure');check($hub->report($id,...$dates)['report']===$old&&$hub->overview()['items'][0]['last_error']==='Analytics provider HTTP 503','failed refresh preserves snapshot');$transport->status=200;$hub->sync($job);check($hub->overview()['items'][0]['last_error']===null,'retry clears failure');
 // Rotating credentials on the same resource must invalidate old-account snapshots atomically.
 $rotated=$hub->save($id,array_replace($input,['revision'=>1,'credential'=>['type'=>'oauth_token','token'=>'rotated-token']]));
 check($rotated['revision']===2&&$hub->report($id,...$dates)['report']===null,'rotating credentials erases old analytics snapshots');
 check($hub->report($id,...$dates)['sync_history']===[],'rotating credentials hides previous revision sync history');
 $staleCount=count($transport->calls);$hub->sync(['id'=>$id,'revision'=>1,'from'=>$dates[0],'to'=>$dates[1]]);
 check(count($transport->calls)===$staleCount,'stale credential job cannot call provider');
 $hub->sync(['id'=>$id,'revision'=>2,'from'=>$dates[0],'to'=>$dates[1]]);
 check($hub->report($id,...$dates)['report']!==null&&end($transport->calls)['headers']['Authorization']==='OAuth rotated-token','new credentials fetch fresh report');
 $updated=$hub->save($id,array_replace($input,['revision'=>2,'enabled'=>false,'credential'=>null]));reject(fn()=>$hub->save($id,$input+['revision'=>1]),RevisionConflict::class,'stale edit rejected');$before=count($transport->calls);$hub->sync($job);check(count($transport->calls)===$before,'stale or disabled job not sent');$hub->delete($id,$updated['revision']);check($hub->overview()['items']===[]&&(int)$db->connection()->query('SELECT COUNT(*) FROM analytics_reports')->fetchColumn()===0,'delete removes reports');
}finally{RecoveryBundle::cleanup($work);}
