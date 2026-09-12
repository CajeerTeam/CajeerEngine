PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS ce_users (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    two_factor_enabled INTEGER NOT NULL DEFAULT 0,
    two_factor_secret TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    email_verified_at TEXT NULL,
    password_changed_at TEXT NULL,
    last_login_at TEXT NULL,
    disabled_at TEXT NULL,
    two_factor_recovery_codes TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_roles (
    id TEXT PRIMARY KEY,
    handle TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    permissions TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_user_roles (
    user_id TEXT NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    role_id TEXT NOT NULL REFERENCES ce_roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS ce_api_tokens (
    id TEXT PRIMARY KEY,
    user_id TEXT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    token_prefix TEXT NULL,
    scopes TEXT NOT NULL DEFAULT '[]',
    last_used_at TEXT NULL,
    expires_at TEXT NULL,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_content_types (
    id TEXT PRIMARY KEY,
    handle TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    fields TEXT NOT NULL DEFAULT '[]',
    blocks TEXT NOT NULL DEFAULT '[]',
    localized INTEGER NOT NULL DEFAULT 0,
    revisionable INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_content_entries (
    id TEXT PRIMARY KEY,
    content_type_id TEXT NOT NULL REFERENCES ce_content_types(id) ON DELETE CASCADE,
    slug TEXT NOT NULL,
    locale TEXT NOT NULL DEFAULT 'ru',
    status TEXT NOT NULL DEFAULT 'draft',
    title TEXT NOT NULL DEFAULT 'Без названия',
    data TEXT NOT NULL DEFAULT '{}',
    author_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    revision_number INTEGER NOT NULL DEFAULT 1,
    translation_group TEXT NOT NULL DEFAULT '',
    published_at TEXT NULL,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(content_type_id, slug, locale)
);

CREATE TABLE IF NOT EXISTS ce_content_revisions (
    id TEXT PRIMARY KEY,
    entry_id TEXT NOT NULL REFERENCES ce_content_entries(id) ON DELETE CASCADE,
    revision_number INTEGER NOT NULL,
    data TEXT NOT NULL,
    actor_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entry_id, revision_number)
);

CREATE TABLE IF NOT EXISTS ce_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '{}',
    type TEXT NOT NULL DEFAULT 'json',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_settings_history (
    id TEXT PRIMARY KEY,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    actor_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_theme_config_history (
    id TEXT PRIMARY KEY,
    theme TEXT NOT NULL,
    config TEXT NOT NULL,
    actor_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_extension_config_history (
    id TEXT PRIMARY KEY,
    extension TEXT NOT NULL,
    config TEXT NOT NULL,
    actor_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_extensions (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,
    version TEXT NOT NULL,
    engine_constraint TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    manifest TEXT NOT NULL,
    signature TEXT NULL,
    installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_jobs (
    id TEXT PRIMARY KEY,
    queue TEXT NOT NULL DEFAULT 'default',
    name TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    attempts INTEGER NOT NULL DEFAULT 0,
    reserved_at TEXT NULL,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status TEXT NOT NULL DEFAULT 'pending',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT NULL,
    failed_at TEXT NULL,
    processed_at TEXT NULL,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_media (
    id TEXT PRIMARY KEY,
    disk TEXT NOT NULL DEFAULT 'local',
    path TEXT NOT NULL,
    filename TEXT NOT NULL,
    mime_type TEXT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    metadata TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event TEXT NOT NULL,
    actor_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    context TEXT NOT NULL DEFAULT '{}',
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_webhooks (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    events TEXT NOT NULL DEFAULT '[]',
    secret_hash TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_search_documents (
    index_name TEXT NOT NULL,
    source_id TEXT NOT NULL,
    title TEXT NOT NULL,
    excerpt TEXT NOT NULL,
    raw TEXT NOT NULL DEFAULT '{}',
    search_vector TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (index_name, source_id)
);

CREATE TABLE IF NOT EXISTS ce_outbox (
    id TEXT PRIMARY KEY,
    event_name TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    headers TEXT NOT NULL DEFAULT '{}',
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_security_events (
    id TEXT PRIMARY KEY,
    event TEXT NOT NULL,
    actor_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    subject_id TEXT NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    context TEXT NOT NULL DEFAULT '{}',
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_failed_jobs (
    id TEXT PRIMARY KEY,
    queue TEXT NOT NULL DEFAULT 'default',
    name TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    attempts INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_webhook_deliveries (
    id TEXT PRIMARY KEY,
    webhook_id TEXT NULL,
    event_name TEXT NOT NULL,
    url TEXT NOT NULL,
    status_code INTEGER NOT NULL DEFAULT 0,
    ok INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    response_excerpt TEXT NULL,
    duration_ms REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_release_locks (
    id TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    channel TEXT NOT NULL DEFAULT 'stable',
    engine_api_version TEXT NOT NULL DEFAULT '1.0',
    payload TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_support_bundles (
    id TEXT PRIMARY KEY,
    filename TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
