<?php
declare(strict_types=1);
use CajeerEngine\Api\ApiContract;
use CajeerEngine\Api\Controller\MigrationController;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,RoleRepository,UserRepository};
use CajeerEngine\Http\Middleware\{ApiContractMiddleware,AuthenticationMiddleware,ErrorHandlerMiddleware,JsonBodyMiddleware,MiddlewarePipeline};
use CajeerEngine\Http\{RouteRegistry,Router};
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Migration\MigrationCenter;
use CajeerEngine\Runtime\{ConfigRepository,RuntimeLogger};
use CajeerEngine\Security\{AuthService,PasswordHasher,TwoFactorTotp};
use Symfony\Component\HttpFoundation\{Request,File\UploadedFile};
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';$work=sys_get_temp_dir().'/ce-migration-http-'.bin2hex(random_bytes(8));mkdir($work,0700);
function checkHttp(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
try{
 $config=new ConfigRepository($work);$config->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);$db=new DatabaseManager($config);(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
 $roles=new RoleRepository($db);$passwords=new PasswordHasher($config);$users=new UserRepository($db,$roles,$passwords);$tokens=new ApiTokenRepository($db);$roles->upsert('migration-admin','Migration admin',['migration:read','migration:write','migration:run','integrations:secrets']);$uid=$users->create('migration@example.org','Migration admin',password_hash('Strong-password123',PASSWORD_DEFAULT),['migration-admin']);
 $admin=$tokens->create($uid,'admin',['*'])['plain_token'];$read=$tokens->create($uid,'read',['migration:read'])['plain_token'];$write=$tokens->create($uid,'write',['migration:read','migration:write'])['plain_token'];$runner=$tokens->create($uid,'run',['migration:read','migration:run'])['plain_token'];
 $auth=new AuthService($config,$users,$tokens,new AuditLogRepository($db),$passwords,new TwoFactorTotp());$controller=new MigrationController(new MigrationCenter($work,new SecretVault('base64:'.base64_encode(random_bytes(32)))),new AuditLogger($work));$router=new Router();foreach(RouteRegistry::api() as [$method,$path,$name,$service,$action,$scopes])if($service==='migration')$router->add([$method],$path,$name,[$controller,$action],$scopes);
 $pipeline=new MiddlewarePipeline([new ErrorHandlerMiddleware($config,new RuntimeLogger($work)),new JsonBodyMiddleware(),new AuthenticationMiddleware($config,$auth,$router),new ApiContractMiddleware(new ApiContract($root))]);
 $call=static function(string $method,string $path,?string $token=null,?array $body=null,?string $cookie=null,?string $csrf=null,?UploadedFile $file=null)use($router,$pipeline){$h=['CONTENT_TYPE'=>$file?'multipart/form-data; boundary=test':'application/json'];if($token!==null)$h['HTTP_AUTHORIZATION']='Bearer '.$token;if($csrf!==null)$h['HTTP_X_CSRF_TOKEN']=$csrf;$r=Request::create('/api/v1'.$path,$method,$file?['revision'=>'1']:[],$cookie===null?[]:['ce_session'=>$cookie],$file?['file'=>$file]:[],$h,$file?null:($body===null?null:json_encode($body,JSON_THROW_ON_ERROR)));return $pipeline->handle($r,$router->dispatch(...));};
 $path='/migration/projects';$input=['name'=>'WXR test','source'=>'wordpress-wxr','config'=>(object)[],'credential'=>(object)[]];
 checkHttp($call('GET',$path)->getStatusCode()===401&&$call('GET',$path,$read)->getStatusCode()===200,'migration read authentication');
 checkHttp($call('POST',$path,$read,$input)->getStatusCode()===403&&$call('POST',$path,$write,$input)->getStatusCode()===403,'create requires secret scope');
 checkHttp($call('POST',$path,null,$input,$admin)->getStatusCode()===403,'browser mutation requires CSRF');
 checkHttp($call('POST',$path,$admin,['name'=>'Incomplete'])->getStatusCode()===422,'incomplete source configuration rejected');
 $res=$call('POST',$path,null,$input,$admin,AuthService::csrfToken($admin));checkHttp($res->getStatusCode()===201,'browser session with CSRF creates project');$id=json_decode($res->getContent(),true)['data']['id'];$item=$path.'/'.$id;
 checkHttp($call('POST',$item.'/scan',$read,['revision'=>1])->getStatusCode()===403,'reader cannot scan');
 $xml=$work.'/fixture.xml';file_put_contents($xml,'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:wxr_version>1.2</wp:wxr_version><item><wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>');$file=new UploadedFile($xml,'fixture.xml','application/xml',UPLOAD_ERR_OK,true);
 checkHttp($call('POST',$item.'/wxr',$runner,file:$file)->getStatusCode()===403,'upload also requires write scope');
 $res=$call('POST',$item.'/wxr',$admin,file:$file);$p=json_decode($res->getContent(),true)['data']??[];checkHttp($res->getStatusCode()===200&&$p['status']==='discovered'&&$p['report']['counts']['posts']===1&&!isset($p['credential'],$p['wxr_ref']),'real multipart XML scan through request pipeline');
 checkHttp($call('POST',$item.'/scan',$runner,['revision'=>1])->getStatusCode()===409,'stale scan maps to 409');
 checkHttp($call('POST',$item.'/scan',$runner,['revision'=>2])->getStatusCode()===200,'run-only scope rescans stored WXR');
 checkHttp($call('DELETE',$item,$read,['revision'=>3])->getStatusCode()===403&&$call('DELETE',$item,$admin,['revision'=>3])->getStatusCode()===200,'delete protected and succeeds');
 checkHttp($call('GET',$item,$read)->getStatusCode()===404,'deleted project no longer available');
 $audit=file_get_contents($work.'/storage/logs/audit.jsonl');checkHttp(str_contains($audit,'migration.discovery.created')&&str_contains($audit,'migration.discovery.discovered')&&!str_contains($audit,'fixture.xml'),'audited operations without source data');
}finally{RecoveryBundle::cleanup($work);}
