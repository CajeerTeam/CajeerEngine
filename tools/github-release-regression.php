<?php

declare(strict_types=1);
use CajeerEngine\Update\GitHubEndpoints as E;
use CajeerEngine\Update\GitHubReleaseCatalog as Catalog;
use CajeerEngine\Update\UpdateResolver;
use CajeerEngine\Release\ReleaseBuilder;
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $path = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($path)) require $path;
    }
});
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function release(string $tag, bool $pre = false, bool $draft = false): array {
    $version = ltrim($tag, 'v');
    $name = "cajeerengine-$version-dist.zip";
    $assets = [];
    foreach (['', '.sha256', '.sig'] as $suffix) $assets[] = ['name'=>$name.$suffix, 'size'=>100, 'state'=>'uploaded', 'digest'=>'sha256:'.str_repeat('a',64), 'browser_download_url'=>'https://attacker.invalid/file'];
    return ['tag_name'=>$tag, 'draft'=>$draft, 'prerelease'=>$pre, 'published_at'=>'2026-01-01T00:00:00Z', 'body'=>'Release notes', 'assets'=>$assets];
}
$incomplete = release('v9.0.0'); array_pop($incomplete['assets']);
$pending = release('v8.0.0'); $pending['assets'][0]['state'] = 'starter';
$data = Catalog::normalize([release('v1.2.6'), release('v1.3.0-rc.1', true), release('v9.1.0', false, true), $incomplete, $pending, release('v9.2.0-beta.1'), release('not-semver')]);
check(count($data['releases']) === 2, 'only complete published SemVer releases');
$a = $data['releases'][0]['artifacts']['dist'];
check($a['download_url'] === E::PROJECT_URL.'/releases/download/v1.2.6/cajeerengine-1.2.6-dist.zip' && $a['sha256'] === str_repeat('a',64), 'canonical asset URL and digest');
check($data['releases'][1]['channel'] === 'rc' && $data['releases'][0]['channel'] === 'stable', 'prerelease channel isolation');
check(Catalog::normalize(['message'=>'rate limit']) === null && Catalog::normalize([]) === ['releases'=>[]], 'API error differs from empty list');
$tmp = tempnam(sys_get_temp_dir(), 'ce-gh-');
try {
    file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR));
    $cfg = ['core'=>['releases_url'=>$tmp,'channel'=>'stable']];
    $result = (new UpdateResolver($cfg))->check('1.2.5');
    check($result['update_available'] && $result['latest']['version'] === '1.2.6', 'resolver selects latest stable');
    $cfg['core']['channel']='rc';
    check((new UpdateResolver($cfg))->check('1.2.5')['latest']['version'] === '1.3.0-rc.1', 'resolver selects rc');
    $cfg['core']['releases_url']=$tmp.'.missing'; $cfg['core']['channel']='stable';
    check((new UpdateResolver($cfg,$root))->check('1.2.4')['latest']['version'] === '1.2.5', 'bundled metadata fallback');
} finally { unlink($tmp); }
$plan = (new ReleaseBuilder($root))->plan('source');
check($plan['github']['release_url'] === E::releaseUrl('v'.$plan['version']) && $plan['github']['download_url'] === E::assetUrl('v'.$plan['version'],$plan['artifact']), 'release builder links match GitHub catalog');
check((require $root.'/config/updates.php')['core']['releases_url'] === E::RELEASES_URL, 'default GitHub REST endpoint');
