<?php

declare(strict_types=1);

/** Real PDO SQLite / sodium lifecycle test; CI requires the database extension. */
require_once __DIR__ . '/../core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Identity\OidcStore;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL: pdo_sqlite required\n"); exit(1); }
    echo "SKIP OIDC PDO SQLite: driver missing\n"; exit(0);
}
$cfg = new ConfigRepository(dirname(__DIR__));
$cfg->set('database.default','sqlite');
$cfg->set('database.connections.sqlite.database', ':memory:');
$db=new DatabaseManager($cfg);
$pdo=$db->connection();
$pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/sqlite/0026_skif4er_oidc.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/sqlite/0027_oidc_account_linking.sql'));
$pdo->exec('CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT,name TEXT,status TEXT,two_factor_enabled INTEGER,last_login_at TEXT, disabled_at TEXT,created_at TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE ce_roles (id TEXT PRIMARY KEY,handle TEXT)');
$pdo->exec('CREATE TABLE ce_user_roles (user_id TEXT,role_id TEXT)');
$uuid='12345678-1234-1234-1234-123456789abc';
$pdo->prepare('INSERT INTO ce_users VALUES (:id,:email,:name,:status,0,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')
    ->execute(['id'=>$uuid,'email'=>'person@example.test','name'=>'Person','status'=>'active']);
$store=new OidcStore($db,new SecretVault('base64:'.base64_encode(random_bytes(32))));
$issuer='https://id.skif4er.ru/realms/skif4er';
$users=new UserRepository($db,new RoleRepository($db),new PasswordHasher($cfg));
$store->link($issuer,'trusted-sub',$uuid,$users);
$store->link($issuer,'trusted-sub',$uuid,$users);
if($store->linkedUser($issuer,'trusted-sub')!==$uuid)throw new RuntimeException('OIDC link not persisted');
if($store->linkedUser($issuer,'unknown')!==null)throw new RuntimeException('Implicit identity match');
echo "PASS explicit subject link and idempotency\n";
$tx=$store->save('state-one','nonce-one','verifier-one');
try{$store->consume('state-one','wrong-browser');throw new RuntimeException('wrong browser accepted');}
catch(RuntimeException $e){if($e->getMessage()==='wrong browser accepted')throw $e;}
$valid=$store->consume('state-one',$tx['browser']);
if($valid!==['nonce'=>'nonce-one','verifier'=>'verifier-one','purpose'=>'login','user_id'=>null,'session_id'=>null])throw new RuntimeException('PKCE verifier not recovered');
try{$store->consume('state-one',$tx['browser']);throw new RuntimeException('replayed state accepted');}
catch(RuntimeException $e){if($e->getMessage()==='replayed state accepted')throw $e;}
echo "PASS encrypted PKCE transaction, browser binding and replay rejection\n";
$tx=$store->save('state-expired','nonce-expired','verifier-expired');
$pdo->prepare('UPDATE ce_oidc_transactions SET expires_at = :expired WHERE state_hash = :hash')->execute(['expired'=>time()-1,'hash'=>hash('sha256','state-expired')]);
try{$store->consume('state-expired',$tx['browser']);throw new RuntimeException('expired state accepted');}
catch(RuntimeException $e){if($e->getMessage()==='expired state accepted')throw $e;}
echo "PASS expiration rejection\n";
echo "OIDC DB regression: PASS\n";
