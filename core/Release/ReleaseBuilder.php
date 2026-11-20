<?php

declare(strict_types=1);

namespace CajeerEngine\Release;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Update\GitHubEndpoints;

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
            'github' => $this->gitHubArtifactMetadata($version, $mode, $artifact),
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
            $expectedLine = $checksumExists ? trim((string) file_get_contents($checksum)) : '';
            $expected = preg_match('/^[a-f0-9]{64}  [^\s\/]+\.zip$/D', $expectedLine) === 1
                && $expectedLine === ($sha . '  ' . basename($artifact)) ? $sha : '';
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
        $required = array_merge(['VERSION', 'release.json', 'README.md', 'CHANGELOG.md', 'composer.json', '.env.example', 'bin/cajeer', 'public/index.php', 'public/install/index.php', 'public/upgrade/index.php', 'public/upgrade/upgrade.js', 'tools/update-postcheck.php', 'tools/update-recovery.php', 'core/Backup/BackupManager.php', 'core/Update/UpdateManager.php', 'core/Console/NativeCli.php', 'public/admin/index.html', 'public/admin/assets/admin-app.js', 'public/admin/assets/admin-app.css', 'public/admin/assets/admin-manifest.json'], ReleaseFilePolicy::KEEP);
        if (version_compare($this->version(), '1.3.2', '>=')) $required = array_merge($required, ['resources/consent/consent.js','resources/consent/consent.css']);
        if (version_compare($this->version(), '1.3.3', '>=')) $required = array_merge($required, ['core/Privacy/TrackingConfig.php','core/Api/Controller/TrackingController.php']);
        if (version_compare($this->version(), '1.3.4', '>=')) $required = array_merge($required, ['core/Analytics/EventConfig.php','core/Analytics/EventJournal.php','core/Analytics/ServerEvents.php','core/Api/Controller/AnalyticsEventController.php']);
        if (version_compare($this->version(), '1.3.5', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/AnalyticsHub.php','core/Analytics/Reporting/ApiReports.php','core/Analytics/Reporting/ReportProvider.php','core/Api/Controller/AnalyticsHubController.php','migrations/sqlite/0015_analytics_hub.sql','migrations/pgsql/0015_analytics_hub.sql','migrations/mysql/0015_analytics_hub.sql']);
        if (version_compare($this->version(), '1.3.6', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/SearchReports.php','migrations/sqlite/0016_search_webmaster.sql','migrations/pgsql/0016_search_webmaster.sql','migrations/mysql/0016_search_webmaster.sql']);
        if (version_compare($this->version(), '1.3.7', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/AlternativeReports.php']);
        if (version_compare($this->version(), '1.3.9', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/CloudflareReports.php','resources/consent/consent.js']);
        if (version_compare($this->version(), '1.4.0', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/ReportComparison.php','migrations/sqlite/0017_analytics_sync_runs.sql','migrations/pgsql/0017_analytics_sync_runs.sql','migrations/mysql/0017_analytics_sync_runs.sql']);
        if (version_compare($this->version(), '1.4.1', '>=')) $required = array_merge($required, ['core/Migration/MigrationCenter.php','core/Migration/SourceDiscovery.php','core/Migration/WxrDiscovery.php','core/Api/Controller/MigrationController.php','docs/migration-center.md']);
        if (version_compare($this->version(), '1.4.2', '>=')) $required = array_merge($required, ['core/Migration/PlanningSource.php','core/Migration/MigrationPlanner.php','docs/migration-planning.md']);
        if (version_compare($this->version(), '1.4.3', '>=')) $required = array_merge($required, ['core/Migration/DleImporter.php','core/Migration/DleContent.php','core/Migration/DleCli.php','core/Migration/MigrationFactory.php','docs/dle-migration.md','migrations/sqlite/0018_dle_migration.sql','migrations/pgsql/0018_dle_migration.sql','migrations/mysql/0018_dle_migration.sql']);
        if (version_compare($this->version(), '1.4.4', '>=')) $required = array_merge($required, ['core/Migration/ContentMigrationImporter.php','core/Migration/WordPressContent.php','docs/wordpress-migration.md','migrations/sqlite/0019_wordpress_migration.sql','migrations/pgsql/0019_wordpress_migration.sql','migrations/mysql/0019_wordpress_migration.sql']);
        if (version_compare($this->version(), '1.4.5', '>=')) $required = array_merge($required, ['core/Migration/IdentityMigration.php','docs/identity-migration.md','migrations/sqlite/0020_migration_identities.sql','migrations/pgsql/0020_migration_identities.sql','migrations/mysql/0020_migration_identities.sql']);
        if (version_compare($this->version(), '1.4.6', '>=')) $required = array_merge($required, ['core/Migration/MediaMigration.php','core/Migration/MediaCli.php','core/Migration/WordPressMediaMetadata.php','docs/media-attachments-migration.md','migrations/sqlite/0021_migration_media.sql','migrations/pgsql/0021_migration_media.sql','migrations/mysql/0021_migration_media.sql']);
        if (version_compare($this->version(), '1.4.7', '>=')) $required = array_merge($required, ['core/Migration/SeoMigration.php','core/Migration/SeoCli.php','core/Migration/SeoMetadata.php','docs/seo-url-migration.md','migrations/sqlite/0022_seo_url_migration.sql','migrations/pgsql/0022_seo_url_migration.sql','migrations/mysql/0022_seo_url_migration.sql']);
        if (version_compare($this->version(), '1.4.8', '>=')) $required = array_merge($required, ['core/Migration/MigrationExecution.php','core/Migration/MigrationExecutionCli.php','docs/migration-execution-recovery.md','migrations/sqlite/0023_migration_execution.sql','migrations/pgsql/0023_migration_execution.sql','migrations/mysql/0023_migration_execution.sql']);
        if (version_compare($this->version(), '1.4.9', '>=')) $required = array_merge($required, ['core/Migration/MigrationCutover.php','core/Migration/MigrationCutoverCli.php','docs/migration-validation-cutover.md','migrations/sqlite/0024_migration_validation_cutover.sql','migrations/pgsql/0024_migration_validation_cutover.sql','migrations/mysql/0024_migration_validation_cutover.sql']);
        if (version_compare($this->version(), '1.5.0', '>=')) $required = array_merge($required, ['core/Migration/MigrationIntegrity.php','docs/migration-ga.md','migrations/sqlite/0025_migration_redirect_evidence.sql','migrations/pgsql/0025_migration_redirect_evidence.sql','migrations/mysql/0025_migration_redirect_evidence.sql']);
        if (version_compare($this->version(), '1.5.3', '>=')) $required = array_merge($required, ['core/Database/Repository/UserSessionRepository.php','docs/unified-session-management.md','migrations/sqlite/0028_unified_sessions.sql','migrations/pgsql/0028_unified_sessions.sql','migrations/mysql/0028_unified_sessions.sql']);
        if (version_compare($this->version(), '1.5.4', '>=')) $required = array_merge($required, ['core/Identity/OidcClient.php','core/Identity/OidcLogin.php','docs/skif4er-sso-global-logout.md','migrations/sqlite/0029_oidc_global_logout.sql','migrations/pgsql/0029_oidc_global_logout.sql','migrations/mysql/0029_oidc_global_logout.sql']);
        if (version_compare($this->version(), '1.5.5', '>=')) $required = array_merge($required, ['core/Identity/OidcApiAuthenticator.php','docs/oidc-api-authentication.md']);
        if (version_compare($this->version(), '1.8.8', '>=')) $required = array_merge($required, ['core/Rc/OperationsDocsVerifier.php', 'tools/operations-docs-check.php', 'docs/operations-handbook-1.8.8.md', 'docs/operations-deployment-1.8.8.md', 'docs/operations-monitoring-1.8.8.md', 'docs/operations-recovery-1.8.8.md', 'docs/operations-security-1.8.8.md', 'docs/operations-incidents-1.8.8.md', 'docs/operations-acceptance-1.8.8.md']);
        if ($mode === 'source' && version_compare($this->version(), '1.8.8', '>=')) $required[] = 'tools/operations-docs-regression.php';
        if ($mode === 'source' && version_compare($this->version(), '1.9.7', '>=')) {
            $required = array_merge($required, ['tools/compatibility-upgrade-regression.php', 'tools/sdk-client-compatibility-regression.mjs', 'docs/compatibility-upgrade-1.9.7.md']);
        }
        if ($mode === 'source' && version_compare($this->version(), '1.9.8', '>=')) {
            $required = array_merge($required, ['tools/database-recovery-integrity-regression.php', 'tools/e2e-acceptance.sh', 'docs/full-platform-e2e-recovery-1.9.8.md']);
        }
        if ($mode === 'dist') $required = array_merge($required, ['vendor/autoload.php', 'vendor/composer/installed.json', 'composer.lock']);
        else $required = array_merge($required, ['deploy/production.py', 'deploy/install.php', 'deploy/verify.php', 'deploy/host-smoke.sh', 'deploy/test_production.py', 'deploy/site.example.json', 'docs/production-deployment.md']);
        if ($mode === 'source' && version_compare($this->version(), '1.3.0', '>=')) $required = array_merge($required, ['tools/production-acceptance.py','tools/certification-evidence.py','tools/certification-regression.py','tools/rate-limit-concurrency.php','docs/production-certification.md']);
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
            'github' => $plan['github'],
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
                'github' => $artifact['github'] ?? null,
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
    private function gitHubArtifactMetadata(string $version, string $mode, string $artifact): array
    {
        $tag = 'v' . $version;
        $base = GitHubEndpoints::releaseUrl($tag);
        return [
            'release_url' => $base,
            'artifact_name' => $artifact,
            'download_url' => GitHubEndpoints::assetUrl($tag, $artifact),
            'tag' => $tag,
            'checksum_url' => GitHubEndpoints::assetUrl($tag, $artifact . '.sha256'),
            'signature_name' => $artifact . '.sig',
            'signature_url' => GitHubEndpoints::assetUrl($tag, $artifact . '.sig'),
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
        if (!preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/D', $version)) throw new \RuntimeException('Invalid VERSION');
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
