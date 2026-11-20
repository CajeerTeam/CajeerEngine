<?php

declare(strict_types=1);
/** Real PHP PDO + Symfony HTTP session lifecycle; release CI requires extensions and Composer. */
$root = dirname(__DIR__);
if (!in_array('sqlite', \PDO::getAvailableDrivers(), true) || !is_file($root.'/vendor/autoload.php')) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL: Composer vendor and pdo_sqlite required\n"); exit(1); }
    echo "SKIP: session HTTP integration requires Composer and pdo_sqlite\n"; exit(0);
}
require $root.'/vendor/autoload.php';
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{ApiTokenRepository,UserSessionRepository,AuditLogRepository,UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\{AuthService,PasswordHasher,TwoFactorTotp};
use Symfony\Component\HttpFoundation\Request;

$cfg=new ConfigRepository($root);$cfg->set('database.default','sqlite');$cfg->set('database.connections.sqlite.database',':memory:');
$cfg->set('security.sessions.idle_minutes',720);
$db=new DatabaseManager($cfg);$pdo=$db->connection();
$pdo->exec("CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT UNIQUE NOT NULL,name TEXT,status TEXT NOT NULL,password_hash TEXT,two_factor_enabled INTEGER NOT NULL DEFAULT 0,two_factor_secret TEXT,two_factor_recovery_codes TEXT DEFAULT '[]',last_login_at TEXT,disabled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT UNIQUE,name TEXT,permissions TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_user_roles(user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id))");
$pdo->exec("CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT REFERENCES ce_users(id),name TEXT,token_hash TEXT UNIQUE,token_prefix TEXT,scopes TEXT,last_used_at TEXT,expires_at TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0028_unified_sessions.sql'));
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0029_oidc_global_logout.sql'));
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0030_authentication_assurance.sql'));
$roles=new RoleRepository($db);$roles->upsert('viewer','Viewer',['content:read']);
$pw=new PasswordHasher($cfg);$users=new UserRepository($db,$roles,$pw);
$userId=$users->create('session@example.test','Session Tester',$pw->hash('SecurePassword123!'),['viewer']);
$other=$users->create('another@example.test','Other Tester',$pw->hash('SecurePassword123!'),['viewer']);
$tokens=new ApiTokenRepository($db);$sessions=new UserSessionRepository($db);
$auth=new AuthService($cfg,$users,$tokens,new AuditLogRepository($db),$pw,new TwoFactorTotp(),$sessions);
$check=static function(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException('FAIL '.$message); echo "PASS $message\n";};
$request=static fn(?string $cookie=null):Request => Request::create('https://cms.example.test/api/v1/auth/me','GET',[], $cookie===null?[]:['ce_session'=>$cookie],[],['HTTP_USER_AGENT'=>'Firefox Test','REMOTE_ADDR'=>'192.168.42.50']);
$a=$auth->login('session@example.test','SecurePassword123!',null,$request());
$b=$auth->loginExternal($userId,['sub'=>'external-sub','amr'=>['pwd','otp'],'sid'=>'opaque-idp-session-id'],$request());
$c=$auth->login('another@example.test','SecurePassword123!',null,$request());
$ia=$auth->authenticateRequest($request($a['access_token']));
$check(($ia['session']??null)===true && ($ia['cookie']??null)===true, 'local session is accepted');
$controller=new \CajeerEngine\Api\Controller\AuthController($auth);
$router=new \CajeerEngine\Http\Router();
foreach (\CajeerEngine\Http\RouteRegistry::api() as [$method,$path,$name,$controllerName,$action,$scopes]) {
    if ($controllerName==='auth' && str_contains($path,'/auth/sessions')) $router->add([$method],$path,$name,[$controller,$action],$scopes);
}
$pipeline=new \CajeerEngine\Http\Middleware\MiddlewarePipeline([new \CajeerEngine\Http\Middleware\AuthenticationMiddleware($auth,$router)]);
$call=static function(string $method,string $path,?string $cookie=null,?string $bearer=null,?string $csrf=null)use($router,$pipeline):\Symfony\Component\HttpFoundation\Response {
    $headers=['CONTENT_TYPE'=>'application/json'];
    if($bearer!==null)$headers['HTTP_AUTHORIZATION']='Bearer '.$bearer;
    if($csrf!==null)$headers['HTTP_X_CSRF_TOKEN']=$csrf;
    return $pipeline->handle(Request::create('https://cms.example.test/api/v1'.$path,$method,[],$cookie===null?[]:['ce_session'=>$cookie],[],$headers,'{}'),$router->dispatch(...));
};
$check($call('GET','/auth/sessions',bearer:$a['access_token'])->getStatusCode()===403,'Bearer-only login cannot list browser sessions');
$check($call('POST','/auth/sessions/revoke-others',cookie:$a['access_token'])->getStatusCode()===403,'mutation without CSRF denied by real middleware');
$check($call('GET','/auth/sessions',cookie:$a['access_token'])->getStatusCode()===200,'browser cookie can list sessions through router');

$items=$auth->sessionList($ia);
$check(count($items)===2 && count(array_filter($items,fn($s)=>$s['provider']==='skif4er'))===1, 'local and external sessions visible together');
$check(!str_contains(json_encode($items),'opaque-idp-session-id') && !str_contains(json_encode($items),$a['access_token']), 'no credential or raw OIDC sid in listing');
$check($auth->revokeSession($ia,$tokens->findValidPlainToken($c['access_token'])['id'])===false, 'cross-user credential cannot be revoked');
$check($auth->revokeOtherSessions($ia)===1, 'revoke all other owned sessions');
$check($auth->authenticateRequest($request($b['access_token']))===null, 'revoked OIDC session denied on next request');
$check($auth->authenticateRequest($request($c['access_token']))!==null, 'another user remains logged in');
$integration=$tokens->create($userId,'integration',['content:read']);
$rotated=$auth->rotateSession($ia,$request($a['access_token']));
$check($auth->authenticateRequest($request($a['access_token']))===null, 'old cookie invalid after rotation');
$check($auth->authenticateRequest($request($rotated['access_token']))!==null, 'new cookie works after rotation');
$check($tokens->findValidPlainToken($integration['plain_token'])!==null,'personal API token unaffected');
$idle=$auth->login('session@example.test','SecurePassword123!',null,$request());
$pdo->prepare("UPDATE ce_user_sessions SET last_seen_at = '2020-01-01 00:00:00' WHERE token_id = :id")
    ->execute(['id'=>$tokens->findValidPlainToken($idle['access_token'])['id']]);
$check($auth->authenticateRequest($request($idle['access_token']))===null, 'idle timeout revokes a stale session');
$check($auth->authenticateRequest($request($rotated['access_token']))!==null, 'other active session unaffected by idle timeout');
$legacy=$tokens->create($userId,'admin-session',['content:read']);
$check($auth->authenticateRequest($request($legacy['plain_token']))!==null && ($sessions->find($legacy['id'])['provider']??null)==='legacy', 'pre-1.5.3 session backfills safely');
$check($auth->revokeAllSessions($auth->authenticateRequest($request($rotated['access_token'])))>=1, 'global local session revocation');
$check($auth->authenticateRequest($request($rotated['access_token']))===null, 'current session is revoked');
$check($tokens->findValidPlainToken($integration['plain_token'])!==null,'API tokens are independent after global logout');
echo "Unified sessions SQL / AuthService integration PASS\n";
