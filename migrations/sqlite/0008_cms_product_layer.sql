CREATE TABLE IF NOT EXISTS ce_cms_navigation_menus (
    id TEXT PRIMARY KEY,
    handle TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    data TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_navigation_items (
    id TEXT PRIMARY KEY,
    menu_id TEXT NOT NULL REFERENCES ce_cms_navigation_menus(id) ON DELETE CASCADE,
    parent_id TEXT NULL REFERENCES ce_cms_navigation_items(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    url TEXT NOT NULL,
    entry_type TEXT NULL,
    entry_id TEXT NULL,
    target TEXT NOT NULL DEFAULT '_self',
    sort_order INTEGER NOT NULL DEFAULT 0,
    data TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_redirects (
    id TEXT PRIMARY KEY,
    source_path TEXT NOT NULL UNIQUE,
    target_url TEXT NOT NULL,
    status_code INTEGER NOT NULL DEFAULT 301,
    enabled INTEGER NOT NULL DEFAULT 1,
    hit_count INTEGER NOT NULL DEFAULT 0,
    last_hit_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_theme_settings (
    key_name TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_preview_tokens (
    id TEXT PRIMARY KEY,
    token_hash TEXT NOT NULL UNIQUE,
    content_type TEXT NOT NULL,
    entry_id TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ce_cms_navigation_items_menu_idx ON ce_cms_navigation_items(menu_id, sort_order);
CREATE INDEX IF NOT EXISTS ce_cms_redirects_source_enabled_idx ON ce_cms_redirects(source_path, enabled);
CREATE INDEX IF NOT EXISTS ce_cms_preview_tokens_hash_idx ON ce_cms_preview_tokens(token_hash, expires_at);
