CREATE TABLE IF NOT EXISTS ce_settings (
    `key` VARCHAR(255) PRIMARY KEY,
    value JSON NOT NULL,
    type VARCHAR(40) NOT NULL DEFAULT 'json',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_outbox (
    id CHAR(36) PRIMARY KEY,
    event_name VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    headers JSON NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ce_outbox_status_available_idx (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ce_content_entries_status_idx ON ce_content_entries(status, locale, updated_at);
CREATE INDEX ce_content_entries_slug_idx ON ce_content_entries(slug, locale);
CREATE INDEX ce_audit_log_event_created_idx ON ce_audit_log(event, created_at);
