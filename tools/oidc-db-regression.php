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
// Exercise OIDC against the same user/role schema used in production. A
// hand-written minimal ce_roles fixture can silently drift from RoleRepository.
$root = dirname(__DIR__);
foreach (['0001_initial_schema.sql', '0026_skif4er_oidc.sql', '0027_oidc_account_linking.sql'] as $migration) {
    $sql = file_get_contents($root.'/migrations/sqlite/'.$migration);
    if (!is_string($sql) || $sql === '') throw new RuntimeException('Missing SQLite migration: '.$migration);
    $pdo->exec($sql);
}
$uuid='12345678-1234-1234-1234-123456789abc';
$pdo->prepare('INSERT INTO ce_users (id,email,name,password_hash,status) VALUES (:id,:email,:name,:password_hash,:status)')
    ->execute(['id'=>$uuid,'email'=>'person@example.test','name'=>'Person',
        'password_hash'=>password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),'status'=>'active']);
$roleId='87654321-4321-4321-8321-cba987654321';
$pdo->prepare('INSERT INTO ce_roles (id,handle,name,permissions) VALUES (:id,:handle,:name,:permissions)')
    ->execute(['id'=>$roleId,'handle'=>'viewer','name'=>'Viewer','permissions'=>'["content:read"]']);
$pdo->prepare('INSERT INTO ce_user_roles (user_id,role_id) VALUES (:user_id,:role_id)')
    ->execute(['user_id'=>$uuid,'role_id'=>$roleId]);
$store=new OidcStore($db,new SecretVault('base64:'.base64_encode(random_bytes(32))));
$issuer='https://id.skif4er.ru/realms/skif4er';
$users=new UserRepository($db,new RoleRepository($db),new PasswordHasher($cfg));
$linkedUser = $users->findById($uuid);
if (!is_array($linkedUser) || $linkedUser['roles'] !== ['viewer'] || $linkedUser['permissions'] !== ['content:read']) {
    throw new RuntimeException('OIDC fixture does not expose real RBAC role permissions');
}
echo "PASS production SQLite roles and permissions loaded\n";
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
