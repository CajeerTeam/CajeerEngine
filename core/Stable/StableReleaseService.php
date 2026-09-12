<?php

declare(strict_types=1);

namespace CajeerEngine\Stable;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Observability\SystemReport;
use CajeerEngine\Rc\ReleaseCandidateAuditor;
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\UpdateResolver;

final readonly class StableReleaseService
{
    public function __construct(private string $rootPath, private ConfigRepository $config)
    {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $version = $this->version();
        $release = $this->releaseJson();
        $lock = $this->lockFile()->readObject([]);
        $rc = (new ReleaseCandidateAuditor($this->rootPath))->summaryOnly();
        $releasePlan = (new ReleaseBuilder($this->rootPath))->plan();
        $supportDir = $this->rootPath . '/storage/app/support';
        $backupDir = $this->rootPath . '/storage/app/backups';
        $admin = (new AdminAssetPublisher($this->rootPath))->manifest(false);
        $security = (new SecurityHardener($this->rootPath, $this->config))->diagnose();
        $dbStatus = $this->databaseStatus();
        $update = (new UpdateResolver($this->config->get('updates', []), $this->rootPath))->diagnostics($version);
        $lockValid = $this->lockValid($lock, $version);

        $checks = [
            'version_is_1_1_1' => $version === '1.1.1',
            'release_channel_stable' => ($release['channel'] ?? null) === 'stable',
            'release_stability_stable' => ($release['stability'] ?? null) === 'stable',
            'engine_api_1_0' => ($release['engine_api_version'] ?? null) === '1.0',
            'api_v1_frozen' => $this->config->string('app.api_version', 'v1') === 'v1',
            'rc_ready' => (bool) ($rc['ready'] ?? false),
            'release_builder_ready' => ($releasePlan['file_count'] ?? 0) > 50,
            'admin_assets_ready' => (bool) ($admin['ready'] ?? false),
            'security_hardened' => (bool) ($security['ok'] ?? false),
            'database_schema_ready_or_unconfigured' => (bool) ($dbStatus['ready_or_unconfigured'] ?? false),
            'updates_metadata_ready' => (bool) ($update['metadata_ready'] ?? false),
            'storage_writable' => $this->writableDirectory($this->rootPath . '/storage/app'),
            'support_dir_writable' => $this->writableDirectory($supportDir),
            'backup_dir_writable' => $this->writableDirectory($backupDir),
            'release_lock_exists' => $lock !== [],
            'release_lock_valid' => $lockValid,
        ];

        return [
            'version' => $version,
            'stable' => !in_array(false, $checks, true),
            'checks' => $checks,
            'release' => [
                'channel' => $release['channel'] ?? 'unknown',
                'stability' => $release['stability'] ?? 'unknown',
                'engine_api_version' => $release['engine_api_version'] ?? 'unknown',
                'release_policy' => $release['release_policy'] ?? null,
            ],
            'rc' => $rc,
            'lock' => $lock,
            'database' => $dbStatus,
            'security' => $security,
            'updates' => $update,
            'paths' => [
                'release_target' => $releasePlan['target'] ?? null,
                'support_dir' => $supportDir,
                'backup_dir' => $backupDir,
                'lock_file' => $this->rootPath . '/storage/app/stable/release-lock.json',
            ],
            'generated_at' => $this->now(),
        ];
    }

    /** @return array<string, mixed> */
    public function smokeTest(): array
    {
        $checks = [];
        $checks[] = $this->check('version', $this->version() === '1.1.1', 'VERSION содержит 1.1.1.');
        $checks[] = $this->check('composer_autoload', $this->composerAutoloadReady(), 'Composer PSR-4 указывает на core/.');
        $checks[] = $this->check('openapi', is_file($this->rootPath . '/api/openapi.yaml') && str_contains((string) file_get_contents($this->rootPath . '/api/openapi.yaml'), '/stable'), 'OpenAPI содержит stable endpoints.');
        $checks[] = $this->check('admin_assets', (bool) ((new AdminAssetPublisher($this->rootPath))->manifest(false)['ready'] ?? false), 'Prebuilt Admin assets присутствуют.');
        $checks[] = $this->check('release_builder', is_file($this->rootPath . '/tools/build-release.php'), 'Release builder присутствует.');
        $security = (new SecurityHardener($this->rootPath, $this->config))->diagnose();
        $checks[] = $this->check('security_hardened', (bool) ($security['ok'] ?? false), 'Security production hardening включён.');
        $db = $this->databaseStatus();
        $checks[] = $this->check('database_schema', (bool) ($db['ready_or_unconfigured'] ?? false), 'База данных готова или явно не настроена в source-окружении.');
        $updates = (new UpdateResolver($this->config->get('updates', []), $this->rootPath))->check($this->version());
        $checks[] = $this->check('updates_metadata', (($updates['metadata_source'] ?? 'empty') !== 'empty'), 'Update metadata доступна из GitFlic JSON или bundled metadata.');
        $checks[] = $this->check('storage_write', $this->writeProbe($this->rootPath . '/storage/app/stable'), 'storage/app/stable доступен на запись.');
        $checks[] = $this->check('support_write', $this->writeProbe($this->rootPath . '/storage/app/support'), 'support directory доступен на запись.');
        $checks[] = $this->check('backup_write', $this->writeProbe($this->rootPath . '/storage/app/backups'), 'backup directory доступен на запись.');

        $passed = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'pass'));
        $result = [
            'ok' => $passed === count($checks),
            'summary' => [
                'total' => count($checks),
                'pass' => $passed,
                'fail' => count($checks) - $passed,
            ],
            'checks' => $checks,
            'database' => $db,
            'security' => $security,
            'updates' => $updates,
            'generated_at' => $this->now(),
        ];
        if ($result['ok']) {
            $result['lock'] = $this->writeReleaseLock();
        }
        $this->jsonFile('storage/app/stable/smoke-test.json')->writeObject($result);
        return $result;
    }

    /** @return array<string, mixed> */
    public function writeReleaseLock(): array
    {
        $rc = (new ReleaseCandidateAuditor($this->rootPath))->summaryOnly();
        $release = $this->releaseJson();
        $docs = [];
        foreach (['README.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'SECURITY.md', 'api/openapi.yaml', 'composer.json', 'release.json', 'VERSION', 'resources/updates/releases.json'] as $file) {
            $path = $this->rootPath . '/' . $file;
            $docs[$file] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $lock = [
            'format' => 'cajeerengine.release-lock.v2',
            'version' => $this->version(),
            'channel' => $release['channel'] ?? 'stable',
            'stability' => $release['stability'] ?? 'stable',
            'engine_api_version' => $release['engine_api_version'] ?? '1.0',
            'api_version' => $this->config->string('app.api_version', 'v1'),
            'rc_ready' => (bool) ($rc['ready'] ?? false),
            'rc_summary' => $rc['summary'] ?? [],
            'documents' => $docs,
            'locked_at' => $this->now(),
        ];
        $this->lockFile()->writeObject($lock);
        return $lock;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function backup(array $options = []): array
    {
        $includeUploads = (bool) ($options['include_uploads'] ?? true);
        $target = $this->rootPath . '/storage/app/backups/cajeerengine-backup-' . date('Ymd-His') . '.zip';
        $manifest = ['format' => 'cajeerengine.backup.v1', 'version' => $this->version(), 'created_at' => $this->now(), 'include_uploads' => $includeUploads, 'files' => []];
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'PHP ZipArchive extension не установлен.', 'target' => $target];
        }
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0775, true); }
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Не удалось создать backup ZIP: ' . $target); }
        foreach ($this->backupFiles($includeUploads) as $relative) {
            $absolute = $this->rootPath . '/' . $relative;
            if (!is_file($absolute)) { continue; }
            $zip->addFile($absolute, $relative);
            $manifest['files'][$relative] = hash_file('sha256', $absolute) ?: '';
        }
        $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        $zip->close();
        return ['ok' => true, 'target' => $target, 'size' => filesize($target) ?: 0, 'sha256' => hash_file('sha256', $target) ?: '', 'files' => count($manifest['files']), 'created_at' => $manifest['created_at']];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function supportBundle(array $options = []): array
    {
        $includeLogs = (bool) ($options['include_logs'] ?? true);
        $target = $this->rootPath . '/storage/app/support/cajeerengine-support-' . date('Ymd-His') . '.zip';
        if (!class_exists(\ZipArchive::class)) { return ['ok' => false, 'error' => 'PHP ZipArchive extension не установлен.', 'target' => $target]; }
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0775, true); }
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Не удалось создать support bundle: ' . $target); }
        $zip->addFromString('system-report.json', $this->json((new SystemReport($this->rootPath))->toArray()));
        $zip->addFromString('stable-status.json', $this->json($this->status()));
        $zip->addFromString('rc-readiness.json', $this->json((new ReleaseCandidateAuditor($this->rootPath))->summaryOnly()));
        $zip->addFromString('env-redacted.txt', $this->redactedEnv());
        foreach (['VERSION', 'release.json', 'composer.json', 'api/openapi.yaml', 'admin/package.json', 'packages/ts-sdk/package.json'] as $file) {
            $path = $this->rootPath . '/' . $file;
            if (is_file($path)) { $zip->addFile($path, 'project/' . $file); }
        }
        if ($includeLogs) {
            foreach (['storage/logs/runtime.jsonl', 'storage/logs/audit.jsonl'] as $file) {
                $path = $this->rootPath . '/' . $file;
                if (is_file($path)) { $zip->addFile($path, 'logs/' . basename($file)); }
            }
        }
        $zip->close();
        return ['ok' => true, 'target' => $target, 'size' => filesize($target) ?: 0, 'sha256' => hash_file('sha256', $target) ?: '', 'include_logs' => $includeLogs, 'created_at' => $this->now()];
    }

    /** @return array<string, mixed> */
    private function databaseStatus(): array
    {
        try {
            $database = new DatabaseManager($this->config);
            $health = $database->health();
            if (($health['ok'] ?? false) !== true) {
                $env = strtolower($this->config->string('app.env', 'production'));
                $hasEnv = is_file($this->rootPath . '/.env');
                $strict = $hasEnv && in_array($env, ['production', 'prod', 'staging'], true);
                return ['ok' => false, 'configured' => $strict, 'ready_or_unconfigured' => !$strict, 'health' => $health];
            }
            $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
            $migrationStatus = $runner->status($database->migrationDriver());
            $ready = (bool) ($health['schema_ready'] ?? false) && count($migrationStatus['failed']) === 0 && count($migrationStatus['pending']) === 0;
            return ['ok' => $ready, 'configured' => true, 'ready_or_unconfigured' => $ready, 'health' => $health, 'migrations' => $migrationStatus];
        } catch (\Throwable $e) {
            $env = strtolower($this->config->string('app.env', 'production'));
            $hasEnv = is_file($this->rootPath . '/.env');
            $strict = $hasEnv && in_array($env, ['production', 'prod', 'staging'], true);
            return ['ok' => false, 'configured' => $strict, 'ready_or_unconfigured' => !$strict, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $lock */
    private function lockValid(array $lock, string $version): bool
    {
        if ($lock === []) { return false; }
        return ($lock['version'] ?? null) === $version && ($lock['engine_api_version'] ?? null) === '1.0' && ($lock['channel'] ?? null) === 'stable';
    }

    /** @return list<string> */
    private function backupFiles(bool $includeUploads): array
    {
        $roots = ['storage/app'];
        if ($includeUploads) { $roots[] = 'public/uploads'; }
        $files = [];
        foreach ($roots as $dir) {
            $base = $this->rootPath . '/' . $dir;
            if (!is_dir($base)) { continue; }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) { continue; }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->rootPath) + 1));
                if (str_contains($relative, '/backups/') || str_contains($relative, '/support/')) { continue; }
                $files[] = $relative;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function redactedEnv(): string
    {
        $path = $this->rootPath . '/.env';
        if (!is_file($path)) { return "# .env отсутствует\n"; }
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^([A-Z0-9_]*(KEY|SECRET|PASSWORD|TOKEN|DSN)[A-Z0-9_]*)=/i', $line, $m)) { $out[] = $m[1] . '=***redacted***'; } else { $out[] = $line; }
        }
        return implode(PHP_EOL, $out) . PHP_EOL;
    }

    /** @return array<string, mixed> */
    private function check(string $name, bool $ok, string $message): array
    {
        return ['name' => $name, 'status' => $ok ? 'pass' : 'fail', 'message' => $message];
    }

    private function composerAutoloadReady(): bool
    {
        $path = $this->rootPath . '/composer.json';
        if (!is_file($path)) { return false; }
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) && (($json['autoload']['psr-4']['CajeerEngine\\'] ?? null) === 'core/');
    }

    private function writeProbe(string $directory): bool
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { return false; }
        $file = $directory . '/.probe-' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($file, 'ok', LOCK_EX) !== false;
        if ($ok) { @unlink($file); }
        return $ok;
    }

    private function writableDirectory(string $directory): bool
    {
        if (!is_dir($directory)) { return is_writable(dirname($directory)); }
        return is_writable($directory);
    }

    /** @return array<string, mixed> */
    private function releaseJson(): array
    {
        $path = $this->rootPath . '/release.json';
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        return is_array($json) ? $json : [];
    }

    private function version(): string
    {
        return trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    private function lockFile(): JsonFile
    {
        return $this->jsonFile('storage/app/stable/release-lock.json');
    }

    private function jsonFile(string $relative): JsonFile
    {
        return new JsonFile($this->rootPath . '/' . $relative);
    }
}
