<?php

declare(strict_types=1);

use CajeerEngine\Extension\{CompatibilityChecker, DependencyGraph, ExtensionManifest, ManifestLoader, VersionConstraint};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\{UpdateManager, UpdateResolver};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen('CajeerEngine\\'))) . '.php';
        if (is_file($file)) require $file;
    }
});
function ceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function ceReject(callable $callback, string $message): void
{
    try { $callback(); } catch (InvalidArgumentException | RuntimeException) {
        echo "PASS $message\n";
        return;
    }
    throw new RuntimeException('Accepted invalid case: ' . $message);
}
function ceWrite(string $path, array $data): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}
function ceRemove(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}

ceAssert(VersionConstraint::matches('1.1', '^1.1'), 'partial Engine API caret constraint');
ceAssert(VersionConstraint::matches('1.2.0', '^1.1') && !VersionConstraint::matches('2.0.0', '^1.1'), 'major API boundary');
ceAssert(VersionConstraint::matches('0.0.3', '^0.0.3') && !VersionConstraint::matches('0.0.4', '^0.0.3'), 'patch-level zero-major caret boundary');
ceAssert(VersionConstraint::matches('0.0.9', '^0.0') && !VersionConstraint::matches('0.1.0', '^0.0'), 'zero-minor caret without explicit patch');
ceAssert(VersionConstraint::matches('1.0.0+build.5', '^1.0.0'), 'SemVer build metadata does not affect compatibility');
ceAssert(VersionConstraint::matches('1.1.9', '~1.1') && !VersionConstraint::matches('1.2.0', '~1.1'), 'tilde minor boundary');
ceAssert(VersionConstraint::matches('1.1.8', '>=1.1 <2.0') && !VersionConstraint::matches('2.0', '>=1.1 <2.0'), 'AND range and partial versions');
foreach (['', '1.1 || >2.0', '^banana', '>=', '1.1.2.3', '* >1.0', '>=01.2'] as $constraint) {
    ceReject(static fn() => VersionConstraint::assertValid($constraint), 'invalid constraint rejected: ' . $constraint);
}
$manifest = new ExtensionManifest('cajeer/demo', 'plugin', '1.0.0', '^1.1');
ceAssert((new CompatibilityChecker('1.1'))->isCompatible($manifest), 'existing plugin compatible with API 1.1');
ceReject(static fn() => (new CompatibilityChecker('2.0'))->assertCompatible($manifest), 'incompatible Engine API rejected');
$loader = new ManifestLoader();
$payload = ['name'=>'cajeer/demo', 'type'=>'plugin', 'version'=>'1.0.0', 'engine'=>'^1.1'];
ceAssert($loader->validatePayload($payload) === [], 'valid production extension manifest');
$payload['engine'] = '>=broken';
ceAssert($loader->validatePayload($payload) !== [], 'invalid Engine API constraint rejected before install');
$payload['engine'] = '^1.1'; $payload['requires'] = ['cajeer/other'=>'???'];
ceAssert($loader->validatePayload($payload) !== [], 'invalid dependency constraint rejected before install');
$nodes = [
    ['name'=>'cajeer/other', 'type'=>'plugin', 'version'=>'0.0.3', 'enabled'=>true, 'installed'=>true, 'valid'=>true],
    ['name'=>'cajeer/demo', 'type'=>'plugin', 'version'=>'1.0.0', 'enabled'=>true, 'installed'=>true, 'valid'=>true, 'requires'=>['cajeer/other'=>'^0.0.3']],
];
ceAssert(count(DependencyGraph::order($nodes)) === 2, 'dependency graph shares compatible version semantics');
$nodes[0]['version'] = '0.0.4';
ceReject(static fn()=>DependencyGraph::order($nodes), 'dependency graph rejects incompatible version');

$work = sys_get_temp_dir() . '/ce-compat-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
try {
    $artifact = static function (string $version): array {
        $name = 'cajeerengine-' . $version . '-dist.zip';
        return ['dist'=>['name'=>$name, 'checksum_name'=>$name.'.sha256', 'signature_name'=>$name.'.sig']];
    };
    $catalog = ['releases'=>[
        ['version'=>'1.9.7-rc.1', 'channel'=>'stable', 'stability'=>'stable', 'artifacts'=>$artifact('1.9.7-rc.1')],
        ['version'=>'1.9.8', 'channel'=>'stable', 'stability'=>'rc', 'artifacts'=>$artifact('1.9.8')],
        ['version'=>'1.9.7', 'channel'=>'stable', 'stability'=>'stable', 'artifacts'=>$artifact('1.9.7')],
        ['version'=>'1.9.9-rc.1', 'channel'=>'rc', 'stability'=>'rc', 'artifacts'=>$artifact('1.9.9-rc.1')],
    ]];
    ceWrite($work.'/catalog.json', $catalog);
    $stable = (new UpdateResolver(['core'=>['channel'=>'stable', 'releases_url'=>$work.'/catalog.json']]))->check('1.9.6');
    ceAssert($stable['latest']['version'] === '1.9.7', 'stable updates ignore spoofed prerelease metadata');
    $catalog['releases'][] = ['version'=>'2.0.0', 'channel'=>'stable', 'stability'=>'stable', 'artifacts'=>['dist'=>['name'=>'malformed.zip']]];
    ceWrite($work.'/catalog.json', $catalog);
    ceAssert((new UpdateResolver(['core'=>['channel'=>'stable', 'releases_url'=>$work.'/catalog.json']]))->check('1.9.6')['latest']['version'] === '1.9.7', 'incomplete dist metadata cannot offer an update');
    $rc = (new UpdateResolver(['core'=>['channel'=>'rc', 'releases_url'=>$work.'/catalog.json']]))->check('1.9.6');
    ceAssert($rc['latest']['version'] === '1.9.9-rc.1', 'RC updates remain available on RC channel');
    $directory = $work.'/transaction/stage';
    ceWrite($directory.'/release.json', ['version'=>'1.9.7', 'engine_api_version'=>'1.1']);
    ceWrite($work.'/modules/cajeer--demo/cajeer.extension.json', ['name'=>'cajeer/demo', 'type'=>'module', 'version'=>'1.0.0', 'engine'=>'^1.1']);
    $record = ['name'=>'cajeer/demo', 'path'=>'modules/cajeer--demo', 'version'=>'1.0.0', 'engine'=>'^1.1', 'enabled'=>true];
    ceWrite($work.'/storage/app/extensions/registry.json', ['extensions'=>['cajeer/demo'=>$record]]);
    $updater = new UpdateManager($work, new ConfigRepository($work));
    $preflight = new ReflectionMethod($updater, 'assertEnabledExtensionCompatibility');
    $preflight->invoke($updater, $work.'/transaction', '1.9.7');
    ceAssert(true, 'compatible installed extension accepted by real updater preflight');
    ceWrite($directory.'/release.json', ['version'=>'1.9.7', 'engine_api_version'=>'2.0']);
    ceReject(static fn()=> $preflight->invoke($updater,$work.'/transaction','1.9.7'), 'incompatible active extension blocks core upgrade');
    $record['enabled'] = false;
    ceWrite($work.'/storage/app/extensions/registry.json', ['extensions'=>['cajeer/demo'=>$record]]);
    $preflight->invoke($updater,$work.'/transaction','1.9.7');
    ceAssert(true, 'disabled extension does not unnecessarily block upgrade');
    $record['enabled'] = true;
    ceWrite($work.'/storage/app/extensions/registry.json', ['extensions'=>['cajeer/demo'=>$record]]);
    ceWrite($directory.'/release.json', ['version'=>'1.9.7', 'engine_api_version'=>'1.1']);
    ceWrite($work.'/modules/cajeer--demo/cajeer.extension.json', ['name'=>'cajeer/demo','type'=>'module','version'=>'1.0.0','engine'=>'^2.0']);
    ceReject(static fn()=> $preflight->invoke($updater,$work.'/transaction','1.9.7'), 'tampered installed extension manifest rejected');
    ceWrite($directory.'/release.json', ['version'=>'1.9.8', 'engine_api_version'=>'1.1']);
    ceReject(static fn()=> $preflight->invoke($updater,$work.'/transaction','1.9.7'), 'target release identity mismatch rejected');
} finally { ceRemove($work); }
echo "Compatibility & Upgrade regression complete\n";
