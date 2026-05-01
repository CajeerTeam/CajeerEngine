CREATE TABLE IF NOT EXISTS cajeer_extensions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(191) NOT NULL UNIQUE,
    type VARCHAR(64) NOT NULL,
    version VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'disabled',
    manifest_json JSON NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cajeer_extensions_type_status (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cajeer_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    event VARCHAR(191) NOT NULL,
    target_type VARCHAR(191) NULL,
    target_id VARCHAR(191) NULL,
    payload_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cajeer_audit_event (event),
    INDEX idx_cajeer_audit_actor (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
