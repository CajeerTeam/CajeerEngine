<?php

declare(strict_types=1);

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Api\Controller\{ConsentController,TrackingController};
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,RoleRepository,UserRepository};
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
    $roles->upsert('tracking-admin','Tracking admin',['tracking:read','tracking:write']);
    $uid=$users->create('tracking@example.org','Tracking admin',password_hash('Strong-password123',PASSWORD_DEFAULT),['tracking-admin']);
    $read=$tokens->create($uid,'reader',['tracking:read'])['plain_token'];$write=$tokens->create($uid,'writer',['tracking:read','tracking:write'])['plain_token'];
    $auth=new AuthService($config,$users,$tokens,new AuditLogRepository($db),$passwords,new TwoFactorTotp());
    $tracking=new TrackingConfig($work);$policy=new ConsentPolicy($work);$audit=new AuditLogger($work);
    $controllers=['tracking'=>new TrackingController($tracking,$audit),'consent'=>new ConsentController($policy,$audit)];$router=new Router();
    foreach(RouteRegistry::api() as [$method,$path,$name,$service,$action,$scopes])if(isset($controllers[$service]))$router->add([$method],$path,$name,[$controllers[$service],$action],$scopes);
    $router->add(['GET'],'/','public.home',fn()=>new Response('<html></html>',200,['Content-Type'=>'text/html']),null);
    $router->add(['GET'],'/admin/','admin.fixture',fn()=>new Response('<html></html>',200,['Content-Type'=>'text/html']),null);
    $pipeline=new MiddlewarePipeline([new SecurityHeadersMiddleware($work),new ErrorHandlerMiddleware($config,new RuntimeLogger($work)),new JsonBodyMiddleware(),new AuthenticationMiddleware($config,$auth,$router),new ApiContractMiddleware(new ApiContract($root))]);
    $call=static function(string $method,string $path,?string $token=null,?array $body=null,?string $cookie=null,?string $csrf=null,string $host='site.example')use($router,$pipeline){
        $headers=['CONTENT_TYPE'=>'application/json'];if($token!==null)$headers['HTTP_AUTHORIZATION']='Bearer '.$token;if($csrf!==null)$headers['HTTP_X_CSRF_TOKEN']=$csrf;
        $request=Request::create('https://'.$host.$path,$method,[],$cookie===null?[]:['ce_session'=>$cookie],[],$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
        return $pipeline->handle($request,$router->dispatch(...));
    };
    $path='/api/v1/tracking/counters';$input=array_replace(TrackingConfig::defaults(),['enabled'=>true,'hosts'=>['site.example'],'metrika_id'=>'12345678','ga4_id'=>'G-ABC1234567']);
    assertHttp($call('GET',$path)->getStatusCode()===401,'anonymous admin read denied');
    assertHttp($call('GET',$path,$read)->getStatusCode()===200,'read scope accepted');
    assertHttp($call('PUT',$path,$read,$input)->getStatusCode()===403,'reader cannot publish');
    assertHttp($call('PUT',$path,null,$input,$write)->getStatusCode()===403,'cookie mutation requires CSRF');
    assertHttp($call('PUT',$path,$write,['revision'=>1])->getStatusCode()===422,'schema rejects incomplete configuration');
    assertHttp($call('PUT',$path,$write,array_replace($input,['gtm_id'=>'GTM-ABC123']))->getStatusCode()===422,'reject direct tags with GTM');
    $old=$policy->publicConfig()['version'];$res=$call('PUT',$path,null,$input,$write,AuthService::csrfToken($write));
    assertHttp($res->getStatusCode()===200&&json_decode((string)$res->getContent(),true)['data']['revision']===2,'authenticated publication persists');
    assertHttp($call('PUT',$path,$write,$input)->getStatusCode()===409,'stale revision conflict');
    $pub=$call('GET','/api/v1/public/consent');$data=json_decode((string)$pub->getContent(),true)['data'];
    assertHttp($pub->getStatusCode()===200&&$data['version']!==$old&&$data['tracking']['ga4_id']==='G-ABC1234567'&&str_contains((string)$pub->headers->get('Cache-Control'),'no-store'),'anonymous versioned public configuration');
    $csp=(string)$call('GET','/')->headers->get('Content-Security-Policy');
    assertHttp(str_contains($csp,'https://mc.yandex.ru')&&str_contains($csp,'https://www.googletagmanager.com')&&str_contains($csp,'https://*.google-analytics.com'),'public CSP permits actual provider endpoints');
    assertHttp(!str_contains((string)$call('GET','/',host:'staging.example')->headers->get('Content-Security-Policy'),'googletagmanager.com'),'unapproved domain CSP unchanged');
    assertHttp(!str_contains((string)$call('GET','/admin/')->headers->get('Content-Security-Policy'),'googletagmanager.com'),'admin CSP unchanged');
    assertHttp(str_contains((string)file_get_contents($work.'/storage/logs/audit.jsonl'),'tracking.config_updated'),'publication audited');
    assertHttp($call('PUT',$path,$write,array_replace($input,['revision'=>2,'enabled'=>false]))->getStatusCode()===200,'disable counters');
    assertHttp(!str_contains((string)$call('GET','/')->headers->get('Content-Security-Policy'),'googletagmanager.com'),'disabled CSP does not permit vendor scripts');
} finally { RecoveryBundle::cleanup($work); }
