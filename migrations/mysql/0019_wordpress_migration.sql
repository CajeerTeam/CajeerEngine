-- Atomic receipt and source-to-target identity mapping for a completed WordPress import.
CREATE TABLE IF NOT EXISTS ce_wordpress_migration_runs (
    project_id VARCHAR(32) PRIMARY KEY,
    plan_sha256 VARCHAR(64) NOT NULL,
    completed_at VARCHAR(40) NOT NULL,
    result LONGTEXT NOT NULL
);
