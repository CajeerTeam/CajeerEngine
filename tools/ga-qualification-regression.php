#!/usr/bin/env php
<?php

declare(strict_types=1);

// Executable recovery-acceptance regression: cryptographic EOF must be
// validated before any extracted data becomes visible at the destination.
require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Backup\RecoveryBundle;
use CajeerEngine\Update\OperationFiles as F;

function gaAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}

function gaReject(callable $callback, string $message): void
{
    try { $callback(); }
    catch (Throwable) { gaAssert(true, $message); return; }
    throw new RuntimeException('FAIL: accepted ' . $message);
}

$work = sys_get_temp_dir() . '/ce-ga-' . bin2hex(random_bytes(12));
F::dir($work);
try {
    $source = $work . '/source'; F::dir($source);
    F::write($source . '/manifest.json', '{"format":"test"}');
    F::write($source . '/manifest.mac', random_bytes(32));
    F::write($source . '/database.dump', random_bytes(1048576 + 123));
    F::write($source . '/files/media/file.bin', random_bytes(1048576 + 37));
    $key = random_bytes(32);
    $bundle = $work . '/backup.enc';
    RecoveryBundle::export($source, $bundle, $key);

    $good = $work . '/verified'; F::dir($good);
    RecoveryBundle::extract($bundle, $good, $key);
    gaAssert(hash_file('sha256', $good.'/database.dump') === hash_file('sha256', $source.'/database.dump')
        && hash_file('sha256', $good.'/files/media/file.bin') === hash_file('sha256', $source.'/files/media/file.bin'),
        'complete authenticated extraction publishes the correct payload');

    $raw = file_get_contents($bundle);
    F::write($work.'/truncated', substr($raw, 0, -1));
    $empty = $work.'/empty'; F::dir($empty);
    gaReject(fn() => RecoveryBundle::extract($work.'/truncated', $empty, $key), 'truncated bundle is rejected');
    gaAssert(is_dir($empty) && !(new FilesystemIterator($empty))->valid(),
        'truncated bundle leaves destination empty');

    $broken = $raw;
    $broken[strlen($broken) - 35] = chr(ord($broken[strlen($broken) - 35]) ^ 1);
    F::write($work.'/tampered', $broken);
    gaReject(fn() => RecoveryBundle::extract($work.'/tampered', $empty, $key), 'tampered final frame rejected');
    gaAssert(!(new FilesystemIterator($empty))->valid(), 'tampered bundle leaves destination empty');
    gaAssert(glob($work . '/.dr-extract-*') === [], 'failed decryptions remove private staging directories');

    gaReject(fn() => RecoveryBundle::extract($bundle, $good, $key), 'nonempty destination rejected');
    gaAssert(is_file($good.'/database.dump'), 'previous verified data remains intact');
    F::write($work.'/sentinel', 'untouched');
    gaReject(fn() => RecoveryBundle::extract($bundle, $work.'/sentinel', $key), 'file destination rejected');
    gaAssert(file_get_contents($work.'/sentinel') === 'untouched', 'non-directory target remains intact');

    $absent = $work.'/new-copy';
    RecoveryBundle::extract($bundle, $absent, $key);
    gaAssert(hash_file('sha256', $absent.'/database.dump') === hash_file('sha256', $source.'/database.dump'),
        'absent destination receives a complete verified snapshot');
} finally {
    RecoveryBundle::cleanup($work);
}
