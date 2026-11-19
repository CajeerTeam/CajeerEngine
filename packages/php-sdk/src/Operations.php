<?php

declare(strict_types=1);
namespace CajeerEngine\ExtensionSdk;

/** Generated from api/openapi.yaml. */
final class Operations {
    public const ALL = array (
  'api_v1_ai_actions' => 
  array (
    'method' => 'GET',
    'path' => '/ai/actions',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_actions_approve' => 
  array (
    'method' => 'POST',
    'path' => '/ai/actions/{id}/approve',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_actions_reject' => 
  array (
    'method' => 'POST',
    'path' => '/ai/actions/{id}/reject',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_actions_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/actions/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_analytics_explain' => 
  array (
    'method' => 'POST',
    'path' => '/ai/analytics/explain',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_analytics_insights' => 
  array (
    'method' => 'GET',
    'path' => '/ai/analytics/insights',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'connection_id',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_chat' => 
  array (
    'method' => 'POST',
    'path' => '/ai/chat',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_chat_stream' => 
  array (
    'method' => 'POST',
    'path' => '/ai/chat/stream',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_conversation_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_conversation_rename' => 
  array (
    'method' => 'PATCH',
    'path' => '/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_conversation_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_conversations' => 
  array (
    'method' => 'GET',
    'path' => '/ai/conversations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_diagnostics_explain' => 
  array (
    'method' => 'POST',
    'path' => '/ai/diagnostics/explain',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_diagnostics_snapshot' => 
  array (
    'method' => 'GET',
    'path' => '/ai/diagnostics',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'window_minutes',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_editor_apply' => 
  array (
    'method' => 'POST',
    'path' => '/ai/editor/proposals/{id}/apply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_editor_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/editor/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_explain' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/explain',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_apply' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation/{proposal}/apply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'proposal',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_options' => 
  array (
    'method' => 'GET',
    'path' => '/ai/migration/projects/{id}/remediation',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_migration_remediation_prepare' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_reject' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation/{proposal}/reject',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'proposal',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_rollback' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation/{proposal}/rollback',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'proposal',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_report' => 
  array (
    'method' => 'GET',
    'path' => '/ai/migration/projects/{id}/report',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_audit' => 
  array (
    'method' => 'GET',
    'path' => '/ai/seo/audit',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'entry_id',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_batch' => 
  array (
    'method' => 'GET',
    'path' => '/ai/seo/batch',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_insights' => 
  array (
    'method' => 'GET',
    'path' => '/ai/seo/insights',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'connection_id',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_insights_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/seo/insights/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_seo_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/seo/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_settings' => 
  array (
    'method' => 'PUT',
    'path' => '/ai/settings',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_status' => 
  array (
    'method' => 'GET',
    'path' => '/ai/status',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_tools' => 
  array (
    'method' => 'GET',
    'path' => '/ai/tools',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_apply' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/proposals/{id}/apply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_translation_batch_create' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/batches',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_translation_batch_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/batches/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_glossary' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/glossary',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'source_locale',
        'in' => 'query',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'target_locale',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_glossary_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/ai/translations/glossary/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_glossary_save' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/glossary',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_translation_overview' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/overview',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'entry_id',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_proposal_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/proposals/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_link' => 
  array (
    'method' => 'POST',
    'path' => '/identity/admin/users/{id}/link',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_revoke_all' => 
  array (
    'method' => 'POST',
    'path' => '/identity/admin/users/{id}/sessions/revoke-all',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_revoke_session' => 
  array (
    'method' => 'DELETE',
    'path' => '/identity/admin/users/{id}/sessions/{session}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'session',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_identity_admin_sessions' => 
  array (
    'method' => 'GET',
    'path' => '/identity/admin/users/{id}/sessions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_identity_admin_unlink' => 
  array (
    'method' => 'DELETE',
    'path' => '/identity/admin/users/{id}/link',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_users' => 
  array (
    'method' => 'GET',
    'path' => '/identity/admin/users',
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
        'name' => 'linked',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_categories' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/categories',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_categories_create' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/categories',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_categories_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/knowledge/categories/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_categories_update' => 
  array (
    'method' => 'PUT',
    'path' => '/knowledge/categories/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_chat_ask' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/chat/ask',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_chat_conversations' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/chat/conversations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_chat_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/knowledge/chat/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_chat_feedback' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/chat/messages/{id}/feedback',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_chat_show' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/chat/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_documents' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/documents',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'category_id',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_documents_create' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/documents',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_documents_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/knowledge/documents/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_documents_show' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/documents/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_documents_update' => 
  array (
    'method' => 'PUT',
    'path' => '/knowledge/documents/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_import' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/import',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_reindex' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/reindex',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_search' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'visibility',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_sync' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/sync-cms',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_config' => 
  array (
    'method' => 'GET',
    'path' => '/public/support/config',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_public_support_handoff' => 
  array (
    'method' => 'POST',
    'path' => '/public/support/tickets/{id}/handoff',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_messages_create' => 
  array (
    'method' => 'POST',
    'path' => '/public/support/tickets/{id}/messages',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_tickets_create' => 
  array (
    'method' => 'POST',
    'path' => '/public/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_tickets_show' => 
  array (
    'method' => 'GET',
    'path' => '/public/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_support_ai_draft' => 
  array (
    'method' => 'POST',
    'path' => '/support/tickets/{id}/ai-draft',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_support_messages_create' => 
  array (
    'method' => 'POST',
    'path' => '/support/tickets/{id}/messages',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_support_tickets' => 
  array (
    'method' => 'GET',
    'path' => '/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_support_tickets_show' => 
  array (
    'method' => 'GET',
    'path' => '/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_support_tickets_update' => 
  array (
    'method' => 'PATCH',
    'path' => '/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_AnalyticsEventJournal' => 
  array (
    'method' => 'DELETE',
    'path' => '/tracking/events/journal',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_AnalyticsReportDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/analytics/reports/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_AnalyticsServerGrant' => 
  array (
    'method' => 'DELETE',
    'path' => '/tracking/events/server/grants/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ApiTokensRevoke' => 
  array (
    'method' => 'DELETE',
    'path' => '/api-tokens/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ApiV1AuthSessionsRevoke' => 
  array (
    'method' => 'DELETE',
    'path' => '/auth/sessions/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_CmsNavigationMenu' => 
  array (
    'method' => 'DELETE',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_CmsRedirect' => 
  array (
    'method' => 'DELETE',
    'path' => '/cms/redirects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ContentDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ContentTypesDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_IntegrationsDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/integrations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_MediaDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/media/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_MigrationProjectDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/migration/projects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_UsersDisable' => 
  array (
    'method' => 'DELETE',
    'path' => '/users/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_WebhooksDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/webhooks/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'extensionActionRead' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/actions/{slug}/{action}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'slug',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'action',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'extensionActionWrite' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/actions/{slug}/{action}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'slug',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'action',
        'in' => 'path',
        'required' => true,
      ),
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
  'get_AnalyticsEventConfig' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/events',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsEventJournal' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/events/journal',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsReportData' => 
  array (
    'method' => 'GET',
    'path' => '/analytics/reports/{id}/data',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsReportIndex' => 
  array (
    'method' => 'GET',
    'path' => '/analytics/reports',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsServerStatus' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/events/server/grants/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
  'get_ApiV1AuthSessionsIndex' => 
  array (
    'method' => 'GET',
    'path' => '/auth/sessions',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AuthAssuranceStatus' => 
  array (
    'method' => 'GET',
    'path' => '/auth/assurance',
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
  'get_AuthOidcStatus' => 
  array (
    'method' => 'GET',
    'path' => '/auth/oidc/status',
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
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
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
  'get_CmsRoutes' => 
  array (
    'method' => 'GET',
    'path' => '/cms/routes',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
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
  'get_ConsentPolicy' => 
  array (
    'method' => 'GET',
    'path' => '/privacy/consent',
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
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'published_only',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      6 => 
      array (
        'name' => 'offset',
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
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentRevisions' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}/revisions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentShow' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesShow' => 
  array (
    'method' => 'GET',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
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
  'get_IdentityAccount' => 
  array (
    'method' => 'GET',
    'path' => '/identity/account',
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
  'get_IntegrationsIndex' => 
  array (
    'method' => 'GET',
    'path' => '/integrations',
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
      0 => 
      array (
        'name' => 'verify',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaDownload' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/download',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'version',
        'in' => 'query',
        'required' => false,
      ),
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
      1 => 
      array (
        'name' => 'folder',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'mime',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'visibility',
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
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaSign' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/sign',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'version',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'ttl',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 's3',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaSignedDownload' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/signed-download',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'version',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'expires',
        'in' => 'query',
        'required' => true,
      ),
      4 => 
      array (
        'name' => 'signature',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MigrationCatalog' => 
  array (
    'method' => 'GET',
    'path' => '/migration/catalog',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MigrationProjectIndex' => 
  array (
    'method' => 'GET',
    'path' => '/migration/projects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MigrationProjectShow' => 
  array (
    'method' => 'GET',
    'path' => '/migration/projects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_PublicConsent' => 
  array (
    'method' => 'GET',
    'path' => '/public/consent',
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
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'format',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_PublicSearch' => 
  array (
    'method' => 'GET',
    'path' => '/public/search',
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
      3 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'locale',
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
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
      3 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'locale',
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
  'get_SystemOperations' => 
  array (
    'method' => 'GET',
    'path' => '/system/operations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_TrackingConfig' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/counters',
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
  'get_Usage' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/usage',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'patch_ContentTypesPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
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
  'patch_IntegrationsUpdate' => 
  array (
    'method' => 'PATCH',
    'path' => '/integrations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Update' => 
  array (
    'method' => 'PATCH',
    'path' => '/media/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_UsersPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/users/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'patch_WebhooksPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/webhooks/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_AnalyticsEventDiagnostic' => 
  array (
    'method' => 'POST',
    'path' => '/public/analytics/events',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsReportCreate' => 
  array (
    'method' => 'POST',
    'path' => '/analytics/reports',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsReportSync' => 
  array (
    'method' => 'POST',
    'path' => '/analytics/reports/{id}/sync',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsServerEvent' => 
  array (
    'method' => 'POST',
    'path' => '/tracking/events/server',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsServerGrant' => 
  array (
    'method' => 'POST',
    'path' => '/tracking/events/server/grants',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsWebmasterAction' => 
  array (
    'method' => 'POST',
    'path' => '/analytics/reports/{id}/webmaster',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
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
  'post_ApiV1AuthSessionsRevokeAll' => 
  array (
    'method' => 'POST',
    'path' => '/auth/sessions/revoke-all',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ApiV1AuthSessionsRevokeOthers' => 
  array (
    'method' => 'POST',
    'path' => '/auth/sessions/revoke-others',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ApiV1AuthSessionsRotate' => 
  array (
    'method' => 'POST',
    'path' => '/auth/sessions/rotate',
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
  'post_AuthAssuranceConfirm' => 
  array (
    'method' => 'POST',
    'path' => '/auth/assurance/confirm',
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
  'post_AuthOidcGlobalLogout' => 
  array (
    'method' => 'POST',
    'path' => '/auth/oidc/logout',
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
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentLocalizationsStore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/localizations',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentPublish' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/publish',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentRevisionsRestore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/revisions/{revision}/restore',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'revision',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentRevisionsRestoreShort' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/revisions/{revision}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'revision',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentStore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
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
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
  'post_IdentityLinkStart' => 
  array (
    'method' => 'POST',
    'path' => '/identity/link/start',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_IdentityStepUpStart' => 
  array (
    'method' => 'POST',
    'path' => '/identity/step-up/start',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_IdentityUnlink' => 
  array (
    'method' => 'POST',
    'path' => '/identity/unlink',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
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
  'post_IntegrationsSend' => 
  array (
    'method' => 'POST',
    'path' => '/integrations/{id}/send',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_IntegrationsStore' => 
  array (
    'method' => 'POST',
    'path' => '/integrations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_IntegrationsTest' => 
  array (
    'method' => 'POST',
    'path' => '/integrations/{id}/test',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
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
  'post_MigrationProjectApprove' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/approve',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectCreate' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectPlan' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/plan',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectScan' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/scan',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectUpload' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/wxr',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
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
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
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
  'post_Replace' => 
  array (
    'method' => 'POST',
    'path' => '/media/{id}/replace',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
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
  'post_Variants' => 
  array (
    'method' => 'POST',
    'path' => '/media/{id}/variants',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
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
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_AnalyticsEventConfig' => 
  array (
    'method' => 'PUT',
    'path' => '/tracking/events',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_AnalyticsReportUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/analytics/reports/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_CmsNavigationMenu' => 
  array (
    'method' => 'PUT',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_ConsentPolicy' => 
  array (
    'method' => 'PUT',
    'path' => '/privacy/consent',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_ContentTypesUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_ContentUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_IntegrationsSecret' => 
  array (
    'method' => 'PUT',
    'path' => '/integrations/{id}/secret',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_MigrationProjectMapping' => 
  array (
    'method' => 'PUT',
    'path' => '/migration/projects/{id}/mapping',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_MigrationProjectUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/migration/projects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_TrackingConfig' => 
  array (
    'method' => 'PUT',
    'path' => '/tracking/counters',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
);
}
