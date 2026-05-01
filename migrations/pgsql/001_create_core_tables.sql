CREATE TABLE IF NOT EXISTS cajeer_content (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(64) NOT NULL DEFAULT 'post',
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    slug VARCHAR(255) NOT NULL UNIQUE,
    title VARCHAR(500) NOT NULL,
    excerpt TEXT NULL,
    body TEXT NULL,
    author_id BIGINT NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cajeer_content_type_status ON cajeer_content(type, status);
CREATE INDEX IF NOT EXISTS idx_cajeer_content_published_at ON cajeer_content(published_at);

CREATE TABLE IF NOT EXISTS cajeer_content_meta (
    id BIGSERIAL PRIMARY KEY,
    content_id BIGINT NOT NULL,
    meta_key VARCHAR(191) NOT NULL,
    meta_value TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_cajeer_content_meta_content_key ON cajeer_content_meta(content_id, meta_key);

CREATE TABLE IF NOT EXISTS cajeer_categories (
    id BIGSERIAL PRIMARY KEY,
    parent_id BIGINT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_users (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_settings (
    setting_key VARCHAR(191) PRIMARY KEY,
    setting_value TEXT NULL,
    autoload SMALLINT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
