<?php

declare(strict_types=1);

namespace CajeerEngine\Kernel;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Api\Controller\ImportExportController;
use CajeerEngine\Api\Controller\InstallerController;
use CajeerEngine\Api\Controller\UpdateController;
use CajeerEngine\Api\Controller\ReleaseCandidateController;
use CajeerEngine\ImportExport\ImportExportService;
use CajeerEngine\Installer\InstallerService;
use CajeerEngine\Api\Controller\AdminController;
use CajeerEngine\Api\Controller\ApiTokenController;
use CajeerEngine\Api\Controller\AuthController;
use CajeerEngine\Api\Controller\ContentEntryController;
use CajeerEngine\Api\Controller\DatabaseController;
use CajeerEngine\Api\Controller\ContentTypeController;
use CajeerEngine\Api\Controller\MediaController;
use CajeerEngine\Api\Controller\CmsController;
use CajeerEngine\Api\Controller\PublicController;
use CajeerEngine\Api\Controller\QueueController;
use CajeerEngine\Api\Controller\SchedulerController;
use CajeerEngine\Api\Controller\SearchController;
use CajeerEngine\Api\Controller\WebhookController;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Api\Controller\ExtensionController;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Extension\Runtime\ExtensionRuntime;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Queue\QueueWorker;
use CajeerEngine\Scheduler\SchedulerRunner;
use CajeerEngine\Search\SearchEngineInterface;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Search\SearchIndexer;
use CajeerEngine\Search\SearchService;
use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Public\PublicPageRenderer;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Template\TemplateSandbox;
use CajeerEngine\Api\Controller\HealthController;
use CajeerEngine\Api\Controller\RoleController;
use CajeerEngine\Api\Controller\RuntimeController;
use CajeerEngine\Api\Controller\SecurityController;
use CajeerEngine\Api\Controller\UserController;
use CajeerEngine\Api\Controller\SystemController;
use CajeerEngine\Stable\StableReleaseService;
use CajeerEngine\Api\Controller\StableController;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Cms\CmsBootstrapService;
use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\PreviewTokenService;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Http\Middleware\AuthenticationMiddleware;
use CajeerEngine\Http\Middleware\ErrorHandlerMiddleware;
use CajeerEngine\Http\Middleware\JsonBodyMiddleware;
use CajeerEngine\Http\Middleware\MiddlewarePipeline;
use CajeerEngine\Http\Middleware\RateLimitMiddleware;
use CajeerEngine\Http\Middleware\RequestIdMiddleware;
use CajeerEngine\Http\Middleware\RuntimeLogMiddleware;
use CajeerEngine\Http\Middleware\SecurityHeadersMiddleware;
use CajeerEngine\Http\Router;
use CajeerEngine\Observability\SystemReport;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\RuntimeEvent;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Runtime\ServiceContainer;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Security\RateLimiter;
use CajeerEngine\Security\TwoFactorTotp;
use CajeerEngine\Support\ProjectInfo;
use CajeerEngine\Rc\ReleaseCandidateAuditor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Application
{
    private function __construct(
        private readonly string $rootPath,
        private readonly ConfigRepository $config,
        private readonly ServiceContainer $container,
        private readonly Router $router,
        private readonly MiddlewarePipeline $pipeline,
        private readonly EventDispatcher $events,
    ) {
    }

    public static function boot(string $rootPath): self
    {
        self::ensureRuntimeDirectories($rootPath);

        $config = new ConfigRepository($rootPath);
        $logger = new RuntimeLogger($rootPath);
        $events = new EventDispatcher($logger);
        $container = new ServiceContainer();
        $router = new Router();

        self::registerServices($container, $rootPath, $config, $logger, $events, $router);
        /** @var ExtensionRuntime $extensionRuntime */
        $extensionRuntime = $container->get(ExtensionRuntime::class);
        $extensionRuntime->boot();

        $authService = $container->get(AuthService::class);
        $middleware = [
            new RequestIdMiddleware(),
            new SecurityHeadersMiddleware(),
            new ErrorHandlerMiddleware($config, $logger),
            new JsonBodyMiddleware(),
            new RateLimitMiddleware($config, $container->get(RateLimiter::class)),
            new AuthenticationMiddleware($config, $authService),
            new RuntimeLogMiddleware($logger),
        ];
        $pipeline = new MiddlewarePipeline($middleware);
        $container->instance('http.middleware', $pipeline->names());
        self::registerRoutes($router, $container);

        $events->dispatch(new RuntimeEvent('kernel.booted', [
            'version' => $config->string('app.version'),
            'env' => $config->string('app.env', 'production'),
            'routes' => count($router->routes()),
            'middleware' => count($pipeline->names()),
        ]));

        return new self($rootPath, $config, $container, $router, $pipeline, $events);
    }

    public function handle(Request $request): Response
    {
        $response = $this->pipeline->handle($request, fn (Request $request): Response => $this->router->dispatch($request));
        $response->headers->set('X-CajeerEngine-Version', $this->config->string('app.version', '1.1.1'));
        $response->headers->set('X-CajeerEngine-Database', $this->config->string('database.default', 'pgsql'));
        $this->events->dispatch(new RuntimeEvent('kernel.request_handled', [
            'request_id' => (string) $request->attributes->get('request_id', ''),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'route' => (string) $request->attributes->get('route_name', ''),
        ]));
        return $response;
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function config(): ConfigRepository
    {
        return $this->config;
    }

    public function container(): ServiceContainer
    {
        return $this->container;
    }

    /** @return list<array{name:string,path:string,methods:list<string>}> */
    public function routeList(): array
    {
        return $this->router->routes();
    }

    private static function registerServices(ServiceContainer $container, string $rootPath, ConfigRepository $config, RuntimeLogger $logger, EventDispatcher $events, Router $router): void
    {
        $container->instance('root_path', $rootPath);
        $container->instance(ConfigRepository::class, $config);
        $container->instance(RuntimeLogger::class, $logger);
        $container->instance(EventDispatcher::class, $events);
        $container->instance(Router::class, $router);
        $container->set(ProjectInfo::class, fn (): ProjectInfo => new ProjectInfo($rootPath));
        $container->set(AuditLogger::class, fn (): AuditLogger => new AuditLogger($rootPath));
        $container->set(DatabaseManager::class, fn (ServiceContainer $c): DatabaseManager => new DatabaseManager($c->get(ConfigRepository::class)));
        $container->set(RoleRepository::class, fn (ServiceContainer $c): RoleRepository => new RoleRepository($c->get(DatabaseManager::class)));
        $container->set(UserRepository::class, fn (ServiceContainer $c): UserRepository => new UserRepository($c->get(DatabaseManager::class), $c->get(RoleRepository::class), $c->get(PasswordHasher::class)));
        $container->set(ApiTokenRepository::class, fn (ServiceContainer $c): ApiTokenRepository => new ApiTokenRepository($c->get(DatabaseManager::class)));
        $container->set(AuditLogRepository::class, fn (ServiceContainer $c): AuditLogRepository => new AuditLogRepository($c->get(DatabaseManager::class)));
        $container->set(PasswordHasher::class, fn (ServiceContainer $c): PasswordHasher => new PasswordHasher($c->get(ConfigRepository::class)));
        $container->set(TwoFactorTotp::class, fn (): TwoFactorTotp => new TwoFactorTotp());
        $container->set(RateLimiter::class, fn (): RateLimiter => new RateLimiter($rootPath));
        $container->set(AuthService::class, fn (ServiceContainer $c): AuthService => new AuthService(
            $c->get(ConfigRepository::class),
            $c->get(UserRepository::class),
            $c->get(ApiTokenRepository::class),
            $c->get(AuditLogRepository::class),
            $c->get(PasswordHasher::class),
            $c->get(TwoFactorTotp::class),
        ));
        $container->set(ContentTypeRepository::class, fn (ServiceContainer $c): ContentTypeRepository => new ContentTypeRepository($rootPath, $c->get(DatabaseManager::class)));
        $container->set(ContentEntryRepository::class, fn (ServiceContainer $c): ContentEntryRepository => new ContentEntryRepository($rootPath, $c->get(DatabaseManager::class)));
        $container->set(SystemReport::class, fn (): SystemReport => new SystemReport($rootPath));
        $container->set(AdminAssetPublisher::class, fn (): AdminAssetPublisher => new AdminAssetPublisher($rootPath));
        $container->set(StorageManager::class, fn (ServiceContainer $c): StorageManager => new StorageManager($rootPath, $c->get(ConfigRepository::class)));
        $container->set(MediaRepository::class, fn (ServiceContainer $c): MediaRepository => new MediaRepository($rootPath, $c->get(StorageManager::class)));
        $container->set(ThemeConfigRepository::class, fn (ServiceContainer $c): ThemeConfigRepository => new ThemeConfigRepository($rootPath, $c->get(DatabaseManager::class)));
        $container->set(NavigationRepository::class, fn (ServiceContainer $c): NavigationRepository => new NavigationRepository($rootPath, $c->get(DatabaseManager::class)));
        $container->set(RedirectRepository::class, fn (ServiceContainer $c): RedirectRepository => new RedirectRepository($rootPath, $c->get(DatabaseManager::class)));
        $container->set(PreviewTokenService::class, fn (ServiceContainer $c): PreviewTokenService => new PreviewTokenService($rootPath, $c->get(DatabaseManager::class)));
        $container->set(CmsBootstrapService::class, fn (): CmsBootstrapService => new CmsBootstrapService($rootPath));
        $container->set(CajeerTemplateEngine::class, fn (): CajeerTemplateEngine => new CajeerTemplateEngine(
            new TemplateSandbox($rootPath . '/templates'),
            $rootPath . '/templates',
            $rootPath . '/storage/cache/templates',
        ));
        $container->set(PublicPageRenderer::class, fn (ServiceContainer $c): PublicPageRenderer => new PublicPageRenderer(
            $rootPath,
            $c->get(ContentTypeRepository::class),
            $c->get(ContentEntryRepository::class),
            $c->get(CajeerTemplateEngine::class),
            $c->get(MediaRepository::class),
            $c->get(ThemeConfigRepository::class),
            $c->get(NavigationRepository::class),
        ));

        $container->set(ManagedQueueDriverInterface::class, fn (ServiceContainer $c): ManagedQueueDriverInterface => (new QueueFactory($rootPath, $c->get(ConfigRepository::class), $c->get(DatabaseManager::class)))->make());
        $container->set(SearchEngineInterface::class, fn (ServiceContainer $c): SearchEngineInterface => (new SearchFactory($rootPath, $c->get(ConfigRepository::class), $c->get(DatabaseManager::class)))->make());
        $container->set(SearchIndexer::class, fn (ServiceContainer $c): SearchIndexer => new SearchIndexer(
            $c->get(ContentTypeRepository::class),
            $c->get(ContentEntryRepository::class),
            $c->get(SearchEngineInterface::class),
        ));
        $container->set(SearchService::class, fn (ServiceContainer $c): SearchService => new SearchService(
            $c->get(SearchEngineInterface::class),
            $c->get(SearchIndexer::class),
        ));
        $container->set(WebhookRepository::class, fn (): WebhookRepository => new WebhookRepository($rootPath));
        $container->set(SignedPayload::class, fn (): SignedPayload => new SignedPayload());
        $container->set(WebhookDispatcher::class, fn (ServiceContainer $c): WebhookDispatcher => new WebhookDispatcher(
            $c->get(SignedPayload::class),
            $c->get(WebhookRepository::class),
        ));
        $container->set(ExtensionRegistry::class, fn (ServiceContainer $c): ExtensionRegistry => new ExtensionRegistry($rootPath, $c->get(ConfigRepository::class)));
        $container->set(ExtensionRuntime::class, fn (ServiceContainer $c): ExtensionRuntime => new ExtensionRuntime(
            $rootPath,
            $c->get(ExtensionRegistry::class),
            $c,
            $c->get(ConfigRepository::class),
            $c->get(EventDispatcher::class),
            $c->get(RuntimeLogger::class),
            $c->get(DatabaseManager::class),
        ));
        $container->set(InstallerService::class, fn (): InstallerService => new InstallerService($rootPath));
        $container->set(ImportExportService::class, fn (): ImportExportService => new ImportExportService($rootPath));
        $container->set(ReleaseCandidateAuditor::class, fn (): ReleaseCandidateAuditor => new ReleaseCandidateAuditor($rootPath));
        $container->set(StableReleaseService::class, fn (ServiceContainer $c): StableReleaseService => new StableReleaseService($rootPath, $c->get(ConfigRepository::class)));
        $container->set(QueueWorker::class, fn (ServiceContainer $c): QueueWorker => new QueueWorker(
            $c->get(ManagedQueueDriverInterface::class),
            $c->get(SearchIndexer::class),
            $c->get(WebhookRepository::class),
            $c->get(WebhookDispatcher::class),
        ));
        $container->set(SchedulerRunner::class, fn (ServiceContainer $c): SchedulerRunner => new SchedulerRunner(
            $rootPath,
            $c->get(SearchIndexer::class),
            $c->get(WebhookRepository::class),
            $c->get(WebhookDispatcher::class),
            $c->get(ManagedQueueDriverInterface::class),
        ));

        $container->set('controller.health', fn (): HealthController => new HealthController($rootPath));
        $container->set('controller.system', fn (): SystemController => new SystemController($rootPath));
        $container->set('controller.content_types', fn (): ContentTypeController => new ContentTypeController($rootPath));
        $container->set('controller.content_entries', fn (): ContentEntryController => new ContentEntryController($rootPath));
        $container->set('controller.runtime', fn (ServiceContainer $c): RuntimeController => new RuntimeController(
            $c->get(ConfigRepository::class),
            $c,
            $c->get(Router::class),
            $c->get(EventDispatcher::class),
            $c->get('http.middleware'),
        ));
        $container->set('controller.database', fn (ServiceContainer $c): DatabaseController => new DatabaseController(
            $c->get(DatabaseManager::class),
            $rootPath,
        ));
        $container->set('controller.auth', fn (ServiceContainer $c): AuthController => new AuthController($c->get(AuthService::class)));
        $container->set('controller.users', fn (ServiceContainer $c): UserController => new UserController($c->get(UserRepository::class), $c->get(PasswordHasher::class)));
        $container->set('controller.roles', fn (ServiceContainer $c): RoleController => new RoleController($c->get(RoleRepository::class)));
        $container->set('controller.api_tokens', fn (ServiceContainer $c): ApiTokenController => new ApiTokenController($c->get(ApiTokenRepository::class)));
        $container->set('controller.security', fn (ServiceContainer $c): SecurityController => new SecurityController(
            $c->get(ConfigRepository::class),
            $c->get(DatabaseManager::class),
            $c->get(UserRepository::class),
            $c->get(RoleRepository::class),
            $c->get(ApiTokenRepository::class),
        ));
        $container->set('controller.admin', fn (ServiceContainer $c): AdminController => new AdminController(
            $c->get(ConfigRepository::class),
            $c->get(SystemReport::class),
            $c->get(ContentTypeRepository::class),
            $c->get(ContentEntryRepository::class),
            $c->get(UserRepository::class),
            $c->get(RoleRepository::class),
            $c->get(ApiTokenRepository::class),
            $c->get(AdminAssetPublisher::class),
            $c->get(ExtensionRegistry::class),
        ));

        $container->set('controller.search', fn (ServiceContainer $c): SearchController => new SearchController($c->get(SearchService::class)));
        $container->set('controller.webhooks', fn (ServiceContainer $c): WebhookController => new WebhookController($c->get(WebhookRepository::class), $c->get(WebhookDispatcher::class)));
        $container->set('controller.extensions', fn (ServiceContainer $c): ExtensionController => new ExtensionController($c->get(ExtensionRegistry::class), $c->get(ExtensionRuntime::class)));
        $container->set('controller.installer', fn (ServiceContainer $c): InstallerController => new InstallerController($c->get(InstallerService::class)));
        $container->set('controller.import_export', fn (ServiceContainer $c): ImportExportController => new ImportExportController($c->get(ImportExportService::class)));
        $container->set('controller.updates', fn (ServiceContainer $c): UpdateController => new UpdateController($c->get(ConfigRepository::class)));
        $container->set('controller.rc', fn (ServiceContainer $c): ReleaseCandidateController => new ReleaseCandidateController($c->get(ReleaseCandidateAuditor::class)));
        $container->set('controller.stable', fn (ServiceContainer $c): StableController => new StableController($c->get(StableReleaseService::class)));
        $container->set('controller.queue', fn (ServiceContainer $c): QueueController => new QueueController($c->get(ManagedQueueDriverInterface::class), $c->get(QueueWorker::class)));
        $container->set('controller.scheduler', fn (ServiceContainer $c): SchedulerController => new SchedulerController($c->get(SchedulerRunner::class)));
        $container->set('controller.media', fn (ServiceContainer $c): MediaController => new MediaController($c->get(MediaRepository::class)));
        $container->set('controller.public', fn (ServiceContainer $c): PublicController => new PublicController(
            $c->get(PublicPageRenderer::class),
            $c->get(RedirectRepository::class),
            $c->get(PreviewTokenService::class),
        ));
        $container->set('controller.cms', fn (ServiceContainer $c): CmsController => new CmsController(
            $c->get(ThemeConfigRepository::class),
            $c->get(NavigationRepository::class),
            $c->get(RedirectRepository::class),
            $c->get(PreviewTokenService::class),
            $c->get(CmsBootstrapService::class),
        ));
    }

    private static function registerRoutes(Router $router, ServiceContainer $container): void
    {
        /** @var HealthController $health */
        $health = $container->get('controller.health');
        /** @var SystemController $system */
        $system = $container->get('controller.system');
        /** @var ContentTypeController $contentTypes */
        $contentTypes = $container->get('controller.content_types');
        /** @var ContentEntryController $entries */
        $entries = $container->get('controller.content_entries');

        $router->get('/api/v1/health', 'api.v1.health', [$health, 'show']);
        $router->get('/api/v1/system', 'api.v1.system', [$system, 'show']);

        $router->get('/api/v1/content-types', 'api.v1.content_types.index', [$contentTypes, 'index']);
        $router->post('/api/v1/content-types', 'api.v1.content_types.store', [$contentTypes, 'store']);
        $router->get('/api/v1/content-types/{handle}', 'api.v1.content_types.show', [$contentTypes, 'show']);
        $router->get('/api/v1/content-types/{handle}/schema', 'api.v1.content_types.schema', [$contentTypes, 'schema']);
        $router->put('/api/v1/content-types/{handle}', 'api.v1.content_types.update', [$contentTypes, 'update']);
        $router->patch('/api/v1/content-types/{handle}', 'api.v1.content_types.patch', [$contentTypes, 'update']);
        $router->delete('/api/v1/content-types/{handle}', 'api.v1.content_types.delete', [$contentTypes, 'delete']);

        $router->get('/api/v1/content/{type}', 'api.v1.content.index', [$entries, 'index']);
        $router->post('/api/v1/content/{type}', 'api.v1.content.store', [$entries, 'store']);
        $router->get('/api/v1/content/{type}/{id}', 'api.v1.content.show', [$entries, 'show']);
        $router->put('/api/v1/content/{type}/{id}', 'api.v1.content.update', [$entries, 'update']);
        $router->patch('/api/v1/content/{type}/{id}', 'api.v1.content.patch', [$entries, 'update']);
        $router->delete('/api/v1/content/{type}/{id}', 'api.v1.content.delete', [$entries, 'delete']);
        $router->post('/api/v1/content/{type}/{id}/publish', 'api.v1.content.publish', [$entries, 'publish']);
        $router->post('/api/v1/content/{type}/{id}/unpublish', 'api.v1.content.unpublish', [$entries, 'unpublish']);
        $router->post('/api/v1/content/{type}/{id}/archive', 'api.v1.content.archive', [$entries, 'archive']);
        $router->get('/api/v1/content/{type}/{id}/localizations', 'api.v1.content.localizations', [$entries, 'localizations']);
        $router->post('/api/v1/content/{type}/{id}/localizations', 'api.v1.content.localizations.store', [$entries, 'storeLocalization']);
        $router->get('/api/v1/content/{type}/{id}/revisions', 'api.v1.content.revisions', [$entries, 'revisions']);
        $router->post('/api/v1/content/{type}/{id}/revisions/{revision}', 'api.v1.content.revisions.restore.short', [$entries, 'restoreRevision']);
        $router->post('/api/v1/content/{type}/{id}/revisions/{revision}/restore', 'api.v1.content.revisions.restore', [$entries, 'restoreRevision']);

        /** @var RuntimeController $runtime */
        $runtime = $container->get('controller.runtime');
        $router->get('/api/v1/runtime', 'api.v1.runtime', [$runtime, 'show']);


        /** @var SearchController $search */
        $search = $container->get('controller.search');
        $router->get('/api/v1/search', 'api.v1.search.index', [$search, 'index']);
        $router->post('/api/v1/search/reindex', 'api.v1.search.reindex', [$search, 'reindex']);
        $router->get('/api/v1/search/diagnostics', 'api.v1.search.diagnostics', [$search, 'diagnostics']);

        /** @var QueueController $queue */
        $queue = $container->get('controller.queue');
        $router->get('/api/v1/queue', 'api.v1.queue.diagnostics', [$queue, 'diagnostics']);
        $router->post('/api/v1/queue/jobs', 'api.v1.queue.jobs.store', [$queue, 'push']);
        $router->post('/api/v1/queue/work', 'api.v1.queue.work', [$queue, 'work']);
        $router->get('/api/v1/queue/failed', 'api.v1.queue.failed', [$queue, 'failed']);
        $router->post('/api/v1/queue/failed/{id}/retry', 'api.v1.queue.failed.retry', [$queue, 'retry']);

        /** @var SchedulerController $scheduler */
        $scheduler = $container->get('controller.scheduler');
        $router->get('/api/v1/scheduler/tasks', 'api.v1.scheduler.tasks', [$scheduler, 'tasks']);
        $router->post('/api/v1/scheduler/run', 'api.v1.scheduler.run', [$scheduler, 'run']);

        /** @var WebhookController $webhooks */
        $webhooks = $container->get('controller.webhooks');
        $router->get('/api/v1/webhooks', 'api.v1.webhooks.index', [$webhooks, 'index']);
        $router->post('/api/v1/webhooks', 'api.v1.webhooks.store', [$webhooks, 'store']);
        $router->patch('/api/v1/webhooks/{id}', 'api.v1.webhooks.patch', [$webhooks, 'update']);
        $router->delete('/api/v1/webhooks/{id}', 'api.v1.webhooks.delete', [$webhooks, 'delete']);
        $router->post('/api/v1/webhooks/{id}/test', 'api.v1.webhooks.test', [$webhooks, 'test']);
        $router->get('/api/v1/webhook-deliveries', 'api.v1.webhook_deliveries.index', [$webhooks, 'deliveries']);
        $router->post('/api/v1/events/dispatch', 'api.v1.events.dispatch', [$webhooks, 'dispatch']);

        /** @var ExtensionController $extensions */
        $extensions = $container->get('controller.extensions');
        $router->get('/api/v1/extensions', 'api.v1.extensions.index', [$extensions, 'index']);
        $router->post('/api/v1/extensions/validate', 'api.v1.extensions.validate', [$extensions, 'validate']);
        $router->post('/api/v1/extensions/install', 'api.v1.extensions.install', [$extensions, 'install']);
        $router->post('/api/v1/extensions/enable', 'api.v1.extensions.enable', [$extensions, 'enable']);
        $router->post('/api/v1/extensions/disable', 'api.v1.extensions.disable', [$extensions, 'disable']);
        $router->post('/api/v1/extensions/uninstall', 'api.v1.extensions.uninstall', [$extensions, 'uninstall']);
        $router->patch('/api/v1/extensions/config', 'api.v1.extensions.config', [$extensions, 'config']);
        $router->get('/api/v1/extensions/diagnostics', 'api.v1.extensions.diagnostics', [$extensions, 'diagnostics']);
        $router->get('/api/v1/extensions/runtime', 'api.v1.extensions.runtime', [$extensions, 'runtime']);
        $router->get('/api/v1/extensions/permissions', 'api.v1.extensions.permissions', [$extensions, 'permissions']);
        $router->get('/api/v1/extensions/events', 'api.v1.extensions.events', [$extensions, 'events']);
        $router->post('/api/v1/extensions/events/dispatch', 'api.v1.extensions.events.dispatch', [$extensions, 'dispatchEvent']);
        $router->post('/api/v1/extensions/assets/publish', 'api.v1.extensions.assets.publish', [$extensions, 'publishAssets']);
        $router->post('/api/v1/extensions/migrate', 'api.v1.extensions.migrate', [$extensions, 'migrate']);

        /** @var InstallerController $installer */
        $installer = $container->get('controller.installer');
        $router->get('/api/v1/installer/requirements', 'api.v1.installer.requirements', [$installer, 'requirements']);
        $router->post('/api/v1/installer/run', 'api.v1.installer.run', [$installer, 'run']);

        /** @var ImportExportController $importExport */
        $importExport = $container->get('controller.import_export');
        $router->get('/api/v1/import-export/exports', 'api.v1.import_export.exports', [$importExport, 'exports']);
        $router->post('/api/v1/import-export/export', 'api.v1.import_export.export', [$importExport, 'export']);
        $router->post('/api/v1/import-export/diff', 'api.v1.import_export.diff', [$importExport, 'diff']);
        $router->post('/api/v1/import-export/import', 'api.v1.import_export.import', [$importExport, 'import']);

        /** @var UpdateController $updates */
        $updates = $container->get('controller.updates');
        $router->get('/api/v1/updates', 'api.v1.updates.diagnostics', [$updates, 'diagnostics']);
        $router->post('/api/v1/updates/check', 'api.v1.updates.check', [$updates, 'check']);
        $router->post('/api/v1/updates/plan', 'api.v1.updates.plan', [$updates, 'plan']);
        $router->post('/api/v1/updates/prepare', 'api.v1.updates.prepare', [$updates, 'prepare']);
        $router->post('/api/v1/updates/apply', 'api.v1.updates.apply', [$updates, 'apply']);
        $router->post('/api/v1/updates/rollback', 'api.v1.updates.rollback', [$updates, 'rollback']);
        $router->get('/api/v1/updates/maintenance', 'api.v1.updates.maintenance', [$updates, 'maintenance']);
        $router->post('/api/v1/updates/maintenance/on', 'api.v1.updates.maintenance.on', [$updates, 'maintenanceOn']);
        $router->post('/api/v1/updates/maintenance/off', 'api.v1.updates.maintenance.off', [$updates, 'maintenanceOff']);

        /** @var ReleaseCandidateController $rc */
        $rc = $container->get('controller.rc');
        $router->get('/api/v1/rc/readiness', 'api.v1.rc.readiness', [$rc, 'readiness']);
        $router->get('/api/v1/rc/checks', 'api.v1.rc.checks', [$rc, 'checks']);
        $router->post('/api/v1/rc/run', 'api.v1.rc.run', [$rc, 'run']);

        /** @var StableController $stable */
        $stable = $container->get('controller.stable');
        $router->get('/api/v1/stable', 'api.v1.stable.status', [$stable, 'status']);
        $router->post('/api/v1/stable/smoke-test', 'api.v1.stable.smoke_test', [$stable, 'smokeTest']);
        $router->post('/api/v1/stable/release-lock', 'api.v1.stable.release_lock', [$stable, 'lock']);
        $router->post('/api/v1/stable/backup', 'api.v1.stable.backup', [$stable, 'backup']);
        $router->post('/api/v1/stable/support-bundle', 'api.v1.stable.support_bundle', [$stable, 'supportBundle']);

        /** @var AdminController $admin */
        $admin = $container->get('controller.admin');
        $router->get('/api/v1/admin/bootstrap', 'api.v1.admin.bootstrap', [$admin, 'bootstrap']);
        $router->get('/api/v1/admin/dashboard', 'api.v1.admin.dashboard', [$admin, 'dashboard']);

        /** @var MediaController $media */
        $media = $container->get('controller.media');
        $router->get('/api/v1/media', 'api.v1.media.index', [$media, 'index']);
        $router->post('/api/v1/media', 'api.v1.media.store', [$media, 'store']);
        $router->get('/api/v1/media/diagnostics', 'api.v1.media.diagnostics', [$media, 'diagnostics']);
        $router->get('/api/v1/media/{id}', 'api.v1.media.show', [$media, 'show']);
        $router->delete('/api/v1/media/{id}', 'api.v1.media.delete', [$media, 'delete']);

        /** @var PublicController $public */
        $public = $container->get('controller.public');
        $router->get('/api/v1/public/sitemap', 'api.v1.public.sitemap', [$public, 'sitemap']);
        $router->get('/api/v1/public/preview/{type}/{id}', 'api.v1.public.preview', [$public, 'preview']);

        /** @var CmsController $cms */
        $cms = $container->get('controller.cms');
        $router->get('/api/v1/cms', 'api.v1.cms.overview', [$cms, 'overview']);
        $router->post('/api/v1/cms/bootstrap', 'api.v1.cms.bootstrap', [$cms, 'bootstrap']);
        $router->get('/api/v1/cms/theme', 'api.v1.cms.theme.show', [$cms, 'theme']);
        $router->patch('/api/v1/cms/theme', 'api.v1.cms.theme.patch', [$cms, 'updateTheme']);
        $router->get('/api/v1/cms/navigation', 'api.v1.cms.navigation.index', [$cms, 'navigation']);
        $router->get('/api/v1/cms/navigation/{handle}', 'api.v1.cms.navigation.show', [$cms, 'navigationShow']);
        $router->put('/api/v1/cms/navigation/{handle}', 'api.v1.cms.navigation.put', [$cms, 'navigationSave']);
        $router->delete('/api/v1/cms/navigation/{handle}', 'api.v1.cms.navigation.delete', [$cms, 'navigationDelete']);
        $router->get('/api/v1/cms/redirects', 'api.v1.cms.redirects.index', [$cms, 'redirects']);
        $router->post('/api/v1/cms/redirects', 'api.v1.cms.redirects.store', [$cms, 'redirectStore']);
        $router->patch('/api/v1/cms/redirects/{id}', 'api.v1.cms.redirects.patch', [$cms, 'redirectUpdate']);
        $router->delete('/api/v1/cms/redirects/{id}', 'api.v1.cms.redirects.delete', [$cms, 'redirectDelete']);
        $router->post('/api/v1/cms/preview-token', 'api.v1.cms.preview_token', [$cms, 'previewToken']);

        /** @var DatabaseController $database */
        $database = $container->get('controller.database');
        $router->get('/api/v1/database', 'api.v1.database.show', [$database, 'show']);
        $router->get('/api/v1/database/migrations', 'api.v1.database.migrations', [$database, 'migrations']);

        /** @var AuthController $auth */
        $auth = $container->get('controller.auth');
        $router->post('/api/v1/auth/login', 'api.v1.auth.login', [$auth, 'login']);
        $router->post('/api/v1/auth/logout', 'api.v1.auth.logout', [$auth, 'logout']);
        $router->get('/api/v1/auth/me', 'api.v1.auth.me', [$auth, 'me']);
        $router->post('/api/v1/auth/2fa/enable', 'api.v1.auth.2fa.enable', [$auth, 'enableTwoFactor']);
        $router->post('/api/v1/auth/2fa/disable', 'api.v1.auth.2fa.disable', [$auth, 'disableTwoFactor']);

        /** @var UserController $users */
        $users = $container->get('controller.users');
        $router->get('/api/v1/users', 'api.v1.users.index', [$users, 'index']);
        $router->post('/api/v1/users', 'api.v1.users.store', [$users, 'store']);
        $router->get('/api/v1/users/{id}', 'api.v1.users.show', [$users, 'show']);
        $router->patch('/api/v1/users/{id}', 'api.v1.users.patch', [$users, 'update']);
        $router->delete('/api/v1/users/{id}', 'api.v1.users.disable', [$users, 'disable']);

        /** @var RoleController $roles */
        $roles = $container->get('controller.roles');
        $router->get('/api/v1/roles', 'api.v1.roles.index', [$roles, 'index']);
        $router->post('/api/v1/roles', 'api.v1.roles.store', [$roles, 'store']);
        $router->get('/api/v1/roles/{id}', 'api.v1.roles.show', [$roles, 'show']);

        /** @var ApiTokenController $apiTokens */
        $apiTokens = $container->get('controller.api_tokens');
        $router->get('/api/v1/api-tokens', 'api.v1.api_tokens.index', [$apiTokens, 'index']);
        $router->post('/api/v1/api-tokens', 'api.v1.api_tokens.store', [$apiTokens, 'store']);
        $router->delete('/api/v1/api-tokens/{id}', 'api.v1.api_tokens.revoke', [$apiTokens, 'revoke']);

        /** @var SecurityController $security */
        $security = $container->get('controller.security');
        $router->get('/api/v1/security', 'api.v1.security.show', [$security, 'show']);


        /** @var PublicController $public */
        $public = $container->get('controller.public');
        $router->get('/sitemap.xml', 'public.sitemap_xml', [$public, 'sitemapXml']);
        $router->get('/robots.txt', 'public.robots_txt', [$public, 'robotsTxt']);
        $router->get('/preview/{token}', 'public.preview_token', [$public, 'previewToken']);
        $router->get('/', 'public.home', [$public, 'renderHome']);
        $router->get('/{path}', 'public.page.path', [$public, 'renderByPath']);
    }

    private static function ensureRuntimeDirectories(string $rootPath): void
    {
        foreach ([
            'storage/app',
            'storage/app/content',
            'storage/app/security',
            'storage/app/search',
            'storage/app/webhooks',
            'storage/app/extensions',
            'storage/cache/extensions',
            'storage/logs/extensions',
            'public/extensions',
            'storage/app/exports',
            'storage/app/imports',
            'storage/app/updates',
            'storage/app/support',
            'storage/app/backups',
            'storage/app/stable',
            'storage/releases',
            'plugins',
            'themes',
            'storage/queue/pending/default',
            'storage/queue/processed/default',
            'storage/queue/failed/default',
            'storage/app/media',
            'storage/cache',
            'storage/cache/templates',
            'storage/logs',
            'storage/tmp',
            'storage/queue/pending',
            'storage/queue/processed',
            'storage/queue/failed',
            'storage/rate-limits',
            'storage/static-export',
            'public/uploads',
        ] as $path) {
            $full = $rootPath . '/' . $path;
            if (!is_dir($full)) {
                @mkdir($full, 0775, true);
            }
        }
    }
}
