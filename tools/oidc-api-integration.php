<?php

declare(strict_types=1);
/** Real AuthService + middleware + PDO integration. Required in release CI. */
$root=dirname(__DIR__);
if (!in_array('sqlite',PDO::getAvailableDrivers(),true) || !is_file($root.'/vendor/autoload.php')) {
    if (in_array('--require-db',$argv,true)) {fwrite(STDERR,"FAIL PDO SQLite / Composer dependencies required\n");exit(1);}
    echo "SKIP PDO SQLite / Composer dependencies unavailable\n";exit(0);
}
require $root.'/vendor/autoload.php';
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\{AuthService,PasswordHasher,TwoFactorTotp};
use CajeerEngine\Identity\{OidcClient,OidcApiAuthenticator};
use CajeerEngine\Http\{Router,RouteRegistry};
use CajeerEngine\Http\Middleware\{AuthenticationMiddleware,MiddlewarePipeline};
use Symfony\Component\HttpFoundation\Request;
$cfg=new ConfigRepository($root);
$cfg->set('database.default','sqlite'); $cfg->set('database.connections.sqlite.database',':memory:');
$db=new DatabaseManager($cfg);$pdo=$db->connection();
$pdo->exec('CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT UNIQUE NOT NULL,name TEXT,status TEXT NOT NULL,password_hash TEXT,two_factor_enabled INTEGER NOT NULL DEFAULT 0,two_factor_secret TEXT,two_factor_recovery_codes TEXT DEFAULT \'[]\',last_login_at TEXT,disabled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT UNIQUE,name TEXT,permissions TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_user_roles(user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id))');
$pdo->exec('CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,token_hash TEXT UNIQUE,token_prefix TEXT,scopes TEXT,last_used_at TEXT,expires_at TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_oidc_identities (issuer TEXT,subject TEXT,user_id TEXT,PRIMARY KEY(issuer,subject))');
$roles=new RoleRepository($db);$roles->upsert('editor','Editor',['content:read','content:write','media:read','users:read']);
$pw=new PasswordHasher($cfg);$users=new UserRepository($db,$roles,$pw);
$uid=$users->create('alice@example.test','Alice',$pw->hash('SecurePassword123!'),['editor']);
$other=$users->create('bob@example.test','Bob',$pw->hash('SecurePassword123!'),['editor']);
$issuer='https://id.skif4er.ru/realms/skif4er';
$pdo->prepare('INSERT INTO ce_oidc_identities(issuer,subject,user_id) VALUES(?,?,?)')->execute([$issuer,'alice-sub',$uid]);
$b64=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$rsa=openssl_pkey_get_details($key)['rsa'];
$metadata=json_encode(['issuer'=>$issuer,'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth','token_endpoint'=>$issuer.'/protocol/openid-connect/token','jwks_uri'=>$issuer.'/protocol/openid-connect/certs','introspection_endpoint'=>$issuer.'/protocol/openid-connect/token/introspect'],JSON_THROW_ON_ERROR);
$jwks=json_encode(['keys'=>[['kid'=>'api-integration','alg'=>'RS256','kty'=>'RSA','n'=>$b64($rsa['n']),'e'=>$b64($rsa['e'])]]],JSON_THROW_ON_ERROR);
$revoked=false;
$transport=static function(string $method,string $url,?string $body,array $headers)use(&$revoked,$metadata,$jwks):array {
    if(str_ends_with($url,'/.well-known/openid-configuration'))return ['status'=>200,'body'=>$metadata];
    if(str_ends_with($url,'/certs'))return ['status'=>200,'body'=>$jwks];
    if(str_ends_with($url,'/token/introspect'))return ['status'=>200,'body'=>json_encode(['active'=>!$revoked,'sub'=>'alice-sub','client_id'=>'frontend-one'],JSON_THROW_ON_ERROR)];
    throw new RuntimeException('Unexpected IdP endpoint');
};
$client=new OidcClient('cajeerengine-web','client-secret','https://cms.example.test/auth/oidc/callback',$transport);
$api=new OidcApiAuthenticator($client,$db,'cajeerengine-api',['frontend-one'],'cajeer.api',['content:read','media:read'],true);
$tokens=new ApiTokenRepository($db);
$auth=new AuthService($cfg,$users,$tokens,new AuditLogRepository($db),$pw,new TwoFactorTotp(),null,$api);
$claims=['iss'=>$issuer,'aud'=>['cajeerengine-api'],'sub'=>'alice-sub','typ'=>'Bearer','azp'=>'frontend-one','scope'=>'openid cajeer.api','iat'=>time()-2,'exp'=>time()+300];
$sign=static function(array $payload)use($key,$b64):string {
    $text=$b64(json_encode(['alg'=>'RS256','kid'=>'api-integration','typ'=>'JWT'],JSON_THROW_ON_ERROR)).'.'.$b64(json_encode($payload,JSON_THROW_ON_ERROR));
    openssl_sign($text,$sig,$key,OPENSSL_ALGO_SHA256);return $text.'.'.$b64($sig);
};
$jwt=$sign($claims);
$checks=0;$check=static function(bool $ok,string $msg)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$msg);$checks++;echo 'PASS '.$msg.PHP_EOL;};
$request=static function(string $path,string $token,string $method='GET',bool $cookie=false):Request {
    return Request::create('https://cms.example.test'.$path,$method,[], $cookie?['ce_session'=>$token]:[], [], $cookie?[]:['HTTP_AUTHORIZATION'=>'Bearer '.$token]);
};
$identity=$auth->authenticateRequest($request('/api/v1/auth/me',$jwt));
$check(($identity['user_id']??null)===$uid && ($identity['auth_source']??null)==='oidc_access','verified subject resolves to linked Cajeer user');
$check(($identity['cookie']??null)===false && ($identity['session']??null)===false && ($identity['token_id']??'missing')===null,'external access is not a browser session');
$check(($identity['scopes']??null)===['content:read','media:read'],'external OAuth scopes capped by local RBAC and API policy');
$check(!$auth->hasScopes($identity,['content:write']) && $auth->hasScopes($identity,['content:read']),'privileged operations blocked by policy');
$check($auth->authenticateRequest($request('/api/v1/auth/sessions',$jwt))===null,'session management cannot use external bearer token');
$check($auth->authenticateRequest($request('/api/v1/api-tokens',$jwt))===null,'API token management cannot use external bearer token');
$check($auth->authenticateRequest($request('/api/v1/identity/account',$jwt))===null,'identity mutation and linking APIs require local session');
$check($auth->authenticateRequest($request('/api/v1/auth/me',$jwt,'GET',true))===null,'browser cookie never accepts access JWT');
$badClaims=$claims;$badClaims['sub']='not-linked';
$check($auth->authenticateRequest($request('/api/v1/auth/me',$sign($badClaims)))===null,'unlinked identity rejected even when JWT signature is valid');
$check($auth->authenticateRequest($request('/auth/oidc/callback',$jwt))===null,'external bearer cannot authenticate non-API route');
$revoked=true;
$check($auth->authenticateRequest($request('/api/v1/auth/me',$jwt))===null,'IdP revocation enforced immediately');
$revoked=false;
$users->update($uid,['status'=>'disabled']);
$check($auth->authenticateRequest($request('/api/v1/auth/me',$jwt))===null,'locally disabled user loses external access');
$users->update($uid,['status'=>'active']);
$personal=$tokens->create($uid,'test-integration',['content:read']);
$check(($auth->authenticateRequest($request('/api/v1/auth/me',$personal['plain_token']))['user_id']??null)===$uid,'legacy local API token remains functional');
$router=new Router();
$router->add(['GET'],'/api/v1/auth/me','test.me',[new \CajeerEngine\Api\Controller\AuthController($auth),'me'],[]);
$router->add(['POST'],'/api/v1/secured-write','test.write',static fn()=>new \Symfony\Component\HttpFoundation\JsonResponse(['ok'=>true]),['content:write']);
$pipeline=new MiddlewarePipeline([new AuthenticationMiddleware($cfg,$auth,$router)]);
$read=$pipeline->handle($request('/api/v1/auth/me',$jwt),$router->dispatch(...));
$check($read->getStatusCode()===200 && str_contains($read->getContent(),'oidc_access'),'external JWT accepted end-to-end by HTTP authentication middleware');
$write=$pipeline->handle($request('/api/v1/secured-write',$jwt,'POST'),$router->dispatch(...));
$check($write->getStatusCode()===403,'external JWT cannot exceed configured permission ceiling');
$check($tokens->findValidPlainToken($personal['plain_token'])!==null,'personal API credential never revoked by external bearer authentication');
echo "OIDC API real PDO HTTP integration: $checks PASS\n";
