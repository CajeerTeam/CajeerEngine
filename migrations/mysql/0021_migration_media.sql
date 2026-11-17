-- Resumable, immutable source-file mapping. All links are rewritten only after verified copies.
CREATE TABLE IF NOT EXISTS ce_migration_media_files (
    project_id VARCHAR(32) NOT NULL,
    source_path VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    checksum VARCHAR(64) NOT NULL,
    media_id VARCHAR(36) NOT NULL,
    size_bytes BIGINT NOT NULL,
    visibility VARCHAR(10) NOT NULL,
    created_at VARCHAR(40) NOT NULL,
    PRIMARY KEY (project_id, source_path)
);
CREATE TABLE IF NOT EXISTS ce_migration_media_rewrites (
    project_id VARCHAR(32) NOT NULL,
    target_id VARCHAR(36) NOT NULL,
    original_revision INTEGER NOT NULL,
    original_sha VARCHAR(64) NOT NULL,
    updated_sha VARCHAR(64) NOT NULL,
    replacements INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL,
    updated_at VARCHAR(40) NOT NULL,
    PRIMARY KEY (project_id, target_id)
);
