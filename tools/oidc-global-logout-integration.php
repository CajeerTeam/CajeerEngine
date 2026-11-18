<?php

declare(strict_types=1);
/** Real PHP PDO SQLite tests, required by the release workflow. */
$root=dirname(__DIR__);
if (!in_array('sqlite',PDO::getAvailableDrivers(),true) || !is_file($root.'/vendor/autoload.php')) {
    if (in_array('--require-db',$argv,true)) {fwrite(STDERR,"FAIL PDO SQLite / Composer dependencies unavailable\n");exit(1);}
    echo "SKIP PDO SQLite / Composer dependencies unavailable\n";exit(0);
}
require $root.'/vendor/autoload.php';
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\UserSessionRepository;
use Symfony\Component\HttpFoundation\Request;
$cfg=new ConfigRepository($root);
$cfg->set('database.default','sqlite');$cfg->set('database.connections.sqlite.database',':memory:');
$db=new DatabaseManager($cfg);$pdo=$db->connection();
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE ce_users (id TEXT PRIMARY KEY)');
$pdo->exec('CREATE TABLE ce_api_tokens (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, name TEXT NOT NULL, revoked_at TEXT, expires_at TEXT)');
$pdo->exec('CREATE TABLE ce_oidc_identities (issuer TEXT NOT NULL, subject TEXT NOT NULL, user_id TEXT NOT NULL, PRIMARY KEY(issuer,subject))');
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0028_unified_sessions.sql'));
$pdo->exec(file_get_contents($root.'/migrations/sqlite/0029_oidc_global_logout.sql'));
$issuer='https://id.skif4er.ru/realms/skif4er';
$u1='11111111-1111-4111-8111-111111111111';$u2='22222222-2222-4222-8222-222222222222';
$pdo->prepare('INSERT INTO ce_users(id) VALUES (?)')->execute([$u1]);
$pdo->prepare('INSERT INTO ce_users(id) VALUES (?)')->execute([$u2]);
$link=$pdo->prepare('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)');$link->execute([$issuer,'sub-one',$u1]);$link->execute([$issuer,'sub-two',$u2]);
$repo=new UserSessionRepository($db);
$req=Request::create('https://cms.example.com/api/v1/auth/me','GET',[],[],[],['HTTP_USER_AGENT'=>'Test Browser','REMOTE_ADDR'=>'192.168.1.44']);
$make=static function(string $id,string $uid,string $name,string $provider,?string $sid,?string $sub)use($pdo,$repo,$req):void {
    $pdo->prepare('INSERT INTO ce_api_tokens (id,user_id,name,expires_at) VALUES (?,?,?,?)')->execute([$id,$uid,$name,'2035-01-01 00:00:00']);
    if ($name==='admin-session')$repo->register($id,$uid,$provider,$req,'2035-01-01 00:00:00',array_filter(['sid'=>$sid,'sub'=>$sub],fn($x)=>$x!==null));
};
$make('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',$u1,'admin-session','local',null,null);
$make('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$u1,'admin-session','skif4er','sid-1','sub-one');
$make('cccccccc-cccc-4ccc-8ccc-cccccccccccc',$u1,'admin-session','skif4er','sid-2','sub-one');
$make('dddddddd-dddd-4ddd-8ddd-dddddddddddd',$u2,'admin-session','skif4er','sid-3','sub-two');
$make('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',$u1,'personal-api','local',null,null);
$pass=0;$check=static function(bool $ok,string $name)use(&$pass):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$pass++;echo 'PASS '.$name.PHP_EOL;};
$event=static fn(string $jti,?string $sub,?string $sid):array=>['iss'=>'https://id.skif4er.ru/realms/skif4er','jti'=>$jti,'exp'=>time()+180,'sub'=>$sub,'sid'=>$sid];
$result=$repo->applyBackchannelLogout($event('one','sub-one','sid-1'));
$check($result['revoked_count']===1&&!$result['already_processed'],'matching sid+sub revoked once');
$check($repo->find('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')['revoked_at']!==null,'revoked local record');
$check($repo->applyBackchannelLogout($event('one','sub-one','sid-1'))['already_processed']===true,'replay is idempotent');
$check($repo->applyBackchannelLogout($event('mismatch','sub-one','sid-3'))['revoked_count']===0,'sub and sid must match');
$check($repo->applyBackchannelLogout($event('sub-only','sub-one',null))['revoked_count']===1,'sub-only revokes remaining OIDC sessions');
$check($repo->applyBackchannelLogout($event('sid-only',null,'sid-3'))['revoked_count']===1,'sid-only revokes another user session');
$check($repo->find('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')['revoked_at']===null,'local password session preserved');
$check($pdo->query("SELECT revoked_at FROM ce_api_tokens WHERE id = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'")->fetchColumn()===null,'personal API token preserved');
$check($pdo->query("SELECT COUNT(*) FROM ce_oidc_logout_replay")->fetchColumn()==4,'replay records persisted');
// Compatibility: an OIDC session issued in 1.5.3 lacks the subject hash, but still has a linked user + sid.
$make('ffffffff-ffff-4fff-8fff-ffffffffffff',$u1,'admin-session','skif4er','old-sid',null);
$check($repo->applyBackchannelLogout($event('older','sub-one',null))['revoked_count']===1,'legacy OIDC subject-only fallback with verified mapping');
$check($pdo->query("SELECT revoked_at FROM ce_api_tokens WHERE id = 'ffffffff-ffff-4fff-8fff-ffffffffffff'")->fetchColumn()!==null,'legacy token revoked in tokens table');
echo "OIDC global logout PDO integration: $pass PASS\n";
