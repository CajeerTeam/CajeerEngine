// Generated from api/openapi.yaml. Do not edit.
export const operations = {
    "api_v1_ai_actions": {
        "method": "GET",
        "path": "/ai/actions",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_ai_actions_approve": {
        "method": "POST",
        "path": "/ai/actions/{id}/approve",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_ai_actions_reject": {
        "method": "POST",
        "path": "/ai/actions/{id}/reject",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_ai_actions_show": {
        "method": "GET",
        "path": "/ai/actions/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_analytics_explain": {
        "method": "POST",
        "path": "/ai/analytics/explain",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_analytics_insights": {
        "method": "GET",
        "path": "/ai/analytics/insights",
        "parameters": [
            {
                "name": "connection_id",
                "in": "query",
                "required": true
            },
            {
                "name": "from",
                "in": "query",
                "required": false
            },
            {
                "name": "to",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_chat": {
        "method": "POST",
        "path": "/ai/chat",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_chat_stream": {
        "method": "POST",
        "path": "/ai/chat/stream",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_conversation_delete": {
        "method": "DELETE",
        "path": "/ai/conversations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_conversation_rename": {
        "method": "PATCH",
        "path": "/ai/conversations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_ai_conversation_show": {
        "method": "GET",
        "path": "/ai/conversations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_conversations": {
        "method": "GET",
        "path": "/ai/conversations",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_ai_diagnostics_explain": {
        "method": "POST",
        "path": "/ai/diagnostics/explain",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_diagnostics_snapshot": {
        "method": "GET",
        "path": "/ai/diagnostics",
        "parameters": [
            {
                "name": "window_minutes",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_editor_apply": {
        "method": "POST",
        "path": "/ai/editor/proposals/{id}/apply",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_ai_editor_propose": {
        "method": "POST",
        "path": "/ai/editor/proposals",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_seo_audit": {
        "method": "GET",
        "path": "/ai/seo/audit",
        "parameters": [
            {
                "name": "type",
                "in": "query",
                "required": true
            },
            {
                "name": "entry_id",
                "in": "query",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_seo_batch": {
        "method": "GET",
        "path": "/ai/seo/batch",
        "parameters": [
            {
                "name": "type",
                "in": "query",
                "required": true
            },
            {
                "name": "limit",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_seo_insights": {
        "method": "GET",
        "path": "/ai/seo/insights",
        "parameters": [
            {
                "name": "connection_id",
                "in": "query",
                "required": true
            },
            {
                "name": "from",
                "in": "query",
                "required": false
            },
            {
                "name": "to",
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
    "api_v1_ai_seo_insights_propose": {
        "method": "POST",
        "path": "/ai/seo/insights/proposals",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_seo_propose": {
        "method": "POST",
        "path": "/ai/seo/proposals",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_settings": {
        "method": "PUT",
        "path": "/ai/settings",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_status": {
        "method": "GET",
        "path": "/ai/status",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_ai_tools": {
        "method": "GET",
        "path": "/ai/tools",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_ai_translation_apply": {
        "method": "POST",
        "path": "/ai/translations/proposals/{id}/apply",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_ai_translation_batch_create": {
        "method": "POST",
        "path": "/ai/translations/batches",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_translation_batch_show": {
        "method": "GET",
        "path": "/ai/translations/batches/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_translation_glossary": {
        "method": "GET",
        "path": "/ai/translations/glossary",
        "parameters": [
            {
                "name": "type",
                "in": "query",
                "required": true
            },
            {
                "name": "source_locale",
                "in": "query",
                "required": true
            },
            {
                "name": "target_locale",
                "in": "query",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_translation_glossary_delete": {
        "method": "DELETE",
        "path": "/ai/translations/glossary/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_translation_glossary_save": {
        "method": "POST",
        "path": "/ai/translations/glossary",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_ai_translation_overview": {
        "method": "GET",
        "path": "/ai/translations/overview",
        "parameters": [
            {
                "name": "type",
                "in": "query",
                "required": true
            },
            {
                "name": "entry_id",
                "in": "query",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_translation_proposal_show": {
        "method": "GET",
        "path": "/ai/translations/proposals/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_ai_translation_propose": {
        "method": "POST",
        "path": "/ai/translations/proposals",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_identity_admin_link": {
        "method": "POST",
        "path": "/identity/admin/users/{id}/link",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_identity_admin_revoke_all": {
        "method": "POST",
        "path": "/identity/admin/users/{id}/sessions/revoke-all",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_identity_admin_revoke_session": {
        "method": "DELETE",
        "path": "/identity/admin/users/{id}/sessions/{session}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "session",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_identity_admin_sessions": {
        "method": "GET",
        "path": "/identity/admin/users/{id}/sessions",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_identity_admin_unlink": {
        "method": "DELETE",
        "path": "/identity/admin/users/{id}/link",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_identity_admin_users": {
        "method": "GET",
        "path": "/identity/admin/users",
        "parameters": [
            {
                "name": "q",
                "in": "query",
                "required": false
            },
            {
                "name": "linked",
                "in": "query",
                "required": false
            },
            {
                "name": "limit",
                "in": "query",
                "required": false
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "api_v1_knowledge_categories": {
        "method": "GET",
        "path": "/knowledge/categories",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_knowledge_categories_create": {
        "method": "POST",
        "path": "/knowledge/categories",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_knowledge_categories_delete": {
        "method": "DELETE",
        "path": "/knowledge/categories/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_knowledge_categories_update": {
        "method": "PUT",
        "path": "/knowledge/categories/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_knowledge_chat_ask": {
        "method": "POST",
        "path": "/knowledge/chat/ask",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_knowledge_chat_conversations": {
        "method": "GET",
        "path": "/knowledge/chat/conversations",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_knowledge_chat_delete": {
        "method": "DELETE",
        "path": "/knowledge/chat/conversations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_knowledge_chat_feedback": {
        "method": "POST",
        "path": "/knowledge/chat/messages/{id}/feedback",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_knowledge_chat_show": {
        "method": "GET",
        "path": "/knowledge/chat/conversations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_knowledge_documents": {
        "method": "GET",
        "path": "/knowledge/documents",
        "parameters": [
            {
                "name": "limit",
                "in": "query",
                "required": false
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            },
            {
                "name": "category_id",
                "in": "query",
                "required": false
            },
            {
                "name": "q",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "api_v1_knowledge_documents_create": {
        "method": "POST",
        "path": "/knowledge/documents",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_knowledge_documents_delete": {
        "method": "DELETE",
        "path": "/knowledge/documents/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_knowledge_documents_show": {
        "method": "GET",
        "path": "/knowledge/documents/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_knowledge_documents_update": {
        "method": "PUT",
        "path": "/knowledge/documents/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_knowledge_import": {
        "method": "POST",
        "path": "/knowledge/import",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_knowledge_reindex": {
        "method": "POST",
        "path": "/knowledge/reindex",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_knowledge_search": {
        "method": "GET",
        "path": "/knowledge/search",
        "parameters": [
            {
                "name": "q",
                "in": "query",
                "required": true
            },
            {
                "name": "visibility",
                "in": "query",
                "required": false
            },
            {
                "name": "locale",
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
    "api_v1_knowledge_sync": {
        "method": "POST",
        "path": "/knowledge/sync-cms",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_public_support_config": {
        "method": "GET",
        "path": "/public/support/config",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_public_support_handoff": {
        "method": "POST",
        "path": "/public/support/tickets/{id}/handoff",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_public_support_messages_create": {
        "method": "POST",
        "path": "/public/support/tickets/{id}/messages",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_public_support_tickets_create": {
        "method": "POST",
        "path": "/public/support/tickets",
        "parameters": [],
        "bodyRequired": true
    },
    "api_v1_public_support_tickets_show": {
        "method": "GET",
        "path": "/public/support/tickets/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_support_ai_draft": {
        "method": "POST",
        "path": "/support/tickets/{id}/ai-draft",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_support_messages_create": {
        "method": "POST",
        "path": "/support/tickets/{id}/messages",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "api_v1_support_tickets": {
        "method": "GET",
        "path": "/support/tickets",
        "parameters": [],
        "bodyRequired": false
    },
    "api_v1_support_tickets_show": {
        "method": "GET",
        "path": "/support/tickets/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "api_v1_support_tickets_update": {
        "method": "PATCH",
        "path": "/support/tickets/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "delete_AnalyticsEventJournal": {
        "method": "DELETE",
        "path": "/tracking/events/journal",
        "parameters": [],
        "bodyRequired": false
    },
    "delete_AnalyticsReportDelete": {
        "method": "DELETE",
        "path": "/analytics/reports/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "delete_AnalyticsServerGrant": {
        "method": "DELETE",
        "path": "/tracking/events/server/grants/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_ApiTokensRevoke": {
        "method": "DELETE",
        "path": "/api-tokens/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_ApiV1AuthSessionsRevoke": {
        "method": "DELETE",
        "path": "/auth/sessions/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_CmsNavigationMenu": {
        "method": "DELETE",
        "path": "/cms/navigation/{handle}",
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_CmsRedirect": {
        "method": "DELETE",
        "path": "/cms/redirects/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_ContentDelete": {
        "method": "DELETE",
        "path": "/content/{type}/{id}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_ContentTypesDelete": {
        "method": "DELETE",
        "path": "/content-types/{handle}",
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_IntegrationsDelete": {
        "method": "DELETE",
        "path": "/integrations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "delete_MediaDelete": {
        "method": "DELETE",
        "path": "/media/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_MigrationProjectDelete": {
        "method": "DELETE",
        "path": "/migration/projects/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "delete_UsersDisable": {
        "method": "DELETE",
        "path": "/users/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "delete_WebhooksDelete": {
        "method": "DELETE",
        "path": "/webhooks/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "extensionActionRead": {
        "method": "GET",
        "path": "/extensions/actions/{slug}/{action}",
        "parameters": [
            {
                "name": "slug",
                "in": "path",
                "required": true
            },
            {
                "name": "action",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "extensionActionWrite": {
        "method": "POST",
        "path": "/extensions/actions/{slug}/{action}",
        "parameters": [
            {
                "name": "slug",
                "in": "path",
                "required": true
            },
            {
                "name": "action",
                "in": "path",
                "required": true
            }
        ],
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
    "get_AnalyticsEventConfig": {
        "method": "GET",
        "path": "/tracking/events",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AnalyticsEventJournal": {
        "method": "GET",
        "path": "/tracking/events/journal",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AnalyticsReportData": {
        "method": "GET",
        "path": "/analytics/reports/{id}/data",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "from",
                "in": "query",
                "required": false
            },
            {
                "name": "to",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_AnalyticsReportIndex": {
        "method": "GET",
        "path": "/analytics/reports",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AnalyticsServerStatus": {
        "method": "GET",
        "path": "/tracking/events/server/grants/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_ApiTokensIndex": {
        "method": "GET",
        "path": "/api-tokens",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ApiV1AuthSessionsIndex": {
        "method": "GET",
        "path": "/auth/sessions",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AuthAssuranceStatus": {
        "method": "GET",
        "path": "/auth/assurance",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AuthMe": {
        "method": "GET",
        "path": "/auth/me",
        "parameters": [],
        "bodyRequired": false
    },
    "get_AuthOidcStatus": {
        "method": "GET",
        "path": "/auth/oidc/status",
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
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
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
    "get_CmsRoutes": {
        "method": "GET",
        "path": "/cms/routes",
        "parameters": [
            {
                "name": "limit",
                "in": "query",
                "required": false
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_CmsTheme": {
        "method": "GET",
        "path": "/cms/theme",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ConsentPolicy": {
        "method": "GET",
        "path": "/privacy/consent",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ContentIndex": {
        "method": "GET",
        "path": "/content/{type}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
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
            },
            {
                "name": "limit",
                "in": "query",
                "required": false
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_ContentLocalizations": {
        "method": "GET",
        "path": "/content/{type}/{id}/localizations",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_ContentRevisions": {
        "method": "GET",
        "path": "/content/{type}/{id}/revisions",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_ContentShow": {
        "method": "GET",
        "path": "/content/{type}/{id}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
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
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_ContentTypesShow": {
        "method": "GET",
        "path": "/content-types/{handle}",
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
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
    "get_IdentityAccount": {
        "method": "GET",
        "path": "/identity/account",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ImportExportDownload": {
        "method": "GET",
        "path": "/import-export/download/{file}",
        "parameters": [
            {
                "name": "file",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_ImportExportExports": {
        "method": "GET",
        "path": "/import-export/exports",
        "parameters": [],
        "bodyRequired": false
    },
    "get_ImportExportRequests": {
        "method": "GET",
        "path": "/import-export/requests",
        "parameters": [],
        "bodyRequired": false
    },
    "get_InstallerRequirements": {
        "method": "GET",
        "path": "/installer/requirements",
        "parameters": [],
        "bodyRequired": false
    },
    "get_IntegrationsIndex": {
        "method": "GET",
        "path": "/integrations",
        "parameters": [],
        "bodyRequired": false
    },
    "get_MediaDiagnostics": {
        "method": "GET",
        "path": "/media/diagnostics",
        "parameters": [
            {
                "name": "verify",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_MediaDownload": {
        "method": "GET",
        "path": "/media/{id}/download",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "variant",
                "in": "query",
                "required": false
            },
            {
                "name": "version",
                "in": "query",
                "required": false
            }
        ],
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
            },
            {
                "name": "folder",
                "in": "query",
                "required": false
            },
            {
                "name": "mime",
                "in": "query",
                "required": false
            },
            {
                "name": "limit",
                "in": "query",
                "required": false
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            },
            {
                "name": "visibility",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_MediaShow": {
        "method": "GET",
        "path": "/media/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_MediaSign": {
        "method": "GET",
        "path": "/media/{id}/sign",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "variant",
                "in": "query",
                "required": false
            },
            {
                "name": "version",
                "in": "query",
                "required": false
            },
            {
                "name": "ttl",
                "in": "query",
                "required": false
            },
            {
                "name": "s3",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_MediaSignedDownload": {
        "method": "GET",
        "path": "/media/{id}/signed-download",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "variant",
                "in": "query",
                "required": false
            },
            {
                "name": "version",
                "in": "query",
                "required": false
            },
            {
                "name": "expires",
                "in": "query",
                "required": true
            },
            {
                "name": "signature",
                "in": "query",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_MigrationCatalog": {
        "method": "GET",
        "path": "/migration/catalog",
        "parameters": [],
        "bodyRequired": false
    },
    "get_MigrationProjectIndex": {
        "method": "GET",
        "path": "/migration/projects",
        "parameters": [],
        "bodyRequired": false
    },
    "get_MigrationProjectShow": {
        "method": "GET",
        "path": "/migration/projects/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "get_PublicConsent": {
        "method": "GET",
        "path": "/public/consent",
        "parameters": [],
        "bodyRequired": false
    },
    "get_PublicPreview": {
        "method": "GET",
        "path": "/public/preview/{type}/{id}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "format",
                "in": "query",
                "required": false
            }
        ],
        "bodyRequired": false
    },
    "get_PublicSearch": {
        "method": "GET",
        "path": "/public/search",
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
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            },
            {
                "name": "type",
                "in": "query",
                "required": false
            },
            {
                "name": "locale",
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
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
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
            },
            {
                "name": "offset",
                "in": "query",
                "required": false
            },
            {
                "name": "type",
                "in": "query",
                "required": false
            },
            {
                "name": "locale",
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
    "get_SystemOperations": {
        "method": "GET",
        "path": "/system/operations",
        "parameters": [],
        "bodyRequired": false
    },
    "get_TrackingConfig": {
        "method": "GET",
        "path": "/tracking/counters",
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
    "get_Usage": {
        "method": "GET",
        "path": "/media/{id}/usage",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
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
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
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
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
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
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "patch_ContentTypesPatch": {
        "method": "PATCH",
        "path": "/content-types/{handle}",
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "patch_ExtensionsConfig": {
        "method": "PATCH",
        "path": "/extensions/config",
        "parameters": [],
        "bodyRequired": true
    },
    "patch_IntegrationsUpdate": {
        "method": "PATCH",
        "path": "/integrations/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "patch_Update": {
        "method": "PATCH",
        "path": "/media/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "patch_UsersPatch": {
        "method": "PATCH",
        "path": "/users/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "patch_WebhooksPatch": {
        "method": "PATCH",
        "path": "/webhooks/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "post_AnalyticsEventDiagnostic": {
        "method": "POST",
        "path": "/public/analytics/events",
        "parameters": [],
        "bodyRequired": true
    },
    "post_AnalyticsReportCreate": {
        "method": "POST",
        "path": "/analytics/reports",
        "parameters": [],
        "bodyRequired": true
    },
    "post_AnalyticsReportSync": {
        "method": "POST",
        "path": "/analytics/reports/{id}/sync",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_AnalyticsServerEvent": {
        "method": "POST",
        "path": "/tracking/events/server",
        "parameters": [],
        "bodyRequired": true
    },
    "post_AnalyticsServerGrant": {
        "method": "POST",
        "path": "/tracking/events/server/grants",
        "parameters": [],
        "bodyRequired": true
    },
    "post_AnalyticsWebmasterAction": {
        "method": "POST",
        "path": "/analytics/reports/{id}/webmaster",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_ApiTokensStore": {
        "method": "POST",
        "path": "/api-tokens",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ApiV1AuthSessionsRevokeAll": {
        "method": "POST",
        "path": "/auth/sessions/revoke-all",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ApiV1AuthSessionsRevokeOthers": {
        "method": "POST",
        "path": "/auth/sessions/revoke-others",
        "parameters": [],
        "bodyRequired": false
    },
    "post_ApiV1AuthSessionsRotate": {
        "method": "POST",
        "path": "/auth/sessions/rotate",
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
    "post_AuthAssuranceConfirm": {
        "method": "POST",
        "path": "/auth/assurance/confirm",
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
    "post_AuthOidcGlobalLogout": {
        "method": "POST",
        "path": "/auth/oidc/logout",
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
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "post_ContentLocalizationsStore": {
        "method": "POST",
        "path": "/content/{type}/{id}/localizations",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "post_ContentPublish": {
        "method": "POST",
        "path": "/content/{type}/{id}/publish",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "post_ContentRevisionsRestore": {
        "method": "POST",
        "path": "/content/{type}/{id}/revisions/{revision}/restore",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "revision",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "post_ContentRevisionsRestoreShort": {
        "method": "POST",
        "path": "/content/{type}/{id}/revisions/{revision}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            },
            {
                "name": "revision",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "post_ContentStore": {
        "method": "POST",
        "path": "/content/{type}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            }
        ],
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
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
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
    "post_IdentityLinkStart": {
        "method": "POST",
        "path": "/identity/link/start",
        "parameters": [],
        "bodyRequired": true
    },
    "post_IdentityStepUpStart": {
        "method": "POST",
        "path": "/identity/step-up/start",
        "parameters": [],
        "bodyRequired": false
    },
    "post_IdentityUnlink": {
        "method": "POST",
        "path": "/identity/unlink",
        "parameters": [],
        "bodyRequired": true
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
    "post_ImportExportUpload": {
        "method": "POST",
        "path": "/import-export/upload",
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
    "post_IntegrationsSend": {
        "method": "POST",
        "path": "/integrations/{id}/send",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_IntegrationsStore": {
        "method": "POST",
        "path": "/integrations",
        "parameters": [],
        "bodyRequired": true
    },
    "post_IntegrationsTest": {
        "method": "POST",
        "path": "/integrations/{id}/test",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_MediaStore": {
        "method": "POST",
        "path": "/media",
        "parameters": [],
        "bodyRequired": true
    },
    "post_MigrationProjectApprove": {
        "method": "POST",
        "path": "/migration/projects/{id}/approve",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_MigrationProjectCreate": {
        "method": "POST",
        "path": "/migration/projects",
        "parameters": [],
        "bodyRequired": true
    },
    "post_MigrationProjectPlan": {
        "method": "POST",
        "path": "/migration/projects/{id}/plan",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_MigrationProjectScan": {
        "method": "POST",
        "path": "/migration/projects/{id}/scan",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_MigrationProjectUpload": {
        "method": "POST",
        "path": "/migration/projects/{id}/wxr",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "post_QueueFailedRetry": {
        "method": "POST",
        "path": "/queue/failed/{id}/retry",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            },
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
    "post_Replace": {
        "method": "POST",
        "path": "/media/{id}/replace",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
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
    "post_Variants": {
        "method": "POST",
        "path": "/media/{id}/variants",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
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
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "put_AnalyticsEventConfig": {
        "method": "PUT",
        "path": "/tracking/events",
        "parameters": [],
        "bodyRequired": true
    },
    "put_AnalyticsReportUpdate": {
        "method": "PUT",
        "path": "/analytics/reports/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "put_CmsNavigationMenu": {
        "method": "PUT",
        "path": "/cms/navigation/{handle}",
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "put_ConsentPolicy": {
        "method": "PUT",
        "path": "/privacy/consent",
        "parameters": [],
        "bodyRequired": true
    },
    "put_ContentTypesUpdate": {
        "method": "PUT",
        "path": "/content-types/{handle}",
        "parameters": [
            {
                "name": "handle",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "put_ContentUpdate": {
        "method": "PUT",
        "path": "/content/{type}/{id}",
        "parameters": [
            {
                "name": "type",
                "in": "path",
                "required": true
            },
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": false
    },
    "put_IntegrationsSecret": {
        "method": "PUT",
        "path": "/integrations/{id}/secret",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "put_MigrationProjectMapping": {
        "method": "PUT",
        "path": "/migration/projects/{id}/mapping",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "put_MigrationProjectUpdate": {
        "method": "PUT",
        "path": "/migration/projects/{id}",
        "parameters": [
            {
                "name": "id",
                "in": "path",
                "required": true
            }
        ],
        "bodyRequired": true
    },
    "put_TrackingConfig": {
        "method": "PUT",
        "path": "/tracking/counters",
        "parameters": [],
        "bodyRequired": true
    }
} as const
export type OperationId = keyof typeof operations
export type OperationArguments = { path?: Record<string, string | number>; query?: Record<string, string | number | boolean>; body?: unknown }
