<?php

declare(strict_types=1);

/** End-to-end PHP/PDO/browser middleware acceptance for privileged mutations. */
$root = dirname(__DIR__);
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !is_file($root.'/vendor/autoload.php')) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL: PDO SQLite and Composer required\n"); exit(1); }
    echo "SKIP assurance PHP middleware acceptance (PDO SQLite / Composer unavailable)\n";
    exit(0);
}
require $root.'/vendor/autoload.php';
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,RoleRepository,UserRepository,UserSessionRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\{AuthService,AuthenticationAssurance,PasswordHasher,TwoFactorTotp};
use CajeerEngine\Http\{Router,RouteRegistry};
use CajeerEngine\Http\Middleware\{AuthenticationMiddleware,JsonBodyMiddleware,MiddlewarePipeline};
use Symfony\Component\HttpFoundation\Request;
$cfg=new ConfigRepository($root);$cfg->set('database.default','sqlite');$cfg->set('database.connections.sqlite.database',':memory:');
$db=new DatabaseManager($cfg);$pdo=$db->connection();
$pdo->exec("CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT UNIQUE NOT NULL,name TEXT,status TEXT NOT NULL,password_hash TEXT,two_factor_enabled INTEGER NOT NULL DEFAULT 0,two_factor_secret TEXT,two_factor_recovery_codes TEXT DEFAULT '[]',last_login_at TEXT,disabled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT UNIQUE,name TEXT,permissions TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE ce_user_roles(user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id))');
$pdo->exec('CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,token_hash TEXT UNIQUE,token_prefix TEXT,scopes TEXT,last_used_at TEXT,expires_at TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
foreach (['0028_unified_sessions.sql','0029_oidc_global_logout.sql','0030_authentication_assurance.sql'] as $name) $pdo->exec(file_get_contents($root.'/migrations/sqlite/'.$name));
$roles=new RoleRepository($db);$roles->upsert('admin','Admin',['*']);
$pw=new PasswordHasher($cfg);$users=new UserRepository($db,$roles,$pw);
$uid=$users->create('assurance@example.test','Assurance',$pw->hash('SecretStrong123!'),['admin']);
$tokens=new ApiTokenRepository($db);$sessions=new UserSessionRepository($db);$audit=new AuditLogRepository($db);
$totp=new TwoFactorTotp();$auth=new AuthService($cfg,$users,$tokens,$audit,$pw,$totp,$sessions);
$assurance=new AuthenticationAssurance($db,$users,$pw,$totp,$audit);
$check=static function (bool $ok,string $message):void { if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n"; };
$loginReq=Request::create('https://cms.example.test/api/v1/auth/login','POST');
$login=$auth->login('assurance@example.test','SecretStrong123!',null,$loginReq);
$cookie=$login['access_token'];$csrf=AuthService::csrfToken($cookie);
$identity=$auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$cookie]));
$check($identity !== null && $assurance->status($identity)['fresh']===false,'new session has no implicit step-up');
$router=new Router();$controller=new CajeerEngine\Api\Controller\AuthController($auth,$assurance);
$usersController=new CajeerEngine\Api\Controller\UserController($users,$pw);
foreach(RouteRegistry::api() as [$method,$path,$name,$group,$action,$scopes]) {
    if(($group==='auth' && str_contains($path,'assurance')) || ($group==='users' && $path==='/api/v1/users')) {
        $router->add([$method],$path,$name,[$group==='auth'?$controller:$usersController,$action],$scopes);
    }
}
$pipeline=new MiddlewarePipeline([new JsonBodyMiddleware(),new AuthenticationMiddleware($cfg,$auth,$router,$assurance)]);
$call=static function(string $method,string $path,array $body=[],?string $c=null,?string $csrfHeader=null,?string $bearer=null)use($pipeline,$router,$cookie,$csrf):\Symfony\Component\HttpFoundation\Response {
    $headers=['CONTENT_TYPE'=>'application/json'];
    if($csrfHeader !== null)$headers['HTTP_X_CSRF_TOKEN']=$csrfHeader;
    if($bearer !== null)$headers['HTTP_AUTHORIZATION']='Bearer '.$bearer;
    $r=Request::create('https://cms.example.test/api/v1'.$path,$method,[],($c??$cookie)===''?[]:['ce_session'=>$c??$cookie],[],$headers,json_encode($body));
    return $pipeline->handle($r,$router->dispatch(...));
};
$check($call('GET','/users')->getStatusCode()===200,'ordinary RBAC reads are unaffected');
$create=['email'=>'new@example.test','name'=>'New User','password'=>'AnotherStrong123!','roles'=>['admin']];
$check($call('POST','/users',$create,csrfHeader:$csrf)->getStatusCode()===403,'privileged user creation blocked without step-up');
$check($call('POST','/auth/assurance/confirm',['password'=>'wrong'],csrfHeader:$csrf)->getStatusCode()===403,'incorrect password cannot stamp assurance');
$check(!$assurance->status($identity)['fresh'],'failed reauth leaves session unassured');
$check($call('POST','/auth/assurance/confirm',['password'=>'SecretStrong123!'],csrfHeader:$csrf)->getStatusCode()===200,'local password reauth via protected HTTP controller');
$check($assurance->status($identity)['fresh'],'SQL evidence is bound to active session');
$check($call('POST','/users',$create,csrfHeader:$csrf)->getStatusCode()===201,'real privileged operation succeeds only after reauth');
$check($users->findByEmail('new@example.test')!==null,'controller writes actual SQL user');
$pdo->prepare('UPDATE ce_user_sessions SET assured_at = :t WHERE token_id = :id')->execute(['t'=>time()-700,'id'=>$identity['token_id']]);
$check($call('POST','/users',['email'=>'denied@example.test','name'=>'Denied','password'=>'AnotherStrong123!'],csrfHeader:$csrf)->getStatusCode()===403,'expired assurance denied');
$check(!$assurance->status($identity)['fresh'],'evidence expiry enforced');
$check($call('POST','/auth/assurance/confirm',['password'=>'SecretStrong123!'])->getStatusCode()===403,'reauth cannot bypass CSRF');
$apiToken=$tokens->create($uid,'machine',['*']);
$check($call('POST','/users',['email'=>'machine@example.test','name'=>'Machine','password'=>'AnotherStrong123!'],c:'',bearer:$apiToken['plain_token'])->getStatusCode()===403,'bearer API token cannot bypass interactive step-up');
$external=$auth->loginExternal($uid,['sub'=>'linked-sub','amr'=>['mfa'],'sid'=>'linked-sid'], $loginReq);
$externalId=$tokens->findValidPlainToken($external['access_token'])['id'];
foreach ([['auth_time'=>time()-500,'amr'=>['mfa']],['auth_time'=>time(),'amr'=>['pwd']]] as $claims) {
    try { $assurance->confirmOidc($externalId,$uid,$claims);throw new RuntimeException('Invalid OIDC assurance accepted'); }
    catch (RuntimeException $e) { if($e->getMessage()==='Invalid OIDC assurance accepted')throw $e; }
}
$check($pdo->query("SELECT assured_at FROM ce_user_sessions WHERE token_id = '".$externalId."'")->fetchColumn()===false || $assurance->status($auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$external['access_token']])))['fresh']===false,'stale/non-MFA OIDC claims cannot mark session');
$assurance->confirmOidc($externalId,$uid,['auth_time'=>time(),'amr'=>['pwd','otp']]);
$check($assurance->status($auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$external['access_token']])))['fresh'],'fresh MFA OIDC assurance accepted');
$sessions->revoke($externalId,$uid);
try{$assurance->confirmOidc($externalId,$uid,['auth_time'=>time(),'amr'=>['mfa']]);throw new RuntimeException('Revoked session accepted');}
catch(RuntimeException $e){if($e->getMessage()==='Revoked session accepted')throw $e;}
$check(true,'revoked OIDC session cannot receive assurance');
echo "Authentication assurance PHP integration PASS\n";
