<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

final readonly class UpdateResolver
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private ?string $rootPath = null, private ?GitHubReleaseMonitor $githubMonitor = null)
    {
    }

    public function channel(): string
    {
        return (string) ($this->config['core']['channel'] ?? 'stable');
    }

    public function releasesUrl(): string
    {
        $value = $this->config['core']['releases_url'] ?? GitHubEndpoints::RELEASES_URL;
        return is_string($value) && $value !== '' ? $value : GitHubEndpoints::RELEASES_URL;
    }

    public function registryUrl(): string
    {
        $value = $this->config['registry']['url'] ?? GitHubEndpoints::REGISTRY_URL;
        return is_string($value) && $value !== '' ? $value : GitHubEndpoints::REGISTRY_URL;
    }

    /** @return array<string, mixed> */
    public function check(string $currentVersion): array
    {
        $metadata = $this->metadata();
        $releases = is_array($metadata['releases'] ?? null) ? $metadata['releases'] : [];
        $channel = $this->channel();
        $latest = null;
        foreach ($releases as $release) {
            if (!is_array($release)) { continue; }
            $releaseChannel = (string) ($release['channel'] ?? 'stable');
            if ($releaseChannel !== $channel && $channel !== 'dev') { continue; }
            $version = (string) ($release['version'] ?? '');
            if (!preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/D', $version)) { continue; }
            // Custom/offline catalogs must not smuggle prereleases into stable,
            // or claim that an RC is a stable artifact.
            $expectedChannel = preg_match('/-rc(?:\.|$)/i', $version) ? 'rc'
                : (preg_match('/-beta(?:\.|$)/i', $version) ? 'beta'
                : (str_contains($version, '-') ? 'dev' : 'stable'));
            if ($releaseChannel !== $expectedChannel ||
                (isset($release['stability']) && $release['stability'] !== $expectedChannel)) {
                continue;
            }
            $dist = $release['artifacts']['dist'] ?? null;
            $artifactName = 'cajeerengine-' . $version . '-dist.zip';
            if (!is_array($dist) || ($dist['name'] ?? null) !== $artifactName ||
                ($dist['checksum_name'] ?? null) !== $artifactName . '.sha256' ||
                ($dist['signature_name'] ?? null) !== $artifactName . '.sig') {
                continue;
            }
            if ($latest === null || version_compare($version, (string) $latest['version'], '>')) {
                $latest = $release;
            }
        }

        $updateAvailable = $latest !== null && version_compare((string) $latest['version'], $currentVersion, '>');
        $result = [
            'current_version' => $currentVersion,
            'channel' => $channel,
            'metadata_source' => $metadata['_source'] ?? 'none',
            'metadata_status' => $metadata['_status'] ?? 'unknown',
            'metadata_fetched_at' => $metadata['_fetched_at'] ?? null,
            'metadata_failure' => $metadata['_failure'] ?? null,
            'retry_at' => $metadata['_retry_at'] ?? null,
            'github_reachable' => $this->releasesUrl() === GitHubEndpoints::RELEASES_URL && in_array($metadata['_status'] ?? '', ['fresh', 'not_modified'], true),
            'metadata_ready' => ($metadata['_source'] ?? 'empty') !== 'empty',
            'checked_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'update_available' => $updateAvailable,
            'latest' => $latest,
            'registry_url' => $this->registryUrl(),
            'signature_required' => (bool) ($this->config['registry']['signature_required'] ?? true),
        ];
        $this->writeLastCheck($result);
        if ($this->rootPath !== null && $result['github_reachable'] && $updateAvailable) {
            // Deduplicated across manual checks and periodic discovery.
            (new UpdateHistory($this->rootPath))->record('update.available', [
                'version'=>$latest['version'], 'previous_version'=>$currentVersion, 'channel'=>$channel,
            ], 'release:'.$channel.':'.$latest['version']);
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function diagnostics(string $currentVersion): array
    {
        $last = $this->lastCheck();
        // Diagnostics are read-only: opening the admin panel must not issue GitHub requests.
        $metadata = $this->metadata(false);
        return [
            'channel' => $this->channel(),
            'current_version' => $currentVersion,
            'releases_url' => $this->releasesUrl(),
            'registry_url' => $this->registryUrl(),
            'signature_required' => (bool) ($this->config['registry']['signature_required'] ?? true),
            'metadata_source' => $metadata['_source'] ?? 'empty',
            'metadata_status' => $last['metadata_status'] ?? $metadata['_status'] ?? 'unknown',
            'metadata_failure' => $last['metadata_failure'] ?? null,
            'retry_at' => $last['retry_at'] ?? null,
            'metadata_fetched_at' => $metadata['_fetched_at'] ?? null,
            'last_checked_at' => $last['checked_at'] ?? null,
            'github_reachable' => $last['github_reachable'] ?? null,
            'metadata_ready' => ($metadata['_source'] ?? 'empty') !== 'empty',
            'release_count' => is_array($metadata['releases'] ?? null) ? count($metadata['releases']) : 0,
            'last_check' => $last,
            'local_metadata_file' => $this->localMetadataPath(),
            'bundled_metadata_file' => $this->bundledMetadataPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(bool $remote = true): array
    {
        $url = $this->releasesUrl();
        if ($url === GitHubEndpoints::RELEASES_URL) {
            $monitor = $this->githubMonitor ?? new GitHubReleaseMonitor($this->rootPath, null, (int) ($this->config['core']['timeout'] ?? 5));
            $data = $remote ? $monitor->fetch() : $monitor->snapshot();
            if ($data !== null && ($data['_source'] ?? '') !== 'empty') return $data;
            if ($remote && $data !== null && ($data['_status'] ?? '') === 'unavailable') {
                $failure = $data['_failure'] ?? null;
                $retryAt = $data['_retry_at'] ?? null;
            }
        } elseif ($remote) {
            $data = $this->readJson($url);
            if ($data !== null) { $data['_source'] = $url; $data['_status'] = 'fresh'; return $data; }
            $failure = 'network_error';
        }

        foreach ([$this->localMetadataPath(), $this->bundledMetadataPath()] as $path) {
            if ($path !== null && is_file($path)) {
                $data = $this->readJson($path);
                if ($data !== null) {
                    $data['_source'] = $path;
                    $data['_status'] = 'fallback';
                    $data['_failure'] = $failure ?? null;
                    $data['_retry_at'] = $retryAt ?? null;
                    return $data;
                }
            }
        }

        return ['releases' => [], '_source' => 'empty', '_status' => 'unavailable', '_failure' => $failure ?? null, '_retry_at' => $retryAt ?? null];
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $source, bool $github = false): ?array
    {
        $contents = null;
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            try {
                $headers = $github
                    ? ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'CajeerEngine-Updater', 'X-GitHub-Api-Version' => '2022-11-28']
                    : ['Accept' => 'application/json'];
                [$status, $body] = (new \CajeerEngine\Security\PublicHttpClient())->request($source, 'GET', $headers, '', 4 * 1024 * 1024);
                if ($status >= 200 && $status < 300) { $contents = $body; }
            } catch (\Throwable) { /* Fall back to bundled metadata; never follow redirects. */ }
        } elseif (is_file($source)) {
            $contents = @file_get_contents($source);
        }
        if (!is_string($contents) || trim($contents) === '') { return null; }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) return null;
        if ($github) return str_starts_with(ltrim($contents), '[') ? GitHubReleaseCatalog::normalize($decoded) : null;
        if (!is_array($decoded['releases'] ?? null)) return null;
        return $decoded;
    }

    private function localMetadataPath(): ?string
    {
        return $this->rootPath === null ? null : $this->rootPath . '/storage/app/updates/releases.json';
    }

    private function bundledMetadataPath(): ?string
    {
        return $this->rootPath === null ? null : $this->rootPath . '/resources/updates/releases.json';
    }

    /** @param array<string, mixed> $result */
    private function writeLastCheck(array $result): void
    {
        if ($this->rootPath === null) { return; }
        $path = OperationFiles::path($this->rootPath, 'storage/app/updates/last-check.json');
        (new \CajeerEngine\Support\JsonFile($path))->writeObject($result);
    }

    /** @return array<string, mixed>|null */
    private function lastCheck(): ?array
    {
        if ($this->rootPath === null) { return null; }
        try {
            $path = OperationFiles::path($this->rootPath, 'storage/app/updates/last-check.json');
            $decoded = (new \CajeerEngine\Support\JsonFile($path))->readObject();
            return $decoded === [] ? null : $decoded;
        } catch (\Throwable) { return null; }
    }
}
