#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'CajeerEngine\\';
        if (!str_starts_with($class, $prefix)) { return; }
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    });
}

function ce_arg(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $i => $arg) {
        if (str_starts_with($arg, $prefix)) { return substr($arg, strlen($prefix)); }
        if ($arg === '--' . $name && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) { return $argv[$i + 1]; }
    }
    return null;
}

$dryRun = in_array('--dry-run', $argv, true);
$dist = in_array('--dist', $argv, true);
$all = in_array('--all', $argv, true);
$checksums = in_array('--checksums', $argv, true);
$json = in_array('--json', $argv, true);
$targetDir = ce_arg($argv, 'target-dir');

try {
    $builder = new CajeerEngine\Release\ReleaseBuilder($root);
    if ($checksums) {
        $result = $builder->verifyArtifacts($targetDir);
    } elseif ($all) {
        $result = $builder->buildAll($dryRun, $targetDir);
    } else {
        $result = $builder->build($dryRun, $dist ? 'dist' : 'source', $targetDir);
    }

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo 'CajeerEngine release builder' . PHP_EOL;
        echo 'Version: ' . ($result['version'] ?? 'unknown') . PHP_EOL;
        echo 'Dry-run: ' . (!empty($result['dry_run']) ? 'yes' : 'no') . PHP_EOL;
        if (isset($result['source'])) {
            echo 'Source: ' . ($result['source']['target'] ?? '') . PHP_EOL;
            echo 'Dist: ' . (isset($result['dist']['target']) ? $result['dist']['target'] : ('not built: ' . (string) ($result['dist_error'] ?? 'unknown'))) . PHP_EOL;
        } elseif (isset($result['checks'])) {
            foreach ($result['checks'] as $mode => $check) {
                echo strtoupper((string) $mode) . ': ' . (($check['valid'] ?? false) ? 'OK' : 'FAIL') . PHP_EOL;
            }
        } else {
            echo 'Mode: ' . ($result['mode'] ?? '') . PHP_EOL;
            echo 'Target: ' . ($result['target'] ?? '') . PHP_EOL;
            echo 'Files: ' . ($result['file_count'] ?? 0) . PHP_EOL;
            if (isset($result['dist_ready'])) { echo 'Dist ready: ' . ($result['dist_ready'] ? 'yes' : 'no') . PHP_EOL; }
            if (!empty($result['sha256'])) { echo 'SHA256: ' . $result['sha256'] . PHP_EOL; }
            if (!empty($result['checksum_file'])) { echo 'Checksum: ' . $result['checksum_file'] . PHP_EOL; }
        }
    }
    exit(($result['ok'] ?? true) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
