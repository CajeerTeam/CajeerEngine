// Generated from api/openapi.yaml. Do not edit.
export const operations = {
    "delete_ApiTokensRevoke": {
        "method": "DELETE",
        "path": "/api-tokens/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_CmsNavigationMenu": {
        "method": "DELETE",
        "path": "/cms/navigation/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_CmsRedirect": {
        "method": "DELETE",
        "path": "/cms/redirects/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_ContentDelete": {
        "method": "DELETE",
        "path": "/content/{type}/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_ContentTypesDelete": {
        "method": "DELETE",
        "path": "/content-types/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_MediaDelete": {
        "method": "DELETE",
        "path": "/media/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_UsersDisable": {
        "method": "DELETE",
        "path": "/users/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_WebhooksDelete": {
        "method": "DELETE",
        "path": "/webhooks/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AdminBootstrap": {
        "method": "GET",
        "path": "/admin/bootstrap",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AdminDashboard": {
        "method": "GET",
        "path": "/admin/dashboard",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ApiTokensIndex": {
        "method": "GET",
        "path": "/api-tokens",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AuthMe": {
        "method": "GET",
        "path": "/auth/me",
        "parameters": [],
        "bodyRequired": false
    },
    "get_CmsNavigation": {
        "method": "GET",
        "path": "/cms/navigation",
        "parameters": [],
        "bodyRequired": false
    },
    "get_CmsNavigationMenu": {
        "method": "GET",
        "path": "/cms/navigation/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_CmsOverview": {
        "method": "GET",
        "path": "/cms",
        "parameters": [],
        "bodyRequired": false
    },
    "get_CmsRedirects": {
        "method": "GET",
        "path": "/cms/redirects",
        "parameters": [],
        "bodyRequired": false
    },
    "get_CmsTheme": {
        "method": "GET",
        "path": "/cms/theme",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentIndex": {
        "method": "GET",
        "path": "/content/{type}",
        "parameters": [
            {
                "name": "status",
                "in": "query",
                "required": false
            },
            {
                "name": "locale",
                "in": "query",
                "required": false
            },
            {
                "name": "q",
                "in": "query",
                "required": false
            },
            {
                "name": "published_only",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_ContentLocalizations": {
        "method": "GET",
        "path": "/content/{type}/{id}/localizations",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentRevisions": {
        "method": "GET",
        "path": "/content/{type}/{id}/revisions",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentShow": {
        "method": "GET",
        "path": "/content/{type}/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentTypesIndex": {
        "method": "GET",
        "path": "/content-types",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentTypesSchema": {
        "method": "GET",
        "path": "/content-types/{handle}/schema",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentTypesShow": {
        "method": "GET",
        "path": "/content-types/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_DatabaseMigrations": {
        "method": "GET",
        "path": "/database/migrations",
        "parameters": [],
        "bodyRequired": false
    },
    "get_DatabaseShow": {
        "method": "GET",
        "path": "/database",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ExtensionRuntime": {
        "method": "GET",
        "path": "/extensions/runtime",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ExtensionsDiagnostics": {
        "method": "GET",
        "path": "/extensions/diagnostics",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ExtensionsEvents": {
        "method": "GET",
        "path": "/extensions/events",
        "parameters": [
            {
                "name": "enabled",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_ExtensionsIndex": {
        "method": "GET",
        "path": "/extensions",
        "parameters": [
            {
                "name": "type",
                "in": "query",
                "required": false
            },
            {
                "name": "enabled",
                "in": "query",
                "required": false
            },
            {
                "name": "installed",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_ExtensionsPermissions": {
        "method": "GET",
        "path": "/extensions/permissions",
        "parameters": [
            {
                "name": "enabled",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_Health": {
        "method": "GET",
        "path": "/health",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ImportExportExports": {
        "method": "GET",
        "path": "/import-export/exports",
        "parameters": [],
        "bodyRequired": false
    },
    "get_InstallerRequirements": {
        "method": "GET",
        "path": "/installer/requirements",
        "parameters": [],
        "bodyRequired": false
    },
    "get_MediaDiagnostics": {
        "method": "GET",
        "path": "/media/diagnostics",
        "parameters": [],
        "bodyRequired": false
    },
    "get_MediaIndex": {
        "method": "GET",
        "path": "/media",
        "parameters": [
            {
                "name": "q",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_MediaShow": {
        "method": "GET",
        "path": "/media/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_PublicPreview": {
        "method": "GET",
        "path": "/public/preview/{type}/{id}",
        "parameters": [
            {
                "name": "format",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_PublicSitemap": {
        "method": "GET",
        "path": "/public/sitemap",
        "parameters": [],
        "bodyRequired": false
    },
    "get_QueueDiagnostics": {
        "method": "GET",
        "path": "/queue",
        "parameters": [],
        "bodyRequired": false
    },
    "get_QueueFailed": {
        "method": "GET",
        "path": "/queue/failed",
        "parameters": [
            {
                "name": "queue",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_RcChecks": {
        "method": "GET",
        "path": "/rc/checks",
        "parameters": [],
        "bodyRequired": false
    },
    "get_RcReadiness": {
        "method": "GET",
        "path": "/rc/readiness",
        "parameters": [],
        "bodyRequired": false
    },
    "get_RolesIndex": {
        "method": "GET",
        "path": "/roles",
        "parameters": [],
        "bodyRequired": false
    },
    "get_RolesShow": {
        "method": "GET",
        "path": "/roles/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_Runtime": {
        "method": "GET",
        "path": "/runtime",
        "parameters": [],
        "bodyRequired": false
    },
    "get_SchedulerTasks": {
        "method": "GET",
        "path": "/scheduler/tasks",
        "parameters": [],
        "bodyRequired": false
    },
    "get_SearchDiagnostics": {
        "method": "GET",
        "path": "/search/diagnostics",
        "parameters": [],
        "bodyRequired": false
    },
    "get_SearchIndex": {
        "method": "GET",
        "path": "/search",
        "parameters": [
            {
                "name": "q",
                "in": "query",
                "required": false
            },
            {
                "name": "index",
                "in": "query",
                "required": false
            },
            {
                "name": "limit",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_SecurityShow": {
        "method": "GET",
        "path": "/security",
        "parameters": [],
        "bodyRequired": false
    },
    "get_StableStatus": {
        "method": "GET",
        "path": "/stable",
        "parameters": [],
        "bodyRequired": false
    },
    "get_System": {
        "method": "GET",
        "path": "/system",
        "parameters": [],
        "bodyRequired": false
    },
    "get_UpdatesDiagnostics": {
        "method": "GET",
        "path": "/updates",
        "parameters": [],
        "bodyRequired": false
    },
    "get_UpdatesMaintenance": {
        "method": "GET",
        "path": "/updates/maintenance",
        "parameters": [],
        "bodyRequired": false
    },
    "get_UsersIndex": {
        "method": "GET",
        "path": "/users",
        "parameters": [],
        "bodyRequired": false
    },
    "get_UsersShow": {
        "method": "GET",
        "path": "/users/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "get_WebhookDeliveriesIndex": {
        "method": "GET",
        "path": "/webhook-deliveries",
        "parameters": [
            {
                "name": "webhook_id",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_WebhooksIndex": {
        "method": "GET",
        "path": "/webhooks",
        "parameters": [],
        "bodyRequired": false
    },
    "patch_CmsRedirect": {
        "method": "PATCH",
        "path": "/cms/redirects/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "patch_CmsTheme": {
        "method": "PATCH",
        "path": "/cms/theme",
        "parameters": [],
        "bodyRequired": false
    },
    "patch_ContentPatch": {
        "method": "PATCH",
        "path": "/content/{type}/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "patch_ContentTypesPatch": {
        "method": "PATCH",
        "path": "/content-types/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "patch_ExtensionsConfig": {
        "method": "PATCH",
        "path": "/extensions/config",
        "parameters": [],
        "bodyRequired": true
    },
    "patch_UsersPatch": {
        "method": "PATCH",
        "path": "/users/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "patch_WebhooksPatch": {
        "method": "PATCH",
        "path": "/webhooks/{id}",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ApiTokensStore": {
        "method": "POST",
        "path": "/api-tokens",
        "parameters": [],
        "bodyRequired": false
    },
    "post_Auth2faDisable": {
        "method": "POST",
        "path": "/auth/2fa/disable",
        "parameters": [],
        "bodyRequired": true
    },
    "post_Auth2faEnable": {
        "method": "POST",
        "path": "/auth/2fa/enable",
        "parameters": [],
        "bodyRequired": true
    },
    "post_AuthLogin": {
        "method": "POST",
        "path": "/auth/login",
        "parameters": [
            {
                "name": "X-Cajeer-Browser",
                "in": "header",
                "required": false
            },
            {
                "name": "X-Cajeer-Browser",
                "in": "header",
                "required": false
            }
        ],
        "bodyRequired": true
    },
    "post_AuthLogout": {
        "method": "POST",
        "path": "/auth/logout",
        "parameters": [],
        "bodyRequired": false
    },
    "post_CmsBootstrap": {
        "method": "POST",
        "path": "/cms/bootstrap",
        "parameters": [],
        "bodyRequired": false
    },
    "post_CmsPreviewToken": {
        "method": "POST",
        "path": "/cms/preview-token",
        "parameters": [],
        "bodyRequired": false
    },
    "post_CmsRedirects": {
        "method": "POST",
        "path": "/cms/redirects",
        "parameters": [],
        "bodyRequired": true
    },
    "post_ContentArchive": {
        "method": "POST",
        "path": "/content/{type}/{id}/archive",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ContentLocalizationsStore": {
        "method": "POST",
        "path": "/content/{type}/{id}/localizations",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ContentPublish": {
        "method": "POST",
        "path": "/content/{type}/{id}/publish",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ContentRevisionsRestore": {
        "method": "POST",
        "path": "/content/{type}/{id}/revisions/{revision}/restore",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ContentRevisionsRestoreShort": {
        "method": "POST",
        "path": "/content/{type}/{id}/revisions/{revision}",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ContentStore": {
        "method": "POST",
        "path": "/content/{type}",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ContentTypesStore": {
        "method": "POST",
        "path": "/content-types",
        "parameters": [],
        "bodyRequired": true
    },
    "post_ContentUnpublish": {
        "method": "POST",
        "path": "/content/{type}/{id}/unpublish",
        "parameters": [],
        "bodyRequired": false
    },
    "post_EventsDispatch": {
        "method": "POST",
        "path": "/events/dispatch",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionAssetsPublish": {
        "method": "POST",
        "path": "/extensions/assets/publish",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionMigrate": {
        "method": "POST",
        "path": "/extensions/migrate",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionsDisable": {
        "method": "POST",
        "path": "/extensions/disable",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionsEnable": {
        "method": "POST",
        "path": "/extensions/enable",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionsEventsDispatch": {
        "method": "POST",
        "path": "/extensions/events/dispatch",
        "parameters": [],
        "bodyRequired": true
    },
    "post_ExtensionsInstall": {
        "method": "POST",
        "path": "/extensions/install",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionsUninstall": {
        "method": "POST",
        "path": "/extensions/uninstall",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ExtensionsValidate": {
        "method": "POST",
        "path": "/extensions/validate",
        "parameters": [
            {
                "name": "target",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "post_ImportExportExport": {
        "method": "POST",
        "path": "/import-export/export",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ImportExportImport": {
        "method": "POST",
        "path": "/import-export/import",
        "parameters": [],
        "bodyRequired": true
    },
    "post_Import_ExportDiff": {
        "method": "POST",
        "path": "/import-export/diff",
        "parameters": [],
        "bodyRequired": true
    },
    "post_InstallerRun": {
        "method": "POST",
        "path": "/installer/run",
        "parameters": [],
        "bodyRequired": false
    },
    "post_MediaStore": {
        "method": "POST",
        "path": "/media",
        "parameters": [],
        "bodyRequired": true
    },
    "post_QueueFailedRetry": {
        "method": "POST",
        "path": "/queue/failed/{id}/retry",
        "parameters": [
            {
                "name": "queue",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "post_QueueJobsStore": {
        "method": "POST",
        "path": "/queue/jobs",
        "parameters": [],
        "bodyRequired": false
    },
    "post_QueueWork": {
        "method": "POST",
        "path": "/queue/work",
        "parameters": [],
        "bodyRequired": false
    },
    "post_RcRun": {
        "method": "POST",
        "path": "/rc/run",
        "parameters": [],
        "bodyRequired": false
    },
    "post_RolesStore": {
        "method": "POST",
        "path": "/roles",
        "parameters": [],
        "bodyRequired": true
    },
    "post_SchedulerRun": {
        "method": "POST",
        "path": "/scheduler/run",
        "parameters": [],
        "bodyRequired": false
    },
    "post_SearchReindex": {
        "method": "POST",
        "path": "/search/reindex",
        "parameters": [],
        "bodyRequired": false
    },
    "post_StableBackup": {
        "method": "POST",
        "path": "/stable/backup",
        "parameters": [],
        "bodyRequired": true
    },
    "post_StableReleaseLock": {
        "method": "POST",
        "path": "/stable/release-lock",
        "parameters": [],
        "bodyRequired": false
    },
    "post_StableSmokeTest": {
        "method": "POST",
        "path": "/stable/smoke-test",
        "parameters": [],
        "bodyRequired": false
    },
    "post_StableSupportBundle": {
        "method": "POST",
        "path": "/stable/support-bundle",
        "parameters": [],
        "bodyRequired": false
    },
    "post_UpdatesApply": {
        "method": "POST",
        "path": "/updates/apply",
        "parameters": [],
        "bodyRequired": true
    },
    "post_UpdatesCheck": {
        "method": "POST",
        "path": "/updates/check",
        "parameters": [],
        "bodyRequired": false
    },
    "post_UpdatesMaintenanceOff": {
        "method": "POST",
        "path": "/updates/maintenance/off",
        "parameters": [],
        "bodyRequired": false
    },
    "post_UpdatesMaintenanceOn": {
        "method": "POST",
        "path": "/updates/maintenance/on",
        "parameters": [],
        "bodyRequired": false
    },
    "post_UpdatesPlan": {
        "method": "POST",
        "path": "/updates/plan",
        "parameters": [],
        "bodyRequired": false
    },
    "post_UpdatesPrepare": {
        "method": "POST",
        "path": "/updates/prepare",
        "parameters": [],
        "bodyRequired": false
    },
    "post_UpdatesRollback": {
        "method": "POST",
        "path": "/updates/rollback",
        "parameters": [],
        "bodyRequired": true
    },
    "post_UsersStore": {
        "method": "POST",
        "path": "/users",
        "parameters": [],
        "bodyRequired": true
    },
    "post_WebhooksStore": {
        "method": "POST",
        "path": "/webhooks",
        "parameters": [],
        "bodyRequired": true
    },
    "post_WebhooksTest": {
        "method": "POST",
        "path": "/webhooks/{id}/test",
        "parameters": [],
        "bodyRequired": false
    },
    "put_CmsNavigationMenu": {
        "method": "PUT",
        "path": "/cms/navigation/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "put_ContentTypesUpdate": {
        "method": "PUT",
        "path": "/content-types/{handle}",
        "parameters": [],
        "bodyRequired": false
    },
    "put_ContentUpdate": {
        "method": "PUT",
        "path": "/content/{type}/{id}",
        "parameters": [],
        "bodyRequired": false
    }
} as const
export type OperationId = keyof typeof operations
export type OperationArguments = { path?: Record<string, string | number>; query?: Record<string, string | number | boolean>; body?: unknown }
