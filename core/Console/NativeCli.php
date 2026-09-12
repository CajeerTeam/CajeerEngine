<?php

declare(strict_types=1);

namespace CajeerEngine\Console;

spl_autoload_register(static function (string $class): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $root = dirname(__DIR__, 2);
    $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Cms\CmsBootstrapService;
use CajeerEngine\Template\TemplateSandbox;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Public\PublicPageRenderer;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Installer\InstallerService;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Observability\SystemReport;
use CajeerEngine\Rc\ReleaseCandidateAuditor;
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use CajeerEngine\Server\NginxConfigGenerator;
use CajeerEngine\Server\ServerDoctorService;
use CajeerEngine\Server\ServerFixerService;
use CajeerEngine\Server\SystemdServiceGenerator;
use CajeerEngine\Stable\StableReleaseService;
use CajeerEngine\Update\UpdateResolver;
use CajeerEngine\Update\UpdateManager;
use CajeerEngine\ImportExport\ImportExportService;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Extension\Runtime\ExtensionRuntime;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Runtime\ServiceContainer;

final class NativeCli
{
    /** @param list<string> $argv */
    public static function run(string $rootPath, array $argv): int
    {
        self::loadEnv($rootPath);
        $command = $argv[1] ?? 'list';
        $json = in_array('--json', $argv, true);
        try {
            $config = new ConfigRepository($rootPath);
            $result = match ($command) {
                'list' => self::commands(),
                'rc:doctor' => (new ReleaseCandidateAuditor($rootPath))->run(),
                'system:report' => (new SystemReport($rootPath))->toArray(),
                'stable:status' => (new StableReleaseService($rootPath, $config))->status(),
                'stable:smoke-test' => (new StableReleaseService($rootPath, $config))->smokeTest(),
                'stable:lock' => (new StableReleaseService($rootPath, $config))->writeReleaseLock(),
                'doctor' => (new ServerDoctorService($rootPath))->run(!self::has($argv, 'no-report')),
                'fix' => (new ServerFixerService($rootPath))->fix(self::csvOption($argv, 'only'), !self::has($argv, 'yes')),
                'fix:permissions' => (new ServerFixerService($rootPath))->fixPermissions(!self::has($argv, 'yes')),
                'fix:env' => (new ServerFixerService($rootPath))->fixEnv(!self::has($argv, 'yes')),
                'fix:security' => (new ServerFixerService($rootPath))->fixSecurity(!self::has($argv, 'yes')),
                'fix:admin-assets' => (new ServerFixerService($rootPath))->fixAdminAssets(!self::has($argv, 'yes')),
                'fix:storage' => (new ServerFixerService($rootPath))->fixStorage(!self::has($argv, 'yes')),
                'nginx:config' => self::nginxConfig($rootPath, $argv),
                'scheduler:install' => self::systemd($rootPath, $argv, 'scheduler'),
                'worker:install' => self::systemd($rootPath, $argv, 'worker'),
                'post-install:report' => (new ServerDoctorService($rootPath))->postInstallReport(),
                'backup:create' => (new StableReleaseService($rootPath, $config))->backup(['include_uploads' => !self::has($argv, 'no-uploads')]),
                'install' => self::install($rootPath, $argv),
                'install:check' => (new InstallerService($rootPath))->requirements(self::option($argv, 'db') ?: self::option($argv, 'preset')),
                'security:doctor' => (new SecurityHardener($rootPath, $config))->diagnose(),
                'security:harden' => (new SecurityHardener($rootPath, $config))->hardenEnv(!in_array('--no-create', $argv, true)),
                'migrate' => self::migrate($rootPath, false, false),
                'migrate:status' => self::migrationStatus($rootPath),
                'migrate:repair' => self::migrate($rootPath, true, in_array('--run', $argv, true)),
                'release:build' => self::releaseBuild($rootPath, $argv),
                'release:artifacts' => (new ReleaseBuilder($rootPath))->buildAll(self::has($argv, 'dry-run'), self::option($argv, 'target-dir')),
                'release:checksums' => (new ReleaseBuilder($rootPath))->verifyArtifacts(self::option($argv, 'target-dir')),
                'release:verify' => (new ReleaseBuilder($rootPath))->plan(self::has($argv, 'dist') ? 'dist' : 'source', self::option($argv, 'target-dir')),
                'update' => self::update($rootPath, $config, $argv),
                'update:prepare' => (new UpdateManager($rootPath, $config))->prepare(self::updateOptions($argv)),
                'update:apply' => (new UpdateManager($rootPath, $config))->apply(self::updateOptions($argv) + ['dry_run' => self::has($argv, 'dry-run')]),
                'update:check' => (new UpdateManager($rootPath, $config))->check(),
                'update:rollback' => (new UpdateManager($rootPath, $config))->rollback(self::option($argv, 'backup')),
                'maintenance:on' => (new UpdateManager($rootPath, $config))->enableMaintenance(self::option($argv, 'message') ?: 'Обслуживание сайта.'),
                'maintenance:off' => (new UpdateManager($rootPath, $config))->disableMaintenance(),
                'maintenance:status' => (new UpdateManager($rootPath, $config))->maintenanceState(),
                'export:create' => (new ImportExportService($rootPath))->createExport(self::csvOption($argv, 'section') ?: [], self::option($argv, 'filename')),
                'export:list' => ['ok' => true, 'data' => (new ImportExportService($rootPath))->exports()],
                'import:diff' => self::importDiff($rootPath, $argv),
                'import:run' => self::importRun($rootPath, $argv),
                'admin:assets' => (new AdminAssetPublisher($rootPath))->manifest(true),
                'cms:bootstrap' => (new CmsBootstrapService($rootPath))->run(self::has($argv, 'demo-home')),
                'cms:sitemap' => self::cmsSitemap($rootPath, $config, $argv),
                'make:extension' => self::makeExtension($rootPath, $argv),
                'extension:list' => ['ok' => true, 'data' => (new ExtensionRegistry($rootPath, $config))->all(), 'meta' => (new ExtensionRegistry($rootPath, $config))->diagnostics()],
                'extension:doctor' => ['ok' => true, 'data' => (new ExtensionRegistry($rootPath, $config))->diagnostics()],
                'extension:runtime' => self::extensionRuntime($rootPath, $config)->diagnostics(),
                'extension:runtime:boot' => self::extensionRuntime($rootPath, $config)->boot(true),
                'extension:install' => self::extensionInstall($rootPath, $config, $argv),
                'extension:enable' => ['ok' => true, 'data' => (new ExtensionRegistry($rootPath, $config))->enable(self::argument($argv, 2, 'name'))],
                'extension:disable' => ['ok' => true, 'data' => (new ExtensionRegistry($rootPath, $config))->disable(self::argument($argv, 2, 'name'))],
                'extension:uninstall' => self::extensionRuntime($rootPath, $config)->uninstall(self::argument($argv, 2, 'name'), !self::has($argv, 'no-lifecycle'), self::has($argv, 'remove-assets')),
                'extension:assets' => self::extensionRuntime($rootPath, $config)->publishAssets(self::option($argv, 'name') ?: ($argv[2] ?? null), self::has($argv, 'dry-run')),
                'extension:migrate' => self::extensionRuntime($rootPath, $config)->migrate(self::option($argv, 'name') ?: ($argv[2] ?? null), self::has($argv, 'dry-run')),
                'extension:event:dispatch' => self::extensionEventDispatch($rootPath, $config, $argv),
                default => throw new \RuntimeException('Команда требует vendor/autoload.php или не поддерживается native fallback: ' . $command),
            };
            self::print($result, $json || in_array('--json', $argv, true));
            return self::ok($result) ? 0 : 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** @return array<string, mixed> */
    private static function commands(): array
    {
        return [
            'ok' => true,
            'mode' => 'native-no-vendor',
            'commands' => ['doctor','fix','fix:permissions','fix:env','fix:security','fix:admin-assets','fix:storage','nginx:config','scheduler:install','worker:install','post-install:report','backup:create','install','install:check','rc:doctor','system:report','stable:status','stable:smoke-test','stable:lock','security:doctor','security:harden','migrate','migrate:status','migrate:repair','release:build','release:artifacts','release:checksums','release:verify','update','update:prepare','update:apply','update:check','update:rollback','maintenance:on','maintenance:off','maintenance:status','export:create','export:list','import:diff','import:run','admin:assets','cms:bootstrap','cms:sitemap','make:extension','extension:list','extension:doctor','extension:runtime','extension:runtime:boot','extension:install','extension:enable','extension:disable','extension:uninstall','extension:assets','extension:migrate','extension:event:dispatch'],
            'message' => 'Symfony Console не найден, включён native fallback для эксплуатационных команд.',
        ];
    }


    /** @param list<string> $argv @return array<string, mixed> */
    private static function install(string $rootPath, array $argv): array
    {
        $options = [
            'source' => 'native-cli',
            'preset' => self::option($argv, 'preset'),
            'sqlite' => self::has($argv, 'sqlite'),
            'db' => self::option($argv, 'db'),
            'db_host' => self::option($argv, 'db-host'),
            'db_port' => self::option($argv, 'db-port'),
            'db_name' => self::option($argv, 'db-name'),
            'db_user' => self::option($argv, 'db-user'),
            'db_password' => self::option($argv, 'db-password'),
            'domain' => self::option($argv, 'domain'),
            'admin_name' => self::option($argv, 'admin-name'),
            'admin_email' => self::option($argv, 'admin-email'),
            'admin_password' => self::option($argv, 'admin-password'),
            'production' => self::has($argv, 'production'),
            'create_env' => !self::has($argv, 'no-env'),
            'run_migrations' => !self::has($argv, 'no-migrate'),
            'create_admin' => !self::has($argv, 'no-admin'),
            'write_lock' => !self::has($argv, 'no-lock'),
            'force' => self::has($argv, 'force'),
        ];

        if (self::has($argv, 'interactive')) {
            $options = self::interactiveInstallOptions($options);
        }

        $options = array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
        return (new InstallerService($rootPath))->install($options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private static function interactiveInstallOptions(array $options): array
    {
        echo "CajeerEngine Install Wizard\n";
        $preset = (string) ($options['preset'] ?: self::ask('Preset [sqlite/postgres/mysql/production/aapanel/custom]', 'sqlite'));
        $driverDefault = match ($preset) { 'sqlite' => 'sqlite', 'mysql' => 'mysql', default => 'pgsql' };
        $options['preset'] = $preset;
        $options['domain'] = (string) ($options['domain'] ?: self::ask('Domain', '127.0.0.1:8080'));
        $options['db'] = (string) ($options['db'] ?: self::ask('DB driver [sqlite/pgsql/mysql/mariadb]', $driverDefault));
        if ($options['db'] !== 'sqlite') {
            $options['db_host'] = (string) ($options['db_host'] ?: self::ask('DB host', '127.0.0.1'));
            $options['db_port'] = (string) ($options['db_port'] ?: self::ask('DB port', $options['db'] === 'mysql' ? '3306' : '5432'));
            $options['db_name'] = (string) ($options['db_name'] ?: self::ask('DB database', 'cajeerengine'));
            $options['db_user'] = (string) ($options['db_user'] ?: self::ask('DB user', 'cajeerengine'));
            $options['db_password'] = (string) ($options['db_password'] ?: self::askSecret('DB password'));
        } else {
            $options['db_name'] = (string) ($options['db_name'] ?: self::ask('SQLite path', 'storage/database/cajeer.sqlite'));
        }
        $options['admin_name'] = (string) ($options['admin_name'] ?: self::ask('Admin name', 'Administrator'));
        $options['admin_email'] = (string) ($options['admin_email'] ?: self::ask('Admin email'));
        $options['admin_password'] = (string) ($options['admin_password'] ?: self::askSecret('Admin password'));
        $options['production'] = (bool) ($options['production'] || self::confirm('Production hardening?', in_array($preset, ['production', 'aapanel', 'postgres'], true)));
        $options['create_env'] = self::confirm('Create/update .env?', true);
        $options['run_migrations'] = self::confirm('Run migrations?', true);
        $options['create_admin'] = self::confirm('Create first admin?', true);
        $options['write_lock'] = self::confirm('Create install lock?', true);
        return $options;
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function nginxConfig(string $rootPath, array $argv): array
    {
        $options = [
            'domain' => self::option($argv, 'domain') ?: 'example.ru',
            'preset' => self::option($argv, 'preset') ?: 'default',
            'root' => self::option($argv, 'root'),
            'php_socket' => self::option($argv, 'php-socket'),
            'client_max_body_size' => self::option($argv, 'client-max-body-size') ?: '64m',
            'output' => self::option($argv, 'output'),
        ];
        $options = array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
        $generator = new NginxConfigGenerator($rootPath);
        return self::has($argv, 'write') ? $generator->write($options) : $generator->generate($options);
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function systemd(string $rootPath, array $argv, string $kind): array
    {
        $options = [
            'php' => self::option($argv, 'php'),
            'user' => self::option($argv, 'user'),
            'group' => self::option($argv, 'group'),
            'workers' => self::option($argv, 'workers') ?: '1',
            'target_dir' => self::option($argv, 'target-dir'),
            'system' => self::has($argv, 'system'),
            'dry_run' => !self::has($argv, 'write'),
        ];
        $options = array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
        $generator = new SystemdServiceGenerator($rootPath);
        if (self::has($argv, 'print') || !self::has($argv, 'write')) {
            $preview = $generator->preview($options);
            $names = $kind === 'scheduler' ? ['cajeerengine-scheduler.service', 'cajeerengine-scheduler.timer'] : ['cajeerengine-worker.service'];
            return ['ok' => true, 'dry_run' => true, 'units' => array_intersect_key($preview['units'], array_flip($names)), 'unit_names' => $preview['unit_names'] ?? []];
        }
        return $kind === 'scheduler' ? $generator->writeScheduler($options) : $generator->writeWorker($options);
    }


    /** @param list<string> $argv @return array<string,mixed>|string */
    private static function cmsSitemap(string $rootPath, ConfigRepository $config, array $argv): array|string
    {
        $database = new DatabaseManager($config);
        $templatesRoot = $rootPath . '/templates';
        $renderer = new PublicPageRenderer(
            $rootPath,
            new ContentTypeRepository($rootPath, $database),
            new ContentEntryRepository($rootPath, $database),
            new CajeerTemplateEngine(new TemplateSandbox($templatesRoot), $templatesRoot, $rootPath . '/storage/cache/templates'),
            new MediaRepository($rootPath, new StorageManager($rootPath, $config)),
            new ThemeConfigRepository($rootPath, $database),
            new NavigationRepository($rootPath, $database),
        );

        if (self::has($argv, 'xml')) {
            return $renderer->sitemapXml(self::option($argv, 'base-url') ?: 'http://localhost');
        }

        $items = $renderer->sitemap();
        return [
            'ok' => true,
            'data' => $items,
            'meta' => ['total' => count($items)],
        ];
    }


    /** @param list<string> $argv @return array<string,mixed> */
    private static function importDiff(string $rootPath, array $argv): array
    {
        $file = $argv[2] ?? self::option($argv, 'file');
        if (!is_string($file) || trim($file) === '') {
            throw new \RuntimeException('Укажите файл: php bin/cajeer import:diff export.json --json');
        }
        return (new ImportExportService($rootPath))->diff($file, ['settings' => self::option($argv, 'settings') ?: 'merge']);
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function importRun(string $rootPath, array $argv): array
    {
        $file = $argv[2] ?? self::option($argv, 'file');
        if (!is_string($file) || trim($file) === '') {
            throw new \RuntimeException('Укажите файл: php bin/cajeer import:run export.json --json');
        }
        return (new ImportExportService($rootPath))->import($file, [
            'dry_run' => self::has($argv, 'dry-run'),
            'settings' => self::option($argv, 'settings') ?: 'merge',
            'create_missing_users' => self::has($argv, 'create-missing-users'),
            'delete_missing_content' => self::has($argv, 'delete-missing-content'),
        ]);
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function releaseBuild(string $rootPath, array $argv): array
    {
        $builder = new ReleaseBuilder($rootPath);
        if (self::has($argv, 'all')) {
            return $builder->buildAll(self::has($argv, 'dry-run'), self::option($argv, 'target-dir'));
        }
        $mode = self::has($argv, 'dist') ? 'dist' : 'source';
        return $builder->build(self::has($argv, 'dry-run'), $mode, self::option($argv, 'target-dir'));
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function update(string $rootPath, ConfigRepository $config, array $argv): array
    {
        $manager = new UpdateManager($rootPath, $config);
        $options = self::updateOptions($argv) + [
            'dry_run' => self::has($argv, 'dry-run'),
            'no_backup' => self::has($argv, 'no-backup'),
            'force' => self::has($argv, 'force'),
            'no_maintenance' => self::has($argv, 'no-maintenance'),
            'no_migrations' => self::has($argv, 'no-migrations'),
            'no_doctor' => self::has($argv, 'no-doctor'),
        ];
        if (self::has($argv, 'apply')) {
            return $manager->apply($options);
        }
        if (self::has($argv, 'prepare')) {
            return $manager->prepare($options);
        }
        return $manager->plan($options);
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function updateOptions(array $argv): array
    {
        return array_filter([
            'package' => self::option($argv, 'package'),
            'sha256' => self::option($argv, 'sha256'),
            'version' => self::option($argv, 'version'),
            'mode' => self::option($argv, 'mode') ?: 'dist',
            'no_backup' => self::has($argv, 'no-backup'),
            'force' => self::has($argv, 'force'),
            'no_maintenance' => self::has($argv, 'no-maintenance'),
            'no_migrations' => self::has($argv, 'no-migrations'),
            'no_doctor' => self::has($argv, 'no-doctor'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }



    /** @param list<string> $argv @return array<string,mixed> */
    private static function makeExtension(string $rootPath, array $argv): array
    {
        $name = strtolower(trim((string) ($argv[2] ?? self::option($argv, 'name') ?? '')));
        $type = strtolower(trim((string) ($argv[3] ?? self::option($argv, 'type') ?? 'module')));
        if (!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('name должен быть в формате vendor/name.');
        }
        if (!in_array($type, ['module', 'plugin', 'theme'], true)) {
            throw new \RuntimeException('type должен быть module, plugin или theme.');
        }
        $baseDir = match ($type) { 'module' => 'modules', 'plugin' => 'plugins', 'theme' => 'themes' };
        [$vendor, $shortName] = explode('/', $name, 2);
        $className = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $shortName))) . 'Extension';
        $vendorNs = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $vendor)));
        $shortNs = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $shortName)));
        $namespace = 'CajeerExtensions\\' . $vendorNs . '\\' . $shortNs;
        $dir = $rootPath . '/' . $baseDir . '/' . $shortName;
        if (is_dir($dir)) {
            throw new \RuntimeException('Расширение уже существует: ' . $dir);
        }
        foreach (['src', 'config', 'assets', 'migrations'] as $subdir) {
            mkdir($dir . '/' . $subdir, 0775, true);
        }
        file_put_contents($dir . '/README.md', "# {$name}\n\nRuntime extension for CajeerEngine Engine API ^1.1.\n");
        file_put_contents($dir . '/assets/admin.js', "console.info('CajeerEngine extension asset loaded: {$name}');\n");
        file_put_contents($dir . '/config/defaults.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn ['enabled_by_default' => false, 'message' => 'Hello from {$name}'];\n");
        file_put_contents($dir . '/cajeer.extension.json', json_encode([
            'name' => $name,
            'type' => $type,
            'version' => '0.1.0',
            'engine' => '^1.1',
            'title' => $name,
            'description' => 'Runtime-расширение CajeerEngine.',
            'permissions' => ['extension.runtime'],
            'events' => ['kernel.booted', 'content.saved'],
            'hooks' => ['content.saved' => 'onContentSaved'],
            'providers' => [$namespace . '\\' . $className],
            'assets' => ['source' => 'assets', 'public' => true],
            'migrations' => ['path' => 'migrations'],
            'config' => ['enabled_by_default' => false, 'message' => 'Hello from ' . $name],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $migration = <<<'PHP_STUB'
<?php

declare(strict_types=1);

use CajeerEngine\Extension\Runtime\ExtensionContext;

return static function (ExtensionContext $context, ?PDO $pdo = null): void {
    $paths = $context->paths();
    if (!is_dir($paths['storage'])) {
        mkdir($paths['storage'], 0775, true);
    }
    file_put_contents($paths['storage'] . '/migration-marker.txt', 'installed=' . date(DATE_ATOM) . PHP_EOL, FILE_APPEND);
};
PHP_STUB;
        file_put_contents($dir . '/migrations/0001_create_runtime_marker.php', $migration);
        $provider = <<<PHP_STUB
<?php

declare(strict_types=1);

namespace {$namespace};

use CajeerEngine\\Extension\\Contracts\\AbstractExtensionProvider;
use CajeerEngine\\Extension\\Runtime\\ExtensionContext;
use CajeerEngine\\Runtime\\RuntimeEvent;
use CajeerEngine\\Runtime\\ServiceContainer;

final class {$className} extends AbstractExtensionProvider
{
    public function register(ServiceContainer \$container, ExtensionContext \$context): void
    {
        \$container->instance('extension.' . \$context->slug() . '.config', \$context->extensionConfig());
    }

    public function boot(ExtensionContext \$context): void
    {
        \$context->log('{$name}.booted', ['config' => \$context->extensionConfig()]);
    }

    public function install(ExtensionContext \$context): void
    {
        \$context->log('{$name}.installed');
    }

    public function uninstall(ExtensionContext \$context): void
    {
        \$context->log('{$name}.uninstalled');
    }

    public function onContentSaved(RuntimeEvent \$event, ExtensionContext \$context): void
    {
        \$context->log('{$name}.content_saved', \$event->payload);
    }
}
PHP_STUB;
        file_put_contents($dir . '/src/' . $className . '.php', $provider);
        return ['ok' => true, 'data' => ['name' => $name, 'type' => $type, 'path' => $baseDir . '/' . $shortName, 'provider' => $namespace . '\\' . $className]];
    }

    private static function extensionRuntime(string $rootPath, ConfigRepository $config): ExtensionRuntime
    {
        $container = new ServiceContainer();
        $logger = new RuntimeLogger($rootPath);
        $events = new EventDispatcher($logger);
        $container->instance('root_path', $rootPath);
        $container->instance(ConfigRepository::class, $config);
        $container->instance(RuntimeLogger::class, $logger);
        $container->instance(EventDispatcher::class, $events);
        return new ExtensionRuntime($rootPath, new ExtensionRegistry($rootPath, $config), $container, $config, $events, $logger, new DatabaseManager($config));
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function extensionInstall(string $rootPath, ConfigRepository $config, array $argv): array
    {
        $target = self::option($argv, 'target') ?: ($argv[2] ?? '');
        if (!is_string($target) || trim($target) === '') {
            throw new \RuntimeException('Укажите расширение: php bin/cajeer extension:install vendor/name --json');
        }
        $item = self::extensionRuntime($rootPath, $config)->install($target, self::has($argv, 'enable'), !self::has($argv, 'no-lifecycle'), !self::has($argv, 'no-assets'), !self::has($argv, 'no-migrations'));
        return ['ok' => true, 'data' => $item];
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function extensionEventDispatch(string $rootPath, ConfigRepository $config, array $argv): array
    {
        $event = self::option($argv, 'event') ?: ($argv[2] ?? '');
        if (!is_string($event) || trim($event) === '') {
            throw new \RuntimeException('Укажите событие: php bin/cajeer extension:event:dispatch content.saved --json');
        }
        $payloadJson = self::option($argv, 'payload') ?: '{}';
        $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        return self::extensionRuntime($rootPath, $config)->dispatch($event, is_array($payload) ? $payload : []);
    }

    /** @param list<string> $argv */
    private static function argument(array $argv, int $index, string $name): string
    {
        $value = $argv[$index] ?? self::option($argv, $name);
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Не указан аргумент: ' . $name);
        }
        return $value;
    }

    /** @param list<string> $argv @return list<string>|null */
    private static function csvOption(array $argv, string $name): ?array
    {
        $value = self::option($argv, $name);
        if ($value === null || trim($value) === '') {
            return null;
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /** @param list<string> $argv */
    private static function option(array $argv, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($argv as $i => $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
            if ($arg === '--' . $name && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                return $argv[$i + 1];
            }
        }
        return null;
    }

    /** @param list<string> $argv */
    private static function has(array $argv, string $name): bool
    {
        return in_array('--' . $name, $argv, true) || self::option($argv, $name) !== null;
    }

    private static function ask(string $label, ?string $default = null): string
    {
        $suffix = $default !== null && $default !== '' ? ' [' . $default . ']' : '';
        echo $label . $suffix . ': ';
        $value = trim((string) fgets(STDIN));
        return $value !== '' ? $value : (string) $default;
    }

    private static function askSecret(string $label): string
    {
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            echo $label . ': ';
            system('stty -echo');
            $value = trim((string) fgets(STDIN));
            system('stty echo');
            echo PHP_EOL;
            return $value;
        }
        return self::ask($label);
    }

    private static function confirm(string $label, bool $default): bool
    {
        $answer = strtolower(self::ask($label . ' ' . ($default ? '[Y/n]' : '[y/N]'), $default ? 'y' : 'n'));
        return in_array($answer, ['y', 'yes', 'д', 'да', '1', 'true'], true);
    }

    /** @return array<string, mixed> */
    private static function migrate(string $rootPath, bool $repair, bool $run): array
    {
        $config = new ConfigRepository($rootPath);
        $database = new DatabaseManager($config);
        $runner = new MigrationRunner($database->connection(), $rootPath . '/migrations');
        return $repair ? $runner->repairFailed($database->migrationDriver(), $run) : $runner->run($database->migrationDriver());
    }

    /** @return array<string, mixed> */
    private static function migrationStatus(string $rootPath): array
    {
        $config = new ConfigRepository($rootPath);
        $database = new DatabaseManager($config);
        $runner = new MigrationRunner($database->connection(), $rootPath . '/migrations');
        return $runner->status($database->migrationDriver());
    }

    /** @param array<string, mixed> $result */
    private static function print(array|string $result, bool $json): void
    {
        if (is_string($result)) {
            echo $result . PHP_EOL;
            return;
        }
        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return;
        }
        foreach ($result as $key => $value) {
            if (is_scalar($value) || $value === null) {
                echo $key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) . PHP_EOL;
            }
        }
    }

    /** @param array<string, mixed>|string $result */
    private static function ok(array|string $result): bool
    {
        if (is_string($result)) {
            return true;
        }
        if (array_key_exists('ok', $result)) {
            return (bool) $result['ok'];
        }
        if (array_key_exists('ready', $result)) {
            return (bool) $result['ready'];
        }
        if (array_key_exists('stable', $result)) {
            return (bool) $result['stable'];
        }
        return true;
    }

    private static function loadEnv(string $rootPath): void
    {
        $path = $rootPath . '/.env';
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

    private static function version(string $rootPath): string
    {
        return trim((string) @file_get_contents($rootPath . '/VERSION')) ?: '1.1.1';
    }
}
