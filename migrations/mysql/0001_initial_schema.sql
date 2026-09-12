CREATE TABLE IF NOT EXISTS ce_users (
    id CHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    password_hash TEXT NOT NULL,
    two_factor_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    two_factor_secret TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_roles (
    id CHAR(36) PRIMARY KEY,
    handle VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    permissions JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_user_roles (
    user_id CHAR(36) NOT NULL,
    role_id CHAR(36) NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT ce_user_roles_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
    CONSTRAINT ce_user_roles_role_fk FOREIGN KEY (role_id) REFERENCES ce_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_api_tokens (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    scopes JSON NOT NULL,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ce_api_tokens_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_content_types (
    id CHAR(36) PRIMARY KEY,
    handle VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    fields JSON NOT NULL,
    blocks JSON NOT NULL,
    localized BOOLEAN NOT NULL DEFAULT FALSE,
    revisionable BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_content_entries (
    id CHAR(36) PRIMARY KEY,
    content_type_id CHAR(36) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    locale VARCHAR(20) NOT NULL DEFAULT 'ru',
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    data JSON NOT NULL,
    author_id CHAR(36) NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ce_content_entries_unique_slug (content_type_id, slug, locale),
    CONSTRAINT ce_content_entries_type_fk FOREIGN KEY (content_type_id) REFERENCES ce_content_types(id) ON DELETE CASCADE,
    CONSTRAINT ce_content_entries_author_fk FOREIGN KEY (author_id) REFERENCES ce_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_content_revisions (
    id CHAR(36) PRIMARY KEY,
    entry_id CHAR(36) NOT NULL,
    revision_number INT NOT NULL,
    data JSON NOT NULL,
    actor_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ce_content_revisions_unique (entry_id, revision_number),
    CONSTRAINT ce_content_revisions_entry_fk FOREIGN KEY (entry_id) REFERENCES ce_content_entries(id) ON DELETE CASCADE,
    CONSTRAINT ce_content_revisions_actor_fk FOREIGN KEY (actor_id) REFERENCES ce_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_settings_history (
    id CHAR(36) PRIMARY KEY,
    `key` VARCHAR(255) NOT NULL,
    value JSON NOT NULL,
    actor_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_theme_config_history (
    id CHAR(36) PRIMARY KEY,
    theme VARCHAR(255) NOT NULL,
    config JSON NOT NULL,
    actor_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_extension_config_history (
    id CHAR(36) PRIMARY KEY,
    extension VARCHAR(255) NOT NULL,
    config JSON NOT NULL,
    actor_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_extensions (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,
    version VARCHAR(50) NOT NULL,
    engine_constraint VARCHAR(50) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    manifest JSON NOT NULL,
    signature VARCHAR(255) NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_jobs (
    id CHAR(36) PRIMARY KEY,
    queue VARCHAR(100) NOT NULL DEFAULT 'default',
    name VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    reserved_at TIMESTAMP NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ce_jobs_queue_reserved_idx (queue, reserved_at, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_media (
    id CHAR(36) PRIMARY KEY,
    disk VARCHAR(50) NOT NULL DEFAULT 'local',
    path TEXT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(255) NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    metadata JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_audit_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    event VARCHAR(255) NOT NULL,
    actor_id CHAR(36) NULL,
    context JSON NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_webhooks (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    events JSON NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_search_documents (
    index_name VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    title TEXT NOT NULL,
    excerpt TEXT NOT NULL,
    raw JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (index_name, source_id),
    FULLTEXT KEY ce_search_documents_fulltext (title, excerpt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
