#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Rc/CheckResult.php';
require dirname(__DIR__) . '/core/Rc/OperationsDocsVerifier.php';

use CajeerEngine\Rc\OperationsDocsVerifier;

$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/cajeer-ops-docs-' . bin2hex(random_bytes(8));

function ceCopyDir(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) throw new RuntimeException('mkdir failed');
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) continue;
        if ($item->isLink()) throw new RuntimeException('Unexpected symlink in fixture');
        if ($item->isDir()) ceCopyDir($item->getPathname(), $target . '/' . $item->getFilename());
        elseif (!copy($item->getPathname(), $target . '/' . $item->getFilename())) throw new RuntimeException('Copy failed');
    }
}
function ceRmDir(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir() && !$item->isLink()) ceRmDir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}
function ceAssert(bool $condition, string $why): void
{
    if (!$condition) throw new RuntimeException($why);
}
function ceCheck(string $root, string $name, string $expect): void
{
    $checks = (new OperationsDocsVerifier($root))->verify();
    $fails = array_values(array_filter($checks, static fn ($c): bool => $c->toArray()['status'] === 'fail'));
    ceAssert($expect === 'pass' ? $fails === [] : count($fails) > 0, "$name: expected $expect, observed " . count($fails) . ' failures');
}

try {
    ceCopyDir($source . '/docs', $fixture . '/docs');
    ceCopyDir($source . '/core/Console/Command', $fixture . '/core/Console/Command');
    mkdir($fixture . '/bin', 0700, true);
    foreach (['VERSION', '.env.example'] as $f) copy($source . '/' . $f, $fixture . '/' . $f);
    foreach (['QueueCli.php','OperationsCli.php'] as $f) copy($source . '/core/Console/' . $f, $fixture . '/core/Console/' . $f);
    copy($source . '/bin/cajeer', $fixture . '/bin/cajeer');

    ceCheck($fixture, 'complete runbooks', 'pass');
    $path = $fixture . '/docs/operations-handbook-1.8.8.md';
    $original = file_get_contents($path);
    file_put_contents($path, $original . "\n[broken](does-not-exist.md)\n");
    ceCheck($fixture, 'missing relative link', 'fail');
    file_put_contents($path, $original);

    file_put_contents($path, $original . "\n```bash\nphp bin/cajeer fake:danger\n```\n");
    ceCheck($fixture, 'unregistered command', 'fail');
    file_put_contents($path, $original);

    file_put_contents($path, $original . "\n```dotenv\nUNSUPPORTED_FUTURE_SETTING=true\n```\n");
    ceCheck($fixture, 'undocumented environment key', 'fail');
    file_put_contents($path, $original);

    file_put_contents($path, str_replace('# CajeerEngine 1.9.2', '# CajeerEngine 1.8.7', $original));
    ceCheck($fixture, 'stale document version', 'fail');
    file_put_contents($path, $original);

    unlink($fixture . '/docs/operations-recovery-1.8.8.md');
    ceCheck($fixture, 'missing recovery runbook', 'fail');
    echo "[OK] Operational documentation: valid runbook + five negative regressions\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    ceRmDir($fixture);
}
