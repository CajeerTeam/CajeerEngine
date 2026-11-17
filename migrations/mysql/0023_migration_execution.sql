CREATE TABLE ce_migration_execution (
    project_id VARCHAR(32) PRIMARY KEY,
    source VARCHAR(32) NOT NULL,
    plan_sha256 VARCHAR(64) NOT NULL,
    source_sha256 VARCHAR(64) NOT NULL,
    backup_id VARCHAR(40) NOT NULL,
    total INT UNSIGNED NOT NULL,
    cursor_index INT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(16) NOT NULL,
    identities LONGTEXT,
    started_at VARCHAR(40) NOT NULL,
    updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_migration_execution_items (
    project_id VARCHAR(32) NOT NULL,
    ordinal INT UNSIGNED NOT NULL,
    source_type VARCHAR(100) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    target_id VARCHAR(40),
    result LONGTEXT NOT NULL,
    PRIMARY KEY(project_id,ordinal),
    UNIQUE KEY uq_migration_item_source(project_id,source_type,source_id),
    CONSTRAINT fk_migration_execution_item FOREIGN KEY(project_id) REFERENCES ce_migration_execution(project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
