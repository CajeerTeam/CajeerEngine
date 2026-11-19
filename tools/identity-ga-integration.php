<?php

declare(strict_types=1);

/**
 * Production SSO acceptance over real PDO SQLite + application services.
 * This test never calls a real Keycloak server: its IdP transport signs
 * RS256 tokens with a generated ephemeral key (no static credentials).
 */
$root = dirname(__DIR__);
if (!is_file($root.'/vendor/autoload.php') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL: Composer and PDO SQLite are required\n"); exit(1); }
    echo "SKIP Identity GA application integration: Composer / PDO SQLite unavailable\n";
    exit(0);
}
require $root.'/vendor/autoload.php';

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{ApiTokenRepository,AuditLogRepository,RoleRepository,UserRepository,UserSessionRepository};
use CajeerEngine\Security\{AuthService,PasswordHasher,TwoFactorTotp};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Identity\{OidcClient,OidcStore,OidcLogin};
use Symfony\Component\HttpFoundation\Request;

$checks = 0;
$ok = static function (bool $pass, string $message) use (&$checks): void {
    if (!$pass) throw new RuntimeException('FAIL: '.$message);
    $checks++; echo "PASS: $message\n";
};
$reject = static function (callable $operation, string $message) use ($ok): void {
    try { $operation(); } catch (RuntimeException|DomainException $e) { $ok(true, $message); return; }
    throw new RuntimeException('FAIL: '.$message);
};
$cfg = new ConfigRepository($root);
$cfg->set('database.default', 'sqlite');
$cfg->set('database.connections.sqlite.database', ':memory:');
$db = new DatabaseManager($cfg);
$pdo = $db->connection();
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT UNIQUE NOT NULL,name TEXT,status TEXT NOT NULL,password_hash TEXT,two_factor_enabled INTEGER NOT NULL DEFAULT 0,two_factor_secret TEXT,two_factor_recovery_codes TEXT DEFAULT '[]',last_login_at TEXT,disabled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT UNIQUE,name TEXT,permissions TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE ce_user_roles(user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id))');
$pdo->exec('CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,token_hash TEXT UNIQUE,token_prefix TEXT,scopes TEXT,last_used_at TEXT,expires_at TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
foreach (['0026_skif4er_oidc.sql','0027_oidc_account_linking.sql','0028_unified_sessions.sql','0029_oidc_global_logout.sql','0030_authentication_assurance.sql'] as $filename) {
    $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$filename));
}
$roles = new RoleRepository($db); $roles->upsert('viewer', 'Viewer', ['content:read','media:read','system:read']);
$passwords = new PasswordHasher($cfg); $users = new UserRepository($db, $roles, $passwords);
$uid = $users->create('ga@example.test', 'GA Test', $passwords->hash('VerifiedPassword123!'), ['viewer']);
$tokens = new ApiTokenRepository($db); $sessions = new UserSessionRepository($db);
$audit = new AuditLogRepository($db); $auth = new AuthService($cfg, $users, $tokens, $audit, $passwords, new TwoFactorTotp(), $sessions);
$store = new OidcStore($db, new SecretVault('base64:'.base64_encode(random_bytes(32))));
$req = Request::create('https://cms.example.test/api/v1/auth/me', 'GET', [], [], [], ['HTTP_USER_AGENT'=>'GA Test Browser']);
$local = $auth->login('ga@example.test','VerifiedPassword123!',null,$req);
$localCookie = $local['access_token'];
$localId = $tokens->findValidPlainToken($localCookie)['id'];
$machine = $tokens->create($uid, 'integration', ['content:read']);
$issuer = 'https://id.skif4er.ru/realms/skif4er';
$b64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$private = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>2048]);
if ($private === false) throw new RuntimeException('RSA unavailable');
$rsa = openssl_pkey_get_details($private)['rsa'];
$discovery = json_encode(['issuer'=>$issuer,
    'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
    'token_endpoint'=>$issuer.'/protocol/openid-connect/token',
    'jwks_uri'=>$issuer.'/protocol/openid-connect/certs'], JSON_THROW_ON_ERROR);
$jwks = json_encode(['keys'=>[['kid'=>'ga-key', 'alg'=>'RS256','kty'=>'RSA','use'=>'sig','n'=>$b64($rsa['n']), 'e'=>$b64($rsa['e'])]]], JSON_THROW_ON_ERROR);
$nonce = '';
$subject = 'verified-sso-user';
$transport = static function (string $method, string $url, ?string $body, array $headers) use ($issuer, $discovery, $jwks, &$nonce, $private, $b64, $subject): array {
    if (str_ends_with($url, '/.well-known/openid-configuration')) return ['status'=>200,'body'=>$discovery];
    if (str_ends_with($url, '/certs')) return ['status'=>200,'body'=>$jwks];
    if (str_ends_with($url, '/token')) {
        $payload = ['iss'=>$issuer, 'aud'=>'cajeerengine-web','sub'=>$subject,'nonce'=>$nonce,
            'iat'=>time()-5,'exp'=>time()+300,'amr'=>['pwd','otp'], 'sid'=>'ga-idp-session'];
        $data=$b64(json_encode(['alg'=>'RS256','kid'=>'ga-key'],JSON_THROW_ON_ERROR)).'.'.$b64(json_encode($payload,JSON_THROW_ON_ERROR));
        openssl_sign($data,$signature,$private,OPENSSL_ALGO_SHA256);
        return ['status'=>200, 'body'=>json_encode(['id_token'=>$data.'.'.$b64($signature)],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected network endpoint '.$url);
};
$client = new OidcClient('cajeerengine-web', 'test-confidential-secret', 'https://cms.example.test/auth/oidc/callback', $transport);
$login = new OidcLogin($client,$store,$auth,$users,$roles,$passwords,$audit,false,$sessions);
$callback = static function (?string $forUser = null, ?string $sessionId = null) use ($login,&$nonce,$req): array {
    $tx = $login->begin($forUser,$sessionId);
    $query = (string)parse_url($tx['url'], PHP_URL_QUERY);
    parse_str($query,$params);
    $nonce = $params['nonce'];
    return $login->finish('ga-code',$params['state'],$tx['browser'],$req);
};
$linked = $callback($uid,$localId);
$ok(($linked['linked'] ?? false) === true && $store->linkedUser($issuer,$subject)===$uid, 'verified OIDC callback links the existing local account');
$ok($auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$localCookie])) !== null, 'account linking does not discard the local session');
$sso = $callback();
$cookie = $sso['access_token']; $ssoId = $tokens->findValidPlainToken($cookie)['id'];
$ok($sessions->find($ssoId)['provider']==='skif4er' && $sessions->find($ssoId)['oidc_sid_hash']===hash('sha256','ga-idp-session'), 'production SSO issues tracked opaque device session with hashed IdP SID');
$ok($login->isSsoSession($uid,$ssoId), 'current SSO session is identified before unlink');
$authUser = $auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$cookie]));
$ok($authUser !== null && $authUser['cookie'] === true, 'SSO cookie authenticates via normal permission middleware');
$pdo->exec("CREATE TRIGGER reject_sso_revocation BEFORE UPDATE OF revoked_at ON ce_user_sessions WHEN OLD.provider='skif4er' BEGIN SELECT RAISE(ABORT,'simulated storage failure'); END");
try { $store->unlink($issuer,$uid); throw new RuntimeException('FAIL: self-unlink ignored storage error'); }
catch (PDOException $e) { $ok($store->linkedUser($issuer,$subject)===$uid, 'revocation failure rolls back unlink (no orphaned SSO)'); }
$pdo->exec('DROP TRIGGER reject_sso_revocation');
$ok($login->unlink($uid), 'self-unlink removes verified mapping and revokes SSO sessions');
$ok($tokens->findValidPlainToken($cookie)===null && $sessions->find($ssoId)['revoked_at']!==null, 'old SSO cookie immediately rejected');
$ok($auth->authenticateRequest(Request::create('https://cms.example.test/api/v1/auth/me','GET',[],['ce_session'=>$localCookie]))!==null, 'unrelated password session survives unlink');
$ok($tokens->findValidPlainToken($machine['plain_token'])!==null, 'personal and integration API tokens unaffected by unlink');
$reject($callback, 'unlinked SkiF4er identity cannot create a fresh SSO session');
$reject(static fn()=>$store->withLinkedIdentity($issuer,$subject,static fn()=>null), 'link row lock refuses absent mapping before issuing a token');
$store->completeLink($issuer,$subject,$uid,$localId,$users);
$ok($store->linkedUser($issuer,$subject)===$uid, 'self-link after reauthentication remains available');
$pdo->prepare('UPDATE ce_user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_id=?')->execute([$localId]);
$reject(static fn()=>$store->completeLink($issuer,'other-subject',$uid,$localId,$users), 'revoked registry row blocks stale callback with still-live API token');
$pdo->prepare('UPDATE ce_users SET two_factor_enabled=1 WHERE id=?')->execute([$uid]);
$reject(static fn()=>$auth->loginExternal($uid,['sub'=>$subject,'amr'=>[['nested'=>'bad']]],$req), 'malformed IdP AMR cannot bypass MFA');
$ok($pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok', 'database integrity after unlink, rollback and re-link');
// Identity acceptance must follow the installed release, not reject every
// post-1.6.0 deployment before checking the actual IdP or browser session.
$identityAcceptance = new \CajeerEngine\Identity\IdentityAcceptance($cfg, $db);
$identityReport = $identityAcceptance->run();
$ok(($identityReport['checks']['version']['ok'] ?? false) === true
    && ($identityReport['release'] ?? '') === trim(file_get_contents($root.'/VERSION')),
    'production acceptance uses installed release rather than obsolete fixed 1.6.0');
echo "Identity GA PDO/SSO acceptance: $checks PASS\n";
