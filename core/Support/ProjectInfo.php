<?php

declare(strict_types=1);

namespace CajeerEngine\Support;

final readonly class ProjectInfo
{
    public function __construct(private string $rootPath)
    {
    }

    public function name(): string
    {
        $release = $this->release();
        return (string) ($release['name'] ?? 'cajeerengine');
    }

    public function title(): string
    {
        return 'CajeerEngine';
    }

    public function version(): string
    {
        $versionFile = $this->rootPath . '/VERSION';
        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        $release = $this->release();
        return (string) ($release['version'] ?? '0.0.0-dev');
    }

    public function engineApiVersion(): string
    {
        $release = $this->release();
        return (string) ($release['engine_api_version'] ?? '0.1');
    }

    /** @return array<string, mixed> */
    public function release(): array
    {
        $path = $this->rootPath . '/release.json';
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @return array<string, mixed> */
    public function composer(): array
    {
        $path = $this->rootPath . '/composer.json';
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }
}
