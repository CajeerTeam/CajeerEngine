<?php

declare(strict_types=1);

namespace CajeerEngine\Release;

use CajeerEngine\Admin\AdminAssetPublisher;

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
        if (!$plan['release_requirements']['ready'] || ($mode === 'dist' && !$plan['dist_ready'])) {
            throw new \RuntimeException('Release prerequisites failed: ' . $this->json($plan['release_requirements'] + $plan['dist_requirements']));
        }
        $target = (string) $plan['target'];
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Не удалось создать release directory: ' . dirname($target));
        }
        $lock = fopen($target . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock release output');
        $temp = dirname($target) . '/.release-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $manifest = $this->manifest($plan);
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) throw new \RuntimeException('Cannot create ZIP');
                foreach ($plan['files'] as $relative) {
                    $name = 'cajeerengine/' . $relative;
                    if (!$zip->addFile($this->rootPath . '/' . $relative, $name) || !$zip->setMtimeName($name, 315532800) || !$zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, (0100000 | ($relative === 'bin/cajeer' ? 0755 : 0644)) << 16) || !$zip->setCompressionName($name, \ZipArchive::CM_DEFLATE, 6)) throw new \RuntimeException('Cannot add ' . $relative);
                }
                $name = 'cajeerengine/release-manifest.json';
                if (!$zip->addFromString($name, $this->json($manifest)) || !$zip->setMtimeName($name, 315532800) || !$zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, 0100644 << 16) || !$zip->setCompressionName($name, \ZipArchive::CM_DEFLATE, 6) || !$zip->close()) throw new \RuntimeException('Cannot finalize ZIP');
            } else {
                $zip = new \PharData($temp, 0, null, \Phar::ZIP);
                foreach ($plan['files'] as $relative) {
                    $name = 'cajeerengine/' . $relative;
                    $zip->addFile($this->rootPath . '/' . $relative, $name);
                    $zip[$name]->chmod($relative === 'bin/cajeer' ? 0755 : 0644);
                }
                $zip->addFromString('cajeerengine/release-manifest.json', $this->json($manifest));
                $zip['cajeerengine/release-manifest.json']->chmod(0644);
                $zip->compressFiles(\Phar::GZ);
            }
            unset($zip);
            (new ReleaseArtifactVerifier())->verify($temp, $mode, $plan['version']);
            $sha256 = hash_file('sha256', $temp);
            if ($sha256 === false || !rename($temp, $target)) throw new \RuntimeException('Cannot publish release archive');
            $this->atomicWrite((string) $plan['checksum_target'], $sha256 . '  ' . basename($target) . PHP_EOL);
            $this->atomicWrite((string) $plan['manifest_target'], $this->json($manifest + ['artifact_sha256' => $sha256]));
        } finally {
            unset($zip);
            if (is_file($temp)) unlink($temp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

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

    public function verifyRelease(string $mode, bool $strict = false, ?string $artifact = null): array
    {
        $mode = $this->normalizeMode($mode);
        if ($artifact !== null) {
            try {
                $manifest = (new ReleaseArtifactVerifier())->verify($artifact, $mode, $this->version());
                $sha = hash_file('sha256', $artifact);
                $expected = trim((string) @file_get_contents($artifact . '.sha256'));
                $sidecar = json_decode((string) @file_get_contents(substr($artifact, 0, -4) . '.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                if ($expected !== $sha . '  ' . basename($artifact) || $sidecar !== $manifest + ['artifact_sha256' => $sha]) throw new \RuntimeException('Artifact sidecar mismatch');
                if ($strict && !in_array('composer.lock', $manifest['files'], true)) throw new \RuntimeException('Missing composer.lock');
                return ['ok' => true, 'mode' => $mode, 'artifact' => $artifact, 'sha256' => $sha, 'file_count' => count($manifest['files'])];
            } catch (\Throwable $e) { return ['ok' => false, 'mode' => $mode, 'error' => $e->getMessage()]; }
        }
        $plan = $this->plan($mode);
        $checks = $plan['release_requirements']['required_files'];
        if ($mode === 'dist') $checks += $plan['dist_requirements'];
        if ($strict) $checks['composer_lock'] = $plan['dist_requirements']['composer_lock'];
        return ['ok' => !in_array(false, $checks, true), 'mode' => $mode, 'checks' => $checks, 'fail' => count(array_filter($checks, static fn ($v) => !$v))];
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
            if ($checks[$mode]['valid']) {
                try {
                    $manifest = (new ReleaseArtifactVerifier())->verify($artifact, $mode, $this->version());
                    $sidecar = json_decode((string) file_get_contents($plan['manifest_target']), true, 512, JSON_THROW_ON_ERROR);
                    if ($sidecar !== $manifest + ['artifact_sha256' => $sha]) throw new \RuntimeException('Sidecar manifest mismatch');
                } catch (\Throwable $e) { $checks[$mode]['valid'] = false; $checks[$mode]['error'] = $e->getMessage(); }
            }
        }
        return ['ok' => !in_array(false, array_column($checks, 'valid'), true), 'version' => $this->version(), 'checks' => $checks];
    }

    /** @return list<string> */
    private function files(string $mode): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function (\SplFileInfo $file) use ($mode): bool {
            $relative = substr($file->getPathname(), strlen($this->rootPath) + 1);
            if ($file->isLink() && (ReleaseFilePolicy::allows($relative, $mode) || ReleaseFilePolicy::traverses($relative, $mode))) throw new \RuntimeException('Symlink not allowed in release: ' . $relative);
            return $file->isDir() ? ReleaseFilePolicy::traverses($relative, $mode) : ReleaseFilePolicy::allows($relative, $mode);
        });
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->rootPath) + 1);
            if (!ReleaseFilePolicy::allows($relative, $mode)) continue;
            if ($file->isLink()) throw new \RuntimeException('Symlink not allowed in release: ' . $relative);
            if (!$file->isFile()) continue;
            if (in_array($relative, ReleaseFilePolicy::KEEP, true) && $file->getSize() !== 0) throw new \RuntimeException('Runtime marker is not empty: ' . $relative);
            $files[] = $relative;
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function excludeDirectories(string $mode): array
    {
        return array_merge(['all paths outside ReleaseFilePolicy', 'runtime data except explicit empty markers', 'user-installed extensions'], $mode === 'dist' ? ['admin', 'tests', 'packages', 'examples', '.github'] : ['vendor']);
    }

    /** @return array<string, bool> */
    private function distRequirements(): array
    {
        return [
            'vendor_autoload' => is_file($this->rootPath . '/vendor/autoload.php'),
            'composer_lock' => is_file($this->rootPath . '/composer.lock'),
            'admin_prebuilt_assets' => (new AdminAssetPublisher($this->rootPath))->manifest()['ready'],
            'locked_vendor' => $this->lockedVendorReady(),
            'env_example' => is_file($this->rootPath . '/.env.example'),
            'cli' => is_file($this->rootPath . '/bin/cajeer'),
        ];
    }

    private function distReady(): bool
    {
        return !in_array(false, $this->distRequirements(), true);
    }

    private function lockedVendorReady(): bool
    {
        try {
            $lock = (string) @file_get_contents($this->rootPath . '/composer.lock');
            $installed = (string) @file_get_contents($this->rootPath . '/vendor/composer/installed.json');
            $packages = ReleaseDependencyInventory::validate($lock, $installed);
            foreach ($packages as $package) if (!is_file($this->rootPath . '/vendor/' . $package . '/composer.json')) return false;
            foreach (ReleaseDependencyInventory::AUTOLOAD_FILES as $file) if (!is_file($this->rootPath . '/' . $file)) return false;
            return true;
        } catch (\Throwable) { return false; }
    }

    /** @return array<string, mixed> */
    private function releaseRequirements(string $mode): array
    {
        $required = array_merge(['VERSION', 'release.json', 'README.md', 'CHANGELOG.md', 'composer.json', '.env.example', 'bin/cajeer', 'public/index.php', 'public/install/index.php', 'core/Console/NativeCli.php', 'public/admin/index.html', 'public/admin/assets/admin-app.js', 'public/admin/assets/admin-app.css', 'public/admin/assets/admin-manifest.json'], ReleaseFilePolicy::KEEP);
        if ($mode === 'dist') $required = array_merge($required, ['vendor/autoload.php', 'vendor/composer/installed.json', 'composer.lock']);
        $checks = [];
        foreach ($required as $file) $checks[$file] = is_file($this->rootPath . '/' . $file) && !is_link($this->rootPath . '/' . $file);
        foreach (['pgsql', 'mysql', 'sqlite'] as $driver) $checks['migrations/' . $driver] = count(glob($this->rootPath . '/migrations/' . $driver . '/*.sql') ?: []) > 0;
        $checks['admin.integrity'] = (new AdminAssetPublisher($this->rootPath))->manifest()['ready'];
        return ['required_files' => $checks, 'ready' => !in_array(false, $checks, true)];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function manifest(array $plan): array
    {
        return [
            'format' => 'cajeerengine.release-manifest.v5',
            'project' => 'cajeerengine',
            'version' => $plan['version'],
            'mode' => $plan['mode'],
            'artifact' => $plan['artifact'],
            'built_at' => gmdate(DATE_ATOM, $this->buildEpoch()),
            'php' => '>=8.4.1 <9.0',
            'engine_api_version' => '1.1',
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
            'dist_requirements' => $plan['mode'] === 'dist' ? $plan['dist_requirements'] : [],
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
            'generated_at' => gmdate(DATE_ATOM, $this->buildEpoch()),
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
            $this->atomicWrite($path, $this->json($index));
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
        if (!in_array($mode, ['source', 'dist'], true)) throw new \InvalidArgumentException('Invalid release mode');
        return $mode;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : $this->rootPath . '/' . trim($path, '/');
    }

    private function version(): string
    {
        $version = trim((string) @file_get_contents($this->rootPath . '/VERSION'));
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.]+)?$/D', $version)) throw new \RuntimeException('Invalid VERSION');
        return $version;
    }

    private function buildEpoch(): int
    {
        $value = getenv('SOURCE_DATE_EPOCH');
        return $value !== false && ctype_digit($value) ? (int) $value : 315532800;
    }

    private function atomicWrite(string $path, string $body): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !rename($tmp, $path)) throw new \RuntimeException('Cannot publish ' . $path);
        } finally { if (is_file($tmp)) unlink($tmp); }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
