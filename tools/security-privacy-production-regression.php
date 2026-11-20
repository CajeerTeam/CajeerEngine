#!/usr/bin/env php
<?php

declare(strict_types=1);

// No Composer/DB required: these regressions run on every PR and release.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $name) use ($root): void {
    if (!str_starts_with($name, 'CajeerEngine\\')) return;
    $file = $root.'/core/'.str_replace('\\', '/', substr($name, strlen('CajeerEngine\\'))).'.php';
    if (is_file($file)) require $file;
});
$check = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
$folder = sys_get_temp_dir().'/ce-privacy-195-'.bin2hex(random_bytes(8));
if (!mkdir($folder, 0700)) throw new RuntimeException('Unable to create test directory');
try {
    $logger = new CajeerEngine\Runtime\RuntimeLogger($folder);
    $logger->error('Bearer secret-should-not-appear', ['password'=>'secret-should-not-appear', 'request_id'=>'test-195']);
    $file = $folder.'/storage/logs/runtime.jsonl';
    $raw = (string)file_get_contents($file);
    $check(str_contains($raw, 'Runtime event') && !str_contains($raw,'secret-should-not-appear'), 'free-form errors and untrusted context are not logged');
    $check((fileperms($file) & 0077) === 0, 'operational logs are owner-private');
    $sink = $folder.'/external.log';
    file_put_contents($sink, 'SENTINEL');
    unlink($file);
    symlink($sink, $file);
    $logger->error('Unhandled HTTP exception', ['request_id'=>'test-195']);
    $check(file_get_contents($sink) === 'SENTINEL', 'symlink cannot redirect runtime log writes');
    unlink($file);
    unlink($file.'.lock');
    symlink($sink, $file.'.lock');
    $logger->info('HTTP request handled', ['request_id'=>'test-195']);
    $check(file_get_contents($sink) === 'SENTINEL' && !is_file($file), 'symlinked lock prevents log creation');
    $hasher = new ReflectionClass(CajeerEngine\Security\AuthService::class);
    $dummy = $hasher->getConstant('UNKNOWN_USER_HASH');
    $check(is_string($dummy) && password_get_info($dummy)['algoName'] === 'argon2id'
        && !password_verify('arbitrary-login-password', $dummy), 'unknown-user verification uses a real non-credential Argon2id hash');
    foreach ([[CajeerEngine\Security\AuthService::class,'login',[0,1,2]],
        [CajeerEngine\Security\PasswordHasher::class,'verify',[0,1]],
        [CajeerEngine\Database\Repository\UserRepository::class,'consumeRecoveryCode',[1]],
        [CajeerEngine\Security\TwoFactorTotp::class,'verify',[0,1]]] as [$class,$method,$positions]) {
        $args = (new ReflectionMethod($class,$method))->getParameters();
        foreach ($positions as $pos) $check($args[$pos]->getAttributes(SensitiveParameter::class) !== [],
            'sensitive argument hidden in traces: '.$class.'::'.$method.'#'.$pos);
    }
    $check(CajeerEngine\Security\SafeHtml::url('javascript:alert(1)') === '', 'untrusted script URL excluded from public content');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($folder);
}
echo 'Security & Privacy Production offline regression PASS'.PHP_EOL;
