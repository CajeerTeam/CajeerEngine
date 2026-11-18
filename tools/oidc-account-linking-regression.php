<?php

declare(strict_types=1);

/** Real SQLite integration test: required in release CI, optional on lean developer hosts. */
require_once __DIR__.'/../core/Console/NativeCli.php';
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Identity\OidcStore;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "pdo_sqlite missing\n"); exit(1); }
    echo "SKIP OIDC account linking DB regression: pdo_sqlite missing\n"; exit(0);
}
$cfg=new ConfigRepository(dirname(__DIR__));
$cfg->set('database.default','sqlite');$cfg->set('database.connections.sqlite.database',':memory:');
$db=new DatabaseManager($cfg);$pdo=$db->connection();
$pdo->exec("CREATE TABLE ce_users (id TEXT PRIMARY KEY,email TEXT UNIQUE NOT NULL,name TEXT,status TEXT,password_hash TEXT,two_factor_enabled INTEGER,two_factor_recovery_codes TEXT,last_login_at TEXT,disabled_at TEXT,created_at TEXT,updated_at TEXT)");
$pdo->exec("CREATE TABLE ce_roles (id TEXT PRIMARY KEY,handle TEXT UNIQUE,name TEXT,permissions TEXT,created_at TEXT,updated_at TEXT)");
$pdo->exec("CREATE TABLE ce_user_roles (user_id TEXT NOT NULL,role_id TEXT NOT NULL)");
$pdo->exec("CREATE TABLE ce_api_tokens (id TEXT PRIMARY KEY,user_id TEXT,name TEXT,revoked_at TEXT,expires_at TEXT)");
$pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/sqlite/0026_skif4er_oidc.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/sqlite/0027_oidc_account_linking.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/sqlite/0028_unified_sessions.sql'));
$roles=new RoleRepository($db);$roles->upsert('viewer','Viewer',['content:read','media:read','system:read']);
$hasher=new PasswordHasher($cfg);$users=new UserRepository($db,$roles,$hasher);
$store=new OidcStore($db,new SecretVault('base64:'.base64_encode(random_bytes(32))));
$issuer='https://id.skif4er.ru/realms/skif4er';$count=0;
$pass=static function (bool $condition,string $message) use (&$count):void {
    if (!$condition) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message."\n"; $count++;
};
$reject=static function (callable $fn,string $message) use (&$pass):void {
    try { $fn(); } catch (Throwable) { $pass(true,$message); return; }
    throw new RuntimeException('FAIL '.$message);
};
$user=$users->create('existing@example.test','Existing',$hasher->hash('Password-Strong123'),['viewer']);
$session='12345678-1234-1234-1234-123456789abc';
$pdo->prepare('INSERT INTO ce_api_tokens (id,user_id,name) VALUES (?,?,?)')->execute([$session,$user,'admin-session']);
$pdo->prepare("INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES (?,?,?)")->execute([$session,$user,'local']);
$tx=$store->save('state-link','nonce-link','secret-verifier',$user,$session);
$parsed=$store->consume('state-link',$tx['browser']);
$pass($parsed['purpose']==='link' && $parsed['user_id']===$user && $parsed['session_id']===$session,'link transaction is browser and session bound');
$reject(fn()=>$store->consume('state-link',$tx['browser']),'link state one-time only');
$store->completeLink($issuer,'subject-one',$user,$session,$users);
$pass($store->statusForUser($issuer,$user)['linked']===true,'OIDC link stored for active local user');
$pdo->prepare('UPDATE ce_user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_id=?')->execute([$session]);
$reject(fn()=>$store->completeLink($issuer,'ignored-after-registry-revoke',$user,$session,$users),'session registry revocation prevents linking despite live API token');
$pdo->prepare('UPDATE ce_user_sessions SET revoked_at=NULL WHERE token_id=?')->execute([$session]);
$reject(fn()=>$store->completeLink($issuer,'different-subject',$user,$session,$users),'cannot bind multiple subjects to same local user');
$pass($store->unlink($issuer,$user),'existing local account can unlink');
$pass($store->linkedUser($issuer,'subject-one')===null,'unlink removes identity only');
$pdo->prepare('UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$session]);
$reject(fn()=>$store->completeLink($issuer,'subject-after-logout',$user,$session,$users),'revoked linking session cannot complete callback');
$pass(!$store->statusForUser($issuer,$user)['linked'],'rejected callback did not link identity');
$claims=['sub'=>'new-sub','email_verified'=>true,'email'=>'NEW@example.test','name'=>'New User'];
$new=$store->provision($issuer,$claims,$users,$roles,$hasher);
$pass($users->findById($new)['email']==='new@example.test','verified new account created via real repository');
$pass($store->statusForUser($issuer,$new)['provisioned']===true,'provisioning provenance persisted');
$pass($store->provision($issuer,$claims,$users,$roles,$hasher)===$new,'repeat callback does not create duplicate account');
$reject(fn()=>$store->unlink($issuer,$new),'provisioned OIDC-only account cannot unlink');
$reject(fn()=>$store->provision($issuer,['sub'=>'hijack-sub','email_verified'=>true,'email'=>'existing@example.test'],$users,$roles,$hasher),'matching email never auto-links existing account');
$reject(fn()=>$store->provision($issuer,['sub'=>'unverified','email_verified'=>false,'email'=>'other@example.test'],$users,$roles,$hasher),'unverified email cannot provision account');
$roles->upsert('viewer','Viewer',['*']);
$reject(fn()=>$store->provision($issuer,['sub'=>'danger','email_verified'=>true,'email'=>'another@example.test'],$users,$roles,$hasher),'modified privileged viewer role blocked');
$pass($users->count()===2,'rejected provisioning attempts created no users');
$pass($pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok','SQLite integrity preserved');
echo "OIDC account linking integration: $count PASS\n";
