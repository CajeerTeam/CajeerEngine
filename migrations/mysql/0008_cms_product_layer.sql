CREATE TABLE IF NOT EXISTS ce_cms_navigation_menus (
    id CHAR(36) PRIMARY KEY,
    handle VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_navigation_items (
    id CHAR(36) PRIMARY KEY,
    menu_id CHAR(36) NOT NULL,
    parent_id CHAR(36) NULL,
    label VARCHAR(160) NOT NULL,
    url VARCHAR(600) NOT NULL,
    entry_type VARCHAR(100) NULL,
    entry_id VARCHAR(100) NULL,
    target VARCHAR(20) NOT NULL DEFAULT '_self',
    sort_order INT NOT NULL DEFAULT 0,
    data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ce_cms_navigation_items_menu_fk FOREIGN KEY (menu_id) REFERENCES ce_cms_navigation_menus(id) ON DELETE CASCADE,
    CONSTRAINT ce_cms_navigation_items_parent_fk FOREIGN KEY (parent_id) REFERENCES ce_cms_navigation_items(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ce_cms_redirects (
    id CHAR(36) PRIMARY KEY,
    source_path VARCHAR(700) NOT NULL UNIQUE,
    target_url VARCHAR(1200) NOT NULL,
    status_code INT NOT NULL DEFAULT 301,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    hit_count BIGINT NOT NULL DEFAULT 0,
    last_hit_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_theme_settings (
    key_name VARCHAR(120) PRIMARY KEY,
    value JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_cms_preview_tokens (
    id CHAR(36) PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    content_type VARCHAR(100) NOT NULL,
    entry_id VARCHAR(100) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX ce_cms_navigation_items_menu_idx ON ce_cms_navigation_items(menu_id, sort_order);
CREATE INDEX ce_cms_redirects_source_enabled_idx ON ce_cms_redirects(source_path, enabled);
CREATE INDEX ce_cms_preview_tokens_hash_idx ON ce_cms_preview_tokens(token_hash, expires_at);
