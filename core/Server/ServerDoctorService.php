<?php

declare(strict_types=1);

namespace CajeerEngine\Server;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Rc\DocsVerifier;
use CajeerEngine\Rc\TemplateSandboxAuditor;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use CajeerEngine\Stable\StableReleaseService;
use CajeerEngine\Update\UpdateResolver;

final readonly class ServerDoctorService
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function run(bool $writeReport = true): array
    {
        $config = new ConfigRepository($this->rootPath);
        $checks = array_merge(
            $this->phpChecks(),
            $this->projectChecks(),
            $this->permissionChecks(),
            $this->adminAssetChecks(),
            $this->securityChecks($config),
            $this->databaseChecks($config),
            $this->docsChecks(),
            $this->templateChecks(),
            $this->stableChecks($config),
            $this->updateChecks($config),
            $this->nginxChecks(),
            $this->systemdChecks()
        );

        $summary = $this->summary($checks);
        $result = [
            'ok' => $summary['fail'] === 0,
            'summary' => $summary,
            'checks' => $checks,
            'commands' => [
                'auto_fix_safe' => 'php bin/cajeer fix --yes',
                'permissions' => 'php bin/cajeer fix:permissions --yes',
                'storage' => 'php bin/cajeer fix:storage --yes',
                'env' => 'php bin/cajeer fix:env --yes',
                'security' => 'php bin/cajeer fix:security --yes',
                'admin_assets' => 'php bin/cajeer fix:admin-assets --yes',
                'nginx_config' => 'php bin/cajeer nginx:config --domain=example.ru',
                'scheduler_systemd' => 'php bin/cajeer scheduler:install --print',
                'worker_systemd' => 'php bin/cajeer worker:install --print',
            ],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        if ($writeReport) {
            $this->writeReport($result, 'storage/app/reports/doctor-latest.json');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function postInstallReport(): array
    {
        $doctor = $this->run(false);
        $lock = $this->readJson('storage/app/installed.lock');
        $env = $this->readEnvSummary();
        $config = new ConfigRepository($this->rootPath);
        $nginx = (new NginxConfigGenerator($this->rootPath))->generate([
            'domain' => $env['app_url_host'] ?? 'example.ru',
            'preset' => $this->looksLikeAaPanel() ? 'aapanel' : 'default',
        ]);
        $systemd = (new SystemdServiceGenerator($this->rootPath))->preview([]);

        $result = [
            'ok' => (bool) ($doctor['ok'] ?? false),
            'version' => trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: 'unknown',
            'installed' => is_file($this->rootPath . '/storage/app/installed.lock'),
            'install_lock' => $lock,
            'env' => $env,
            'doctor_summary' => $doctor['summary'] ?? [],
            'next_steps' => $this->nextSteps($doctor),
            'nginx' => [
                'suggested_path' => $nginx['suggested_path'] ?? null,
                'command' => 'php bin/cajeer nginx:config --domain=' . ($env['app_url_host'] ?? 'example.ru'),
            ],
            'systemd' => [
                'scheduler_command' => 'php bin/cajeer scheduler:install --print',
                'worker_command' => 'php bin/cajeer worker:install --print',
                'unit_names' => $systemd['unit_names'] ?? [],
            ],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $this->writeReport($result, 'storage/app/reports/post-install-latest.json');
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function phpChecks(): array
    {
        $checks = [];
        $checks[] = $this->check('php', 'version', version_compare(PHP_VERSION, '8.4.1', '>='), 'PHP >= 8.4.1', 'FAIL', 'Установите PHP 8.4+ или выберите /www/server/php/84/bin/php на aaPanel.', ['actual' => PHP_VERSION]);
        foreach (['json', 'mbstring', 'openssl', 'pdo'] as $extension) {
            $checks[] = $this->check('php', 'extension.' . $extension, extension_loaded($extension), 'PHP extension ' . $extension, 'FAIL', 'Установите PHP extension ' . $extension . ' и перезапустите PHP-FPM.', ['fix_command' => 'apt install php8.4-' . ($extension === 'json' ? 'common' : $extension)]);
        }
        foreach (['pdo_pgsql', 'pdo_mysql', 'pdo_sqlite', 'zip', 'fileinfo', 'redis'] as $extension) {
            $checks[] = $this->check('php', 'extension.' . $extension, extension_loaded($extension), 'Optional PHP extension ' . $extension, 'WARN', 'Установите extension, если используете соответствующий модуль.', ['extension' => $extension]);
        }
        return $checks;
    }

    /** @return list<array<string, mixed>> */
    private function projectChecks(): array
    {
        $checks = [];
        $requiredFiles = [
            'bin/cajeer' => 'CLI entrypoint',
            '.env.example' => 'ENV example',
            'public/index.php' => 'Public front controller',
            'public/install/index.php' => 'Web Installer',
            'core/Installer/InstallerService.php' => 'Installer backend',
            'core/Server/ServerDoctorService.php' => 'Server doctor backend',
            'migrations/sqlite/0001_initial_schema.sql' => 'SQLite migrations',
            'migrations/pgsql/0001_initial_schema.sql' => 'PostgreSQL migrations',
            'migrations/mysql/0001_initial_schema.sql' => 'MySQL migrations',
        ];
        foreach ($requiredFiles as $file => $label) {
            $checks[] = $this->check('project', 'file.' . str_replace(['/', '.'], '_', $file), is_file($this->rootPath . '/' . $file), $label, 'FAIL', 'Восстановите файл из dist/source архива.', ['file' => $file]);
        }
        $checks[] = $this->check('project', 'composer.lock', is_file($this->rootPath . '/composer.lock'), 'composer.lock present for dist', 'WARN', 'Для dist-архива выполните composer update --no-dev --optimize-autoloader при сборке релиза.', ['fix_command' => 'composer update --no-dev --optimize-autoloader']);
        $checks[] = $this->check('project', 'vendor.autoload', is_file($this->rootPath . '/vendor/autoload.php'), 'vendor/autoload.php present', 'WARN', 'Используйте dist-архив или выполните composer install --no-dev --optimize-autoloader.', ['fix_command' => 'composer install --no-dev --optimize-autoloader']);
        $checks[] = $this->check('project', 'installed.lock', is_file($this->rootPath . '/storage/app/installed.lock'), 'install lock present', 'WARN', 'Запустите php bin/cajeer install или откройте /install.', ['fix_command' => 'php bin/cajeer install --interactive']);
        return $checks;
    }

    /** @return list<array<string, mixed>> */
    private function permissionChecks(): array
    {
        $checks = [];
        foreach ($this->runtimeDirectories() as $dir) {
            $path = $this->rootPath . '/' . $dir;
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $status = $exists && $writable;
            $checks[] = $this->check('permissions', 'dir.' . str_replace('/', '_', $dir), $status, $dir . ' writable', $exists ? 'FAIL' : 'WARN', 'Создайте директорию и выставьте права.', ['path' => $dir, 'fix_command' => 'php bin/cajeer fix:permissions --yes']);
        }
        foreach (['.env', 'storage/app/installed.lock'] as $file) {
            $path = $this->rootPath . '/' . $file;
            if (!is_file($path)) {
                $checks[] = $this->check('permissions', 'file.' . str_replace(['/', '.'], '_', $file), false, $file . ' present', 'WARN', 'Файл будет создан установщиком или fix-командой.', ['file' => $file]);
                continue;
            }
            $checks[] = $this->check('permissions', 'file.' . str_replace(['/', '.'], '_', $file), is_readable($path), $file . ' readable', 'FAIL', 'Проверьте права файла.', ['file' => $file]);
        }
        return $checks;
    }

    /** @return list<array<string, mixed>> */
    private function adminAssetChecks(): array
    {
        $manifest = (new AdminAssetPublisher($this->rootPath))->manifest(false);
        $checks = [];
        $checks[] = $this->check('admin_assets', 'ready', (bool) ($manifest['ready'] ?? false), 'Admin assets ready', 'FAIL', 'Опубликуйте prebuilt assets или fallback assets.', ['fix_command' => 'php bin/cajeer fix:admin-assets --yes']);
        foreach (($manifest['files'] ?? []) as $name => $file) {
            $checks[] = $this->check('admin_assets', 'file.' . $name, !empty($file['exists']) && (int) ($file['size'] ?? 0) > 0, 'Admin asset ' . $name, 'FAIL', 'Файл asset отсутствует или пустой.', ['file' => $file['file'] ?? $name]);
        }
        return $checks;
    }

    /** @return list<array<string, mixed>> */
    private function securityChecks(ConfigRepository $config): array
    {
        try {
            $diagnostics = (new SecurityHardener($this->rootPath, $config))->diagnose();
            $checks = [];
            foreach (($diagnostics['checks'] ?? []) as $name => $ok) {
                $checks[] = $this->check('security', (string) $name, (bool) $ok, 'Security: ' . (string) $name, 'FAIL', 'Включите production hardening.', ['fix_command' => 'php bin/cajeer fix:security --yes']);
            }
            return $checks;
        } catch (\Throwable $e) {
            return [$this->check('security', 'diagnose', false, 'Security diagnostics', 'FAIL', $e->getMessage(), ['fix_command' => 'php bin/cajeer fix:security --yes'])];
        }
    }

    /** @return list<array<string, mixed>> */
    private function databaseChecks(ConfigRepository $config): array
    {
        $checks = [];
        try {
            $database = new DatabaseManager($config);
            $health = $database->health();
            $checks[] = $this->check('database', 'connection', (bool) ($health['ok'] ?? false), 'Database connection', is_file($this->rootPath . '/.env') ? 'FAIL' : 'WARN', 'Проверьте .env и доступность выбранной БД.', ['health' => $health, 'fix_command' => 'php bin/cajeer fix:env --yes']);
            if (($health['ok'] ?? false) !== true) {
                return $checks;
            }
            $checks[] = $this->check('database', 'schema_ready', (bool) ($health['schema_ready'] ?? false), 'Database schema ready', 'FAIL', 'Запустите миграции.', ['fix_command' => 'php bin/cajeer migrate']);
            $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
            $status = $runner->status($database->migrationDriver());
            $checks[] = $this->check('migrations', 'pending', count($status['pending'] ?? []) === 0, 'No pending migrations', 'FAIL', 'Запустите php bin/cajeer migrate.', ['pending' => count($status['pending'] ?? []), 'fix_command' => 'php bin/cajeer migrate']);
            $checks[] = $this->check('migrations', 'failed', count($status['failed'] ?? []) === 0, 'No failed migrations', 'FAIL', 'Проверьте ошибку и выполните migrate:repair.', ['failed' => count($status['failed'] ?? []), 'fix_command' => 'php bin/cajeer migrate:repair --run']);
        } catch (\Throwable $e) {
            $checks[] = $this->check('database', 'exception', false, 'Database diagnostics', 'WARN', $e->getMessage(), ['fix_command' => 'php bin/cajeer install:check']);
        }
        return $checks;
    }

    /** @return list<array<string, mixed>> */
    private function docsChecks(): array
    {
        try {
            return array_map(fn ($check): array => $this->fromCheckResult($check->toArray(), 'docs:verify'), (new DocsVerifier($this->rootPath))->verify());
        } catch (\Throwable $e) {
            return [$this->check('docs', 'exception', false, 'Docs verification', 'WARN', $e->getMessage(), ['fix_command' => 'php bin/cajeer docs:verify'])];
        }
    }

    /** @return list<array<string, mixed>> */
    private function templateChecks(): array
    {
        try {
            return array_map(fn ($check): array => $this->fromCheckResult($check->toArray(), 'template:audit'), (new TemplateSandboxAuditor($this->rootPath))->audit());
        } catch (\Throwable $e) {
            return [$this->check('templates', 'exception', false, 'Template audit', 'WARN', $e->getMessage(), ['fix_command' => 'php bin/cajeer template:audit'])];
        }
    }

    /** @return list<array<string, mixed>> */
    private function stableChecks(ConfigRepository $config): array
    {
        try {
            $stable = (new StableReleaseService($this->rootPath, $config))->status();
            $summary = $stable['checks'] ?? [];
            $checks = [];
            if (is_array($summary)) {
                foreach ($summary as $name => $ok) {
                    $checks[] = $this->check('stable', (string) $name, (bool) $ok, 'Stable status: ' . (string) $name, 'WARN', 'Проверьте stable status.', ['fix_command' => 'php bin/cajeer stable:status']);
                }
            }
            return $checks;
        } catch (\Throwable $e) {
            return [$this->check('stable', 'exception', false, 'Stable status', 'WARN', $e->getMessage(), ['fix_command' => 'php bin/cajeer stable:status'])];
        }
    }

    /** @return list<array<string, mixed>> */
    private function updateChecks(ConfigRepository $config): array
    {
        try {
            $version = $config->string('app.version', trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1');
            $updates = (new UpdateResolver($config->get('updates', []), $this->rootPath))->diagnostics($version);
            return [
                $this->check('updates', 'metadata_ready', (bool) ($updates['metadata_ready'] ?? false), 'Update metadata ready', 'WARN', 'Проверьте resources/updates/releases.json или GitFlic releases endpoint.', ['diagnostics' => $updates, 'fix_command' => 'php bin/cajeer update:check']),
            ];
        } catch (\Throwable $e) {
            return [$this->check('updates', 'exception', false, 'Update diagnostics', 'WARN', $e->getMessage(), ['fix_command' => 'php bin/cajeer update:check'])];
        }
    }

    /** @return list<array<string, mixed>> */
    private function nginxChecks(): array
    {
        $isNginxProjectReady = is_dir($this->rootPath . '/public') && is_file($this->rootPath . '/public/index.php');
        $checks = [$this->check('nginx', 'public_root', $isNginxProjectReady, 'public/ root ready', 'FAIL', 'Проверьте public/index.php.', ['fix_command' => 'php bin/cajeer nginx:config --domain=example.ru'])];
        if ($this->looksLikeAaPanel()) {
            $checks[] = $this->check('nginx', 'aapanel_paths', true, 'aaPanel paths detected', 'WARN', 'Можно сгенерировать конфиг под aaPanel.', ['fix_command' => 'php bin/cajeer nginx:config --preset=aapanel --domain=example.ru']);
        } else {
            $checks[] = $this->check('nginx', 'aapanel_paths', false, 'aaPanel paths not detected', 'WARN', 'Это нормально вне aaPanel. Для aaPanel используйте --preset=aapanel.', ['fix_command' => 'php bin/cajeer nginx:config --domain=example.ru']);
        }
        return $checks;
    }

    /** @return list<array<string, mixed>> */
    private function systemdChecks(): array
    {
        $checks = [];
        $checks[] = $this->check('systemd', 'scheduler_template', is_file($this->rootPath . '/examples/systemd/cajeerengine-scheduler.service') || is_file($this->rootPath . '/config/systemd/cajeerengine-scheduler.service'), 'Scheduler systemd template available', 'WARN', 'Сгенерируйте unit через scheduler:install.', ['fix_command' => 'php bin/cajeer scheduler:install --print']);
        $checks[] = $this->check('systemd', 'worker_template', is_file($this->rootPath . '/examples/systemd/cajeerengine-queue.service') || is_file($this->rootPath . '/config/systemd/cajeerengine-worker.service'), 'Worker systemd template available', 'WARN', 'Сгенерируйте unit через worker:install.', ['fix_command' => 'php bin/cajeer worker:install --print']);
        return $checks;
    }

    /** @param array<string,mixed> $result */
    private function writeReport(array $result, string $relativePath): void
    {
        $path = $this->rootPath . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $relativePath): ?array
    {
        $path = $this->rootPath . '/' . $relativePath;
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,mixed> */
    private function readEnvSummary(): array
    {
        $path = $this->rootPath . '/.env';
        $result = ['exists' => is_file($path)];
        if (!is_file($path)) {
            return $result;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (in_array($key, ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_DRIVER', 'DB_DATABASE', 'CACHE_DRIVER', 'QUEUE_DRIVER'], true)) {
                $result[strtolower($key)] = $value;
            }
        }
        if (isset($result['app_url'])) {
            $host = parse_url((string) $result['app_url'], PHP_URL_HOST);
            $result['app_url_host'] = is_string($host) && $host !== '' ? $host : preg_replace('/^https?:\/\//', '', (string) $result['app_url']);
        }
        return $result;
    }

    /** @param array<string,mixed> $doctor @return list<string> */
    private function nextSteps(array $doctor): array
    {
        $steps = [];
        foreach (($doctor['checks'] ?? []) as $check) {
            if (!is_array($check) || ($check['status'] ?? 'OK') === 'OK') {
                continue;
            }
            $fix = $check['fix_command'] ?? ($check['context']['fix_command'] ?? null);
            if (is_string($fix) && $fix !== '' && !in_array($fix, $steps, true)) {
                $steps[] = $fix;
            }
            if (count($steps) >= 8) {
                break;
            }
        }
        return $steps;
    }

    private function looksLikeAaPanel(): bool
    {
        return is_dir('/www/server/panel') || is_dir('/www/server/php') || str_starts_with($this->rootPath, '/www/wwwroot/');
    }

    /** @return list<string> */
    private function runtimeDirectories(): array
    {
        return ['storage', 'storage/app', 'storage/cache', 'storage/database', 'storage/logs', 'storage/tmp', 'storage/app/reports', 'public/uploads', 'bootstrap/cache'];
    }

    /** @param array<string,mixed> $check */
    private function fromCheckResult(array $check, string $command): array
    {
        $status = match ($check['status'] ?? 'warn') {
            'pass' => 'OK',
            'fail' => 'FAIL',
            default => 'WARN',
        };
        return [
            'status' => $status,
            'group' => (string) ($check['group'] ?? 'unknown'),
            'name' => (string) ($check['name'] ?? 'unknown'),
            'label' => (string) ($check['message'] ?? ''),
            'message' => (string) ($check['message'] ?? ''),
            'fix' => 'Запустите ' . $command . ' и исправьте найденные проблемы.',
            'fix_command' => 'php bin/cajeer ' . $command,
            'context' => is_array($check['context'] ?? null) ? $check['context'] : [],
        ];
    }

    /** @param array<string,mixed> $context */
    private function check(string $group, string $name, bool $ok, string $label, string $failStatus, string $fix, array $context = []): array
    {
        return [
            'status' => $ok ? 'OK' : $failStatus,
            'group' => $group,
            'name' => $name,
            'label' => $label,
            'message' => $ok ? 'OK' : $fix,
            'fix' => $ok ? null : $fix,
            'fix_command' => $context['fix_command'] ?? null,
            'context' => $context,
        ];
    }

    /** @param list<array<string,mixed>> $checks @return array<string,mixed> */
    private function summary(array $checks): array
    {
        $summary = ['total' => count($checks), 'ok' => 0, 'warn' => 0, 'fail' => 0, 'groups' => []];
        foreach ($checks as $check) {
            $status = strtoupper((string) ($check['status'] ?? 'WARN'));
            $key = strtolower($status);
            if (!isset($summary[$key])) {
                $summary[$key] = 0;
            }
            $summary[$key]++;
            $group = (string) ($check['group'] ?? 'unknown');
            if (!isset($summary['groups'][$group])) {
                $summary['groups'][$group] = ['total' => 0, 'ok' => 0, 'warn' => 0, 'fail' => 0];
            }
            $summary['groups'][$group]['total']++;
            if (isset($summary['groups'][$group][$key])) {
                $summary['groups'][$group][$key]++;
            }
        }
        return $summary;
    }
}
