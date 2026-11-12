<?php

declare(strict_types=1);
namespace CajeerEngine\ExtensionSdk;

/** Generated from api/openapi.yaml. */
final class Operations {
    public const ALL = array (
  'delete_ApiTokensRevoke' => 
  array (
    'method' => 'DELETE',
    'path' => '/api-tokens/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_CmsNavigationMenu' => 
  array (
    'method' => 'DELETE',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_CmsRedirect' => 
  array (
    'method' => 'DELETE',
    'path' => '/cms/redirects/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_ContentDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_ContentTypesDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_MediaDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/media/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_UsersDisable' => 
  array (
    'method' => 'DELETE',
    'path' => '/users/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_WebhooksDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/webhooks/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AdminBootstrap' => 
  array (
    'method' => 'GET',
    'path' => '/admin/bootstrap',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AdminDashboard' => 
  array (
    'method' => 'GET',
    'path' => '/admin/dashboard',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ApiTokensIndex' => 
  array (
    'method' => 'GET',
    'path' => '/api-tokens',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AuthMe' => 
  array (
    'method' => 'GET',
    'path' => '/auth/me',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsNavigation' => 
  array (
    'method' => 'GET',
    'path' => '/cms/navigation',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsNavigationMenu' => 
  array (
    'method' => 'GET',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsOverview' => 
  array (
    'method' => 'GET',
    'path' => '/cms',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsRedirects' => 
  array (
    'method' => 'GET',
    'path' => '/cms/redirects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsTheme' => 
  array (
    'method' => 'GET',
    'path' => '/cms/theme',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentIndex' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'published_only',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentLocalizations' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}/localizations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentRevisions' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}/revisions',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentShow' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesIndex' => 
  array (
    'method' => 'GET',
    'path' => '/content-types',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesSchema' => 
  array (
    'method' => 'GET',
    'path' => '/content-types/{handle}/schema',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesShow' => 
  array (
    'method' => 'GET',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_DatabaseMigrations' => 
  array (
    'method' => 'GET',
    'path' => '/database/migrations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_DatabaseShow' => 
  array (
    'method' => 'GET',
    'path' => '/database',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionRuntime' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/runtime',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/diagnostics',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsEvents' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/events',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'enabled',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsIndex' => 
  array (
    'method' => 'GET',
    'path' => '/extensions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'enabled',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'installed',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsPermissions' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/permissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'enabled',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Health' => 
  array (
    'method' => 'GET',
    'path' => '/health',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ImportExportDownload' => 
  array (
    'method' => 'GET',
    'path' => '/import-export/download/{file}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'file',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ImportExportExports' => 
  array (
    'method' => 'GET',
    'path' => '/import-export/exports',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ImportExportRequests' => 
  array (
    'method' => 'GET',
    'path' => '/import-export/requests',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_InstallerRequirements' => 
  array (
    'method' => 'GET',
    'path' => '/installer/requirements',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MediaDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/media/diagnostics',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MediaIndex' => 
  array (
    'method' => 'GET',
    'path' => '/media',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaShow' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_PublicPreview' => 
  array (
    'method' => 'GET',
    'path' => '/public/preview/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'format',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_PublicSitemap' => 
  array (
    'method' => 'GET',
    'path' => '/public/sitemap',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_QueueDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/queue',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_QueueFailed' => 
  array (
    'method' => 'GET',
    'path' => '/queue/failed',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'queue',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_RcChecks' => 
  array (
    'method' => 'GET',
    'path' => '/rc/checks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_RcReadiness' => 
  array (
    'method' => 'GET',
    'path' => '/rc/readiness',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_RolesIndex' => 
  array (
    'method' => 'GET',
    'path' => '/roles',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_RolesShow' => 
  array (
    'method' => 'GET',
    'path' => '/roles/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Runtime' => 
  array (
    'method' => 'GET',
    'path' => '/runtime',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SchedulerTasks' => 
  array (
    'method' => 'GET',
    'path' => '/scheduler/tasks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SearchDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/search/diagnostics',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SearchIndex' => 
  array (
    'method' => 'GET',
    'path' => '/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'index',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_SecurityShow' => 
  array (
    'method' => 'GET',
    'path' => '/security',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_StableStatus' => 
  array (
    'method' => 'GET',
    'path' => '/stable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_System' => 
  array (
    'method' => 'GET',
    'path' => '/system',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UpdatesDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/updates',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UpdatesMaintenance' => 
  array (
    'method' => 'GET',
    'path' => '/updates/maintenance',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UsersIndex' => 
  array (
    'method' => 'GET',
    'path' => '/users',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UsersShow' => 
  array (
    'method' => 'GET',
    'path' => '/users/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_WebhookDeliveriesIndex' => 
  array (
    'method' => 'GET',
    'path' => '/webhook-deliveries',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'webhook_id',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_WebhooksIndex' => 
  array (
    'method' => 'GET',
    'path' => '/webhooks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_CmsRedirect' => 
  array (
    'method' => 'PATCH',
    'path' => '/cms/redirects/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_CmsTheme' => 
  array (
    'method' => 'PATCH',
    'path' => '/cms/theme',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_ContentPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_ContentTypesPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_ExtensionsConfig' => 
  array (
    'method' => 'PATCH',
    'path' => '/extensions/config',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'patch_UsersPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/users/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_WebhooksPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/webhooks/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ApiTokensStore' => 
  array (
    'method' => 'POST',
    'path' => '/api-tokens',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_Auth2faDisable' => 
  array (
    'method' => 'POST',
    'path' => '/auth/2fa/disable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Auth2faEnable' => 
  array (
    'method' => 'POST',
    'path' => '/auth/2fa/enable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthLogin' => 
  array (
    'method' => 'POST',
    'path' => '/auth/login',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'X-Cajeer-Browser',
        'in' => 'header',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'X-Cajeer-Browser',
        'in' => 'header',
        'required' => false,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_AuthLogout' => 
  array (
    'method' => 'POST',
    'path' => '/auth/logout',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_CmsBootstrap' => 
  array (
    'method' => 'POST',
    'path' => '/cms/bootstrap',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_CmsPreviewToken' => 
  array (
    'method' => 'POST',
    'path' => '/cms/preview-token',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_CmsRedirects' => 
  array (
    'method' => 'POST',
    'path' => '/cms/redirects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ContentArchive' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/archive',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ContentLocalizationsStore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/localizations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ContentPublish' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/publish',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ContentRevisionsRestore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/revisions/{revision}/restore',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ContentRevisionsRestoreShort' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/revisions/{revision}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ContentStore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ContentTypesStore' => 
  array (
    'method' => 'POST',
    'path' => '/content-types',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ContentUnpublish' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/unpublish',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_EventsDispatch' => 
  array (
    'method' => 'POST',
    'path' => '/events/dispatch',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionAssetsPublish' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/assets/publish',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionMigrate' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/migrate',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsDisable' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/disable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsEnable' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/enable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsEventsDispatch' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/events/dispatch',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ExtensionsInstall' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/install',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsUninstall' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/uninstall',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsValidate' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/validate',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'target',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ImportExportExport' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/export',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ImportExportImport' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/import',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ImportExportUpload' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/upload',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Import_ExportDiff' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/diff',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_InstallerRun' => 
  array (
    'method' => 'POST',
    'path' => '/installer/run',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_MediaStore' => 
  array (
    'method' => 'POST',
    'path' => '/media',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_QueueFailedRetry' => 
  array (
    'method' => 'POST',
    'path' => '/queue/failed/{id}/retry',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'queue',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_QueueJobsStore' => 
  array (
    'method' => 'POST',
    'path' => '/queue/jobs',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_QueueWork' => 
  array (
    'method' => 'POST',
    'path' => '/queue/work',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_RcRun' => 
  array (
    'method' => 'POST',
    'path' => '/rc/run',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_RolesStore' => 
  array (
    'method' => 'POST',
    'path' => '/roles',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_SchedulerRun' => 
  array (
    'method' => 'POST',
    'path' => '/scheduler/run',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_SearchReindex' => 
  array (
    'method' => 'POST',
    'path' => '/search/reindex',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_StableBackup' => 
  array (
    'method' => 'POST',
    'path' => '/stable/backup',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_StableReleaseLock' => 
  array (
    'method' => 'POST',
    'path' => '/stable/release-lock',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_StableSmokeTest' => 
  array (
    'method' => 'POST',
    'path' => '/stable/smoke-test',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_StableSupportBundle' => 
  array (
    'method' => 'POST',
    'path' => '/stable/support-bundle',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesApply' => 
  array (
    'method' => 'POST',
    'path' => '/updates/apply',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_UpdatesCheck' => 
  array (
    'method' => 'POST',
    'path' => '/updates/check',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesMaintenanceOff' => 
  array (
    'method' => 'POST',
    'path' => '/updates/maintenance/off',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesMaintenanceOn' => 
  array (
    'method' => 'POST',
    'path' => '/updates/maintenance/on',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesPlan' => 
  array (
    'method' => 'POST',
    'path' => '/updates/plan',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesPrepare' => 
  array (
    'method' => 'POST',
    'path' => '/updates/prepare',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesRollback' => 
  array (
    'method' => 'POST',
    'path' => '/updates/rollback',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_UsersStore' => 
  array (
    'method' => 'POST',
    'path' => '/users',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_WebhooksStore' => 
  array (
    'method' => 'POST',
    'path' => '/webhooks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_WebhooksTest' => 
  array (
    'method' => 'POST',
    'path' => '/webhooks/{id}/test',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'put_CmsNavigationMenu' => 
  array (
    'method' => 'PUT',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'put_ContentTypesUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'put_ContentUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
);
}
