ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'pending';
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS last_error TEXT NULL;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS failed_at TIMESTAMP NULL;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS processed_at TIMESTAMP NULL;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS max_attempts INT NOT NULL DEFAULT 3;

CREATE TABLE IF NOT EXISTS ce_failed_jobs (
    id CHAR(36) PRIMARY KEY,
    queue VARCHAR(100) NOT NULL DEFAULT 'default',
    name VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    error TEXT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ce_failed_jobs_queue_failed_idx (queue, failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ce_webhook_deliveries (
    id CHAR(36) PRIMARY KEY,
    webhook_id CHAR(36) NULL,
    event_name VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    status_code INT NOT NULL DEFAULT 0,
    ok BOOLEAN NOT NULL DEFAULT FALSE,
    error TEXT NULL,
    response_excerpt TEXT NULL,
    duration_ms DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ce_webhook_deliveries_webhook_created_idx (webhook_id, created_at),
    INDEX ce_webhook_deliveries_event_created_idx (event_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
