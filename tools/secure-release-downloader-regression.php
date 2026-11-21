#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Console/NativeCli.php';

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\GitHubAssetTransport;
use CajeerEngine\Update\GitHubEndpoints;
use CajeerEngine\Update\GitHubReleaseDownloader;
use CajeerEngine\Update\GitHubReleaseMonitor;
use CajeerEngine\Update\ReleaseSignature;

function requireDownloader(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}

function eraseDownloader(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (new FilesystemIterator($directory) as $file) {
        if ($file->isDir() && !$file->isLink()) eraseDownloader($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($directory);
}

$badUrls = [
    'http://github.com/CajeerTeam/CajeerEngine/releases/download/v2.0.2/x.zip',
    'https://github.com.evil.tld/CajeerTeam/CajeerEngine/releases/download/v2.0.2/x.zip',
    'https://127.0.0.1/release.zip',
    'https://github.com/OtherTeam/CajeerEngine/releases/download/v2.0.2/x.zip',
    'https://user:pass@github.com/CajeerTeam/CajeerEngine/releases/download/v2.0.2/x.zip',
    'https://release-assets.githubusercontent.com:8443/example',
    'https://release-assets.githubusercontent.com/x#fragment',
];
foreach ($badUrls as $url) {
    $denied = false;
    try { GitHubAssetTransport::validateUrl($url); } catch (RuntimeException) { $denied = true; }
    requireDownloader($denied, 'reject unsafe release URL: ' . parse_url($url, PHP_URL_HOST));
}
GitHubAssetTransport::validateUrl(GitHubEndpoints::assetUrl('v2.0.2', 'cajeerengine-2.0.2-dist.zip'));
GitHubAssetTransport::validateUrl('https://release-assets.githubusercontent.com/github-production-release-asset/opaque?token=xyz');
requireDownloader(true, 'canonical GitHub and CDN paths accepted');

$tmp = sys_get_temp_dir() . '/cajeer-downloader-test-' . bin2hex(random_bytes(8));
$root = $tmp . '/site';
$keys = $tmp . '/private';
mkdir($root . '/config', 0700, true);
mkdir($keys, 0700);
file_put_contents($root . '/VERSION', "2.0.1\n");
$old = getenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE');
try {
    ReleaseSignature::generate($keys . '/private.key', $keys . '/public.key');
    putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE=' . $keys . '/public.key');
    // A real Ed25519-signed ZIP with invalid contents must NEVER become a verified dist.
    $zipFile = $tmp . '/cajeerengine-2.0.2-dist.zip';
    $archive = new PharData($zipFile, 0, null, Phar::ZIP);
    $archive->addFromString('cajeerengine/release-manifest.json', json_encode([
        'format' => 'cajeerengine.release-manifest.v5', 'project' => 'cajeerengine',
        'version' => '2.0.2', 'mode' => 'dist', 'files' => [], 'checksums' => [],
    ], JSON_THROW_ON_ERROR));
    $archive->addFromString('cajeerengine/invalid-payload.txt', str_repeat('x', 1600));
    unset($archive);
    ReleaseSignature::sign($zipFile, $keys . '/private.key');
    $name = basename($zipFile);
    $digest = hash_file('sha256', $zipFile);
    $release = [[
        'tag_name' => 'v2.0.2', 'draft' => false, 'prerelease' => false,
        'published_at' => '2026-11-21T08:00:00Z', 'body' => 'test',
        'assets' => array_map(static fn ($suffix) => [
            'state' => 'uploaded', 'name' => $name . $suffix,
            'size' => $suffix === '' ? filesize($zipFile) : filesize($zipFile . $suffix),
            'digest' => $suffix === '' ? 'sha256:' . $digest : null,
        ], ['', '.sha256', '.sig']),
    ]];
    $catalog = json_encode($release, JSON_THROW_ON_ERROR);
    $monitor = new GitHubReleaseMonitor($root, static fn () => [200, $catalog, ['etag' => '"test-release"']]);
    $config = new ConfigRepository($root);
    $config->set('updates', ['core' => ['releases_url' => GitHubEndpoints::RELEASES_URL, 'channel' => 'stable', 'download_timeout_seconds' => 15]]);
    $downloads = [];
    $shaMode = 'mismatch';
    $transport = static function (string $url, $stream, int $limit, int $timeout) use ($tmp, $name, &$downloads, &$shaMode): int {
        $downloads[] = $url;
        $path = $tmp . '/' . basename(parse_url($url, PHP_URL_PATH));
        $contents = file_get_contents($path);
        if ($shaMode === 'mismatch' && str_ends_with($path, '.sha256')) $contents = str_repeat('0', 64) . '  ' . $name . "\n";
        if (!is_string($contents) || strlen($contents) > $limit) throw new RuntimeException('mock overflow');
        $written = fwrite($stream, $contents);
        if ($written !== strlen($contents)) throw new RuntimeException('mock partial stream');
        return $written;
    };
    $downloader = new GitHubReleaseDownloader($root, $config, $monitor, $transport);
    $fail = false;
    try { $downloader->download(); } catch (RuntimeException $error) { $fail = str_contains($error->getMessage(), 'checksum mismatch'); }
    requireDownloader($fail, 'SHA-256 sidecar mismatch blocks release before publication');
    $final = $root . '/storage/app/updates/downloads/2.0.2';
    requireDownloader(!is_file($final . '/verified.json') && !is_file($final . '/' . $name), 'failed download leaves no ready package');

    $shaMode = 'correct';
    $fail = false;
    try { $downloader->download('2.1.0'); } catch (RuntimeException $error) { $fail = str_contains($error->getMessage(), 'not the latest'); }
    requireDownloader($fail, 'cannot select unadvertised release');
    $fail = false;
    try { $downloader->download(); } catch (RuntimeException $error) { $fail = str_contains($error->getMessage(), 'Signed ZIP release verification failed'); }
    requireDownloader($fail, 'valid Ed25519 signature cannot bypass full dist ZIP verification');
    requireDownloader(!is_file($final . '/verified.json') && !is_file($final . '/' . $name), 'full manifest failure leaves no published archive');
    requireDownloader(count($downloads) === 6, 'both failure scenarios streamed checksum, signature and ZIP assets');

    $missingKey = false;
    putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE=');
    try { $downloader->download(); } catch (RuntimeException $error) { $missingKey = str_contains($error->getMessage(), 'Signed ZIP release verification failed'); }
    requireDownloader($missingKey, 'missing out-of-band trust key blocks release');
} finally {
    $old === false ? putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE') : putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE=' . $old);
    eraseDownloader($tmp);
}
