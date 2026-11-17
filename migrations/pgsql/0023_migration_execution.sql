CREATE TABLE ce_migration_execution (
    project_id VARCHAR(32) PRIMARY KEY,
    source VARCHAR(32) NOT NULL,
    plan_sha256 VARCHAR(64) NOT NULL,
    source_sha256 VARCHAR(64) NOT NULL,
    backup_id VARCHAR(40) NOT NULL,
    total INTEGER NOT NULL,
    cursor_index INTEGER NOT NULL DEFAULT 0,
    state VARCHAR(16) NOT NULL CHECK(state IN ('running','paused','completed')),
    identities TEXT,
    started_at VARCHAR(40) NOT NULL,
    updated_at VARCHAR(40) NOT NULL,
    CHECK(cursor_index >= 0 AND cursor_index <= total)
);
CREATE TABLE ce_migration_execution_items (
    project_id VARCHAR(32) NOT NULL REFERENCES ce_migration_execution(project_id),
    ordinal INTEGER NOT NULL,
    source_type VARCHAR(100) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    target_id VARCHAR(40),
    result TEXT NOT NULL,
    PRIMARY KEY(project_id,ordinal),
    UNIQUE(project_id,source_type,source_id)
);
