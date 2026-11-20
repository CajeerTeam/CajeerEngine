<?php

declare(strict_types=1);

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Api\Controller\IntegrationController;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Database\Repository\UserSessionRepository;
use CajeerEngine\Http\Middleware\ApiContractMiddleware;
use CajeerEngine\Http\Middleware\AuthenticationMiddleware;
use CajeerEngine\Http\Middleware\ErrorHandlerMiddleware;
use CajeerEngine\Http\Middleware\JsonBodyMiddleware;
use CajeerEngine\Http\Middleware\MiddlewarePipeline;
use CajeerEngine\Http\RouteRegistry;
use CajeerEngine\Http\Router;
use CajeerEngine\Integration\IntegrationCenter;
use CajeerEngine\Integration\IntegrationStore;
use CajeerEngine\Integration\Provider\ProviderRegistry;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Security\TwoFactorTotp;
use Symfony\Component\HttpFoundation\Request;

$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/ce-integration-http-'.bin2hex(random_bytes(8));mkdir($work,0700);
function assertHttp(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message);echo "PASS $message\n"; }
try {
    $config=new ConfigRepository($work);
    $config->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);
    $db=new DatabaseManager($config);(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
    $roles=new RoleRepository($db);$passwords=new PasswordHasher($config);$users=new UserRepository($db,$roles,$passwords);$tokens=new ApiTokenRepository($db);
    $roles->upsert('integration-admin','Integration admin',['integrations:read','integrations:write','integrations:secrets','integrations:run']);
    $uid=$users->create('admin@example.org','Integration admin',password_hash('Strong-password123',PASSWORD_DEFAULT),['integration-admin']);
    $admin=$tokens->create($uid,'test',['*'])['plain_token'];
    $read=$tokens->create($uid,'reader',['integrations:read'])['plain_token'];
    $write=$tokens->create($uid,'writer',['integrations:read','integrations:write'])['plain_token'];
    // A browser cookie is valid only when its opaque token also has an active,
    // revocable device-session record. Do not bypass that production safeguard.
    $sessionToken=$tokens->create($uid,'admin-session',['*']);
    $session=$sessionToken['plain_token'];
    $sessions=new UserSessionRepository($db);
    $sessions->register($sessionToken['id'],$uid,'local',Request::create('/api/v1/integrations','POST'),$sessionToken['expires_at']);
    $auth=new AuthService($config,$users,$tokens,new AuditLogRepository($db),$passwords,new TwoFactorTotp(),$sessions);
    $center=new IntegrationCenter(new IntegrationStore($work),ProviderRegistry::defaults(),new SecretVault('base64:'.base64_encode(random_bytes(32))),$config);
    $controller=new IntegrationController($center,new AuditLogger($work));$router=new Router();
    foreach(RouteRegistry::api() as [$method,$path,$name,$service,$action,$scopes]) if($service==='integrations') $router->add([$method],$path,$name,[$controller,$action],$scopes);
    $pipeline=new MiddlewarePipeline([new ErrorHandlerMiddleware($config,new RuntimeLogger($work)),new JsonBodyMiddleware(),new AuthenticationMiddleware($config,$auth,$router),new ApiContractMiddleware(new ApiContract($root))]);
    $call=static function(string $method,string $path,?string $token=null,?array $body=null,?string $cookie=null,?string $csrf=null)use($router,$pipeline){
        $headers=['CONTENT_TYPE'=>'application/json'];if($token!==null)$headers['HTTP_AUTHORIZATION']='Bearer '.$token;if($csrf!==null)$headers['HTTP_X_CSRF_TOKEN']=$csrf;
        $request=Request::create('/api/v1'.$path,$method,[],$cookie===null?[]:['ce_session'=>$cookie],[],$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
        return $pipeline->handle($request,$router->dispatch(...));
    };
    $input=['provider'=>'http-json','name'=>'HTTP test','config'=>['url'=>'https://example.org/hook'],'secret'=>'credential-for-test','enabled'=>true];
    assertHttp($call('GET','/integrations')->getStatusCode()===401,'unauthenticated center rejected');
    assertHttp($call('GET','/integrations',$read)->getStatusCode()===200,'read token accepted');
    assertHttp($call('POST','/integrations',$read,$input)->getStatusCode()===403,'read token cannot create');
    assertHttp($call('POST','/integrations',$write,$input)->getStatusCode()===403,'write token cannot create credentials');
    $withoutCsrf=$call('POST','/integrations',null,$input,$session);
    assertHttp($withoutCsrf->getStatusCode()===403
        && (json_decode((string)$withoutCsrf->getContent(),true)['error']['code'] ?? null)==='csrf_failed',
        'authenticated cookie mutation requires CSRF');
    $created=$call('POST','/integrations',null,$input,$session,AuthService::csrfToken($session));
    assertHttp($created->getStatusCode()===201 && !str_contains((string)$created->getContent(),'credential-for-test'),'cookie plus CSRF creates safely');
    $item=json_decode((string)$created->getContent(),true,512,JSON_THROW_ON_ERROR)['data'];$path='/integrations/'.$item['id'];
    assertHttp($call('PUT',$path.'/secret',$write,['revision'=>1,'secret'=>'new'])->getStatusCode()===403,'writer cannot rotate secret');
    assertHttp($call('POST',$path.'/test',$read,['revision'=>1])->getStatusCode()===403,'reader cannot run integration');
    assertHttp($call('PATCH',$path,$write,['revision'=>1,'name'=>'Updated'])->getStatusCode()===200,'writer edits metadata');
    assertHttp($call('PATCH',$path,$write,['revision'=>1,'name'=>'Stale'])->getStatusCode()===409,'stale revision maps to 409');
    assertHttp($call('PUT',$path.'/secret',$admin,['revision'=>2,'secret'=>'new-secret'])->getStatusCode()===200,'credential rotation authorized');
    assertHttp($call('DELETE',$path,$admin,['revision'=>3])->getStatusCode()===204,'delete returns 204');
    assertHttp($call('PATCH',$path,$admin,['revision'=>3,'name'=>'Missing'])->getStatusCode()===404,'missing record maps to 404');
    assertHttp($sessions->revoke($sessionToken['id'],$uid),'browser session can be revoked');
    assertHttp($call('POST','/integrations',null,$input,$session,AuthService::csrfToken($session))->getStatusCode()===401,
        'revoked browser session cannot mutate with a valid CSRF token');
    $audit=file_get_contents($work.'/storage/logs/audit.jsonl');assertHttp(str_contains($audit,'integration.credential_changed')&&!str_contains($audit,'new-secret')&&!str_contains($audit,'credential-for-test'),'audit contains operation but no secret');
} finally { RecoveryBundle::cleanup($work); }
