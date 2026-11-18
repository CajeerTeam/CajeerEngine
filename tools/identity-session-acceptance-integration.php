<?php

declare(strict_types=1);

/** Real PDO integration: register/migrate/revoke, strict rollover and acceptance safety. */
$root=dirname(__DIR__);
if (!in_array('sqlite', PDO::getAvailableDrivers(),true) || !is_file($root.'/vendor/autoload.php')) {
    if (in_array('--require-db',$argv,true)) { fwrite(STDERR,"FAIL PDO SQLite + Composer required\n"); exit(1); }
    echo "SKIP PDO SQLite + Composer required\n"; exit(0);
}
require $root.'/vendor/autoload.php';

use CajeerEngine\Identity\{IdentitySessionMigration,IdentityAcceptance};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\UserSessionRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Request;

$checkCount=0;
$assert=static function (bool $ok,string $label) use (&$checkCount):void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $checkCount++; echo "PASS $label\n";
};
$config = new ConfigRepository($root);
$config->set('database.default','sqlite');
$config->set('database.connections.sqlite.database',':memory:');
$config->set('security.sessions.idle_minutes',720);
$db = new DatabaseManager($config);
$pdo=$db->connection();
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE ce_users (id TEXT PRIMARY KEY, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE ce_api_tokens (id TEXT PRIMARY KEY, user_id TEXT, name TEXT NOT NULL, revoked_at TEXT NULL, expires_at TEXT NULL, created_at TEXT NOT NULL, last_used_at TEXT NULL)');
$pdo->exec('CREATE TABLE ce_oidc_identities (issuer TEXT, subject TEXT, user_id TEXT)');
$pdo->exec('CREATE TABLE ce_oidc_transactions (state_hash TEXT PRIMARY KEY)');
$pdo->exec('CREATE TABLE ce_oidc_logout_replay (jti_hash TEXT PRIMARY KEY)');
foreach (['0028_unified_sessions.sql','0030_authentication_assurance.sql'] as $file) {
    $pdo->exec(file_get_contents($root.'/migrations/sqlite/'.$file));
}
$pdo->exec('ALTER TABLE ce_user_sessions ADD COLUMN oidc_sub_hash TEXT NULL');
$uid1='11111111-1111-4111-8111-111111111111';
$uid2='22222222-2222-4222-8222-222222222222';
$pdo->prepare('INSERT INTO ce_users VALUES (?,?)')->execute([$uid1,'active']);
$pdo->prepare('INSERT INTO ce_users VALUES (?,?)')->execute([$uid2,'disabled']);
$now=gmdate('Y-m-d H:i:s');
$stale=gmdate('Y-m-d H:i:s',time()-86400);
$future=gmdate('Y-m-d H:i:s',time()+86400);
$past=gmdate('Y-m-d H:i:s',time()-30);
$ids = [
    'eligible'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'stale'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'disabled'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    'api'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    'oidc'=>'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    'expired'=>'ffffffff-ffff-4fff-8fff-ffffffffffff',
    'strict'=>'99999999-9999-4999-8999-999999999999',
];
$insert=$pdo->prepare('INSERT INTO ce_api_tokens (id,user_id,name,created_at,last_used_at,expires_at) VALUES (?,?,?,?,?,?)');
foreach (['eligible'=>[$uid1,'admin-session',$now,$now,$future],
           'stale'=>[$uid1,'admin-session',$stale,$stale,$future],
           'disabled'=>[$uid2,'admin-session',$now,$now,$future],
           'api'=>[$uid1,'personal-api',$now,$now,$future],
           'oidc'=>[$uid1,'admin-session',$now,$now,$future],
           'expired'=>[$uid1,'admin-session',$stale,$stale,$past]] as $name=>$fields) {
    $insert->execute(array_merge([$ids[$name]],$fields));
}
$browser=Request::create('https://cms.example.test/api/v1/auth/me','GET',[],[],[],['HTTP_USER_AGENT'=>'Acceptance browser']);
$registry=new UserSessionRepository($db);
$registry->register($ids['oidc'],$uid1,'skif4er',$browser,$future,['sub'=>'real-subject','sid'=>'real-session']);
$service=new IdentitySessionMigration($db,720);
$before=$service->status();
$assert($before['unregistered']===4 && $before['eligible_for_backfill']===1,'preflight detects exact migration population');
$assert($before['registered_skif4er']===1 && $before['inconsistent_registry']===0,'existing OIDC session untouched');
putenv('SKIF4ER_ID_ENABLED=false');putenv('IDENTITY_SESSION_STRICT=false');
$acceptance=new IdentityAcceptance($config,$db);
$assert(!$acceptance->run()['ok'] && in_array('session_migration_complete',$acceptance->run()['failed_checks'],true),'go-live blocked while unregistered sessions remain');
$assert(!$acceptance->run(true)['ok'] && in_array('oidc_enabled',$acceptance->run(true)['failed_checks'],true),'require-oidc cannot pass with disabled provider');
$assert(in_array('deployed_site',$acceptance->run(false,false,true)['failed_checks'],true),'live probe refuses non-HTTPS deployment');
$r1=$service->migrate(2,1);
$assert($r1['migrated']===1 && $r1['revoked_stale']===1 && $r1['remaining']===2,'bounded first batch migrates valid and revokes stale');
$r2=$service->migrate(2,3);
$assert($r2['migrated']===0 && $r2['revoked_stale']===2 && $r2['complete'],'resume handles disabled and expired');
$assert($service->migrate(2,2)['scanned']===0,'migration is repeatable without side effects');
$legacy=$registry->find($ids['eligible']);
$assert($legacy!==null && $legacy['provider']==='legacy' && $legacy['oidc_sid_hash']===null && $legacy['issuer']===null,'pre-OIDC token cannot be falsely upgraded to SSO');
$assert($legacy['created_at']===$now && $legacy['last_seen_at']===$now && $legacy['expires_at']===$future,'token timestamps and absolute expiry preserved');
$assert($registry->find($ids['oidc'])['provider']==='skif4er','new OIDC session metadata preserved');
$assert($pdo->query("SELECT revoked_at FROM ce_api_tokens WHERE id='".$ids['api']."'")->fetchColumn()===null,'machine API credential never revoked');
$assert($acceptance->run()['ok'],'read-only acceptance passes after migration');
$insert->execute([$ids['strict'],$uid1,'admin-session',$now,$now,$future]);
putenv('IDENTITY_SESSION_STRICT=true');
$token=['id'=>$ids['strict'],'user_id'=>$uid1,'name'=>'admin-session','created_at'=>$now,'last_used_at'=>$now,'expires_at'=>$future];
$assert($registry->validate($token,$browser)===false && $registry->find($ids['strict'])===null,'strict rollout rejects unregistered credential without writes');
$assert(!$acceptance->run()['ok'],'strict rollout fails acceptance when a legacy token appears');
putenv('IDENTITY_SESSION_STRICT=false');
$assert($registry->validate($token,$browser)===true && $registry->find($ids['strict'])['provider']==='legacy','legacy compatibility remains before strict cutover');
putenv('IDENTITY_SESSION_STRICT=true');
$assert($registry->validate($token,$browser)===true && $acceptance->run()['ok'],'migrated legacy token works after strict cutover');
$pdo->exec("UPDATE ce_user_sessions SET user_id = '".$uid2."' WHERE token_id = '".$ids['eligible']."'");
$assert(!$acceptance->run()['ok'] && in_array('session_registry_integrity',$acceptance->run()['failed_checks'],true),'ownership mismatch fails acceptance');
$pdo->exec("UPDATE ce_user_sessions SET user_id = '".$uid1."' WHERE token_id = '".$ids['eligible']."'");
$assert($acceptance->run()['ok'],'repair verified by acceptance');
putenv('IDENTITY_SESSION_STRICT=false');
echo "Identity migration, strict cutover and live PDO acceptance: $checkCount PASS\n";
