<?php

declare(strict_types=1);
use CajeerEngine\Analytics\{EventConfig,EventJournal,ServerEvents};
use CajeerEngine\Privacy\{TrackingConfig,ConsentPolicy};
use CajeerEngine\Integration\{IntegrationCenter,IntegrationStore,SecretVault,WebhookRepository,WebhookDispatcher};
use CajeerEngine\Integration\Provider\{ProviderRegistry,HttpProvider};
use CajeerEngine\Queue\{FileQueueDriver,QueueWorker};
use CajeerEngine\Search\{SearchIndexer,FileSearchIndex,PublicSearchPage};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository,RevisionConflict};
use CajeerEngine\Security\{OutboundHttpTransport,SignedPayload};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Api\ApiContract;
use CajeerEngine\Http\RouteRegistry;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
function reject(callable $call,string $class,string $message): void {try{$call();}catch(Throwable $e){check($e instanceof $class,$message.' ('.$e::class.')');return;}throw new RuntimeException('Expected rejection: '.$message);}
$work=sys_get_temp_dir().'/ce-events-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
 $config=new EventConfig($work);$policy=new ConsentPolicy($work);$defaults=$config->get();$version=$policy->publicConfig()['version'];
 check(!$defaults['collect_diagnostics']&&EventConfig::rule($defaults,'page_view')['ga4']==='page_view','safe defaults and legacy pageview');
 check(EventConfig::rule($defaults,'search')['ga4']==='site_search','automatic search mapped without enhanced-measurement duplicate');
 check(EventConfig::rule($defaults,'purchase')===null,'purchase forbidden on browser route');
 $rules=$defaults['rules'];$rules[1]['metrika']='search_goal';$rules[1]['ga4']='';$c=$config->update(array_replace($defaults,['rules'=>$rules,'collect_diagnostics'=>true,'allow_custom'=>false]));
 check((new EventConfig($work))->get()===$c&&$c['revision']===2,'persisted rules');check($policy->publicConfig()['version']!==$version,'rule change invalidates consent');
 reject(fn()=>$config->update($defaults),RevisionConflict::class,'stale revision');
 foreach([['rules'=>[]],['rules'=>[$rules[0],$rules[0]]],['rules'=>[$rules[0],array_replace($rules[1],['name'=>'purchase'])]],['rules'=>[$rules[0],array_replace($rules[1],['ga4'=>'page_view'])]],['rules'=>[$rules[0],array_replace($rules[1],['name'=>'login','automatic'=>true])]]] as $bad)reject(fn()=>$config->update(array_replace($c,$bad)),InvalidArgumentException::class,'invalid rules rejected');
 check(EventConfig::rule($c,'unknown')===null&&EventConfig::rule($c,'search')['metrika']==='search_goal','allowlist and provider mapping');
 $tracking=new TrackingConfig($work);$tracking->update(array_replace($tracking->get(),['enabled'=>true,'hosts'=>['site.example'],'ga4_id'=>'G-ABC12345','metrika_id'=>'12345']));
 $journal=new EventJournal($work);$row=['id'=>str_repeat('a',32),'name'=>'search','version'=>$policy->publicConfig()['version'],'outcomes'=>['metrika'=>'submitted']];
 check(!$journal->collect($row,'site.example')['duplicate']&&$journal->collect($row,'site.example')['duplicate']&&count($journal->recent())===1,'journal persists and deduplicates');
 reject(fn()=>$journal->collect(array_replace($row,['outcomes'=>['metrika'=>'failed']]),'site.example'),InvalidArgumentException::class,'same event ID conflicting data rejected');
 foreach([['name'=>'unknown'],['version'=>str_repeat('b',64)],['outcomes'=>['ga4'=>'submitted']],['outcomes'=>['metrika'=>'confirmed']],['email'=>'test@example.com']] as $bad)reject(fn()=>$journal->collect(array_replace($row,$bad),'site.example'),InvalidArgumentException::class,'invalid diagnostic rejected');
 reject(fn()=>$journal->collect($row,'other.example'),InvalidArgumentException::class,'host restriction');
 check(!str_contains(file_get_contents($work.'/storage/app/privacy/event-journal.json'),'site.example')&&!str_contains(json_encode($journal->recent()),'version'),'no host or consent version stored');
 $journal->clear();check($journal->recent()===[],'journal clear');
 $html=PublicSearchPage::render(['meta'=>['query'=>'personal@example.com','limit'=>10,'offset'=>0,'total'=>3],'data'=>[]]);check(str_contains($html,'data-ce-analytics-search="3"')&&!str_contains($html,'data-ce-analytics-search="personal'),'actual CMS search marker only exposes count');
 $contract=new ApiContract($root);check($contract->validate(json_decode(json_encode($policy->publicConfig())),['$ref'=>'#/components/schemas/PublicConsentPolicy'])===[],'public schema includes events');
 $routes=array_column(RouteRegistry::all(),5,2);check($routes['api.v1.tracking.events.update']===['tracking:write']&&$routes['api.v1.public.analytics.events.collect']===null&&$routes['api.v1.tracking.events.server.enqueue']===['tracking:write','integrations:run'],'route permissions');
 $transport=new class implements OutboundHttpTransport {public array $calls=[];public int $status=200;public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array{$this->calls[]=compact('url','method','headers','body');return [$this->status,''];}};
 $registry=new ProviderRegistry();$registry->register(new HttpProvider(false,$transport));$center=new IntegrationCenter(new IntegrationStore($work),$registry,new SecretVault('base64:'.base64_encode(random_bytes(32))),new ConfigRepository($work));
 $target=$center->create(['name'=>'Analytics receiver','provider'=>'http-json','config'=>['url'=>'https://example.com/events'],'secret'=>'abc123','enabled'=>true],'actor');
 $queue=new FileQueueDriver($work);$server=new ServerEvents($work,$center,$queue);$version=$policy->publicConfig()['version'];
 putenv('ANALYTICS_SERVER_TARGETS');reject(fn()=>$server->grant('owner',$version,300),InvalidArgumentException::class,'server disabled by default');putenv('ANALYTICS_SERVER_TARGETS='.$target['id']);
 $grant=$server->grant('owner',$version,300);$input=['grant_id'=>$grant['grant_id'],'id'=>str_repeat('c',32),'name'=>'purchase','integration_id'=>$target['id'],'revision'=>1,'value'=>10,'currency'=>'USD'];
 reject(fn()=>$server->enqueue('other','actor',$input),InvalidArgumentException::class,'grant owner enforced');reject(fn()=>$server->enqueue('owner','actor',array_replace($input,['email'=>'a@b.com'])),InvalidArgumentException::class,'server PII rejected');
 $first=$server->enqueue('owner','actor',$input);check($first===$server->enqueue('owner','actor',$input),'stable durable queue idempotency');reject(fn()=>$server->enqueue('owner','actor',array_replace($input,['value'=>11])),InvalidArgumentException::class,'conflicting server event rejected');
 $wh=new WebhookRepository($work);$index=new SearchIndexer(new ContentEntryRepository($work),new FileSearchIndex($work));$worker=new QueueWorker($queue,$index,$wh,new WebhookDispatcher(new SignedPayload(),$wh),null,$server);
 check($worker->work(null,1)['processed']===1&&count($transport->calls)===1,'real worker invokes actual integration provider');$call=$transport->calls[0];$body=json_decode($call['body'],true);check($body['event']==='analytics.purchase'&&$body['event_id']===$input['id']&&$body['payload']['value']===10&&$call['headers']['Idempotency-Key']===$input['id'],'stable outbound purchase envelope');check($server->status('owner',$grant['grant_id'])['items'][0]['status']==='delivered','server delivery status');
 $next=array_replace($input,['id'=>str_repeat('d',32)]);$server->enqueue('owner','actor',$next);$server->revoke('owner',$grant['grant_id']);check($worker->work(null,1)['processed']===1&&count($transport->calls)===1,'revoked queued event not sent');check($server->status('owner',$grant['grant_id'])['items'][1]['status']==='cancelled','cancelled status');
 $grant2=$server->grant('owner',$version,300);$next=array_replace($next,['grant_id'=>$grant2['grant_id'],'id'=>str_repeat('e',32)]);$receipt=$server->enqueue('owner','actor',$next);$transport->status=503;putenv('QUEUE_RETRY_BACKOFF=1');
 check($worker->work(null,1)['failed']===1&&$server->status('owner',$grant2['grant_id'])['items'][0]['status']==='failed','remote failure persisted and worker retries');
 // Retry through a restarted worker after the real backoff interval.
 usleep(1200000);$transport->status=200;$worker=new QueueWorker($queue,$index,$wh,new WebhookDispatcher(new SignedPayload(),$wh),null,$server);
 check($worker->work(null,1)['processed']===1,'worker retry succeeds after backoff');
 $payload=$next+['owner'=>'owner','actor'=>'actor','_idempotency_key'=>'analytics:'.$next['id'].':'.$next['integration_id']];$server->deliver($payload);check(count($transport->calls)===3&&end($transport->calls)['headers']['Idempotency-Key']===$next['id'],'retry reuses stable ID; successful receipt prevents repeat');
 $grant3=$server->grant('owner',$version,300);$next=array_replace($next,['grant_id'=>$grant3['grant_id'],'id'=>str_repeat('f',32)]);$server->enqueue('owner','actor',$next);$config->update(array_replace($c,['enabled'=>false]));$worker->work(null,5);check(count($transport->calls)===3,'policy change cancels queued event');
 reject(fn()=>$server->grant('owner',$version,300),InvalidArgumentException::class,'stale/disabled consent cannot create grant');
}finally{putenv('ANALYTICS_SERVER_TARGETS');putenv('QUEUE_RETRY_BACKOFF');RecoveryBundle::cleanup($work);}
