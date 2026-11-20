<?php

declare(strict_types=1);

/** Real PDO, session auth, RBAC, CSRF, step-up and transactional Identity Center tests. */
$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "Missing Composer autoload or PDO SQLite\n"); exit(1); }
    echo "SKIP: real PHP Identity Center integration requires Composer and PDO SQLite\n";
    exit(0);
}
require $root . '/vendor/autoload.php';
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,RoleRepository,UserRepository,UserSessionRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\{AuthService,AuthenticationAssurance,PasswordHasher,TwoFactorTotp};
use CajeerEngine\Identity\IdentityCenter;
use CajeerEngine\Api\Controller\{IdentityAdminController,AuthController};
use CajeerEngine\Http\{Router,RouteRegistry};
use CajeerEngine\Http\Middleware\{AuthenticationMiddleware,JsonBodyMiddleware,MiddlewarePipeline};
use Symfony\Component\HttpFoundation\Request;

$assert = static function (bool $truth, string $message): void {
    if (!$truth) throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
};
$cfg = new ConfigRepository($root);
$cfg->set('database.default', 'sqlite');
$cfg->set('database.connections.sqlite.database', ':memory:');
$db = new DatabaseManager($cfg);
$pdo = $db->connection();
$pdo->exec("CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT UNIQUE NOT NULL,name TEXT,status TEXT NOT NULL,password_hash TEXT,two_factor_enabled INTEGER NOT NULL DEFAULT 0,two_factor_secret TEXT,two_factor_recovery_codes TEXT DEFAULT '[]',last_login_at TEXT,disabled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT UNIQUE,name TEXT,permissions TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE ce_user_roles(user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id))');
$pdo->exec('CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,token_hash TEXT UNIQUE,token_prefix TEXT,scopes TEXT,last_used_at TEXT,expires_at TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
foreach (['0026_skif4er_oidc.sql','0027_oidc_account_linking.sql','0028_unified_sessions.sql','0029_oidc_global_logout.sql','0030_authentication_assurance.sql'] as $name) {
    $pdo->exec((string) file_get_contents($root.'/migrations/sqlite/'.$name));
}
$roles = new RoleRepository($db);
$roles->upsert('admin', 'Admin', ['*']);
$roles->upsert('viewer', 'Viewer', ['users:read']);
$passwords = new PasswordHasher($cfg);
$users = new UserRepository($db, $roles, $passwords);
$admin = $users->create('admin@identity.test', 'Admin', $passwords->hash('CorrectPassword123!'), ['admin']);
$target = $users->create('target@identity.test', 'Target', $passwords->hash('CorrectPassword123!'), ['viewer']);
$other = $users->create('other@identity.test', 'Other', $passwords->hash('CorrectPassword123!'), ['viewer']);
$sessions = new UserSessionRepository($db);
$tokens = new ApiTokenRepository($db);
$audit = new AuditLogRepository($db);
$auth = new AuthService($cfg, $users, $tokens, $audit, $passwords, new TwoFactorTotp(), $sessions);
$assurance = new AuthenticationAssurance($db, $users, $passwords, new TwoFactorTotp(), $audit);
$center = new IdentityCenter($db, $sessions, $audit);
$controller = new IdentityAdminController($center);
$authController = new AuthController($auth, $assurance);
$router = new Router();
foreach (RouteRegistry::api() as [$method,$path,$name,$group,$action,$scopes]) {
    if ($group === 'identityAdmin' || ($group === 'auth' && $name === 'api.v1.auth.assurance.confirm')) {
        $router->add([$method], $path, $name, [$group === 'identityAdmin' ? $controller : $authController, $action], $scopes);
    }
}
$pipeline = new MiddlewarePipeline([new JsonBodyMiddleware(), new AuthenticationMiddleware($auth, $router, $assurance)]);
$browser = Request::create('https://cms.example.test/api/v1/auth/login','POST');
$adminCookie = $auth->login('admin@identity.test', 'CorrectPassword123!', null, $browser)['access_token'];
$viewerCookie = $auth->login('target@identity.test', 'CorrectPassword123!', null, $browser)['access_token'];
$adminCsrf = AuthService::csrfToken($adminCookie);
$machine = $tokens->create($admin, 'service', ['*']);
$call = static function (string $method, string $path, array $payload = [], string $cookie = '', ?string $csrf = null, ?string $bearer = null) use ($pipeline, $router): \Symfony\Component\HttpFoundation\Response {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($csrf !== null) $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
    if ($bearer !== null) $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    $r = Request::create('https://cms.example.test/api/v1'.$path, $method, [], $cookie === '' ? [] : ['ce_session' => $cookie], [], $headers,
        in_array($method, ['POST','PUT','PATCH','DELETE'], true) ? json_encode((object) $payload, JSON_THROW_ON_ERROR) : null);
    return $pipeline->handle($r, $router->dispatch(...));
};
$path = '/identity/admin/users';
$assert($call('GET',$path)->getStatusCode() === 401, 'anonymous user denied');
$assert($call('GET',$path,cookie:$viewerCookie)->getStatusCode() === 200, 'users:read can list local mappings');
$assert($call('GET',$path,bearer:$machine['plain_token'])->getStatusCode() === 403, 'machine bearer cannot browse Identity Center');
$assert($call('POST',$path.'/'.$target.'/link',['subject'=>'verified-sub','confirm_subject'=>'verified-sub'],$adminCookie)->getStatusCode() === 403, 'missing CSRF blocked');
$assert($call('POST',$path.'/'.$target.'/link',['subject'=>'verified-sub','confirm_subject'=>'verified-sub'],$adminCookie,$adminCsrf)->getStatusCode() === 403, 'recent assurance required');
$assert($call('POST','/auth/assurance/confirm',['password'=>'CorrectPassword123!'],$adminCookie,$adminCsrf)->getStatusCode() === 200, 'local password step-up works');
$assert($call('POST',$path.'/'.$target.'/link',['subject'=>'verified-sub','confirm_subject'=>'different'],$adminCookie,$adminCsrf)->getStatusCode() === 422, 'wrong confirmation rejected');
$assert($call('POST',$path.'/'.$target.'/link',['subject'=>'verified-sub','confirm_subject'=>'verified-sub'],$viewerCookie,AuthService::csrfToken($viewerCookie))->getStatusCode() === 403, 'read-only user cannot mutate');
$assert($call('POST',$path.'/'.$target.'/link',['subject'=>'verified-sub','confirm_subject'=>'verified-sub'],$adminCookie,$adminCsrf)->getStatusCode() === 200, 'verified-sub prelink persisted');
$assert($center->users('target@identity.test','yes')['total'] === 1, 'search and linked filter return correct count');
$assert($center->users('%','all')['total'] === 0, 'LIKE metacharacters escaped (no wildcard data leakage)');
$assert($call('POST',$path.'/'.$other.'/link',['subject'=>'verified-sub','confirm_subject'=>'verified-sub'],$adminCookie,$adminCsrf)->getStatusCode() === 409, 'duplicate external sub blocked');
$assert($call('POST',$path.'/'.$admin.'/link',['subject'=>'admin-sub','confirm_subject'=>'admin-sub'],$adminCookie,$adminCsrf)->getStatusCode() === 409, 'operator cannot prelink itself');
$oidc = $auth->loginExternal($target, ['sub'=>'verified-sub','sid'=>'external-sid'], $browser);
$local = $auth->login('target@identity.test', 'CorrectPassword123!', null, $browser);
$service = $tokens->create($target, 'unrelated-machine', ['users:read']);
$list = json_decode($call('GET',$path.'/'.$target.'/sessions',cookie:$adminCookie)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
$assert(count($list) === 1 && $list[0]['provider'] === 'skif4er', 'only SSO sessions shown, no local sessions');
$pdo->prepare('UPDATE ce_oidc_identities SET provisioned = 1 WHERE user_id = :uid')->execute(['uid'=>$target]);
$assert($call('DELETE',$path.'/'.$target.'/link',['confirm_user_id'=>$target],$adminCookie,$adminCsrf)->getStatusCode() === 409, 'OIDC-only account cannot be unlinked');
$pdo->prepare('UPDATE ce_oidc_identities SET provisioned = 0 WHERE user_id = :uid')->execute(['uid'=>$target]);
$assert($call('DELETE',$path.'/'.$target.'/link',['confirm_user_id'=>'wrong'],$adminCookie,$adminCsrf)->getStatusCode() === 422, 'unlink confirmation required');
$assert($call('DELETE',$path.'/'.$target.'/link',['confirm_user_id'=>$target],$adminCookie,$adminCsrf)->getStatusCode() === 200, 'unlink commits atomically with SSO revocation');
$assert($auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$oidc['access_token']])) === null, 'revoked OIDC session cannot authenticate');
$assert($auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$local['access_token']])) !== null, 'local login survives unlink');
$assert($tokens->findValidPlainToken($service['plain_token']) !== null, 'service token survives unlink');
$assert($center->users('','no')['total'] === 3, 'unlink updates local Identity directory');
$assert($call('POST',$path.'/'.$target.'/link',['subject'=>'verified-sub','confirm_subject'=>'verified-sub'],$adminCookie,$adminCsrf)->getStatusCode() === 200, 'relink successful');
$oidc2 = $auth->loginExternal($target, ['sub'=>'verified-sub','sid'=>'session-two'], $browser);
$active = $center->sessions($target);
$assert(count($active) === 1, 'relinked user has new SSO session');
$assert($call('DELETE',$path.'/'.$target.'/sessions/'.$active[0]['id'],cookie:$adminCookie,csrf:$adminCsrf)->getStatusCode() === 200, 'selected session revoked');
$oidc3 = $auth->loginExternal($target, ['sub'=>'verified-sub','sid'=>'session-three'], $browser);
$oidc4 = $auth->loginExternal($target, ['sub'=>'verified-sub','sid'=>'session-four'], $browser);
$all = $call('POST',$path.'/'.$target.'/sessions/revoke-all',['confirm_user_id'=>$target],$adminCookie,$adminCsrf);
$assert($all->getStatusCode() === 200 && json_decode($all->getContent(),true)['data']['revoked_sessions'] === 2, 'all active target OIDC sessions revoked');
$assert($center->users('target@identity.test','yes')['total'] === 1, 'session revocation does not delete mapping');
$assert((int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event LIKE 'identity.admin_%'")->fetchColumn() === 5, 'every successful identity administrative action audited once');
$pdo->prepare('UPDATE ce_user_sessions SET assured_at = :time WHERE token_id = :id')->execute(['time'=>time()-9999,'id'=>$auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$adminCookie]))['token_id']]);
$assert($call('POST',$path.'/'.$other.'/link',['subject'=>'other-sub','confirm_subject'=>'other-sub'],$adminCookie,$adminCsrf)->getStatusCode() === 403, 'expired step-up blocks next mutation');
echo "Identity Center real PDO and HTTP acceptance: PASS\n";
