<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Download a fresh, advertised GitHub production dist and authenticate it before publication. */
final readonly class GitHubReleaseDownloader
{
    /** @param null|\Closure(string,resource,int,int):int $transport */
    public function __construct(
        private string $rootPath,
        private ConfigRepository $config,
        private ?GitHubReleaseMonitor $monitor = null,
        private ?\Closure $transport = null,
    ) {}

    /** @return array<string,mixed> */
    public function download(?string $requestedVersion = null): array
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Release downloads require operator CLI');
        if ($this->config->string('updates.core.releases_url', GitHubEndpoints::RELEASES_URL) !== GitHubEndpoints::RELEASES_URL) {
            throw new \RuntimeException('Remote downloads require the canonical GitHub release catalog');
        }
        $lock = OperationLock::acquire($this->rootPath, false);
        // Use a distinct lock from operations.lock: downloads cannot starve site requests.
        $downloadGuard = null;
        try {
            $directory = OperationFiles::path($this->rootPath, 'storage/app/updates/downloads');
            OperationFiles::dir($directory, 0700);
            $guardPath = OperationFiles::path($this->rootPath, 'storage/app/updates/downloads/.download.lock');
            $downloadGuard = OperationLock::openLockFile($guardPath, 0600);
            if (!is_resource($downloadGuard) || !flock($downloadGuard, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Another release download is in progress');
            }
            if (OperationLock::active($this->rootPath) || is_file($this->rootPath . '/storage/app/backup-restore.json')) {
                throw new \RuntimeException('Recovery in progress; do not download updates');
            }
            $resolver = new UpdateResolver($this->config->get('updates', []), $this->rootPath, $this->monitor);
            $current = trim((string) file_get_contents($this->rootPath . '/VERSION'));
            $check = $resolver->check($current);
            if (!in_array($check['metadata_status'] ?? '', ['fresh', 'not_modified'], true)
                || ($check['metadata_source'] ?? null) !== GitHubEndpoints::RELEASES_URL) {
                throw new \RuntimeException('Fresh GitHub Release metadata required; cached/offline metadata is not install authorization');
            }
            $release = $check['latest'] ?? null;
            if (!is_array($release) || !($check['update_available'] ?? false)) {
                throw new \RuntimeException('No newer release available on selected channel');
            }
            $version = $release['version'] ?? null;
            if (!is_string($version) || ($requestedVersion !== null && $requestedVersion !== $version)) {
                throw new \RuntimeException('Requested release is not the latest eligible GitHub release');
            }
            $asset = $release['artifacts']['dist'] ?? null;
            $name = 'cajeerengine-' . $version . '-dist.zip';
            if (!is_array($asset) || ($asset['name'] ?? null) !== $name
                || ($asset['checksum_name'] ?? null) !== $name . '.sha256'
                || ($asset['signature_name'] ?? null) !== $name . '.sig') {
                throw new \RuntimeException('GitHub release lacks the signed production distribution');
            }
            $tag = 'v' . $version;
            // Some releases use unprefixed SemVer tags. Use only a fixed canonical tag derived
            // from the release source after validating its precise repository URL.
            $source = $release['source'] ?? null;
            if ($source === GitHubEndpoints::releaseUrl($version)) $tag = $version;
            elseif ($source !== GitHubEndpoints::releaseUrl('v' . $version)) {
                throw new \RuntimeException('Unexpected GitHub release identity');
            }
            $urls = [
                'package' => GitHubEndpoints::assetUrl($tag, $name),
                'checksum' => GitHubEndpoints::assetUrl($tag, $name . '.sha256'),
                'signature' => GitHubEndpoints::assetUrl($tag, $name . '.sig'),
            ];
            foreach (['package' => 'download_url', 'checksum' => 'checksum_url', 'signature' => 'signature_url'] as $type => $key) {
                if (($asset[$key] ?? null) !== $urls[$type]) throw new \RuntimeException('Noncanonical GitHub asset location');
            }
            $maxBytes = max(1048576, min(2147483648, $this->config->int('updates.core.download_max_bytes', 2147483648)));
            $declaredSize = $asset['size'] ?? null;
            if (!is_int($declaredSize) || $declaredSize < 1024 || $declaredSize > $maxBytes) {
                throw new \RuntimeException('Invalid or oversized GitHub dist asset');
            }
            $available = disk_free_space($directory);
            if ($available !== false && $available < ($declaredSize * 2 + 33554432)) {
                throw new \RuntimeException('Insufficient disk space for release staging');
            }
            $path = OperationFiles::path($this->rootPath, 'storage/app/updates/downloads/' . $version);
            OperationFiles::dir($path, 0700);
            if (is_link($path)) throw new \RuntimeException('Untrusted release directory');
            $published = $path . '/' . $name;
            $receipt = $path . '/verified.json';
            if (is_file($receipt) && !is_link($receipt) && is_file($published) && !is_link($published)) {
                try {
                    $prior = OperationFiles::read($receipt);
                    $digest = $prior['sha256'] ?? '';
                    if (($prior['version'] ?? null) === $version && is_string($digest)
                        && preg_match('/^[a-f0-9]{64}$/D', $digest)
                        && (!isset($asset['sha256']) || hash_equals($asset['sha256'], $digest))) {
                        $verified = (new UpdateManager($this->rootPath, $this->config))->verifyPackage($published, $digest);
                        if (($verified['ok'] ?? false) && ($verified['version'] ?? '') === $version && ($verified['mode'] ?? '') === 'dist'
                            && $this->checksumMatches($published . '.sha256', $name, $digest)) {
                            return $this->result($version, $published, $verified, true);
                        }
                    }
                } catch (\Throwable) { /* Replace unverified cache only after fetching new verified bytes. */ }
            }
            // An old receipt must never mark partially replaced or invalid bytes as ready.
            if (file_exists($receipt) || is_link($receipt)) OperationFiles::remove($receipt);
            $suffix = bin2hex(random_bytes(12));
            $parts = [
                'package' => $path . '/.' . $suffix . '.zip',
                'checksum' => $path . '/.' . $suffix . '.sha256',
                'signature' => $path . '/.' . $suffix . '.sig',
            ];
            $temporarySignature = $parts['package'] . '.sig';
            try {
                $timeout = max(15, min(1800, $this->config->int('updates.core.download_timeout_seconds', 300)));
                $sizes = [
                    'checksum' => 256,
                    'signature' => 8192,
                    'package' => min($maxBytes, max($declaredSize + 1048576, $declaredSize)),
                ];
                foreach (['checksum', 'signature', 'package'] as $type) {
                    $stream = fopen($parts[$type], 'xb');
                    if (!is_resource($stream)) throw new \RuntimeException('Cannot create download staging file');
                    try {
                        chmod($parts[$type], 0600);
                        $size = $this->transport !== null
                            ? ($this->transport)($urls[$type], $stream, $sizes[$type], $timeout)
                            : (new GitHubAssetTransport())->download($urls[$type], $stream, $sizes[$type], $timeout);
                        if (!is_int($size) || $size < 1 || $size > $sizes[$type]
                            || fstat($stream)['size'] !== $size || !fflush($stream) || !fsync($stream)) {
                            throw new \RuntimeException('Incomplete or oversized GitHub release asset');
                        }
                    } finally { fclose($stream); }
                }
                if (filesize($parts['package']) !== $declaredSize) {
                    throw new \RuntimeException('Downloaded ZIP length differs from GitHub release metadata');
                }
                $sha = OperationFiles::hash($parts['package']);
                if (!$this->checksumMatches($parts['checksum'], $name, $sha)) {
                    throw new \RuntimeException('GitHub release SHA-256 checksum mismatch');
                }
                if (isset($asset['sha256']) && (!is_string($asset['sha256']) || !hash_equals($asset['sha256'], $sha))) {
                    throw new \RuntimeException('GitHub asset digest mismatch');
                }
                OperationFiles::copy($parts['signature'], $temporarySignature);
                $verified = (new UpdateManager($this->rootPath, $this->config))->verifyPackage($parts['package'], $sha);
                if (!($verified['ok'] ?? false) || ($verified['version'] ?? '') !== $version || ($verified['mode'] ?? '') !== 'dist') {
                    throw new \RuntimeException('Signed ZIP release verification failed: ' . ($verified['error'] ?? 'wrong version or mode'));
                }
                // Write sidecars first. Receipt is the final publication commit marker.
                OperationFiles::copy($parts['checksum'], $published . '.sha256');
                OperationFiles::copy($parts['signature'], $published . '.sig');
                OperationFiles::copy($parts['package'], $published);
                OperationFiles::json($receipt, [
                    'format' => 'cajeerengine.verified-release.v1',
                    'version' => $version,
                    'channel' => $check['channel'],
                    'sha256' => $sha,
                    'key_id' => $verified['key_id'],
                    'size' => $declaredSize,
                    'downloaded_at' => gmdate(DATE_ATOM),
                    'source' => GitHubEndpoints::releaseUrl($tag),
                ], 0600);
                return $this->result($version, $published, $verified, false);
            } finally {
                foreach (array_merge(array_values($parts), [$temporarySignature]) as $temp) {
                    if (is_file($temp) && !is_link($temp)) unlink($temp);
                }
            }
        } finally {
            if (is_resource($downloadGuard)) { flock($downloadGuard, LOCK_UN); fclose($downloadGuard); }
            OperationLock::release($lock);
        }
    }

    private function checksumMatches(string $path, string $name, string $digest): bool
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 256) return false;
        $text = file_get_contents($path);
        return is_string($text) && (bool) preg_match('/^([a-f0-9]{64})  ' . preg_quote($name, '/') . '\r?\n?$/D', $text, $match)
            && hash_equals($match[1], $digest);
    }

    /** @param array<string,mixed> $verified @return array<string,mixed> */
    private function result(string $version, string $path, array $verified, bool $cached): array
    {
        return [
            'ok' => true,
            'version' => $version,
            'state' => 'ready_to_install',
            'cached' => $cached,
            'package' => $path,
            'sha256' => $verified['sha256'],
            'key_id' => $verified['key_id'],
            'file_count' => $verified['file_count'],
            'next_command' => 'php bin/cajeer update:apply --package=' . $path . ' --sha256=' . $verified['sha256'],
        ];
    }
}
