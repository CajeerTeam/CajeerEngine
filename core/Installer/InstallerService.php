<?php

declare(strict_types=1);

namespace CajeerEngine\Installer;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\SettingsRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Security\SecurityHardener;
use CajeerEngine\Support\EnvironmentFile;

final readonly class InstallerService
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function requirements(?string $driver = null): array
    {
        $this->loadEnvFileToProcess();
        $driver = $this->normalizeDriver($driver ?: (getenv('DB_DRIVER') ?: $this->envValue('DB_DRIVER') ?: 'pgsql'));
        $checks = [
            'php_version' => [
                'label' => 'PHP >= 8.4.1',
                'ok' => version_compare(PHP_VERSION, '8.4.1', '>='),
                'current' => PHP_VERSION,
                'fix' => 'Установите PHP 8.4+ и используйте нужный php-fpm/CLI binary.',
            ],
            'ext_json' => ['label' => 'PHP extension json', 'ok' => extension_loaded('json'), 'fix' => 'Установите php-json.'],
            'ext_mbstring' => ['label' => 'PHP extension mbstring', 'ok' => extension_loaded('mbstring'), 'fix' => 'Установите php-mbstring.'],
            'ext_openssl' => ['label' => 'PHP extension openssl', 'ok' => extension_loaded('openssl'), 'fix' => 'Включите openssl extension.'],
            'ext_pdo' => ['label' => 'PHP extension pdo', 'ok' => extension_loaded('pdo'), 'fix' => 'Установите/включите PDO.'],
            'ext_selected_pdo' => [
                'label' => 'PDO driver: ' . $driver,
                'ok' => extension_loaded($this->pdoExtension($driver)),
                'driver' => $driver,
                'extension' => $this->pdoExtension($driver),
                'fix' => 'Установите расширение ' . $this->pdoExtension($driver) . '.',
            ],
            'storage_writable' => ['label' => 'storage/ writable', 'ok' => $this->writable('storage'), 'fix' => 'php bin/cajeer fix:permissions'],
            'storage_app_writable' => ['label' => 'storage/app/ writable', 'ok' => $this->writable('storage/app'), 'fix' => 'php bin/cajeer fix:storage'],
            'storage_database_writable' => ['label' => 'storage/database/ writable', 'ok' => $this->writable('storage/database'), 'fix' => 'php bin/cajeer fix:storage'],
            'public_uploads_writable' => ['label' => 'public/uploads/ writable', 'ok' => $this->writable('public/uploads'), 'fix' => 'php bin/cajeer fix:permissions'],
            'bootstrap_cache_writable' => ['label' => 'bootstrap/cache/ writable', 'ok' => $this->writable('bootstrap/cache'), 'fix' => 'php bin/cajeer fix:storage'],
            'env_file' => ['label' => '.env present', 'ok' => is_file($this->rootPath . '/.env'), 'fix' => 'php bin/cajeer install --preset=sqlite'],
            'app_key' => ['label' => 'APP_KEY configured', 'ok' => $this->appKeyReady(), 'fix' => 'php bin/cajeer fix:env'],
            'composer_autoload' => ['label' => 'vendor/autoload.php present', 'ok' => is_file($this->rootPath . '/vendor/autoload.php'), 'fix' => 'Используйте dist-архив или выполните composer install --no-dev --optimize-autoloader.'],
            'admin_assets' => ['label' => 'prebuilt Admin assets present', 'ok' => $this->adminAssetsReady(), 'fix' => 'Используйте dist-архив или выполните npm run build:release.'],
        ];

        $database = null;
        $migrations = ['ok' => false, 'error' => 'DB connection ещё не проверялся.'];
        if (is_file($this->rootPath . '/.env') && extension_loaded($this->pdoExtension($driver))) {
            try {
                $config = new ConfigRepository($this->rootPath);
                $databaseManager = new DatabaseManager($config);
                $database = $databaseManager->health();
                $migrations = $this->migrationStatus($databaseManager);
            } catch (\Throwable $e) {
                $database = ['ok' => false, 'driver' => $driver, 'error' => ['message' => $e->getMessage(), 'class' => $e::class]];
            }
        }

        return [
            'ready' => !in_array(false, array_map(static fn (array $check): bool => (bool) $check['ok'], $checks), true),
            'installed' => $this->installed(),
            'version' => $this->version(),
            'driver' => $driver,
            'checks' => $checks,
            'database' => $database,
            'migrations' => $migrations,
            'directories' => $this->runtimeDirectories(),
            'lock_file' => $this->lockPath(),
        ];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function install(array $options = []): array
    {
        if ($this->installed() && empty($options['force'])) {
            throw new \RuntimeException('CajeerEngine уже установлен. Найден lock-файл: ' . $this->lockPath());
        }

        $profile = $this->profile($options);
        foreach (['json', 'mbstring', 'openssl', 'pdo'] as $requiredExtension) {
            if (!extension_loaded($requiredExtension)) {
                throw new \RuntimeException('Не установлен обязательный PHP extension: ' . $requiredExtension);
            }
        }
        $extension = $this->pdoExtension((string) $profile['db_driver']);
        if (!extension_loaded($extension)) {
            throw new \RuntimeException('Не установлен PHP extension для выбранной БД: ' . $extension);
        }
        $created = $this->ensureRuntimeDirectories(true);
        $envResult = ['created' => false, 'changed' => []];
        if (($profile['create_env'] ?? true) === true) {
            $envResult = $this->writeEnv($profile);
        }

        $this->loadProcessEnv($profile['env']);
        $this->prepareDatabaseStorage($profile);

        $dbCheck = $this->checkDatabaseConnection();
        if (($dbCheck['ok'] ?? false) !== true) {
            throw new \RuntimeException('Не удалось подключиться к БД: ' . ($dbCheck['error']['message'] ?? 'unknown error'));
        }

        $migrationResult = null;
        if (($profile['run_migrations'] ?? true) === true) {
            $migrationResult = $this->runMigrations();
        }

        $seedResult = null;
        if (($profile['create_admin'] ?? true) === true) {
            $seedResult = $this->seed((string) $profile['admin_email'], (string) $profile['admin_name'], (string) $profile['admin_password']);
        }

        $final = $this->finalVerify();
        if (($profile['write_lock'] ?? true) === true) {
            $this->writeInstallLock($profile, $final);
        }

        $result = [
            'ok' => true,
            'installed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'source' => (string) ($profile['source'] ?? 'installer'),
            'preset' => $profile['preset'],
            'domain' => $profile['domain'],
            'database_driver' => $profile['db_driver'],
            'created_directories' => $created,
            'env_created' => $envResult['created'],
            'env_changed' => array_keys($envResult['changed']),
            'database' => $dbCheck,
            'migrations' => $migrationResult,
            'seed' => $seedResult,
            'final' => $final,
            'lock_file' => $this->lockPath(),
        ];

        $this->writeInstallState($result);
        return $result;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function profile(array $options): array
    {
        $preset = strtolower((string) ($options['preset'] ?? ''));
        if (!empty($options['sqlite'])) {
            $preset = 'sqlite';
        }
        $dbDriver = $this->normalizeDriver((string) ($options['db'] ?? $options['db_driver'] ?? ''));
        if ($dbDriver === '') {
            $dbDriver = match ($preset) {
                'sqlite' => 'sqlite',
                'mysql', 'mariadb' => 'mysql',
                default => 'pgsql',
            };
        }
        if ($preset === '') {
            $preset = $dbDriver === 'sqlite' ? 'sqlite' : 'custom';
        }

        $production = (bool) ($options['production'] ?? in_array($preset, ['production', 'aapanel', 'postgres'], true));
        if ($preset === 'aapanel') {
            $options['php_binary'] ??= '/www/server/php/84/bin/php';
        }

        $domain = trim((string) ($options['domain'] ?? '127.0.0.1:8080'));
        $appUrl = $this->normalizeUrl((string) ($options['app_url'] ?? $domain));
        $dbName = (string) ($options['db_name'] ?? $options['database'] ?? ($dbDriver === 'sqlite' ? 'storage/database/cajeer.sqlite' : 'cajeerengine'));
        $dbHost = (string) ($options['db_host'] ?? '127.0.0.1');
        $dbPort = (int) ($options['db_port'] ?? ($dbDriver === 'mysql' ? 3306 : 5432));
        $dbUser = (string) ($options['db_user'] ?? ($dbDriver === 'sqlite' ? '' : 'cajeerengine'));
        $dbPassword = (string) ($options['db_password'] ?? '');
        $adminName = trim((string) ($options['admin_name'] ?? 'Administrator')) ?: 'Administrator';
        $adminEmail = trim((string) ($options['admin_email'] ?? ''));
        $adminPassword = (string) ($options['admin_password'] ?? '');
        $createAdmin = (bool) ($options['create_admin'] ?? true);
        if ($createAdmin) {
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Для установки нужен корректный email администратора.');
            }
            if ($adminPassword === '') {
                throw new \InvalidArgumentException('Для установки нужен пароль администратора.');
            }
        }

        $env = [
            'APP_NAME' => (string) ($options['app_name'] ?? 'CajeerEngine'),
            'APP_ENV' => $production ? 'production' : 'local',
            'APP_DEBUG' => $production ? 'false' : 'true',
            'APP_URL' => $appUrl,
            'APP_KEY' => $this->existingOrGeneratedSecret('APP_KEY', 'base64:' . base64_encode(random_bytes(32)), true),
            'APP_LOCALE' => (string) ($options['locale'] ?? 'ru'),
            'APP_VERSION' => $this->version(),
            'JWT_SECRET' => $this->existingOrGeneratedSecret('JWT_SECRET', bin2hex(random_bytes(48))),
            'API_TOKEN_SECRET' => $this->existingOrGeneratedSecret('API_TOKEN_SECRET', bin2hex(random_bytes(48))),
            'WEBHOOK_SIGNING_SECRET' => $this->existingOrGeneratedSecret('WEBHOOK_SIGNING_SECRET', bin2hex(random_bytes(48))),
            'DB_DRIVER' => $dbDriver,
            'DB_HOST' => $dbHost,
            'DB_PORT' => (string) $dbPort,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_SSLMODE' => (string) ($options['db_sslmode'] ?? 'prefer'),
            'SQLITE_DATABASE' => $dbDriver === 'sqlite' ? $dbName : 'storage/database/cajeer.sqlite',
            'CACHE_DRIVER' => (string) ($options['cache_driver'] ?? ($production ? 'redis' : 'file')),
            'QUEUE_DRIVER' => (string) ($options['queue_driver'] ?? 'file'),
            'SEARCH_DRIVER' => (string) ($options['search_driver'] ?? 'file'),
            'STORAGE_DRIVER' => 'local',
            'STORAGE_LOCAL_PATH' => 'storage/app',
            'STORAGE_PUBLIC_UPLOADS_URL' => '/uploads',
            'SECURITY_REQUIRE_AUTH' => 'true',
            'SECURITY_PROTECT_CONTENT_WRITES' => 'true',
            'SECURITY_PROTECT_SYSTEM_ROUTES' => 'true',
            'MEDIA_ALLOW_SVG' => 'false',
            'PASSWORD_MIN_LENGTH' => '12',
            'PASSWORD_REQUIRE_MIXED' => 'true',
            'ADMIN_EMAIL' => $adminEmail,
            'ADMIN_NAME' => $adminName,
            'ADMIN_PASSWORD' => '',
        ];

        return [
            'preset' => $preset,
            'source' => (string) ($options['source'] ?? 'cli'),
            'production' => $production,
            'domain' => $domain,
            'app_url' => $appUrl,
            'db_driver' => $dbDriver,
            'db_name' => $dbName,
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_user' => $dbUser,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'create_admin' => $createAdmin,
            'create_env' => !array_key_exists('create_env', $options) || (bool) $options['create_env'],
            'run_migrations' => !array_key_exists('run_migrations', $options) || (bool) $options['run_migrations'],
            'write_lock' => !array_key_exists('write_lock', $options) || (bool) $options['write_lock'],
            'env' => $env,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function runtimeDirectories(): array
    {
        $result = [];
        foreach ($this->runtimeDirectoryList() as $directory) {
            $path = $this->rootPath . '/' . $directory;
            $result[$directory] = [
                'exists' => is_dir($path),
                'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname($path)),
            ];
        }
        return $result;
    }

    public function installed(): bool
    {
        return is_file($this->lockPath());
    }

    public function lockPath(): string
    {
        return $this->rootPath . '/storage/app/installed.lock';
    }

    /** @return list<string> */
    private function runtimeDirectoryList(): array
    {
        return [
            'storage/app',
            'storage/app/content',
            'storage/app/exports',
            'storage/app/imports',
            'storage/app/updates',
            'storage/app/extensions',
            'storage/app/media',
            'storage/app/stable',
            'storage/app/support',
            'storage/app/backups',
            'storage/cache',
            'storage/cache/templates',
            'storage/database',
            'storage/logs',
            'storage/tmp',
            'storage/queue/pending/default',
            'storage/queue/processed/default',
            'storage/queue/failed/default',
            'storage/rate-limits',
            'storage/static-export',
            'storage/releases',
            'public/uploads',
            'bootstrap/cache',
            'plugins',
            'themes',
        ];
    }

    /** @return list<string> */
    private function ensureRuntimeDirectories(bool $writeGitkeep): array
    {
        $created = [];
        foreach ($this->runtimeDirectoryList() as $directory) {
            $path = $this->rootPath . '/' . $directory;
            if (!is_dir($path)) {
                if (!mkdir($path, 0775, true) && !is_dir($path)) {
                    throw new \RuntimeException('Не удалось создать директорию: ' . $directory);
                }
                $created[] = $directory;
            }
            if ($writeGitkeep) {
                $gitkeep = $path . '/.gitkeep';
                if (!is_file($gitkeep)) {
                    @file_put_contents($gitkeep, '');
                }
            }
        }
        return $created;
    }

    /** @param array<string, mixed> $profile @return array{created:bool,changed:array<string,array{old:?string,new:string}>} */
    private function writeEnv(array $profile): array
    {
        $env = new EnvironmentFile($this->rootPath . '/.env');
        $created = false;
        if (!$env->exists()) {
            $created = $env->ensureFromExample($this->rootPath . '/.env.example');
        }
        /** @var array<string, string> $values */
        $values = $profile['env'];
        $changed = $env->setMany($values);
        $this->loadProcessEnv($values);
        return ['created' => $created, 'changed' => $changed];
    }


    private function loadEnvFileToProcess(): void
    {
        $path = $this->rootPath . '/.env';
        if (!is_file($path)) {
            return;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    /** @param array<string, string> $values */
    private function loadProcessEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** @param array<string, mixed> $profile */
    private function prepareDatabaseStorage(array $profile): void
    {
        if (($profile['db_driver'] ?? '') !== 'sqlite') {
            return;
        }
        $relative = (string) ($profile['db_name'] ?? 'storage/database/cajeer.sqlite');
        $path = $this->absolutePath($relative);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать директорию SQLite: ' . $dir);
        }
        if (!is_file($path)) {
            if (@file_put_contents($path, '') === false) {
                throw new \RuntimeException('Не удалось создать SQLite-файл: ' . $path);
            }
        }
    }

    /** @return array<string, mixed> */
    private function checkDatabaseConnection(): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        return $database->health();
    }

    /** @return array<string, mixed> */
    private function runMigrations(): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
        return $runner->run($database->migrationDriver());
    }

    /** @return array<string, mixed> */
    private function seed(string $email, string $name, string $password): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $roles = new RoleRepository($database);
        $settings = new SettingsRepository($database);
        $users = new UserRepository($database, $roles, new PasswordHasher($config));
        $audit = new AuditLogRepository($database);

        $roleCount = $roles->seedDefaults();
        $settings->set('app.name', $config->string('app.name', 'CajeerEngine'), 'string');
        $settings->set('app.locale', $config->string('app.locale', 'ru'), 'string');
        $settings->set('engine.version', $this->version(), 'string');
        $settings->set('engine.database_driver', $database->driver(), 'string');
        $userId = $users->createAdmin($email, $name, $password);
        $audit->record('installer.admin_created', $userId, ['email' => mb_strtolower($email), 'source' => 'install-1.1.1']);

        return [
            'roles_seeded' => $roleCount,
            'admin_user_id' => $userId,
            'admin_email' => mb_strtolower($email),
        ];
    }

    /** @return array<string, mixed> */
    private function finalVerify(): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
        $migrationStatus = $runner->status($database->migrationDriver());
        $security = (new SecurityHardener($this->rootPath, $config))->diagnose();
        $adminAssets = $this->adminAssetsReady();
        $databaseHealth = $database->health();
        $ok = ($databaseHealth['ok'] ?? false) === true
            && count($migrationStatus['failed']) === 0
            && count($migrationStatus['pending']) === 0
            && (bool) ($security['ok'] ?? false)
            && $adminAssets;

        return [
            'ok' => $ok,
            'database' => $databaseHealth,
            'migrations' => [
                'driver' => $migrationStatus['driver'],
                'applied' => count($migrationStatus['applied']),
                'pending' => count($migrationStatus['pending']),
                'failed' => count($migrationStatus['failed']),
            ],
            'security_doctor' => $security,
            'admin_assets' => ['ok' => $adminAssets],
        ];
    }

    /** @param array<string, mixed> $profile @param array<string, mixed> $final */
    private function writeInstallLock(array $profile, array $final): void
    {
        $payload = [
            'format' => 'cajeerengine.installed.v1',
            'version' => $this->version(),
            'installed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'source' => $profile['source'] ?? 'installer',
            'preset' => $profile['preset'] ?? 'custom',
            'app_url' => $profile['app_url'] ?? null,
            'database_driver' => $profile['db_driver'] ?? null,
            'final_ok' => (bool) ($final['ok'] ?? false),
        ];
        $path = $this->lockPath();
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Не удалось создать директорию lock-файла.');
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    }

    private function writable(string $relativePath): bool
    {
        $path = $this->rootPath . '/' . $relativePath;
        return is_dir($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function appKeyReady(): bool
    {
        $value = $this->envValue('APP_KEY');
        return $value !== null && $value !== '' && $value !== 'CHANGE_ME' && $value !== 'base64:CHANGE_ME';
    }

    private function adminAssetsReady(): bool
    {
        return is_file($this->rootPath . '/public/admin/assets/admin-app.js')
            && is_file($this->rootPath . '/public/admin/assets/admin-app.css')
            && is_file($this->rootPath . '/public/admin/index.html');
    }

    private function envValue(string $key): ?string
    {
        $path = $this->rootPath . '/.env';
        if (!is_file($path)) {
            return null;
        }
        $contents = (string) file_get_contents($path);
        if (!preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $match)) {
            return null;
        }
        return trim((string) $match[1], " \t\n\r\0\x0B\"'");
    }

    private function existingOrGeneratedSecret(string $key, string $generated, bool $rejectChangeMe = false): string
    {
        $existing = $this->envValue($key);
        if ($existing !== null && $existing !== '' && (!$rejectChangeMe || !in_array($existing, ['CHANGE_ME', 'base64:CHANGE_ME'], true))) {
            return $existing;
        }
        return $generated;
    }

    private function pdoExtension(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
            default => 'pdo_pgsql',
        };
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function normalizeUrl(string $domainOrUrl): string
    {
        $domainOrUrl = trim($domainOrUrl);
        if ($domainOrUrl === '') {
            return 'http://127.0.0.1:8080';
        }
        if (!preg_match('~^https?://~i', $domainOrUrl)) {
            $domainOrUrl = 'https://' . $domainOrUrl;
        }
        return rtrim($domainOrUrl, '/');
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return $this->rootPath . '/storage/database/cajeer.sqlite';
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->rootPath . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function migrationStatus(DatabaseManager $database): array
    {
        try {
            $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
            $status = $runner->status($database->migrationDriver());
            return [
                'ok' => count($status['failed']) === 0,
                'driver' => $status['driver'],
                'applied' => count($status['applied']),
                'pending' => count($status['pending']),
                'failed' => count($status['failed']),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $state */
    private function writeInstallState(array $state): void
    {
        $path = $this->rootPath . '/storage/app/install-state.json';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    }

    private function version(): string
    {
        return trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1';
    }
}
