<?php

declare(strict_types=1);

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Api\Controller\{ConsentController,TrackingController,AnalyticsEventController};
use CajeerEngine\Analytics\{EventConfig,EventJournal,ServerEvents};
use CajeerEngine\Integration\{IntegrationCenter,IntegrationStore,SecretVault};
use CajeerEngine\Integration\Provider\ProviderRegistry;
use CajeerEngine\Queue\FileQueueDriver;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,RoleRepository,UserRepository,UserSessionRepository};
use CajeerEngine\Http\Middleware\{ApiContractMiddleware,AuthenticationMiddleware,ErrorHandlerMiddleware,JsonBodyMiddleware,MiddlewarePipeline,SecurityHeadersMiddleware};
use CajeerEngine\Http\{RouteRegistry,Router};
use CajeerEngine\Privacy\{TrackingConfig,ConsentPolicy};
use CajeerEngine\Runtime\{ConfigRepository,RuntimeLogger};
use CajeerEngine\Security\{AuthService,PasswordHasher,TwoFactorTotp};
use Symfony\Component\HttpFoundation\{Request,Response};

$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/ce-tracking-http-'.bin2hex(random_bytes(8));mkdir($work,0700);
function assertHttp(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message);echo "PASS $message\n"; }
try {
    $config=new ConfigRepository($work);$config->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);
    $db=new DatabaseManager($config);(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
    $roles=new RoleRepository($db);$passwords=new PasswordHasher($config);$users=new UserRepository($db,$roles,$passwords);$tokens=new ApiTokenRepository($db);
    $roles->upsert('tracking-admin','Tracking admin',['tracking:read','tracking:write','integrations:run','analytics:read','analytics:write','analytics:run','integrations:secrets']);
    $uid=$users->create('tracking@example.org','Tracking admin',password_hash('Strong-password123',PASSWORD_DEFAULT),['tracking-admin']);
    $read=$tokens->create($uid,'reader',['tracking:read'])['plain_token'];$write=$tokens->create($uid,'writer',['tracking:read','tracking:write'])['plain_token'];
    $serverToken=$tokens->create($uid,'server',['tracking:read','tracking:write','integrations:run'])['plain_token'];
    $hubRead=$tokens->create($uid,'hub-reader',['analytics:read'])['plain_token'];$hubWrite=$tokens->create($uid,'hub-writer',['analytics:read','analytics:write','analytics:run','integrations:secrets'])['plain_token'];
    // Browser cookies must reference a real, revocable session; API bearer tokens are not cookies.
    $sessionRecord=$tokens->create($uid,'admin-session',['*']);
    $session=$sessionRecord['plain_token'];
    $sessions=new UserSessionRepository($db);
    $sessions->register($sessionRecord['id'],$uid,'local',Request::create('/api/v1/tracking/counters','PUT'),$sessionRecord['expires_at']);
    $auth=new AuthService($config,$users,$tokens,new AuditLogRepository($db),$passwords,new TwoFactorTotp(),$sessions);
    $tracking=new TrackingConfig($work);$policy=new ConsentPolicy($work);$audit=new AuditLogger($work);
    $eventConfig=new EventConfig($work);$journal=new EventJournal($work);$center=new IntegrationCenter(new IntegrationStore($work),ProviderRegistry::defaults(),new SecretVault('base64:'.base64_encode(random_bytes(32))),$config);$server=new ServerEvents($work,$center,new FileQueueDriver($work));
    $hub=new \CajeerEngine\Analytics\Reporting\AnalyticsHub($db,new SecretVault('base64:'.base64_encode(random_bytes(32))),new FileQueueDriver($work),$config);
    $controllers=['analyticsHub'=>new \CajeerEngine\Api\Controller\AnalyticsHubController($hub,$audit),'tracking'=>new TrackingController($tracking,$audit),'consent'=>new ConsentController($policy,$audit),'analyticsEvents'=>new AnalyticsEventController($eventConfig,$journal,$audit,$server)];$router=new Router();
    foreach(RouteRegistry::api() as [$method,$path,$name,$service,$action,$scopes])if(isset($controllers[$service]))$router->add([$method],$path,$name,[$controllers[$service],$action],$scopes);
    $router->add(['GET'],'/','public.home',fn()=>new Response('<html></html>',200,['Content-Type'=>'text/html']),null);
    $router->add(['GET'],'/admin/','admin.fixture',fn()=>new Response('<html></html>',200,['Content-Type'=>'text/html']),null);
    $pipeline=new MiddlewarePipeline([new SecurityHeadersMiddleware($work),new ErrorHandlerMiddleware($config,new RuntimeLogger($work)),new JsonBodyMiddleware(),new AuthenticationMiddleware($auth,$router),new ApiContractMiddleware(new ApiContract($root))]);
    $call=static function(string $method,string $path,?string $token=null,?array $body=null,?string $cookie=null,?string $csrf=null,string $host='site.example',?string $origin=null)use($router,$pipeline){
        $headers=['CONTENT_TYPE'=>'application/json'];if($origin!==null)$headers['HTTP_ORIGIN']=$origin;if($token!==null)$headers['HTTP_AUTHORIZATION']='Bearer '.$token;if($csrf!==null)$headers['HTTP_X_CSRF_TOKEN']=$csrf;
        $request=Request::create('https://'.$host.$path,$method,[],$cookie===null?[]:['ce_session'=>$cookie],[],$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
        return $pipeline->handle($request,$router->dispatch(...));
    };
    $path='/api/v1/tracking/counters';$input=array_replace(TrackingConfig::defaults(),['enabled'=>true,'hosts'=>['site.example'],'metrika_id'=>'12345678','ga4_id'=>'G-ABC1234567']);
    assertHttp($call('GET',$path)->getStatusCode()===401,'anonymous admin read denied');
    assertHttp($call('GET',$path,$read)->getStatusCode()===200,'read scope accepted');
    assertHttp($call('PUT',$path,$read,$input)->getStatusCode()===403,'reader cannot publish');
    $withoutCsrf=$call('PUT',$path,null,$input,$session);
    assertHttp($withoutCsrf->getStatusCode()===403
        && (json_decode((string)$withoutCsrf->getContent(),true)['error']['code'] ?? null)==='csrf_failed',
        'cookie mutation requires CSRF');
    assertHttp($call('PUT',$path,$write,['revision'=>1])->getStatusCode()===422,'schema rejects incomplete configuration');
    assertHttp($call('PUT',$path,$write,array_replace($input,['gtm_id'=>'GTM-ABC123']))->getStatusCode()===422,'reject direct tags with GTM');
    $old=$policy->publicConfig()['version'];$res=$call('PUT',$path,null,$input,$session,AuthService::csrfToken($session));
    assertHttp($res->getStatusCode()===200&&json_decode((string)$res->getContent(),true)['data']['revision']===2,'authenticated publication persists');
    assertHttp($call('PUT',$path,$write,$input)->getStatusCode()===409,'stale revision conflict');
    $pub=$call('GET','/api/v1/public/consent');$data=json_decode((string)$pub->getContent(),true)['data'];
    assertHttp($pub->getStatusCode()===200&&$data['version']!==$old&&$data['tracking']['ga4_id']==='G-ABC1234567'&&str_contains((string)$pub->headers->get('Cache-Control'),'no-store'),'anonymous versioned public configuration');
    $csp=(string)$call('GET','/')->headers->get('Content-Security-Policy');
    assertHttp(str_contains($csp,'https://mc.yandex.ru')&&str_contains($csp,'https://www.googletagmanager.com')&&str_contains($csp,'https://*.google-analytics.com'),'public CSP permits actual provider endpoints');
    assertHttp(!str_contains((string)$call('GET','/',host:'staging.example')->headers->get('Content-Security-Policy'),'googletagmanager.com'),'unapproved domain CSP unchanged');
    assertHttp(!str_contains((string)$call('GET','/admin/')->headers->get('Content-Security-Policy'),'googletagmanager.com'),'admin CSP unchanged');
    assertHttp(str_contains((string)file_get_contents($work.'/storage/logs/audit.jsonl'),'tracking.config_updated'),'publication audited');
    $events='/api/v1/tracking/events';$eventSettings=array_replace(EventConfig::defaults(),['collect_diagnostics'=>true]);
    assertHttp($call('GET',$events)->getStatusCode()===401&&$call('PUT',$events,$read,$eventSettings)->getStatusCode()===403,'event settings RBAC');
    assertHttp($call('PUT',$events,$write,$eventSettings)->getStatusCode()===200,'event settings publication');
    $row=['id'=>str_repeat('a',32),'name'=>'page_view','version'=>$policy->publicConfig()['version'],'outcomes'=>['ga4'=>'submitted']];$collector='/api/v1/public/analytics/events';
    assertHttp($call('POST',$collector,body:$row,origin:'https://evil.example')->getStatusCode()===422,'cross-origin diagnostics rejected');
    assertHttp($call('POST',$collector,body:$row,origin:'https://site.example')->getStatusCode()===200,'public first-party collector');
    assertHttp(count(json_decode($call('GET',$events.'/journal',$read)->getContent(),true)['data']['items'])===1,'authorized journal read');
    assertHttp($call('DELETE',$events.'/journal',$read)->getStatusCode()===403&&$call('DELETE',$events.'/journal',$write)->getStatusCode()===200,'journal clear permission');
    $target=$center->create(['name'=>'server','provider'=>'http-json','config'=>['url'=>'https://example.com/events'],'secret'=>'','enabled'=>true],'actor');putenv('ANALYTICS_SERVER_TARGETS='.$target['id']);
    $grantBody=['version'=>$policy->publicConfig()['version'],'ttl_seconds'=>60];$grantPath=$events.'/server/grants';
    assertHttp($call('POST',$grantPath,$write,$grantBody)->getStatusCode()===403,'server scopes both required');
    $grantResponse=$call('POST',$grantPath,$serverToken,$grantBody);assertHttp($grantResponse->getStatusCode()===200,'server API token can issue lease');$grant=json_decode($grantResponse->getContent(),true)['data']['grant_id'];
    $serverBody=['grant_id'=>$grant,'id'=>str_repeat('b',32),'name'=>'purchase','integration_id'=>$target['id'],'revision'=>1,'value'=>10,'currency'=>'USD'];
    assertHttp($call('POST',$events.'/server',$serverToken,$serverBody)->getStatusCode()===200,'server event enqueued over HTTP');
    assertHttp($call('GET',$grantPath.'/'.$grant,$serverToken)->getStatusCode()===200&&$call('DELETE',$grantPath.'/'.$grant,$serverToken)->getStatusCode()===200,'server lease status and revoke');
    assertHttp($call('PUT',$path,$write,array_replace($input,['revision'=>2,'enabled'=>false]))->getStatusCode()===200,'disable counters');
    assertHttp(!str_contains((string)$call('GET','/')->headers->get('Content-Security-Policy'),'googletagmanager.com'),'disabled CSP does not permit vendor scripts');
    $hubPath='/api/v1/analytics/reports';$source=['name'=>'Metrika site','provider'=>'metrika','resource'=>'1234','enabled'=>true,'credential'=>['type'=>'oauth_token','token'=>'private-test-token']];
    assertHttp($call('GET',$hubPath)->getStatusCode()===401&&$call('GET',$hubPath,$hubRead)->getStatusCode()===200,'analytics hub read scope');
    assertHttp($call('POST',$hubPath,$hubRead,$source)->getStatusCode()===403&&$call('POST',$hubPath,null,$source,$session)->getStatusCode()===403,'analytics write scope and CSRF required');
    assertHttp($call('POST',$hubPath,$hubWrite,array_replace($source,['resource'=>'G-123']))->getStatusCode()===422,'analytics property schema rejects measurement ID');
    $created=$call('POST',$hubPath,$hubWrite,$source);assertHttp($created->getStatusCode()===201&&!str_contains($created->getContent(),'private-test-token'),'source created and credential not exposed');$hubId=json_decode($created->getContent(),true)['data']['id'];
    assertHttp($call('GET',$hubPath.'/'.$hubId.'/data',$hubRead)->getStatusCode()===200,'empty stored report readable');
    assertHttp($call('POST',$hubPath.'/'.$hubId.'/sync',$hubRead,['from'=>'2026-01-01','to'=>'2026-01-02'])->getStatusCode()===403&&$call('POST',$hubPath.'/'.$hubId.'/sync',$hubWrite,['from'=>'2026-01-01','to'=>'2026-01-02'])->getStatusCode()===202,'sync route enqueues with correct permission');
    assertHttp($call('PUT',$hubPath.'/'.$hubId,$hubWrite,$source+['revision'=>0])->getStatusCode()===422,'revision schema rejects invalid revision');
    $searchSource=array_replace($source,['provider'=>'search_console','resource'=>'https://example.com/']);
    $searchCreated=$call('POST',$hubPath,$hubWrite,$searchSource);assertHttp($searchCreated->getStatusCode()===201,'Search Console property created');$searchId=json_decode($searchCreated->getContent(),true)['data']['id'];
    $actionPath=$hubPath.'/'.$searchId.'/webmaster';$action=['revision'=>1,'action'=>'inspect','url'=>'https://other.example/page'];
    assertHttp($call('POST',$actionPath,$hubRead,$action)->getStatusCode()===403,'webmaster action requires write and run scopes');
    assertHttp($call('POST',$actionPath,null,$action,$session)->getStatusCode()===403,'webmaster action CSRF required');
    assertHttp($call('POST',$actionPath,$hubWrite,array_replace($action,['action'=>'invalid']))->getStatusCode()===422,'webmaster action validates operation');
    assertHttp($call('DELETE',$hubPath.'/'.$searchId,$hubWrite,['revision'=>1])->getStatusCode()===200,'search source deletion');
    assertHttp($call('DELETE',$hubPath.'/'.$hubId,$hubWrite,['revision'=>1])->getStatusCode()===200,'source deletion');
    assertHttp($sessions->revoke($sessionRecord['id'],$uid),'browser session can be revoked');
    assertHttp($call('PUT',$path,null,$input,$session,AuthService::csrfToken($session))->getStatusCode()===401,
        'revoked browser session rejected despite valid CSRF');
} finally { putenv('ANALYTICS_SERVER_TARGETS'); RecoveryBundle::cleanup($work); }
