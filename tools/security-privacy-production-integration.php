#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real PHP/PDO security checks. Release CI must use --require-db; never silently skip. */
$root = dirname(__DIR__);
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !is_file($root.'/vendor/autoload.php')) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL: PDO SQLite and Composer are required\n"); exit(1); }
    echo "SKIP PHP/PDO security acceptance: SQLite or Composer unavailable\n";
    exit(0);
}
require $root.'/vendor/autoload.php';

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository,ApiTokenRepository,UserSessionRepository,AuditLogRepository};
use CajeerEngine\Runtime\{ConfigRepository,RuntimeLogger};
use CajeerEngine\Security\{AuthService,PasswordHasher,TwoFactorTotp};
use CajeerEngine\Http\Middleware\{ErrorHandlerMiddleware,JsonBodyMiddleware};
use Symfony\Component\HttpFoundation\Request;

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
$path = sys_get_temp_dir().'/ce-privacy-195-'.bin2hex(random_bytes(8)).'.sqlite';
$configuration = static function (string $sqlitePath) use ($root): ConfigRepository {
    $config = new ConfigRepository($root);
    $config->set('database.default', 'sqlite');
    $config->set('database.connections.sqlite.database', $sqlitePath);
    return $config;
};
try {
    $cfg = $configuration($path);
    $db = new DatabaseManager($cfg);
    $pdo = $db->connection();
    $pdo->exec("CREATE TABLE ce_users (id TEXT PRIMARY KEY, email TEXT UNIQUE, name TEXT, status TEXT NOT NULL, password_hash TEXT, two_factor_enabled INTEGER NOT NULL DEFAULT 0, two_factor_secret TEXT, two_factor_recovery_codes TEXT NOT NULL DEFAULT '[]', last_login_at TEXT, disabled_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE ce_roles (id TEXT PRIMARY KEY, handle TEXT UNIQUE, name TEXT, permissions TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE ce_user_roles (user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id))');
    $pdo->exec('CREATE TABLE ce_api_tokens (id TEXT PRIMARY KEY, user_id TEXT, name TEXT, token_hash TEXT UNIQUE, token_prefix TEXT, scopes TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_used_at TEXT, revoked_at TEXT)');
    $pdo->exec('CREATE TABLE ce_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    foreach (['0028_unified_sessions.sql','0029_oidc_global_logout.sql','0030_authentication_assurance.sql'] as $migration) {
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration));
    }
    $roles = new RoleRepository($db);
    $roles->upsert('admin','Admin',['*']);
    $passwords = new PasswordHasher($cfg);
    $users = new UserRepository($db,$roles,$passwords);
    $totp = new TwoFactorTotp();
    $auth = new AuthService($cfg,$users,new ApiTokenRepository($db),new AuditLogRepository($db),$passwords,$totp,new UserSessionRepository($db));
    $uid = $users->create('disabled@example.test','Disabled',$passwords->hash('SecretStrong123!'),['admin']);
    $req = Request::create('https://cms.example.test/api/v1/auth/login','POST');
    $users->disable($uid);
    $failures = [];
    foreach ([['absent@example.test','SecretStrong123!'],['disabled@example.test','SecretStrong123!'],['disabled@example.test','invalid-password']] as [$email,$password]) {
        try { $auth->login($email,$password,null,$req); throw new RuntimeException('FAIL: disabled or absent account authenticated'); }
        catch (RuntimeException $e) { if (str_starts_with($e->getMessage(), 'FAIL:')) throw $e; $failures[] = $e->getMessage(); }
    }
    $check(count(array_unique($failures)) === 1, 'absent/disabled/invalid credentials expose identical error text');
    $events = $pdo->query("SELECT context FROM ce_audit_log WHERE event='auth.login_failed'")->fetchAll(PDO::FETCH_COLUMN);
    $check(count($events) === 3 && !str_contains(implode(' ', $events),'@example.test') && str_contains($events[0], 'email_sha256'), 'audit records hash account identifiers rather than storing raw email');

    $validCode = 'A0B1C2D3E4';
    $anotherCode = 'F5E6D7C8B9';
    $hashes = json_encode([password_hash($validCode,PASSWORD_DEFAULT),password_hash($anotherCode,PASSWORD_DEFAULT)], JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE ce_users SET status=:active,two_factor_enabled=1,two_factor_recovery_codes=:codes WHERE id=:uid')
        ->execute(['active'=>'active','codes'=>$hashes,'uid'=>$uid]);
    $check(!$users->consumeRecoveryCode($uid,'not-a-code'), 'malformed recovery code is rejected');
    $check($users->consumeRecoveryCode($uid,$validCode), 'first recovery redemption succeeds');
    $check(!$users->consumeRecoveryCode($uid,$validCode), 'redeemed recovery code is permanently invalid');
    $check($users->consumeRecoveryCode($uid,$anotherCode), 'unrelated recovery code remains usable');
    $check(!$users->consumeRecoveryCode($uid,$anotherCode), 'last recovery code cannot be reused');

    // Actual concurrent PHP/PDO connections, not mocks: only one may redeem a code.
    if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
        $code = '1A2B3C4D5E';
        $pdo->prepare('UPDATE ce_users SET two_factor_recovery_codes = :codes WHERE id = :id')
            ->execute(['codes'=>json_encode([password_hash($code,PASSWORD_DEFAULT)],JSON_THROW_ON_ERROR),'id'=>$uid]);
        $barrier = sys_get_temp_dir().'/ce-privacy-barrier-'.bin2hex(random_bytes(8));
        $children = [];
        // Do not let a child inherit the parent's live PDO connection.
        $db->disconnect(); unset($pdo);
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) throw new RuntimeException('Unable to fork recovery test');
            if ($pid === 0) {
                $childDb = new DatabaseManager($configuration($path));
                $childUsers = new UserRepository($childDb,new RoleRepository($childDb));
                $start = microtime(true);
                while (!is_file($barrier) && microtime(true)-$start < 5) usleep(10000);
                try { exit($childUsers->consumeRecoveryCode($uid,$code) ? 0 : 2); }
                catch (Throwable) { exit(3); }
            }
            $children[] = $pid;
        }
        file_put_contents($barrier,'go');
        $results = [];
        foreach ($children as $pid) { pcntl_waitpid($pid,$status); $results[] = pcntl_wexitstatus($status); }
        @unlink($barrier);
        $check(count(array_filter($results, static fn (int $status): bool => $status===0))===1,
            'two simultaneous connections cannot redeem the same recovery code twice');
        $verifyDb = new DatabaseManager($configuration($path));
        $verifyUsers = new UserRepository($verifyDb,new RoleRepository($verifyDb));
        $check(!$verifyUsers->consumeRecoveryCode($uid,$code), 'recovery code stays consumed after concurrent requests');
        $verifyDb->disconnect();
    }

    $cfg->set('app.env','production');
    $logRoot = sys_get_temp_dir().'/ce-privacy-errors-'.bin2hex(random_bytes(8));
    mkdir($logRoot, 0700);
    $handler = new ErrorHandlerMiddleware($cfg,new RuntimeLogger($logRoot));
    $request = Request::create('/api/v1/system','POST');
    $response = $handler->process($request,static fn():never=>throw new InvalidArgumentException('Bearer super-secret-value in validation error'));
    $check($response->getStatusCode()===422 && !str_contains($response->getContent(),'super-secret-value'), 'production validation errors do not expose secrets');
    $body = Request::create('https://cms.example.test/api/v1/auth/login','POST',[],[],[],[
        'CONTENT_TYPE'=>'application/json', 'CONTENT_LENGTH'=>(string)(41*1024*1024)],'{"password":"small"}');
    $response = (new JsonBodyMiddleware())->process($body,static fn()=>throw new RuntimeException('Large request reached service'));
    $check($response->getStatusCode()===413, 'declared oversized JSON is blocked before request parsing');
} finally {
    $db->disconnect();
    foreach ([$path,$path.'-shm',$path.'-wal'] as $file) @unlink($file);
    if (isset($logRoot) && is_dir($logRoot)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($logRoot);
    }
}
echo 'Security & Privacy Production PDO integration PASS'.PHP_EOL;
