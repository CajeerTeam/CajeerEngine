<?php

declare(strict_types=1);

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Release\ReleaseArtifactVerifier;
use CajeerEngine\Release\ReleaseBuilder;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $path = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($path)) require $path;
    }
});
function ensure(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (Throwable) { echo "PASS $message\n"; return; }
    throw new RuntimeException($message);
}
function cleanup(string $path): void {
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($path);
}
$workspace = sys_get_temp_dir() . '/ce-release-test-' . bin2hex(random_bytes(8));
mkdir($workspace, 0700);
try {
    $builder = new ReleaseBuilder($root);
    $one = $builder->build(false, 'source', $workspace . '/one');
    $two = $builder->build(false, 'source', $workspace . '/two');
    ensure($one['sha256'] === $two['sha256'], 'independent source ZIPs are byte-identical');
    ensure($builder->verifyRelease('source', false, $one['target'])['ok'], 'physical ZIP and sidecars verify');
    (new PharData($one['target']))->extractTo($workspace . '/unpacked');
    $copy = $workspace . '/unpacked/cajeerengine';
    $copyBuilder = new ReleaseBuilder($copy);
    $asset = $copy . '/public/admin/assets/admin-app.js';
    $manifest = $copy . '/public/admin/assets/admin-manifest.json';
    $original = file_get_contents($asset);
    $manifestHash = hash_file('sha256', $manifest);
    unlink($asset);
    ensure(!(new AdminAssetPublisher($copy))->manifest()['ready'] && !is_file($asset) && hash_file('sha256', $manifest) === $manifestHash, 'asset check rejects missing JS and never writes');
    rejects(fn () => $copyBuilder->build(false, 'source', $workspace . '/missing'), 'missing assets block physical release');
    file_put_contents($asset, $original . "\n// modified\n");
    ensure(!(new AdminAssetPublisher($copy))->manifest()['ready'], 'modified admin JS fails hash check');
    file_put_contents($asset, $original);
    ensure((new AdminAssetPublisher($copy))->manifest()['ready'], 'restoring canonical assets passes');
    foreach (['.env', 'storage/app/installed.lock', 'storage/app/content/private.json', 'storage/database/site.sqlite', 'storage/app/exports/secret.json', 'public/uploads/private.jpg', 'plugins/private/src.php', 'public/extensions/private/app.js', 'admin/.output/public/leak.js', 'cajeerengine112_changed_files_manifest.json'] as $file) {
        $path = $copy . '/' . $file;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'PRIVATE TEST DATA');
        ensure(!in_array($file, $copyBuilder->plan('source')['files'], true) && !in_array($file, $copyBuilder->plan('dist')['files'], true), 'excluded: ' . $file);
    }
    $dirty = $copyBuilder->build(false, 'source', $workspace . '/dirty');
    ensure($dirty['sha256'] === $one['sha256'], 'runtime contamination does not change release ZIP');
    ensure(!$copyBuilder->verifyRelease('dist', true)['ok'], 'strict dist fails without vendor');
    rejects(fn () => $copyBuilder->build(false, 'dist', $workspace . '/dist'), 'dist build refuses missing dependencies');
    $tampered = $workspace . '/tampered.zip';
    copy($one['target'], $tampered);
    $archive = new PharData($tampered);
    $archive->addFromString('cajeerengine/README.md', 'changed');
    unset($archive);
    rejects(fn () => (new ReleaseArtifactVerifier())->verify($tampered, 'source', $one['version']), 'tampered ZIP file fails manifest checksum');
    $extra = $workspace . '/extra.zip';
    copy($one['target'], $extra);
    $archive = new PharData($extra);
    $archive->addFromString('cajeerengine/.env', 'private');
    unset($archive);
    rejects(fn () => (new ReleaseArtifactVerifier())->verify($extra, 'source', $one['version']), 'unlisted secret entry fails verification');
    $wrongSidecar = $one['target'] . '.sha256';
    file_put_contents($wrongSidecar, str_repeat('0', 64));
    ensure(!$builder->verifyRelease('source', false, $one['target'])['ok'], 'incorrect external SHA256 fails verification');
    // Installer lock test without mutating an actual installation or connecting to DB.
    $installerLock = fopen($copy . '/storage/app/install-running.lock', 'c');
    flock($installerLock, LOCK_EX);
    try {
        $installer = new CajeerEngine\Installer\InstallerService($copy);
        try { $installer->install([]); throw new LogicException('concurrent install accepted'); }
        catch (RuntimeException $e) { ensure(str_contains($e->getMessage(), 'другим процессом'), 'concurrent installer refused before configuration'); }
    } finally { flock($installerLock, LOCK_UN); fclose($installerLock); }
    echo "Release regression passed\n";
} finally { cleanup($workspace); }
