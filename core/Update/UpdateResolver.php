<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

final readonly class UpdateResolver
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private ?string $rootPath = null)
    {
    }

    public function channel(): string
    {
        return (string) ($this->config['core']['channel'] ?? 'stable');
    }

    public function releasesUrl(): ?string
    {
        $value = $this->config['core']['releases_url'] ?? GitFlicEndpoints::RELEASES_URL;
        return is_string($value) && $value !== '' ? $value : GitFlicEndpoints::RELEASES_URL;
    }

    public function registryUrl(): ?string
    {
        $value = $this->config['registry']['url'] ?? GitFlicEndpoints::REGISTRY_URL;
        return is_string($value) && $value !== '' ? $value : GitFlicEndpoints::REGISTRY_URL;
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
            if ($version === '') { continue; }
            if ($latest === null || version_compare($version, (string) $latest['version'], '>')) {
                $latest = $release;
            }
        }

        $updateAvailable = $latest !== null && version_compare((string) $latest['version'], $currentVersion, '>');
        $result = [
            'current_version' => $currentVersion,
            'channel' => $channel,
            'metadata_source' => $metadata['_source'] ?? 'none',
            'metadata_ready' => ($metadata['_source'] ?? 'empty') !== 'empty',
            'checked_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'update_available' => $updateAvailable,
            'latest' => $latest,
            'registry_url' => $this->registryUrl(),
            'signature_required' => (bool) ($this->config['registry']['signature_required'] ?? true),
        ];
        $this->writeLastCheck($result);
        return $result;
    }

    /** @return array<string, mixed> */
    public function diagnostics(string $currentVersion): array
    {
        $last = $this->lastCheck();
        $metadata = $this->metadata();
        return [
            'channel' => $this->channel(),
            'current_version' => $currentVersion,
            'releases_url' => $this->releasesUrl(),
            'registry_url' => $this->registryUrl(),
            'signature_required' => (bool) ($this->config['registry']['signature_required'] ?? true),
            'metadata_source' => $metadata['_source'] ?? 'empty',
            'metadata_ready' => ($metadata['_source'] ?? 'empty') !== 'empty',
            'release_count' => is_array($metadata['releases'] ?? null) ? count($metadata['releases']) : 0,
            'last_check' => $last,
            'local_metadata_file' => $this->localMetadataPath(),
            'bundled_metadata_file' => $this->bundledMetadataPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $url = $this->releasesUrl();
        if ($url !== null) {
            $data = $this->readJson($url);
            if ($data !== null) { $data['_source'] = $url; return $data; }
        }

        foreach ([$this->localMetadataPath(), $this->bundledMetadataPath()] as $path) {
            if ($path !== null && is_file($path)) {
                $data = $this->readJson($path);
                if ($data !== null) { $data['_source'] = $path; return $data; }
            }
        }

        return ['releases' => [], '_source' => 'empty'];
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $source): ?array
    {
        $contents = null;
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            $context = stream_context_create(['http' => ['timeout' => (int) ($this->config['core']['timeout'] ?? 5), 'header' => "Accept: application/json\r\n"]]);
            $contents = @file_get_contents($source, false, $context);
        } elseif (is_file($source)) {
            $contents = @file_get_contents($source);
        }
        if (!is_string($contents) || trim($contents) === '') { return null; }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !is_array($decoded['releases'] ?? null)) { return null; }
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
        $path = $this->rootPath . '/storage/app/updates/last-check.json';
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0775, true); }
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    private function lastCheck(): ?array
    {
        if ($this->rootPath === null) { return null; }
        $path = $this->rootPath . '/storage/app/updates/last-check.json';
        if (!is_file($path)) { return null; }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }
}
