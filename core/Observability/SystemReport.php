<?php

declare(strict_types=1);

namespace CajeerEngine\Observability;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Update\UpdateResolver;
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Installer\InstallerService;
use CajeerEngine\ImportExport\ImportExportService;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Template\TemplateSandbox;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\ProjectInfo;
use CajeerEngine\Rc\ReleaseCandidateAuditor;
use CajeerEngine\Stable\StableReleaseService;

final readonly class SystemReport
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $project = new ProjectInfo($this->rootPath);
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $contentTypes = new ContentTypeRepository($this->rootPath, $database);
        $entries = new ContentEntryRepository($this->rootPath, $database);
        $entryCounts = $entries->counts();
        $audit = new AuditLogger($this->rootPath);
        $roles = new RoleRepository($database);
        $users = new UserRepository($database, $roles);
        $tokens = new ApiTokenRepository($database);
        $adminAssets = new AdminAssetPublisher($this->rootPath);
        $adminManifest = $adminAssets->manifest(false);
        $storage = new StorageManager($this->rootPath, $config);
        $media = new MediaRepository($this->rootPath, $storage);
        $mediaDiagnostics = $media->diagnostics();
        $templateDiagnostics = $this->templateDiagnostics();
        $autoloadOk = is_file($this->rootPath . '/vendor/autoload.php') || is_dir($this->rootPath . '/core');
        $dbHealth = $database->health();
        $migrationSummary = $this->migrationSummary($database);
        $queueDiagnostics = (new QueueFactory($this->rootPath, $config, $database))->make()->diagnostics();
        $searchDiagnostics = (new SearchFactory($this->rootPath, $config, $database))->make()->diagnostics();
        $webhookDiagnostics = (new WebhookRepository($this->rootPath))->diagnostics();
        $extensionRegistry = new ExtensionRegistry($this->rootPath, $config);
        $extensionDiagnostics = $extensionRegistry->diagnostics();
        $installerRequirements = (new InstallerService($this->rootPath))->requirements();
        $importExport = new ImportExportService($this->rootPath);
        $exports = $importExport->exports();
        $updates = new UpdateResolver($config->get('updates', []), $this->rootPath);
        $updateDiagnostics = $updates->diagnostics($project->version());
        $releasePlan = (new ReleaseBuilder($this->rootPath))->plan();
        $rcReadiness = (new ReleaseCandidateAuditor($this->rootPath))->summaryOnly();
        $stableStatus = (new StableReleaseService($this->rootPath, $config))->status();

        return [
            'engine' => [
                'name' => $project->title(),
                'package' => $project->name(),
                'version' => $project->version(),
                'engine_api_version' => $project->engineApiVersion(),
                'api_version' => $config->string('app.api_version', 'v1'),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'timezone' => date_default_timezone_get(),
                'memory_limit' => ini_get('memory_limit'),
                'environment' => $config->string('app.env', 'production'),
                'debug' => $config->bool('app.debug'),
                'boot_config_loaded' => $config->has('app.name'),
                'extensions' => [
                    'json' => extension_loaded('json'),
                    'mbstring' => extension_loaded('mbstring'),
                    'openssl' => extension_loaded('openssl'),
                    'pdo' => extension_loaded('pdo'),
                    'pdo_pgsql' => extension_loaded('pdo_pgsql'),
                    'pdo_mysql' => extension_loaded('pdo_mysql'),
                    'redis' => extension_loaded('redis'),
                ],
            ],
            'config' => $config->safePublicConfig(),
            'database' => [
                'health' => $dbHealth,
                'migrations' => $migrationSummary,
            ],
            'paths' => [
                'root' => $this->rootPath,
                'public' => $this->rootPath . '/public',
                'storage' => $this->rootPath . '/storage',
                'storage_writable' => $this->directoryWritable($this->rootPath . '/storage'),
                'cache_writable' => $this->directoryWritable($this->rootPath . '/storage/cache'),
                'logs_writable' => $this->directoryWritable($this->rootPath . '/storage/logs'),
                'app_writable' => $this->directoryWritable($this->rootPath . '/storage/app'),
                'runtime_log' => $this->rootPath . '/storage/logs/runtime.jsonl',
                'audit_log' => $this->rootPath . '/storage/logs/audit.jsonl',
            ],
            'product' => [
                'autoload_mapping' => 'CajeerEngine\\ => core/',
                'autoload_ready' => $autoloadOk,
                'content_types' => count($contentTypes->all()),
                'content_entries' => $entryCounts['entries'],
                'published_entries' => $entryCounts['published'],
                'draft_entries' => $entryCounts['drafts'],
                'archived_entries' => $entryCounts['archived'] ?? 0,
                'entry_revisions' => $entryCounts['revisions'],
                'audit_events' => $audit->count(),
                'runtime_log_events' => $this->jsonlCount($this->rootPath . '/storage/logs/runtime.jsonl'),
                'content_storage' => $entries->storage(),
                'content_storage_detail' => $entries->storage() === 'database' ? 'pdo:' . $database->driver() . ':ce_content_entries' : 'file:storage/app/content/*.json',
                'database_storage' => 'pdo:' . $database->driver(),
                'database_content_core_ready' => $database->contentCoreReady(),
                'database_security_core_ready' => $database->securityCoreReady(),
                'database_schema_ready' => (bool) ($dbHealth['schema_ready'] ?? false),
                'security_users' => $this->safeCount(fn (): int => $users->count()),
                'security_roles' => $this->safeCount(fn (): int => count($roles->all())),
                'security_api_tokens' => $this->safeCount(fn (): int => $tokens->count()),
                'auth_required' => $config->bool('security.api_auth_required', false),
                'content_write_auth' => $config->bool('security.protect_content_writes', true),
                'system_routes_protected' => $config->bool('security.protect_system_routes', true),
                'release_builder' => is_file($this->rootPath . '/tools/build-release.php'),
                'admin_ui_ready' => (bool) ($adminManifest['ready'] ?? false),
                'admin_ui_mode' => (string) ($adminManifest['mode'] ?? 'unknown'),
                'template_engine_ready' => $templateDiagnostics['ready'],
                'template_cache_writable' => $templateDiagnostics['cache_writable'],
                'template_default_page_renders' => $templateDiagnostics['default_page_renders'],
                'media_items' => (int) ($mediaDiagnostics['items'] ?? 0),
                'media_bytes' => (int) ($mediaDiagnostics['bytes'] ?? 0),
                'media_missing_files' => (int) ($mediaDiagnostics['missing_files'] ?? 0),
                'storage_driver' => $storage->driver(),
                'storage_local_probe_write' => (bool) ($storage->diagnostics()['local_probe_write'] ?? false),
                'queue_driver' => (string) ($queueDiagnostics['driver'] ?? 'file'),
                'queue_queues' => count((array) ($queueDiagnostics['queues'] ?? [])),
                'queue_writable' => (bool) ($queueDiagnostics['writable'] ?? false),
                'search_driver' => (string) ($searchDiagnostics['driver'] ?? 'file'),
                'search_documents' => (int) ($searchDiagnostics['documents'] ?? 0),
                'search_writable' => (bool) ($searchDiagnostics['writable'] ?? false),
                'webhooks' => (int) ($webhookDiagnostics['webhooks'] ?? 0),
                'webhook_deliveries' => (int) ($webhookDiagnostics['deliveries'] ?? 0),
                'pending_outbox' => (int) ($webhookDiagnostics['pending_outbox'] ?? 0),
                'extensions_driver' => (string) ($extensionDiagnostics['driver'] ?? 'file'),
                'extensions_discovered' => (int) ($extensionDiagnostics['discovered'] ?? 0),
                'extensions_installed' => (int) ($extensionDiagnostics['installed'] ?? 0),
                'extensions_enabled' => (int) ($extensionDiagnostics['enabled'] ?? 0),
                'extensions_invalid' => (int) ($extensionDiagnostics['invalid'] ?? 0),
                'extension_permissions' => (int) ($extensionDiagnostics['permissions'] ?? 0),
                'extension_event_subscriptions' => (int) ($extensionDiagnostics['event_subscriptions'] ?? 0),
                'extension_registry_writable' => (bool) ($extensionDiagnostics['state_writable'] ?? false),
                'installer_ready' => (bool) ($installerRequirements['ready'] ?? false),
                'installer_checks' => count((array) ($installerRequirements['checks'] ?? [])),
                'exports' => count($exports),
                'exports_dir' => $this->rootPath . '/storage/app/exports',
                'imports_dir' => $this->rootPath . '/storage/app/imports',
                'updates_channel' => (string) ($updateDiagnostics['channel'] ?? 'stable'),
                'updates_metadata_source' => (string) (($updateDiagnostics['last_check']['metadata_source'] ?? null) ?: ($updateDiagnostics['releases_url'] ?? 'local')),
                'updates_last_check' => $updateDiagnostics['last_check']['checked_at'] ?? null,
                'release_builder_files' => (int) ($releasePlan['file_count'] ?? 0),
                'release_builder_target' => (string) ($releasePlan['target'] ?? ''),
                'admin_source_pages' => (int) ($adminManifest['source']['pages'] ?? 0),
                'admin_source_components' => (int) ($adminManifest['source']['components'] ?? 0),
                'admin_source_composables' => (int) ($adminManifest['source']['composables'] ?? 0),
                'rc_ready' => (bool) ($rcReadiness['ready'] ?? false),
                'rc_checks_total' => (int) ($rcReadiness['summary']['total'] ?? 0),
                'rc_checks_failed' => (int) ($rcReadiness['summary']['fail'] ?? 0),
                'rc_checks_warn' => (int) ($rcReadiness['summary']['warn'] ?? 0),
                'stable_release_ready' => (bool) ($stableStatus['stable'] ?? false),
                'stable_release_lock_exists' => (bool) ($stableStatus['checks']['release_lock_exists'] ?? false),
                'stable_backup_dir' => (string) ($stableStatus['paths']['backup_dir'] ?? ''),
                'stable_support_dir' => (string) ($stableStatus['paths']['support_dir'] ?? ''),
            ],
        ];
    }

    private function directoryWritable(string $path): bool
    {
        if (!is_dir($path)) {
            return is_writable(dirname($path));
        }

        return is_writable($path);
    }

    private function jsonlCount(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $count = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }


    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return 0;
        }
    }


    /** @return array<string, mixed> */
    private function templateDiagnostics(): array
    {
        $templatesRoot = $this->rootPath . '/templates';
        $cacheRoot = $this->rootPath . '/storage/cache/templates';
        $ready = is_file($templatesRoot . '/default/page.cjr') && is_file($templatesRoot . '/default/layouts/app.cjr');
        $renders = false;
        $error = null;
        try {
            if ($ready) {
                $engine = new CajeerTemplateEngine(new TemplateSandbox($templatesRoot), $templatesRoot, $cacheRoot);
                $html = $engine->renderName('default.page', [
                    'title' => 'Diagnostics',
                    'summary' => '',
                    'content' => '<p>OK</p>',
                ]);
                $renders = str_contains($html, 'Diagnostics') && str_contains($html, '<p>OK</p>');
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'ready' => $ready,
            'cache_writable' => is_dir($cacheRoot) ? is_writable($cacheRoot) : is_writable(dirname($cacheRoot)),
            'default_page_renders' => $renders,
            'error' => $error,
        ];
    }

    /** @return array<string, mixed> */
    private function migrationSummary(DatabaseManager $database): array
    {
        try {
            $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
            $status = $runner->status($database->migrationDriver());
            return [
                'ok' => count($status['failed']) === 0 && count($status['pending']) === 0,
                'driver' => $status['driver'],
                'applied' => count($status['applied']),
                'pending' => count($status['pending']),
                'failed' => count($status['failed']),
                'failed_migrations' => array_map(static fn (array $row): array => [
                    'migration' => (string) ($row['migration'] ?? ''),
                    'error' => (string) ($row['error'] ?? ''),
                    'applied_at' => (string) ($row['applied_at'] ?? ''),
                ], $status['failed']),
                'pending_migrations' => array_map(static fn (array $row): string => (string) ($row['migration'] ?? ''), $status['pending']),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
