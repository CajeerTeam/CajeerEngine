<?php

declare(strict_types=1);
use CajeerEngine\Analytics\{EventConfig,EventJournal,ServerEvents};
use CajeerEngine\Privacy\{TrackingConfig,ConsentPolicy};
use CajeerEngine\Integration\{IntegrationCenter,IntegrationStore,SecretVault,WebhookRepository,WebhookDispatcher};
use CajeerEngine\Integration\Provider\{ProviderRegistry,HttpProvider,AdvertisingProvider};
use CajeerEngine\Queue\{FileQueueDriver,QueueWorker};
use CajeerEngine\Search\{SearchIndexer,FileSearchIndex};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository,RevisionConflict};
use CajeerEngine\Security\{OutboundHttpTransport,SignedPayload};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Api\ApiContract;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function reject(callable $f,string $class,string $msg):void{try{$f();}catch(Throwable $e){check($e instanceof $class,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);}
$work=sys_get_temp_dir().'/ce-ads-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
 $tracking=new TrackingConfig($work);$policy=new ConsentPolicy($work);$old=$tracking->get();$version=$policy->publicConfig()['version'];
 check($old['ads_events']===[]&&$old['meta_id']===''&&!$old['ads_attribution'],'advertising off by default');
 $cfg=$tracking->update(array_replace($old,['enabled'=>true,'hosts'=>['site.example'],'ga4_id'=>'G-ABC12345','metrika_id'=>'12345678','google_ads_id'=>'AW-123456789','meta_id'=>'123456789012345','tiktok_id'=>'C1234567890123456789','vk_id'=>'12345678','ads_events'=>['generate_lead','sign_up'],'ads_lead_label'=>'AbC-D_efG','ads_signup_label'=>'Signup123','ads_yandex_lead_goal'=>'generate_lead','ads_attribution'=>true]));
 check($tracking->get()===$cfg&&$policy->publicConfig()['version']!==$version,'saved configuration invalidates consent');
 reject(fn()=>$tracking->update($old),RevisionConflict::class,'stale update rejected');
 foreach([['meta_id'=>'x'],['google_ads_id'=>'AW-x'],['tiktok_id'=>'x'],['vk_id'=>'x'],['gtm_id'=>'GTM-ABC123'],['ads_events'=>['purchase']],['ads_events'=>['generate_lead','generate_lead']],['ads_lead_label'=>'https://bad.example'],['ads_events'=>[]],['metrika_id'=>''],['ads_attribution'=>'true']] as $bad)reject(fn()=>$tracking->update(array_replace($cfg,$bad)),InvalidArgumentException::class,'invalid configuration '.array_key_first($bad));
 $legacy=$cfg;foreach(array_keys(TrackingConfig::advertisingDefaults()) as $key)unset($legacy[$key]);$new=$tracking->update($legacy);check($new['meta_id']===$cfg['meta_id']&&$new['ads_events']===$cfg['ads_events'],'legacy client cannot silently erase advertising configuration');
 $csp=TrackingConfig::csp($new);check(in_array('https://connect.facebook.net',$csp['script'],true)&&in_array('https://analytics.tiktok.com',$csp['script'],true)&&in_array('https://top-fwz1.mail.ru',$csp['script'],true)&&in_array('https://*.googleadservices.com',$csp['connect'],true),'fixed vendor CSP origins');
 $contract=new ApiContract($root);check($contract->validate(json_decode(json_encode($policy->publicConfig())),['$ref'=>'#/components/schemas/PublicConsentPolicy'])===[],'public config conforms to OpenAPI');
 $ec=new EventConfig($work);$ec->update(array_replace($ec->get(),['collect_diagnostics'=>true]));$version=$policy->publicConfig()['version'];$journal=new EventJournal($work);
 $row=['id'=>str_repeat('a',32),'name'=>'generate_lead','version'=>$version,'outcomes'=>['google_ads'=>'submitted','meta'=>'submitted','tiktok'=>'queued','vk'=>'failed','yandex_ads'=>'submitted']];
 check(!$journal->collect($row,'site.example')['duplicate']&&$journal->collect($row,'site.example')['duplicate'],'ad journal persists and deduplicates');
 reject(fn()=>$journal->collect(array_replace($row,['outcomes'=>['metrika'=>'submitted']]),'site.example'),InvalidArgumentException::class,'overlapping Yandex goal cannot be recorded as analytics-only');
 reject(fn()=>$journal->collect(array_replace($row,['id'=>str_repeat('b',32),'name'=>'form_submit']),'site.example'),InvalidArgumentException::class,'unselected ad conversion diagnostic rejected');
 check($contract->validate(json_decode(json_encode($row)),['$ref'=>'#/components/schemas/AnalyticsEventDiagnostic'])===[],'advertising diagnostic conforms to API');
 $transport=new class implements OutboundHttpTransport{public array $calls=[];public int $status=200;public bool $bad=false;public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576):array{$this->calls[]=compact('url','method','headers','body');return [$this->status,$this->bad?'{"code":123,"error":{"message":"sensitive upstream error"}}':(str_contains($url,'facebook')?'{"events_received":1}':'{"code":0,"data":{}}')];}};
 $meta=new AdvertisingProvider(true,$transport);$tiktok=new AdvertisingProvider(false,$transport);$registry=new ProviderRegistry();$registry->register($meta);$registry->register($tiktok);$registry->register(new HttpProvider(false,$transport));
 check(count(ProviderRegistry::defaults()->descriptors())===4,'default registry exposes advertising providers');
 reject(fn()=>$meta->configuration(['url'=>'https://evil.example/v25.0/123456789/events']),InvalidArgumentException::class,'Meta destination fixed');
 reject(fn()=>$tiktok->configuration(['url'=>AdvertisingProvider::TIKTOK_URL,'pixel_id'=>'invalid']),InvalidArgumentException::class,'TikTok destination and ID validated');
 $master='base64:'.base64_encode(random_bytes(32));putenv('INTEGRATIONS_KEY='.$master);$center=new IntegrationCenter(new IntegrationStore($work),$registry,new SecretVault($master),new ConfigRepository($work));
 $m=$center->create(['provider'=>'meta-capi','name'=>'Meta','config'=>['url'=>'https://graph.facebook.com/v25.0/123456789012345/events'],'secret'=>'test-token-secret','enabled'=>true],'actor');
 $t=$center->create(['provider'=>'tiktok-events','name'=>'TikTok','config'=>['url'=>AdvertisingProvider::TIKTOK_URL,'pixel_id'=>'C1234567890123456789'],'secret'=>'test-token-secret','enabled'=>true],'actor');
 $h=$center->create(['provider'=>'http-json','name'=>'Webhook','config'=>['url'=>'https://example.com/events'],'enabled'=>true],'actor');
 check(!str_contains(json_encode($center->overview()),'test-token-secret'),'advertising tokens never returned');
 reject(fn()=>$center->run($m['id'],1,'analytics.purchase',[],'actor'),InvalidArgumentException::class,'manual execution cannot bypass consent');
 reject(fn()=>$center->run($m['id'],1,'integration.test',[],'actor',true),InvalidArgumentException::class,'test cannot fake a conversion');
 putenv('ANALYTICS_SERVER_TARGETS='.$m['id'].','.$t['id'].','.$h['id']);$queue=new FileQueueDriver($work);$server=new ServerEvents($work,$center,$queue);$version=$policy->publicConfig()['version'];
 $grant=$server->grant('owner',$version,300);$hash=hash('sha256','example@example.com');$input=['grant_id'=>$grant['grant_id'],'id'=>str_repeat('c',32),'name'=>'purchase','integration_id'=>$m['id'],'revision'=>1,'value'=>45.5,'currency'=>'USD','email_sha256'=>$hash,'source_url'=>'https://site.example/thanks','user_agent'=>'Mozilla/5.0 example'];
 reject(fn()=>$server->enqueue('owner','actor',$input),InvalidArgumentException::class,'analytics-only lease cannot authorize advertising');
 $grant=$server->grant('owner',$version,300,true);$input['grant_id']=$grant['grant_id'];
 foreach([['email_sha256'=>'raw@example.com'],['source_url'=>'https://evil.example/thanks'],['source_url'=>'https://site.example/thanks?email=x'],['source_url'=>'https://site.example/admin/'],['user_agent'=>"x\ny"],['name'=>'refund'],['integration_id'=>$h['id']]] as $bad)reject(fn()=>$server->enqueue('owner','actor',array_replace($input,$bad)),InvalidArgumentException::class,'invalid server conversion '.array_key_first($bad));
 $receipt=$server->enqueue('owner','actor',$input);check($receipt===$server->enqueue('owner','actor',$input),'encrypted queued payload has stable retry identity');
 reject(fn()=>$server->enqueue('owner','actor',array_replace($input,['email_sha256'=>str_repeat('a',64)])),InvalidArgumentException::class,'same ID different matching data rejected');
 $stored='';foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS))as $file)if($file->isFile())$stored.=file_get_contents($file->getPathname());
 check(!str_contains($stored,$hash)&&!str_contains($stored,$input['user_agent'])&&!str_contains($stored,$input['source_url']),'matching data encrypted in consent records and queue');
 $wh=new WebhookRepository($work);$index=new SearchIndexer(new ContentEntryRepository($work),new FileSearchIndex($work));$worker=new QueueWorker($queue,$index,$wh,new WebhookDispatcher(new SignedPayload(),$wh),null,$server);
 check($worker->work(null,1)['processed']===1&&count($transport->calls)===1,'real worker sends Meta conversion');
 $call=$transport->calls[0];$data=json_decode($call['body'],true)['data'][0];check($data['event_name']==='Purchase'&&$data['event_id']===$input['id']&&$data['user_data']['em']===[$hash]&&$data['custom_data']['value']===45.5&&$call['headers']['Authorization']==='Bearer test-token-secret','Meta request payload and credentials');
 check($server->status('owner',$grant['grant_id'])['items'][0]['status']==='delivered','Meta API receipt recorded');
 $tik=array_replace($input,['integration_id'=>$t['id'],'id'=>str_repeat('d',32),'name'=>'generate_lead']);$server->enqueue('owner','actor',$tik);$transport->bad=true;putenv('QUEUE_RETRY_BACKOFF=1');
 check($worker->work(null,1)['failed']===1&&$server->status('owner',$grant['grant_id'])['items'][1]['status']==='failed','TikTok HTTP 200 with API error rejected');
 $first=json_decode($transport->calls[1]['body'],true);check($first['event_source_id']===$t['config']['pixel_id']&&$first['data'][0]['event']==='Contact'&&$first['data'][0]['event_id']===$tik['id']&&$transport->calls[1]['headers']['Access-Token']==='test-token-secret','TikTok request uses Events API 2.0 and stable ID');
 usleep(1200000);$transport->bad=false;check($worker->work(null,1)['processed']===1&&count($transport->calls)===3,'real queue retry delivers TikTok conversion');check(json_decode($transport->calls[2]['body'],true)===$first,'retry retains event timestamp and ID');
 $next=array_replace($input,['id'=>str_repeat('e',32)]);$server->enqueue('owner','actor',$next);$server->revoke('owner',$grant['grant_id']);check($worker->work(null,1)['processed']===1&&count($transport->calls)===3,'withdrawn consent blocks pending conversion');
 check($server->status('owner',$grant['grant_id'])['items'][2]['status']==='cancelled','cancelled status exposed');
 check($contract->validate(json_decode(json_encode($input)),['$ref'=>'#/components/schemas/AnalyticsServerEvent'])===[],'server conversion request conforms to OpenAPI');
 check($contract->validate((object)['version'=>$version,'ttl_seconds'=>300,'marketing'=>true],['$ref'=>'#/components/schemas/AnalyticsServerGrantRequest'])===[],'marketing lease conforms to OpenAPI');
}finally{putenv('ANALYTICS_SERVER_TARGETS');putenv('QUEUE_RETRY_BACKOFF');putenv('INTEGRATIONS_KEY');RecoveryBundle::cleanup($work);}
