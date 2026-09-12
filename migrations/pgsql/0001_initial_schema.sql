CREATE TABLE IF NOT EXISTS ce_users (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    password_hash TEXT NOT NULL,
    two_factor_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    two_factor_secret TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_roles (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    handle VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_user_roles (
    user_id UUID NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    role_id UUID NOT NULL REFERENCES ce_roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS ce_api_tokens (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    user_id UUID NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    last_used_at TIMESTAMPTZ NULL,
    expires_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_content_types (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    handle VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    fields JSONB NOT NULL DEFAULT '[]'::jsonb,
    blocks JSONB NOT NULL DEFAULT '[]'::jsonb,
    localized BOOLEAN NOT NULL DEFAULT FALSE,
    revisionable BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_content_entries (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    content_type_id UUID NOT NULL REFERENCES ce_content_types(id) ON DELETE CASCADE,
    slug VARCHAR(255) NOT NULL,
    locale VARCHAR(20) NOT NULL DEFAULT 'ru',
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    data JSONB NOT NULL DEFAULT '{}'::jsonb,
    author_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    published_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(content_type_id, slug, locale)
);

CREATE TABLE IF NOT EXISTS ce_content_revisions (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    entry_id UUID NOT NULL REFERENCES ce_content_entries(id) ON DELETE CASCADE,
    revision_number INT NOT NULL,
    data JSONB NOT NULL,
    actor_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entry_id, revision_number)
);

CREATE TABLE IF NOT EXISTS ce_settings_history (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    key VARCHAR(255) NOT NULL,
    value JSONB NOT NULL,
    actor_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_theme_config_history (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    theme VARCHAR(255) NOT NULL,
    config JSONB NOT NULL,
    actor_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_extension_config_history (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    extension VARCHAR(255) NOT NULL,
    config JSONB NOT NULL,
    actor_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_extensions (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    name VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,
    version VARCHAR(50) NOT NULL,
    engine_constraint VARCHAR(50) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    manifest JSONB NOT NULL,
    signature VARCHAR(255) NULL,
    installed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_jobs (
    id UUID PRIMARY KEY,
    queue VARCHAR(100) NOT NULL DEFAULT 'default',
    name VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    attempts INT NOT NULL DEFAULT 0,
    reserved_at TIMESTAMPTZ NULL,
    available_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ce_jobs_queue_reserved_idx ON ce_jobs(queue, reserved_at, available_at);

CREATE TABLE IF NOT EXISTS ce_media (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    disk VARCHAR(50) NOT NULL DEFAULT 'local',
    path TEXT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(255) NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_audit_log (
    id BIGSERIAL PRIMARY KEY,
    event VARCHAR(255) NOT NULL,
    actor_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    context JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address INET NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_webhooks (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    name VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    events JSONB NOT NULL DEFAULT '[]'::jsonb,
    secret_hash VARCHAR(255) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_search_documents (
    index_name VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    title TEXT NOT NULL,
    excerpt TEXT NOT NULL,
    raw JSONB NOT NULL DEFAULT '{}'::jsonb,
    search_vector TSVECTOR NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (index_name, source_id)
);

CREATE INDEX IF NOT EXISTS ce_search_documents_vector_idx ON ce_search_documents USING GIN(search_vector);
