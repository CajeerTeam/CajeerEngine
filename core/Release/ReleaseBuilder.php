<?php

declare(strict_types=1);

namespace CajeerEngine\Release;

final readonly class ReleaseBuilder
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function plan(bool|string $distOrMode = false, ?string $targetDir = null): array
    {
        $mode = $this->normalizeMode($distOrMode);
        $files = $this->files($mode);
        $version = $this->version();
        $artifact = 'cajeerengine-' . $version . '-' . $mode . '.zip';
        $target = ($targetDir !== null && $targetDir !== '' ? $this->absolutePath($targetDir) : $this->rootPath . '/storage/releases') . '/' . $artifact;
        return [
            'ok' => true,
            'version' => $version,
            'mode' => $mode,
            'artifact' => $artifact,
            'target' => $target,
            'checksum_target' => $target . '.sha256',
            'manifest_target' => dirname($target) . '/' . pathinfo($artifact, PATHINFO_FILENAME) . '.manifest.json',
            'files' => $files,
            'file_count' => count($files),
            'excluded' => $this->excludeDirectories($mode),
            'dist_ready' => $mode !== 'dist' || $this->distReady(),
            'dist_requirements' => $this->distRequirements(),
            'release_requirements' => $this->releaseRequirements($mode),
            'gitflic' => $this->gitFlicArtifactMetadata($version, $mode, $artifact),
        ];
    }

    /** @return array<string, mixed> */
    public function build(bool $dryRun = false, bool|string $distOrMode = false, ?string $targetDir = null): array
    {
        $mode = $this->normalizeMode($distOrMode);
        $plan = $this->plan($mode, $targetDir);
        if ($dryRun) {
            $plan['dry_run'] = true;
            return $plan;
        }
        if ($mode === 'dist' && !$this->distReady()) {
            throw new \RuntimeException('Dist release требует vendor/autoload.php, composer.lock и собранные public/admin/assets. Соберите dist на release host, а не на production-сервере.');
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension не установлен.');
        }
        $target = (string) $plan['target'];
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Не удалось создать release directory: ' . dirname($target));
        }
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось открыть ZIP для записи: ' . $target);
        }
        foreach ($plan['files'] as $relative) {
            $zip->addFile($this->rootPath . '/' . $relative, 'cajeerengine/' . $relative);
        }
        $manifest = $this->manifest($plan);
        $zip->addFromString('cajeerengine/release-manifest.json', $this->json($manifest));
        $zip->close();

        $sha256 = hash_file('sha256', $target) ?: '';
        $checksumLine = $sha256 . '  ' . basename($target) . PHP_EOL;
        file_put_contents((string) $plan['checksum_target'], $checksumLine, LOCK_EX);
        file_put_contents((string) $plan['manifest_target'], $this->json($manifest + ['artifact_sha256' => $sha256]), LOCK_EX);

        $plan['dry_run'] = false;
        $plan['size'] = filesize($target) ?: 0;
        $plan['sha256'] = $sha256;
        $plan['checksum_file'] = (string) $plan['checksum_target'];
        $plan['manifest_file'] = (string) $plan['manifest_target'];
        return $plan;
    }

    /** @return array<string, mixed> */
    public function buildAll(bool $dryRun = false, ?string $targetDir = null): array
    {
        $source = $this->build($dryRun, 'source', $targetDir);
        $dist = null;
        $distError = null;
        try {
            $dist = $this->build($dryRun, 'dist', $targetDir);
        } catch (\Throwable $e) {
            $distError = $e->getMessage();
        }
        $artifacts = array_values(array_filter([$source, $dist], static fn (mixed $item): bool => is_array($item)));
        $index = $this->writeArtifactIndex($artifacts, $dryRun, $targetDir);
        return [
            'ok' => $distError === null,
            'version' => $this->version(),
            'dry_run' => $dryRun,
            'source' => $source,
            'dist' => $dist,
            'dist_error' => $distError,
            'artifact_index' => $index,
        ];
    }

    /** @return array<string, mixed> */
    public function verifyArtifacts(?string $targetDir = null): array
    {
        $dir = $targetDir !== null && $targetDir !== '' ? $this->absolutePath($targetDir) : $this->rootPath . '/storage/releases';
        $checks = [];
        foreach (['source', 'dist'] as $mode) {
            $plan = $this->plan($mode, $dir);
            $artifact = (string) $plan['target'];
            $checksum = $artifact . '.sha256';
            $exists = is_file($artifact);
            $checksumExists = is_file($checksum);
            $sha = $exists ? (hash_file('sha256', $artifact) ?: '') : '';
            $expected = $checksumExists ? trim(explode(' ', trim((string) file_get_contents($checksum)))[0] ?? '') : '';
            $checks[$mode] = [
                'artifact' => $artifact,
                'exists' => $exists,
                'checksum_file' => $checksum,
                'checksum_exists' => $checksumExists,
                'sha256' => $sha,
                'expected_sha256' => $expected,
                'valid' => $exists && $checksumExists && $sha !== '' && hash_equals($expected, $sha),
            ];
        }
        return ['ok' => !in_array(false, array_column($checks, 'valid'), true), 'version' => $this->version(), 'checks' => $checks];
    }

    /** @return list<string> */
    private function files(string $mode): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) { continue; }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->rootPath) + 1));
            if ($this->excluded($relative, $mode)) { continue; }
            $files[] = $relative;
        }
        foreach ($this->runtimeKeepFiles() as $keepFile) {
            if (is_file($this->rootPath . '/' . $keepFile) && !in_array($keepFile, $files, true)) {
                $files[] = $keepFile;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function excluded(string $relative, string $mode): bool
    {
        $basename = basename($relative);
        if (in_array($basename, ['.env'], true)) { return true; }
        if (preg_match('/^cajeerengine[0-9]+_changed_files_manifest\.json$/', $basename) === 1) { return true; }
        if (preg_match('/^cajeerengine-[0-9.]+-(source|dist|artifacts).*\.(zip|json|sha256)$/', $basename) === 1) { return true; }
        if (str_ends_with($relative, '.log') || str_ends_with($relative, '.tmp')) { return true; }
        if (str_starts_with($relative, 'storage/app/updates/') && $relative !== 'storage/app/updates/.gitkeep') { return true; }
        foreach ($this->excludeDirectories($mode) as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded . '/')) { return true; }
        }
        if ($mode === 'dist' && str_starts_with($relative, 'admin/') && !str_starts_with($relative, 'admin/package') && !str_ends_with($relative, 'pnpm-lock.yaml')) {
            return true;
        }
        return false;
    }

    /** @return list<string> */
    private function excludeDirectories(string $mode = 'source'): array
    {
        $dirs = [
            '.git',
            '.idea',
            '.vscode',
            'node_modules',
            'admin/node_modules',
            'storage/releases',
            'storage/cache',
            'storage/logs',
            'storage/tmp',
            'storage/static-export',
            'storage/queue',
            'storage/rate-limits',
            'storage/app/backups',
            'storage/app/support',
            'storage/app/reports',
            'storage/app/updates/downloads',
            'storage/app/updates/extracted',
            'storage/app/updates/plans',
            'storage/app/updates/rollback',
            'storage/app/updates/uploads',
            'storage/app/updates/reports',
        ];
        if ($mode === 'source') {
            $dirs[] = 'vendor';
            return $dirs;
        }
        return array_merge($dirs, ['admin', 'tests', 'tools/dev-only', 'examples', 'wiki', 'packages']);
    }

    /** @return list<string> */
    private function runtimeKeepFiles(): array
    {
        return [
            'storage/.gitkeep',
            'storage/app/.gitkeep',
            'storage/cache/.gitkeep',
            'storage/database/.gitkeep',
            'storage/logs/.gitkeep',
            'storage/tmp/.gitkeep',
            'bootstrap/cache/.gitkeep',
            'public/uploads/.gitkeep',
            'plugins/.gitkeep',
            'themes/.gitkeep',
        ];
    }

    /** @return array<string, bool> */
    private function distRequirements(): array
    {
        return [
            'vendor_autoload' => is_file($this->rootPath . '/vendor/autoload.php'),
            'composer_lock' => is_file($this->rootPath . '/composer.lock'),
            'admin_prebuilt_assets' => is_dir($this->rootPath . '/public/admin/assets') && count(glob($this->rootPath . '/public/admin/assets/*') ?: []) > 0,
            'env_example' => is_file($this->rootPath . '/.env.example'),
            'cli' => is_file($this->rootPath . '/bin/cajeer'),
        ];
    }

    private function distReady(): bool
    {
        return !in_array(false, $this->distRequirements(), true);
    }

    /** @return array<string, mixed> */
    private function releaseRequirements(string $mode): array
    {
        $required = ['VERSION', 'release.json', 'README.md', 'CHANGELOG.md', 'bin/cajeer', 'public/index.php', 'public/install/index.php', 'core/Console/NativeCli.php'];
        if ($mode === 'dist') {
            $required[] = 'vendor/autoload.php';
            $required[] = 'composer.lock';
            $required[] = 'public/admin/assets/admin-app.js';
        }
        $checks = [];
        foreach ($required as $file) {
            $checks[$file] = is_file($this->rootPath . '/' . $file);
        }
        return ['required_files' => $checks, 'ready' => !in_array(false, $checks, true)];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function manifest(array $plan): array
    {
        return [
            'format' => 'cajeerengine.release-manifest.v4',
            'project' => 'cajeerengine',
            'version' => $plan['version'],
            'mode' => $plan['mode'],
            'artifact' => $plan['artifact'],
            'built_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'php' => '>=8.4.1 <9.0',
            'engine_api_version' => '1.0',
            'install' => [
                'web' => '/install',
                'cli' => 'php bin/cajeer install --interactive',
            ],
            'upgrade' => [
                'web' => '/upgrade',
                'cli' => 'php bin/cajeer update --package=/path/to/' . (string) $plan['artifact'] . ' --apply',
                'backup_before_update' => true,
            ],
            'files' => $plan['files'],
            'checksums' => $this->checksums($plan['files']),
            'removed_files' => [],
            'dist_requirements' => $plan['dist_requirements'],
            'gitflic' => $plan['gitflic'],
        ];
    }

    /** @param list<string> $files @return array<string, string> */
    private function checksums(array $files): array
    {
        $result = [];
        foreach ($files as $file) {
            $result[$file] = hash_file('sha256', $this->rootPath . '/' . $file) ?: '';
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $artifacts @return array<string, mixed> */
    private function writeArtifactIndex(array $artifacts, bool $dryRun, ?string $targetDir): array
    {
        $dir = $targetDir !== null && $targetDir !== '' ? $this->absolutePath($targetDir) : $this->rootPath . '/storage/releases';
        $index = [
            'format' => 'cajeerengine.release-artifacts.v1',
            'version' => $this->version(),
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'artifacts' => array_map(static fn (array $artifact): array => [
                'mode' => $artifact['mode'] ?? null,
                'name' => $artifact['artifact'] ?? basename((string) ($artifact['target'] ?? '')),
                'path' => $artifact['target'] ?? null,
                'sha256' => $artifact['sha256'] ?? null,
                'size' => $artifact['size'] ?? null,
                'checksum_file' => $artifact['checksum_file'] ?? $artifact['checksum_target'] ?? null,
                'gitflic' => $artifact['gitflic'] ?? null,
            ], $artifacts),
        ];
        $path = $dir . '/cajeerengine-' . $this->version() . '-artifacts.json';
        if (!$dryRun) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Не удалось создать release directory: ' . $dir);
            }
            file_put_contents($path, $this->json($index), LOCK_EX);
        }
        return ['path' => $path, 'dry_run' => $dryRun, 'data' => $index];
    }

    /** @return array<string, string> */
    private function gitFlicArtifactMetadata(string $version, string $mode, string $artifact): array
    {
        $base = 'https://gitflic.ru/project/cajeerteam/cajeerengine/release/' . $version;
        return [
            'release_url' => $base,
            'artifact_name' => $artifact,
            'download_url' => $base . '/download/' . $artifact,
            'checksum_name' => $artifact . '.sha256',
        ];
    }

    private function normalizeMode(bool|string $distOrMode): string
    {
        if (is_bool($distOrMode)) { return $distOrMode ? 'dist' : 'source'; }
        $mode = strtolower($distOrMode);
        return in_array($mode, ['source', 'dist'], true) ? $mode : 'source';
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : $this->rootPath . '/' . trim($path, '/');
    }

    private function version(): string
    {
        return trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1';
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
