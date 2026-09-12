<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Server\ServerDoctorService;
use CajeerEngine\Stable\StableReleaseService;

final class UpdateManager
{
    public function __construct(private readonly string $rootPath, private readonly ConfigRepository $config)
    {
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $current = $this->currentVersion();
        $check = (new UpdateResolver($this->config->get('updates', []), $this->rootPath))->check($current);
        $latest = is_array($check['latest'] ?? null) ? $check['latest'] : null;
        return $check + [
            'ok' => true,
            'current_version' => $current,
            'latest_version' => is_array($latest) ? ($latest['version'] ?? null) : null,
            'maintenance' => $this->maintenanceState(),
            'safe_update_command' => $latest === null
                ? null
                : 'php bin/cajeer update --package=/path/to/cajeerengine-' . (string) ($latest['version'] ?? 'latest') . '-dist.zip --apply',
        ];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function plan(array $options = []): array
    {
        $check = $this->check();
        $package = $this->stringOption($options, 'package');
        $targetVersion = $this->stringOption($options, 'version') ?: (is_array($check['latest'] ?? null) ? (string) ($check['latest']['version'] ?? '') : '');
        $mode = $this->stringOption($options, 'mode') ?: 'dist';
        $expectedSha = $this->stringOption($options, 'sha256') ?: $this->artifactShaFromLatest($check, $mode);
        $artifactName = $targetVersion !== '' ? 'cajeerengine-' . $targetVersion . '-' . $mode . '.zip' : null;
        $releaseUrl = is_array($check['latest'] ?? null) ? ($check['latest']['source'] ?? $check['latest']['url'] ?? null) : null;

        return [
            'ok' => true,
            'current_version' => $this->currentVersion(),
            'target_version' => $targetVersion !== '' ? $targetVersion : null,
            'update_available' => (bool) ($check['update_available'] ?? false),
            'metadata_source' => $check['metadata_source'] ?? 'none',
            'package' => $package,
            'mode' => $mode,
            'expected_sha256' => $expectedSha,
            'artifact_name' => $artifactName,
            'release_url' => $releaseUrl,
            'backup_before_update' => !($options['no_backup'] ?? false),
            'code_snapshot_before_update' => true,
            'maintenance_mode_during_apply' => !($options['no_maintenance'] ?? false),
            'migrations_after_apply' => !($options['no_migrations'] ?? false),
            'post_update_doctor' => !($options['no_doctor'] ?? false),
            'rollback_command' => 'php bin/cajeer update:rollback --backup=storage/app/updates/rollback/<snapshot>.zip',
            'apply_command' => $package !== null
                ? 'php bin/cajeer update --package=' . $package . ' --apply'
                : ($artifactName !== null ? 'php bin/cajeer update --package=/path/to/' . $artifactName . ' --apply' : null),
            'notes' => [
                'Автоматическое обновление применяет только локальный ZIP или прямой URL на ZIP.',
                'Перед применением создаётся runtime backup и code rollback snapshot.',
                'Файлы .env, installed.lock, public/uploads, SQLite database и storage runtime не перезаписываются.',
                'Downgrade запрещён без --force.',
                'После apply можно выполнить rollback по snapshot, созданному перед копированием файлов.',
            ],
            'checked_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function prepare(array $options = []): array
    {
        $plan = $this->plan($options);
        $dir = $this->rootPath . '/storage/app/updates/plans';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать directory update plans: ' . $dir);
        }
        $path = $dir . '/update-plan-' . date('Ymd-His') . '.json';
        file_put_contents($path, $this->json($plan), LOCK_EX);
        $plan['plan_file'] = $path;
        return $plan;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function apply(array $options = []): array
    {
        $package = $this->stringOption($options, 'package');
        if ($package === null) {
            throw new \RuntimeException('Укажите --package=/path/to/cajeerengine-x.y.z-dist.zip или загрузите ZIP через /upgrade.');
        }
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $noBackup = (bool) ($options['no_backup'] ?? false);
        $force = (bool) ($options['force'] ?? false);
        $noMaintenance = (bool) ($options['no_maintenance'] ?? false);
        $noMigrations = (bool) ($options['no_migrations'] ?? false);
        $noDoctor = (bool) ($options['no_doctor'] ?? false);
        $expectedSha = $this->stringOption($options, 'sha256');
        $packagePath = $this->resolvePackage($package);
        $verify = $this->verifyPackage($packagePath, $expectedSha);
        if (!$force && ($verify['ok'] ?? false) !== true) {
            throw new \RuntimeException('Update package verification failed: ' . (string) ($verify['error'] ?? 'unknown error'));
        }

        $targetVersion = (string) ($verify['manifest']['version'] ?? $verify['version'] ?? '');
        if (!$force && $targetVersion !== '' && version_compare($targetVersion, $this->currentVersion(), '<')) {
            throw new \RuntimeException('Downgrade запрещён без --force: ' . $this->currentVersion() . ' -> ' . $targetVersion);
        }

        $copyPlan = $this->copyPlanFromPackage($packagePath, is_array($verify['manifest'] ?? null) ? $verify['manifest'] : []);
        if ($dryRun) {
            return [
                'ok' => true,
                'dry_run' => true,
                'package' => $packagePath,
                'verification' => $verify,
                'backup_before_update' => !$noBackup,
                'code_snapshot_before_update' => true,
                'maintenance_mode' => !$noMaintenance,
                'migrations_after_apply' => !$noMigrations,
                'post_update_doctor' => !$noDoctor,
                'copy_plan' => $copyPlan,
            ];
        }

        $previousVersion = $this->currentVersion();
        $runtimeBackup = null;
        $rollbackSnapshot = null;
        $maintenanceWasEnabled = (bool) ($this->maintenanceState()['enabled'] ?? false);
        $applied = [];
        $removed = [];
        $migrations = null;
        $doctor = null;

        try {
            if (!$noBackup) {
                $runtimeBackup = (new StableReleaseService($this->rootPath, $this->config))->backup(['include_uploads' => true]);
                if (($runtimeBackup['ok'] ?? false) !== true) {
                    throw new \RuntimeException('Backup before update failed: ' . (string) ($runtimeBackup['error'] ?? 'unknown error'));
                }
            }
            $rollbackSnapshot = $this->createRollbackSnapshot($copyPlan['files'], $copyPlan['removed_files']);
            if (!$noMaintenance && !$maintenanceWasEnabled) {
                $this->enableMaintenance('CajeerEngine обновляется до ' . ($targetVersion ?: 'новой версии') . '.');
            }

            $applied = $this->copyPackageFiles($packagePath, $copyPlan['files']);
            $removed = $this->removePackageFiles($copyPlan['removed_files']);

            if (!$noMigrations) {
                $migrations = $this->runMigrationsAfterUpdate();
            }
            if (!$noDoctor) {
                $doctor = (new ServerDoctorService($this->rootPath))->run(false);
            }
        } catch (\Throwable $e) {
            if (!$noMaintenance && !$maintenanceWasEnabled) { $this->disableMaintenance(); }
            $report = [
                'ok' => false,
                'error' => $e->getMessage(),
                'package' => $packagePath,
                'verification' => $verify,
                'backup' => $runtimeBackup,
                'rollback_snapshot' => $rollbackSnapshot,
                'applied_files' => $applied,
                'removed_files' => $removed,
                'previous_version' => $previousVersion,
                'target_version' => $targetVersion ?: null,
                'failed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
            $this->writeUpdateReport($report);
            throw $e;
        }

        if (!$noMaintenance && !$maintenanceWasEnabled) { $this->disableMaintenance(); }
        $report = [
            'ok' => true,
            'dry_run' => false,
            'package' => $packagePath,
            'verification' => $verify,
            'backup' => $runtimeBackup,
            'rollback_snapshot' => $rollbackSnapshot,
            'applied_files' => $applied,
            'removed_files' => $removed,
            'skipped_files' => $copyPlan['skipped'],
            'migrations' => $migrations,
            'doctor' => $doctor,
            'previous_version' => $previousVersion,
            'target_version' => $targetVersion ?: null,
            'applied_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->writeUpdateReport($report);
        return $report;
    }

    /** @return array<string, mixed> */
    public function rollback(?string $backup = null): array
    {
        $backup = $backup !== null && trim($backup) !== '' ? trim($backup) : $this->lastRollbackSnapshotPath();
        if ($backup === null || $backup === '') {
            throw new \RuntimeException('Rollback snapshot не найден. Укажите --backup=storage/app/updates/rollback/<snapshot>.zip.');
        }
        $path = str_starts_with($backup, '/') ? $backup : $this->rootPath . '/' . ltrim($backup, '/');
        if (!is_file($path)) { throw new \RuntimeException('Rollback snapshot не найден: ' . $path); }
        if (!class_exists(\ZipArchive::class)) { throw new \RuntimeException('PHP ZipArchive extension не установлен.'); }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) { throw new \RuntimeException('Не удалось открыть rollback snapshot: ' . $path); }
        $manifestRaw = $zip->getFromName('rollback-manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : [];
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'cajeerengine.rollback.v1') {
            $zip->close();
            throw new \RuntimeException('Некорректный rollback snapshot manifest.');
        }
        $restored = [];
        foreach ((array) ($manifest['files'] ?? []) as $relative => $checksum) {
            $relative = (string) $relative;
            if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { continue; }
            $content = $zip->getFromName('files/' . $relative);
            if (!is_string($content)) { continue; }
            $target = $this->rootPath . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new \RuntimeException('Не удалось создать directory: ' . dirname($target));
            }
            file_put_contents($target, $content, LOCK_EX);
            $restored[] = $relative;
        }
        $deleted = [];
        foreach ((array) ($manifest['absent_files'] ?? []) as $relative) {
            $relative = (string) $relative;
            if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { continue; }
            $target = $this->rootPath . '/' . $relative;
            if (is_file($target)) { @unlink($target); $deleted[] = $relative; }
        }
        $zip->close();
        $report = ['ok' => true, 'backup' => $path, 'restored_files' => $restored, 'deleted_new_files' => $deleted, 'rolled_back_at' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        $this->writeUpdateReport(['rollback' => $report] + $report);
        return $report;
    }

    /** @return array<string, mixed> */
    public function wizardState(): array
    {
        $check = $this->check();
        return [
            'ok' => true,
            'installed' => is_file($this->rootPath . '/storage/app/installed.lock'),
            'current_version' => $this->currentVersion(),
            'check' => $check,
            'maintenance' => $this->maintenanceState(),
            'last_report' => $this->lastReport(),
            'upload_limit' => ini_get('upload_max_filesize'),
            'post_limit' => ini_get('post_max_size'),
            'zip_extension' => class_exists(\ZipArchive::class),
            'storage_writable' => is_writable($this->rootPath . '/storage/app') || is_writable(dirname($this->rootPath . '/storage/app')),
        ];
    }

    /** @return array<string, mixed> */
    public function maintenanceState(): array
    {
        $path = $this->maintenancePath();
        if (!is_file($path)) { return ['enabled' => false, 'file' => $path]; }
        $decoded = json_decode((string) file_get_contents($path), true);
        return ['enabled' => true, 'file' => $path] + (is_array($decoded) ? $decoded : []);
    }

    /** @return array<string, mixed> */
    public function enableMaintenance(string $message = 'Сайт временно недоступен из-за обслуживания.'): array
    {
        $payload = ['enabled' => true, 'message' => $message, 'since' => (new \DateTimeImmutable())->format(DATE_ATOM), 'version' => $this->currentVersion()];
        $path = $this->maintenancePath();
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0775, true); }
        file_put_contents($path, $this->json($payload), LOCK_EX);
        return ['ok' => true] + $payload + ['file' => $path];
    }

    /** @return array<string, mixed> */
    public function disableMaintenance(): array
    {
        $path = $this->maintenancePath();
        if (is_file($path)) { @unlink($path); }
        return ['ok' => true, 'enabled' => false, 'file' => $path];
    }

    /** @return array<string, mixed> */
    public function verifyPackage(string $packagePath, ?string $expectedSha = null): array
    {
        if (!is_file($packagePath)) { return ['ok' => false, 'error' => 'Package file not found.', 'package' => $packagePath]; }
        if (!class_exists(\ZipArchive::class)) { return ['ok' => false, 'error' => 'PHP ZipArchive extension не установлен.', 'package' => $packagePath]; }
        $sha = hash_file('sha256', $packagePath) ?: '';
        if ($expectedSha !== null && $expectedSha !== '' && !hash_equals(strtolower($expectedSha), strtolower($sha))) {
            return ['ok' => false, 'error' => 'SHA256 mismatch.', 'package' => $packagePath, 'sha256' => $sha, 'expected_sha256' => $expectedSha];
        }
        $zip = new \ZipArchive();
        if ($zip->open($packagePath) !== true) { return ['ok' => false, 'error' => 'Cannot open ZIP package.', 'package' => $packagePath]; }
        $manifestRaw = $zip->getFromName('cajeerengine/release-manifest.json') ?: $zip->getFromName('release-manifest.json');
        if (!is_string($manifestRaw) || trim($manifestRaw) === '') { $zip->close(); return ['ok' => false, 'error' => 'release-manifest.json not found in package.', 'package' => $packagePath, 'sha256' => $sha]; }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) { $zip->close(); return ['ok' => false, 'error' => 'release-manifest.json is invalid JSON.', 'package' => $packagePath, 'sha256' => $sha]; }
        $version = (string) ($manifest['version'] ?? '');
        $project = (string) ($manifest['project'] ?? 'cajeerengine');
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $warnings = [];
        foreach ((array) ($manifest['checksums'] ?? []) as $relative => $expectedFileSha) {
            $zipName = $zip->locateName('cajeerengine/' . (string) $relative) !== false ? 'cajeerengine/' . (string) $relative : (string) $relative;
            $content = $zip->getFromName($zipName);
            if (is_string($content) && !hash_equals((string) $expectedFileSha, hash('sha256', $content))) {
                $warnings[] = 'Checksum mismatch for ' . (string) $relative;
                break;
            }
        }
        if (!isset($manifest['signature'])) { $warnings[] = 'Release manifest не подписан. SHA256 проверен, но detached signature отсутствует.'; }
        $zip->close();
        if ($project !== 'cajeerengine' || $version === '' || $files === []) {
            return ['ok' => false, 'error' => 'Package manifest is incomplete.', 'package' => $packagePath, 'sha256' => $sha, 'manifest' => $manifest];
        }
        return ['ok' => true, 'package' => $packagePath, 'sha256' => $sha, 'manifest' => $manifest, 'version' => $version, 'mode' => $manifest['mode'] ?? 'unknown', 'file_count' => count($files), 'warnings' => $warnings];
    }

    private function resolvePackage(string $package): string
    {
        if (str_starts_with($package, 'http://') || str_starts_with($package, 'https://')) { return $this->downloadPackage($package); }
        return str_starts_with($package, '/') ? $package : $this->rootPath . '/' . $package;
    }

    private function downloadPackage(string $url): string
    {
        $dir = $this->rootPath . '/storage/app/updates/downloads';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Не удалось создать downloads directory: ' . $dir); }
        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'update.zip');
        if (!str_ends_with(strtolower($name), '.zip')) { $name .= '.zip'; }
        $target = $dir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $name);
        $context = stream_context_create(['http' => ['timeout' => 30, 'header' => "Accept: application/zip, application/octet-stream\r\n"]]);
        $contents = @file_get_contents($url, false, $context);
        if (!is_string($contents) || $contents === '') { throw new \RuntimeException('Не удалось скачать update package: ' . $url); }
        file_put_contents($target, $contents, LOCK_EX);
        return $target;
    }

    /** @param array<string,mixed> $manifest @return array{files:list<string>,skipped:list<string>,removed_files:list<string>} */
    private function copyPlanFromPackage(string $packagePath, array $manifest = []): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($packagePath) !== true) { throw new \RuntimeException('Cannot open ZIP package: ' . $packagePath); }
        $manifestFiles = is_array($manifest['files'] ?? null) ? array_values(array_map('strval', $manifest['files'])) : [];
        $files = [];
        $skipped = [];
        if ($manifestFiles !== []) {
            foreach ($manifestFiles as $relative) {
                if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { $skipped[] = $relative; continue; }
                $zipName = $zip->locateName('cajeerengine/' . $relative) !== false ? 'cajeerengine/' . $relative : $relative;
                if ($zip->locateName($zipName) === false) { $skipped[] = $relative . ':missing-in-zip'; continue; }
                $files[] = $relative;
            }
        } else {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($name === '' || str_ends_with($name, '/')) { continue; }
                if ($name === 'cajeerengine/release-manifest.json' || $name === 'release-manifest.json') { continue; }
                $relative = str_starts_with($name, 'cajeerengine/') ? substr($name, strlen('cajeerengine/')) : $name;
                if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { $skipped[] = $relative; continue; }
                $files[] = $relative;
            }
        }
        $zip->close();
        $removed = [];
        foreach ((array) ($manifest['removed_files'] ?? []) as $relative) {
            $relative = (string) $relative;
            if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { $skipped[] = $relative . ':protected-remove'; continue; }
            $removed[] = $relative;
        }
        sort($files, SORT_STRING); sort($skipped, SORT_STRING); sort($removed, SORT_STRING);
        return ['files' => array_values(array_unique($files)), 'skipped' => array_values(array_unique($skipped)), 'removed_files' => array_values(array_unique($removed))];
    }

    /** @param list<string> $files @return list<string> */
    private function copyPackageFiles(string $packagePath, array $files): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($packagePath) !== true) { throw new \RuntimeException('Cannot open ZIP package: ' . $packagePath); }
        $applied = [];
        foreach ($files as $relative) {
            $zipName = $zip->locateName('cajeerengine/' . $relative) !== false ? 'cajeerengine/' . $relative : $relative;
            $content = $zip->getFromName($zipName);
            if (!is_string($content)) { continue; }
            $target = $this->rootPath . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) { throw new \RuntimeException('Не удалось создать directory: ' . dirname($target)); }
            file_put_contents($target, $content, LOCK_EX);
            $applied[] = $relative;
        }
        $zip->close();
        return $applied;
    }

    /** @param list<string> $files @return list<string> */
    private function removePackageFiles(array $files): array
    {
        $removed = [];
        foreach ($files as $relative) {
            if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { continue; }
            $path = $this->rootPath . '/' . $relative;
            if (is_file($path)) { @unlink($path); $removed[] = $relative; }
        }
        return $removed;
    }

    /** @param list<string> $copyFiles @param list<string> $removedFiles @return array<string,mixed>|null */
    private function createRollbackSnapshot(array $copyFiles, array $removedFiles): ?array
    {
        if (!class_exists(\ZipArchive::class)) { return ['ok' => false, 'error' => 'PHP ZipArchive extension не установлен.']; }
        $dir = $this->rootPath . '/storage/app/updates/rollback';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Не удалось создать rollback directory: ' . $dir); }
        $target = $dir . '/rollback-' . date('Ymd-His') . '-' . $this->currentVersion() . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Не удалось создать rollback ZIP: ' . $target); }
        $files = [];
        $absent = [];
        foreach (array_values(array_unique(array_merge($copyFiles, $removedFiles))) as $relative) {
            if ($this->unsafePackagePath($relative) || $this->protectedUpdatePath($relative)) { continue; }
            $path = $this->rootPath . '/' . $relative;
            if (is_file($path)) { $zip->addFile($path, 'files/' . $relative); $files[$relative] = hash_file('sha256', $path) ?: ''; }
            else { $absent[] = $relative; }
        }
        $manifest = ['format' => 'cajeerengine.rollback.v1', 'version' => $this->currentVersion(), 'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'files' => $files, 'absent_files' => $absent];
        $zip->addFromString('rollback-manifest.json', $this->json($manifest));
        $zip->close();
        return ['ok' => true, 'target' => $target, 'size' => filesize($target) ?: 0, 'sha256' => hash_file('sha256', $target) ?: '', 'files' => count($files), 'absent_files' => count($absent)];
    }

    /** @return array<string,mixed>|null */
    private function runMigrationsAfterUpdate(): ?array
    {
        try {
            $database = new DatabaseManager($this->config);
            $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
            return ['ok' => true, 'result' => $runner->run($database->migrationDriver())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function unsafePackagePath(string $relative): bool
    {
        return $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../') || str_contains($relative, '..\\') || $relative === '..';
    }

    private function protectedUpdatePath(string $relative): bool
    {
        if (in_array($relative, ['.env', 'storage/app/installed.lock', 'storage/app/maintenance.json'], true)) { return true; }
        foreach (['public/uploads/', 'storage/database/', 'storage/app/backups/', 'storage/app/support/', 'storage/app/reports/', 'storage/app/updates/', 'storage/logs/', 'storage/cache/', 'storage/tmp/'] as $prefix) {
            if (str_starts_with($relative, $prefix)) { return true; }
        }
        return false;
    }

    /** @param array<string, mixed> $report */
    private function writeUpdateReport(array $report): void
    {
        $dir = $this->rootPath . '/storage/app/updates/reports';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Не удалось создать update reports directory: ' . $dir); }
        file_put_contents($dir . '/update-' . date('Ymd-His') . '.json', $this->json($report), LOCK_EX);
        file_put_contents($this->rootPath . '/storage/app/updates/last-update.json', $this->json($report), LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    private function lastReport(): ?array
    {
        $path = $this->rootPath . '/storage/app/updates/last-update.json';
        if (!is_file($path)) { return null; }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function lastRollbackSnapshotPath(): ?string
    {
        $report = $this->lastReport();
        $snapshot = is_array($report['rollback_snapshot'] ?? null) ? ($report['rollback_snapshot']['target'] ?? null) : null;
        return is_string($snapshot) && $snapshot !== '' ? $snapshot : null;
    }

    /** @param array<string, mixed> $check */
    private function artifactShaFromLatest(array $check, string $mode): ?string
    {
        $latest = is_array($check['latest'] ?? null) ? $check['latest'] : [];
        $artifacts = is_array($latest['artifacts'] ?? null) ? $latest['artifacts'] : [];
        $artifact = is_array($artifacts[$mode] ?? null) ? $artifacts[$mode] : [];
        $sha = $artifact['sha256'] ?? $artifact['checksum'] ?? null;
        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function currentVersion(): string
    {
        return trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: $this->config->string('app.version', '1.1.1');
    }

    private function maintenancePath(): string
    {
        return $this->rootPath . '/storage/app/maintenance.json';
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
