<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Observability\SystemReport;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminController
{
    public function __construct(
        private ConfigRepository $config,
        private SystemReport $systemReport,
        private ContentTypeRepository $contentTypes,
        private ContentEntryRepository $contentEntries,
        private UserRepository $users,
        private RoleRepository $roles,
        private ApiTokenRepository $tokens,
        private AdminAssetPublisher $assets,
        private ExtensionRegistry $extensions,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'app' => [
                    'name' => $this->config->string('app.name', 'CajeerEngine'),
                    'version' => $this->config->string('app.version', '1.1.1'),
                    'locale' => $this->config->string('app.locale', 'ru'),
                    'api_base' => '/api/v1',
                    'admin_base' => '/admin/',
                ],
                'identity' => $this->identity($request),
                'sections' => $this->sections(),
                'features' => [
                    'dashboard' => true,
                    'content_types' => true,
                    'content_entries' => true,
                    'users' => true,
                    'roles' => true,
                    'api_tokens' => true,
                    'system_diagnostics' => true,
                    'prebuilt_assets' => true,
                    'static_spa' => true,
                    'cms_crud' => true,
                    'media_upload' => true,
                    'cms_product_layer' => true,
                    'search_admin' => true,
                    'import_export_ui' => true,
                    'update_ui' => true,
                    'extensions' => true,
                ],
                'security' => [
                    'api_auth_required' => $this->config->bool('security.api_auth_required', false),
                    'content_write_auth' => $this->config->bool('security.protect_content_writes', true),
                    'system_auth' => $this->config->bool('security.protect_system_routes', false),
                ],
                'assets' => $this->assets->manifest(false),
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $report = $this->systemReport->toArray();
        $entryCounts = $this->safe(fn (): array => $this->contentEntries->counts(), []);
        $security = [
            'users' => $this->safe(fn (): int => $this->users->count(), 0),
            'roles' => $this->safe(fn (): int => count($this->roles->all()), 0),
            'api_tokens' => $this->safe(fn (): int => $this->tokens->count(), 0),
        ];

        return new JsonResponse([
            'data' => [
                'cards' => [
                    ['label' => 'Версия', 'value' => $this->config->string('app.version', '1.1.1'), 'status' => 'ok'],
                    ['label' => 'Типы контента', 'value' => count($this->contentTypes->all()), 'status' => 'ok'],
                    ['label' => 'Записи', 'value' => (int) ($entryCounts['entries'] ?? 0), 'status' => 'ok'],
                    ['label' => 'Опубликовано', 'value' => (int) ($entryCounts['published'] ?? 0), 'status' => 'ok'],
                    ['label' => 'Пользователи', 'value' => $security['users'], 'status' => 'ok'],
                    ['label' => 'API tokens', 'value' => $security['api_tokens'], 'status' => 'ok'],
                    ['label' => 'Расширения', 'value' => (int) ($this->extensions->diagnostics()['enabled'] ?? 0), 'status' => 'ok'],
                ],
                'content' => [
                    'types' => count($this->contentTypes->all()),
                    'entries' => $entryCounts,
                    'storage' => $this->contentEntries->storage(),
                ],
                'security' => $security,
                'extensions' => $this->extensions->diagnostics(),
                'runtime' => [
                    'php' => $report['runtime']['php'] ?? PHP_VERSION,
                    'environment' => $report['runtime']['environment'] ?? 'production',
                    'database' => $report['database']['health']['ok'] ?? false,
                    'admin_assets_ready' => $this->assets->manifest(false)['ready'] ?? false,
                ],
                'paths' => [
                    'admin' => '/admin/',
                    'api' => '/api/v1',
                ],
            ],
        ]);
    }

    /** @return list<array{key:string,label:string,path:string,scope:string|null}> */
    private function sections(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Обзор', 'path' => '/admin/#/dashboard', 'scope' => null],
            ['key' => 'content_types', 'label' => 'Типы контента', 'path' => '/admin/#/content-types', 'scope' => 'content:write'],
            ['key' => 'content', 'label' => 'Контент', 'path' => '/admin/#/content', 'scope' => 'content:write'],
            ['key' => 'media', 'label' => 'Media', 'path' => '/admin/#/media', 'scope' => 'media:write'],
            ['key' => 'cms', 'label' => 'CMS', 'path' => '/admin/#/cms', 'scope' => 'content:write'],
            ['key' => 'users', 'label' => 'Пользователи и роли', 'path' => '/admin/#/users', 'scope' => 'users:read'],
            ['key' => 'extensions', 'label' => 'Расширения', 'path' => '/admin/#/extensions', 'scope' => 'extensions:read'],
            ['key' => 'updates', 'label' => 'Updates', 'path' => '/admin/#/updates', 'scope' => 'system:write'],
            ['key' => 'search', 'label' => 'Search', 'path' => '/admin/#/search', 'scope' => 'content:read'],
            ['key' => 'import_export', 'label' => 'Import/export', 'path' => '/admin/#/import-export', 'scope' => 'content:write'],
            ['key' => 'system', 'label' => 'Система', 'path' => '/admin/#/system', 'scope' => 'system:read'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function identity(Request $request): ?array
    {
        $identity = $request->attributes->get('auth');
        return is_array($identity) ? $identity : null;
    }

    /** @template T @param callable():T $callback @param T $default @return T */
    private function safe(callable $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $default;
        }
    }
}
