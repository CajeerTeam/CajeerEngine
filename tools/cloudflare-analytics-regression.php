<?php
declare(strict_types=1);
use CajeerEngine\Analytics\Reporting\{AnalyticsHub,CloudflareReports};
use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Privacy\{TrackingConfig,ConsentPolicy};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Queue\{FileQueueDriver,QueueWorker};
use CajeerEngine\Integration\{SecretVault,WebhookRepository,WebhookDispatcher};
use CajeerEngine\Search\{SearchIndexer,FileSearchIndex};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Api\ApiContract;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);echo "PASS $what\n";}
function reject(callable $f,string $class,string $what):void{try{$f();}catch(Throwable $e){check($e instanceof $class,$what);return;}throw new RuntimeException('Expected failure: '.$what);}
$transport=new class implements OutboundHttpTransport {
    public array $calls=[];public int $status=200;public bool $denied=false;public bool $bad=false;public bool $noVitals=false;public bool $empty=false;
    public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array{
        $q=json_decode($body,true,32,JSON_THROW_ON_ERROR);$this->calls[]=compact('url','method','headers','q','maxBytes');
        if($this->status!==200)return [$this->status,'raw private token'];
        $query=$q['query'];$scope=str_contains($query,'accounts(')?'accounts':'zones';$web=$scope==='accounts';
        if($this->bad)return [200,'{"data":{"viewer":{"zones":[]}}}'];
        if($this->denied)return [200,'{"errors":[{"message":"raw private token"}],"data":null}'];
        if($this->noVitals&&str_contains($query,'rumWebVitalsEventsAdaptiveGroups'))return [200,'{"errors":[{"message":"dataset not available"}],"data":null}'];
        $row=static fn($count,$label=null,$dim=null)=>['count'=>$count,'sum'=>['visits'=>2,'edgeResponseBytes'=>$count*100],'avg'=>['sampleInterval'=>2], 'dimensions'=>$dim===null?[]:[$dim=>$label]];
        $rows=match(true){
            str_contains($query,'rumWebVitalsEventsAdaptiveGroups')=>[['quantiles'=>['largestContentfulPaintP75'=>2500000,'interactionToNextPaintP75'=>-1,'cumulativeLayoutShiftP75'=>0.12]]],
            str_contains($query,'firewallEventsAdaptiveGroups')=>[['count'=>3,'dimensions'=>['action'=>'block']]],
            str_contains($query,'edgeTimeToFirstByteMs')=>[['avg'=>['edgeTimeToFirstByteMs'=>12.5]]],
            str_contains($query,'dimensions { date }')=>[$row(10,'2026-10-01','date')],
            str_contains($query,'dimensions { cacheStatus }')=>[$row(8,'hit','cacheStatus'),$row(2,'miss','cacheStatus')],
            str_contains($query,'dimensions { edgeResponseStatus }')=>[$row(7,200,'edgeResponseStatus'),$row(2,404,'edgeResponseStatus'),$row(1,503,'edgeResponseStatus')],
            str_contains($query,'dimensions { clientCountryName }')=>[$row(10,'PL','clientCountryName')],
            str_contains($query,'dimensions { requestPath }')=>[$row(10,'/home?secret=1','requestPath')],
            str_contains($query,'dimensions { countryName }')=>[$row(10,'PL','countryName')],
            str_contains($query,'dimensions { deviceType }')=>[$row(10,'desktop','deviceType')],
            default=>[$row(10)]};
        return [200,json_encode(['data'=>['viewer'=>[$scope=>[['series'=>$this->empty?[]:$rows]]]],'errors'=>null],JSON_THROW_ON_ERROR)];
    }
};
$zone=new CloudflareReports('cf_zone',$transport);$web=new CloudflareReports('cf_web',$transport);$z=str_repeat('a',32);$site=str_repeat('b',32);$account=str_repeat('c',32);$zc=['type'=>'api_token','token'=>'private-token'];$wc=$zc+['account_id'=>$account];$dates=['2026-10-01','2026-10-03'];
$r=$zone->report($z,$zc,...[...$dates,null]);check(count($transport->calls)===7,'seven fixed edge queries');check($r['sections']['overview']['rows'][0]['values']===[10.0,1000.0,2.0],'requests bytes visits distinct');check($r['sections']['daily']['quality']['sampled']&&count($r['sections']['daily']['rows'])===1,'sampling and missing dates retained');check($r['sections']['summary']['rows'][0]['values']===[80.0]&&$r['sections']['summary']['rows'][1]['values']===[2.0]&&$r['sections']['summary']['rows'][2]['values']===[1.0],'HIT and 4xx/5xx summaries');check($r['sections']['performance']['rows'][0]['values']===[12.5]&&$r['sections']['security']['rows'][0]['values']===[3.0],'edge TTFB and WAF events');
foreach($transport->calls as $c)check($c['url']==='https://api.cloudflare.com/client/v4/graphql'&&$c['method']==='POST'&&$c['headers']['Authorization']==='Bearer private-token'&&$c['q']['variables']['scope']===$z&&$c['q']['variables']['end']==='2026-10-04T00:00:00Z'&&!str_contains($c['q']['query'],'private-token'),'fixed authenticated request and exclusive UTC end');
$contract=new ApiContract($root);check($contract->validate(json_decode(json_encode($r)),['$ref'=>'#/components/schemas/AnalyticsReportSnapshot'])===[],'edge report matches OpenAPI');
$transport->calls=[];$r=$web->report($site,$wc,...[...$dates,null]);check(count($transport->calls)===6&&$r['sections']['overview']['rows'][0]['values']===[2.0,10.0],'RUM visits and pageviews');check($r['sections']['pages']['rows'][0]['label']==='/home','query redacted');check($r['sections']['vitals']['rows'][0]['values']===[2500.0,null,0.12],'microseconds to milliseconds and negative N/A');foreach($transport->calls as $c)check($c['q']['variables']['scope']===$account&&$c['q']['variables']['site']===$site&&str_contains($c['q']['query'],'siteTag: $site'),'account and site scope');check($contract->validate(json_decode(json_encode($r)),['$ref'=>'#/components/schemas/AnalyticsReportSnapshot'])===[],'web report matches OpenAPI');
$transport->noVitals=true;$r=$web->report($site,$wc,...[...$dates,null]);check(isset($r['sections']['vitals']['quality']['unavailable'])&&$r['sections']['vitals']['rows']===[],'optional unsupported vitals not fabricated');$transport->noVitals=false;
$transport->empty=true;$r=$zone->report($z,$zc,...[...$dates,null]);check($r['sections']['overview']['rows']===[]&&$r['sections']['summary']['rows']===[],'empty API data remains empty');$transport->empty=false;
foreach([['type'=>'api_token','token'=>"x\r\ny"],$zc+['endpoint'=>'https://evil.example'],['type'=>'api_token','token'=>'']] as $bad)reject(fn()=>$zone->validateCredential($bad),InvalidArgumentException::class,'invalid credential rejected');reject(fn()=>$web->validateCredential($zc),InvalidArgumentException::class,'account required');reject(fn()=>$zone->report('invalid',$zc,...[...$dates,null]),InvalidArgumentException::class,'invalid zone rejected');
reject(fn()=>CloudflareReports::period('2026-09-01','2026-10-01'),InvalidArgumentException::class,'Cloudflare period capped at 30 days');
$transport->denied=true;try{$zone->report($z,$zc,...[...$dates,null]);throw new LogicException('expected error');}catch(RuntimeException $e){check($e->getMessage()==='Cloudflare GraphQL query rejected','HTTP 200 GraphQL failure sanitized');}$transport->denied=false;$transport->status=429;try{$zone->report($z,$zc,...[...$dates,null]);throw new LogicException('expected error');}catch(RuntimeException $e){check($e->getMessage()==='Analytics provider HTTP 429','rate limit propagated');}$transport->status=200;$transport->bad=true;reject(fn()=>$zone->report($z,$zc,...[...$dates,null]),RuntimeException::class,'malformed response rejected');$transport->bad=false;
$work=sys_get_temp_dir().'/ce-cf-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
    $t=new TrackingConfig($work);$d=$t->get();$legacy=$d;unset($legacy['cloudflare_token']);$t->update($legacy);check($t->get()['cloudflare_token']==='','legacy tracking settings compatible');$d=$t->get();$next=$t->update(array_replace($d,['enabled'=>true,'hosts'=>['site.example'],'cloudflare_token'=>$site]));$csp=TrackingConfig::csp($next);check(in_array('https://static.cloudflareinsights.com',$csp['script'],true)&&in_array('https://cloudflareinsights.com',$csp['connect'],true),'beacon CSP');check((new ConsentPolicy($work))->publicConfig()['tracking']['cloudflare_token']===$site,'public beacon ID present');reject(fn()=>$t->update(array_replace($next,['gtm_id'=>'GTM-ABCDE'])),InvalidArgumentException::class,'GTM remains exclusive');reject(fn()=>$t->update(array_replace($next,['cloudflare_token'=>'private-token'])),InvalidArgumentException::class,'private API token cannot be used as beacon ID');
    $input=['provider'=>'cf_zone','name'=>'Cloudflare Edge','resource'=>$z,'goal'=>null,'enabled'=>true,'credential'=>$zc];check($contract->validate(json_decode(json_encode($input)),['$ref'=>'#/components/schemas/AnalyticsReportCreate'])===[],'edge create matches API');$webInput=array_replace($input,['provider'=>'cf_web','resource'=>$site,'credential'=>$wc,'name'=>'Cloudflare RUM']);check($contract->validate(json_decode(json_encode($webInput)),['$ref'=>'#/components/schemas/AnalyticsReportCreate'])===[],'web create matches API');
    if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite required');echo "SKIP SQL + worker regression (pdo_sqlite unavailable)\n";exit(0);}
    $config=new ConfigRepository($work);$config->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);$db=new DatabaseManager($config);(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');$q=new FileQueueDriver($work);$vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));$hub=new AnalyticsHub($db,$vault,$q,$config,null,null,null,null,null,null,$zone,$web);
    $config->set('integrations.allowed_hosts',['api.example.com']);reject(fn()=>$hub->save(null,$input),InvalidArgumentException::class,'operator host policy');$config->set('integrations.allowed_hosts',['api.cloudflare.com']);$a=$hub->save(null,$input);$b=$hub->save(null,$webInput);check(!str_contains(json_encode($hub->overview()),'private-token'),'credentials hidden from public API');
    $wh=new WebhookRepository($work);$idx=new SearchIndexer(new ContentEntryRepository($work),new FileSearchIndex($work));$worker=new QueueWorker($q,$idx,$wh,new WebhookDispatcher(new SignedPayload(),$wh),null,null,$hub);
    foreach([$a,$b] as $src){$hub->enqueue($src['id'],...$dates);check($worker->work(null,1)['processed']===1&&$hub->report($src['id'],...$dates)['report']['provider']===$src['provider'],'worker persists '.$src['provider']);}
    $old=$hub->report($a['id'],...$dates)['report'];$transport->denied=true;reject(fn()=>$hub->sync(['id'=>$a['id'],'revision'=>1,'from'=>$dates[0],'to'=>$dates[1]]),RuntimeException::class,'failed sync');check($hub->report($a['id'],...$dates)['report']===$old,'previous snapshot retained');
}finally{RecoveryBundle::cleanup($work);}
