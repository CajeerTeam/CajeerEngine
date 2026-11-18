-- Validation evidence is bounded by the project inventory; never contains source credentials.
CREATE TABLE ce_migration_validation (
    project_id VARCHAR(32) PRIMARY KEY,
    report_sha256 VARCHAR(64) NOT NULL,
    source_sha256 VARCHAR(64) NOT NULL,
    ready SMALLINT NOT NULL,
    report TEXT NOT NULL,
    checked_at VARCHAR(40) NOT NULL
);
-- Changes commit atomically with their durable source-to-target revision mapping.
CREATE TABLE ce_migration_sync_items (
    project_id VARCHAR(32) NOT NULL,
    source_type VARCHAR(100) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    source_sha256 VARCHAR(64) NOT NULL,
    target_id VARCHAR(40) NOT NULL,
    revision INTEGER NOT NULL,
    updated_at VARCHAR(40) NOT NULL,
    PRIMARY KEY(project_id,source_type,source_id)
);
CREATE TABLE ce_migration_sync_runs (
    project_id VARCHAR(32) PRIMARY KEY,
    report_sha256 VARCHAR(64) NOT NULL,
    source_sha256 VARCHAR(64) NOT NULL,
    backup_id VARCHAR(40) NOT NULL,
    cursor_index INTEGER NOT NULL DEFAULT 0,
    total INTEGER NOT NULL,
    state VARCHAR(16) NOT NULL,
    updated_at VARCHAR(40) NOT NULL,
    CHECK(cursor_index>=0 AND cursor_index<=total),
    CHECK(state IN ('running','paused','completed'))
);
CREATE TABLE ce_migration_cutovers (
    project_id VARCHAR(32) PRIMARY KEY,
    report_sha256 VARCHAR(64) NOT NULL,
    target_url VARCHAR(1500) NOT NULL,
    backup_id VARCHAR(40) NOT NULL,
    activated_at VARCHAR(40) NOT NULL
);
