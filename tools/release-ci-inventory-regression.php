#!/usr/bin/env php
<?php

declare(strict_types=1);

use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Release\ReleaseDependencyInventory;

$root = dirname(__DIR__);
require $root . '/core/Release/ReleaseDependencyInventory.php';
require $root . '/core/Release/ReleaseBuilder.php';

function ensureInventory(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}

function rejectsInventory(callable $action, string $description): void
{
    try {
        $action();
    } catch (Throwable) {
        ensureInventory(true, $description);
        return;
    }
    ensureInventory(false, $description);
}

/** @param array<string,mixed> $value */
function jsonInventory(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function removeInventoryDir(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) removeInventoryDir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
}

$lockJson = (string) file_get_contents($root . '/composer.lock');
$lock = json_decode($lockJson, true, 512, JSON_THROW_ON_ERROR);
ensureInventory(count($lock['packages'] ?? []) > 0 && count($lock['packages-dev'] ?? []) > 0, 'actual composer.lock includes runtime and test packages');
$runtime = $lock['packages'];
$all = array_merge($runtime, $lock['packages-dev']);
$prod = ['packages' => $runtime, 'dev' => false];
$ci = ['packages' => $all, 'dev' => true];

ensureInventory(count(ReleaseDependencyInventory::validate($lockJson, jsonInventory($prod))) > 0, 'no-dev production install accepted');
ensureInventory(count(ReleaseDependencyInventory::validate($lockJson, jsonInventory($ci), true)) === count(array_filter($all, static fn ($p) => ($p['type'] ?? 'library') !== 'metapackage')), 'exact CI dev inventory accepted');
rejectsInventory(static fn () => ReleaseDependencyInventory::validate($lockJson, jsonInventory($ci)), 'production validator rejects dev dependencies');

$missing = $ci;
array_pop($missing['packages']);
rejectsInventory(static fn () => ReleaseDependencyInventory::validate($lockJson, jsonInventory($missing), true), 'missing dev package rejected');
$changed = $ci;
$changed['packages'][0]['version'] = '9999.0.0';
rejectsInventory(static fn () => ReleaseDependencyInventory::validate($lockJson, jsonInventory($changed), true), 'wrong locked version rejected');
$duplicate = $ci;
$duplicate['packages'][] = $duplicate['packages'][0];
rejectsInventory(static fn () => ReleaseDependencyInventory::validate($lockJson, jsonInventory($duplicate), true), 'duplicate dependency rejected');
$extra = $ci;
$extra['packages'][] = ['name' => 'unexpected/foreign', 'version' => '1.0.0'];
rejectsInventory(static fn () => ReleaseDependencyInventory::validate($lockJson, jsonInventory($extra), true), 'unlocked dependency rejected');
$noFlag = $ci;
unset($noFlag['dev']);
rejectsInventory(static fn () => ReleaseDependencyInventory::validate($lockJson, jsonInventory($noFlag), true), 'missing Composer dev flag rejected');

// Validate the filesystem gate as well: having a valid JSON inventory must
// not be enough if actual package files or autoload metadata are absent.
$tmp = sys_get_temp_dir() . '/ce-composer-ci-' . bin2hex(random_bytes(8));
try {
    mkdir($tmp . '/vendor/composer', 0700, true);
    file_put_contents($tmp . '/composer.lock', $lockJson);
    file_put_contents($tmp . '/vendor/composer/installed.json', jsonInventory($ci));
    foreach (ReleaseDependencyInventory::AUTOLOAD_FILES as $name) {
        $file = $tmp . '/' . $name;
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        if (!is_file($file)) file_put_contents($file, '<?php // test fixture' . PHP_EOL);
    }
    foreach ($all as $package) {
        if (($package['type'] ?? 'library') === 'metapackage') continue;
        $file = $tmp . '/vendor/' . $package['name'] . '/composer.json';
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        file_put_contents($file, jsonInventory(['name' => $package['name']]));
    }
    $builder = new ReleaseBuilder($tmp);
    ensureInventory($builder->auditedVendorReady(), 'CI filesystem inventory matches lock');
    $productionOnly = new ReflectionMethod($builder, 'lockedVendorReady');
    ensureInventory(!$productionOnly->invoke($builder), 'development vendor is not production-dist ready');
    unlink($tmp . '/vendor/' . $all[0]['name'] . '/composer.json');
    ensureInventory(!$builder->auditedVendorReady(), 'missing installed package rejected');
} finally {
    removeInventoryDir($tmp);
}

echo 'Composer CI dependency regression: OK' . PHP_EOL;
