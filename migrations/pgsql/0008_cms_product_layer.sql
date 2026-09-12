CREATE TABLE IF NOT EXISTS ce_cms_navigation_menus (
    id UUID PRIMARY KEY,
    handle VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    data JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_navigation_items (
    id UUID PRIMARY KEY,
    menu_id UUID NOT NULL REFERENCES ce_cms_navigation_menus(id) ON DELETE CASCADE,
    parent_id UUID NULL REFERENCES ce_cms_navigation_items(id) ON DELETE CASCADE,
    label VARCHAR(160) NOT NULL,
    url VARCHAR(600) NOT NULL,
    entry_type VARCHAR(100) NULL,
    entry_id VARCHAR(100) NULL,
    target VARCHAR(20) NOT NULL DEFAULT '_self',
    sort_order INT NOT NULL DEFAULT 0,
    data JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_redirects (
    id UUID PRIMARY KEY,
    source_path VARCHAR(700) NOT NULL UNIQUE,
    target_url VARCHAR(1200) NOT NULL,
    status_code INT NOT NULL DEFAULT 301,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    hit_count BIGINT NOT NULL DEFAULT 0,
    last_hit_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_theme_settings (
    key_name VARCHAR(120) PRIMARY KEY,
    value JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_preview_tokens (
    id UUID PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    content_type VARCHAR(100) NOT NULL,
    entry_id VARCHAR(100) NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ce_cms_navigation_items_menu_idx ON ce_cms_navigation_items(menu_id, sort_order);
CREATE INDEX IF NOT EXISTS ce_cms_redirects_source_enabled_idx ON ce_cms_redirects(source_path, enabled);
CREATE INDEX IF NOT EXISTS ce_cms_preview_tokens_hash_idx ON ce_cms_preview_tokens(token_hash, expires_at);
